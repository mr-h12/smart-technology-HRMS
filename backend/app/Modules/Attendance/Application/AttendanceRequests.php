<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceRequest;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\Paginates;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class AttendanceRequests
{
    use Paginates;

    public function __construct(
        private readonly AttendanceDays $days,
        private readonly DayCrew $crew,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
    ) {}

    /** @param list<string> $ids
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function forSite(string $site, string $date, string $manager, array $ids): array
    {
        $result = [];
        foreach (AttendanceRequest::where(['site_id' => $site, 'date' => $date, 'requested_by' => $manager])->whereIn('employee_id', $ids)->get() as $request) {
            $result[$request->employee_id][$request->type] = $request->only(['id', 'status', 'minutes', 'amount', 'version']);
        }

        return $result;
    }

    /** @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $q = AttendanceRequest::with(['employee:id,code,name_ar,name_en', 'site:id,name_ar,name_en'])
            ->orderByDesc('date')->orderByDesc('id');
        $status = $filters['status'] ?? 'pending';
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        foreach (['employee_id', 'site_id', 'date', 'type'] as $field) {
            if (isset($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }

        return $this->page($q, (int) ($filters['per_page'] ?? 20));
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->row(AttendanceRequest::with(['employee:id,code,name_ar,name_en', 'site:id,name_ar,name_en'])->findOrFail($id));
    }

    /** Called after the manager's attendance write in the same employee-locked transaction. */
    public function overtime(string $employee, string $date, string $site, string $manager, ?string $in, ?string $out, int $minutes): void
    {
        $this->sync($employee, $date, $site, $manager, 'overtime', $minutes === 0 ? null : [
            'minutes' => $minutes, 'in_time' => $in.':00', 'out_time' => $out.':00',
        ]);
    }

    /** A decided advance is a financial record. A pending advance can be corrected or withdrawn.
     * @param  numeric-string|null  $amount
     */
    public function advance(string $employee, string $date, string $site, string $manager, ?string $amount, bool $withdrawForAttendance): void
    {
        $this->sync($employee, $date, $site, $manager, 'advance', $amount === null ? null : [
            'amount' => bcadd($amount, '0', 6),
        ], $withdrawForAttendance);
    }

    /** @param array<string, mixed>|null $values */
    private function sync(string $employee, string $date, string $site, string $manager, string $type, ?array $values, bool $withdrawForAttendance = false): void
    {
        $key = ['employee_id' => $employee, 'date' => $date, 'type' => $type];
        $request = AttendanceRequest::where($key)->lockForUpdate()->first();
        if ($type === 'advance' && $request !== null && ($request->status !== 'pending' || $request->requested_by !== $manager)) {
            if (! $withdrawForAttendance) {
                throw new ConcurrencyConflict;
            }

            return;
        }
        if ($values === null) {
            $request?->delete();

            return;
        }
        $request ??= new AttendanceRequest([...$key, 'version' => 0]);
        $request->fill([...$values, 'site_id' => $site, 'requested_by' => $manager]);
        if ($request->exists && ! $request->isDirty()) {
            return;
        }
        $request->fill(['status' => 'pending', 'version' => $request->version + 1, 'decided_by' => null, 'decided_at' => null, 'reason' => null]);
        $request->save();

        foreach ($this->holders->userIds('attendance_request.decide.all') as $hr) {
            $this->notify($request, $hr, 'attendance_request_created');
        }
    }

    public function decide(string $id, int $version, bool $approve, ?string $reason, string $by, ?string $paperDate = null, bool $paperDateSupplied = false): string
    {
        $employee = (string) AttendanceRequest::findOrFail($id)->employee_id;

        return $this->crew->locked([$employee], function () use ($id, $version, $approve, $reason, $by, $paperDate, $paperDateSupplied): string {
            $request = AttendanceRequest::find($id) ?? throw new ConcurrencyConflict;
            if ($request->status !== 'pending' || $request->version !== $version) {
                throw new ConcurrencyConflict;
            }
            if ($paperDateSupplied && $request->type !== 'advance') {
                throw ValidationException::withMessages(['paper_decision_date' => __('errors.advance_paper_date_type')]);
            }
            if ($paperDate !== null && $paperDate > now('Africa/Cairo')->toDateString()) {
                throw ValidationException::withMessages(['paper_decision_date' => __('errors.advance_paper_date_future')]);
            }
            if ($paperDate !== null && $paperDate < $request->date) {
                throw ValidationException::withMessages(['paper_decision_date' => __('errors.advance_paper_date_before_request')]);
            }
            $this->days->decideRequest($request, $approve);
            $status = $approve ? 'approved' : 'refused';
            $request->update(['status' => $status, 'version' => $version + 1, 'decided_by' => $by, 'decided_at' => now(), 'reason' => $reason, 'paper_decision_date' => $paperDate]);
            $this->notify($request, (string) $request->requested_by, 'attendance_request_decided');

            return $status;
        });
    }

    private function notify(AttendanceRequest $request, string $to, string $type): void
    {
        $this->notifier->notifyOnce($to, $type, (string) $request->date, [
            'request_id' => $request->id, 'kind' => $request->type, 'status' => $request->status, 'site_id' => $request->site_id,
        ], (string) Uuid::uuid5((string) $request->id, (string) $request->version));
    }
}
