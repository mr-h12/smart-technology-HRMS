<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceRequest;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceSignoff;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Application\SiteService;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Ramsey\Uuid\Uuid;

final class Signoffs
{
    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly SiteService $sites,
        private readonly DayCrew $crew,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
    ) {}

    /** @param list<string> $ids
     * @return array<string, list<AttendanceSignoff>>
     */
    public function overlapping(array $ids, string $start, string $end, ?EmployeeType $type = null): array
    {
        return AttendanceSignoff::whereIn('employee_id', $ids)
            ->where('period_start', '<=', $end)->where('period_end', '>=', $start)
            ->when($type !== null, fn ($q) => $q->where('employee_type', $type?->value))
            ->orderBy('period_start')->get()->groupBy('employee_id')->map(fn ($rows) => array_values($rows->all()))->all();
    }

    /** @return array<string, mixed> */
    public static function summary(AttendanceSignoff $row): array
    {
        return [
            ...$row->only(['id', 'employee_type', 'period_start', 'period_end', 'version', 'return_note']),
            'status' => $row->returned_at === null ? 'signed' : 'returned',
        ];
    }

    /** @param list<AttendanceSignoff> $records */
    public function managerState(array $records, EmployeeType $type, string $manager): ?string
    {
        foreach ($records as $row) {
            if ($row->employee_type === $type->value) {
                return $row->returned_at === null ? 'signed' : ($row->signed_by === $manager ? 'returned' : null);
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function forSite(string $site, string $which, string $manager, ?string $at = null): array
    {
        $managedSites = $this->sites->idsManagedBy($manager);
        $this->assertSite($site, $managedSites);
        $today = EffectiveDays::today();
        $rows = [];
        foreach ([EmployeeType::Engineer, EmployeeType::PermanentWorker, EmployeeType::DayLaborer] as $type) {
            $period = Period::containing($type, $at ?? $today);
            if ($at === null && $which === 'previous') {
                $period = $period->previous();
            }
            $asOf = min($period->end, $today);
            $employees = array_values(array_filter($this->atSite($site, $asOf, $type, $manager), fn (array $e) => Employment::typeOn($e, $asOf) === $type));
            $ids = array_column($employees, 'id');
            $signoffs = $this->overlapping($ids, $period->start, $period->end, $type);
            $days = $this->days->forPeriod($period, $employees);
            $pending = AttendanceRequest::whereIn('employee_id', $ids)->where('status', 'pending')
                ->whereIn('site_id', $managedSites)->whereBetween('date', [$period->start, $asOf])
                ->get(['employee_id', 'date', 'site_id'])->groupBy('employee_id');
            $locked = PeriodGuard::isLocked($period);
            foreach ($employees as $employee) {
                $id = $employee['id'];
                $signoff = $signoffs[$id][0] ?? null;
                $editableReturn = $signoff !== null && $signoff->returned_at !== null && $signoff->signed_by === $manager;
                $correctionDays = [];
                $marked = $missing = $open = 0;
                $reachableDates = [];
                foreach ($days[$id] as $day) {
                    $on = $day['date'];
                    $reachesEmployee = $type === EmployeeType::Engineer
                        ? (EffectiveDated::at($employee['managers'], $on)['manager_id'] ?? null) === $manager
                        : Employment::homeSiteOn($employee, $on) === $site;
                    if ($on > $asOf || $day['type'] !== $type->value || ! $reachesEmployee
                        || ($day['leave_code'] === null && $day['site_id'] !== null && ! in_array($day['site_id'], $managedSites, true))) {
                        continue;
                    }
                    $reachableDates[$on] = true;
                    $isOpen = $day['state'] === 'stored' && $day['leave_code'] === null && $day['in_time'] !== null && $day['out_time'] === null;
                    $marked += $day['state'] === 'stored' ? 1 : 0;
                    $missing += $day['state'] === 'missing' ? 1 : 0;
                    $open += $isOpen ? 1 : 0;
                    if ($editableReturn && in_array($day['state'], ['missing', 'stored'], true) && ($day['state'] === 'missing' || $day['source'] === 'manager')) {
                        $target = $day['leave_code'] === null && $day['site_id'] !== null ? $day['site_id'] : (Employment::homeSiteOn($employee, $day['date']) ?? $site);
                        if (in_array($target, $managedSites, true)) {
                            $correctionDays[] = ['date' => $day['date'], 'site_id' => $target];
                        }
                    }
                }
                $rows[] = [
                    'employee' => [...array_intersect_key($employee, array_flip(['id', 'code', 'name_ar', 'name_en'])), 'type' => $type->value],
                    'period' => ['start' => $period->start, 'end' => $period->end],
                    'marked' => $marked, 'missing' => $missing, 'open' => $open, 'pending' => ($pending[$id] ?? collect())->filter(fn (AttendanceRequest $r) => isset($reachableDates[$r->date]))->count(),
                    'signoff' => $signoff === null ? null : [...self::summary($signoff), 'return_note' => $signoff->signed_by === $manager ? $signoff->return_note : null],
                    'can_sign' => ! $locked && $today >= $period->end && ($signoff === null || $editableReturn),
                    'locked' => $locked,
                    'correction_days' => $locked ? [] : $correctionDays,
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function sign(string $site, string $employee, EmployeeType $type, string $start, ?int $version, string $manager): array
    {
        $this->assertSite($site, $this->sites->idsManagedBy($manager));
        $period = Period::containing($type, $start);
        if ($period->start !== $start || $period->end > EffectiveDays::today()) {
            throw AttendanceRefused::because(AttendanceRefusal::SignoffNotDue);
        }

        return $this->crew->locked([$employee], function () use ($site, $employee, $period, $version, $manager): array {
            $employees = array_column($this->atSite($site, $period->end, $period->group, $manager), null, 'id');
            if (! isset($employees[$employee]) || Employment::typeOn($employees[$employee], $period->end) !== $period->group) {
                throw new ModelNotFoundException;
            }
            PeriodGuard::assertOpen($period);
            $key = ['employee_id' => $employee, 'employee_type' => $period->group->value, 'period_start' => $period->start];
            $row = AttendanceSignoff::firstOrNew($key);
            if (($row->exists ? $row->version : null) !== $version || ($row->exists && ($row->returned_at === null || $row->signed_by !== $manager))) {
                throw new ConcurrencyConflict;
            }
            $row->fill([
                'site_id' => $site, 'period_end' => $period->end, 'signed_by' => $manager, 'signed_at' => now(),
                'returned_by' => null, 'returned_at' => null, 'return_note' => null, 'version' => ($version ?? 0) + 1,
            ])->save();
            foreach ($this->holders->userIds('attendance.edit.all') as $hr) {
                $this->notify($row, $hr, 'attendance_period_signed');
            }

            return self::summary($row);
        });
    }

    /** @return array<string, mixed> */
    public function returnToManager(string $id, int $version, string $note, string $hr): array
    {
        $employee = (string) AttendanceSignoff::findOrFail($id)->employee_id;

        return $this->crew->locked([$employee], function () use ($id, $version, $note, $hr): array {
            $row = AttendanceSignoff::findOrFail($id);
            if ($row->version !== $version || $row->returned_at !== null) {
                throw new ConcurrencyConflict;
            }
            PeriodGuard::assertOpen(Period::startingAt(EmployeeType::from($row->employee_type), $row->period_start));
            $row->update(['returned_by' => $hr, 'returned_at' => now(), 'return_note' => $note, 'version' => $version + 1]);
            $this->notify($row, (string) $row->signed_by, 'attendance_period_returned');

            return self::summary($row);
        });
    }

    private function notify(AttendanceSignoff $row, string $user, string $kind): void
    {
        $this->notifier->notifyOnce($user, $kind, EffectiveDays::today(), $row->only([
            'employee_id', 'employee_type', 'period_start', 'period_end', 'site_id',
        ]), (string) Uuid::uuid5((string) $row->id, (string) $row->version));
    }

    /** @return list<array<string, mixed>> */
    private function atSite(string $site, string $date, EmployeeType $type, string $manager): array
    {
        return $type === EmployeeType::Engineer ? $this->roster->engineersOf($manager, $date, withManagerHistory: true) : $this->roster->onSite($site, $date, $manager);
    }

    /** @param list<string> $sites */
    private function assertSite(string $site, array $sites): void
    {
        if (! in_array($site, $sites, true)) {
            throw new ModelNotFoundException;
        }
    }
}
