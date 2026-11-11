<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Domain\LeaveBalance;
use App\Modules\Leave\Domain\LeaveKind;
use App\Modules\Leave\Domain\LeaveRefusal;
use App\Modules\Leave\Domain\LeaveRefused;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Modules\Leave\Infrastructure\Eloquent\LeaveRequest;
use App\Modules\Leave\Infrastructure\LeaveRequestLock;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\Paginates;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** The leave request workflow (leave spec, Workflow). Preview and submit run the same checks, so the dialog shows a refusal before submit. */
final class LeaveRequests
{
    use Paginates;

    /** Calendar days from from_date to to_date inclusive (plan, Global Constraints: a code limit, not a setting). */
    public const MAX_RANGE_DAYS = 60;

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly LeaveDays $days,
        private readonly LeaveBalances $balances,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
        private readonly AttendanceDays $attendance,
        private readonly AuthorizeAction $authorize,
    ) {}

    /**
     * The counted dates and what they do to the year's balance. $ownerId (own scope) must reach the employee today and on every counted date.
     *
     * @return array{dates: list<string>, days: int, balance: ?array<string, ?int>, keep: int, unpaid: int, remaining_after: ?int}
     */
    public function preview(string $employeeId, LeaveKind $kind, string $from, string $to, ?string $ownerId): array
    {
        $dates = $this->check($employeeId, $from, $to, $ownerId);
        $balance = $this->balances->forEmployee($employeeId, (int) substr($from, 0, 4), $dates)['balance'];

        return ['dates' => $dates, 'days' => count($dates), 'balance' => $balance, ...LeaveBalance::split($kind, count($dates), $balance)];
    }

    /**
     * Files a pending request. A manager's (own-scope) request notifies each holder of leave.decide.all at once; HR's notifies nobody.
     *
     * @param  array{employee_id: string, kind: string, from_date: string, to_date: string, note?: ?string}  $input  validated
     * @return array<string, mixed> the list row
     */
    public function submit(array $input, string $by, ?string $ownerId): array
    {
        $request = DB::transaction(function () use ($input, $by, $ownerId): LeaveRequest {
            LeaveRequestLock::forEmployee($input['employee_id']);
            $dates = $this->check($input['employee_id'], $input['from_date'], $input['to_date'], $ownerId);
            $request = LeaveRequest::create([
                'employee_id' => $input['employee_id'], 'kind' => $input['kind'], 'from_date' => $input['from_date'], 'to_date' => $input['to_date'],
                'days' => count($dates), 'note' => $input['note'] ?? null, 'status' => LeaveStatus::Pending->value, 'requested_by' => $by, 'version' => 1,
            ]);
            if ($ownerId !== null) {
                $data = ['employee_id' => $input['employee_id'], 'request_id' => $request->id, 'kind' => $input['kind'], 'from_date' => $input['from_date'], 'to_date' => $input['to_date'], 'days' => count($dates)];
                foreach ($this->holders->userIds('leave.decide.all') as $id) {
                    $this->notifier->notifyOnce($id, 'leave_requested', now('Africa/Cairo')->toDateString(), $data, (string) $request->id);
                }
            }

            return $request;
        });

        return $this->named([$request->toArray()])[0];
    }

    /**
     * Pending and escalated first, then newest. $managerId narrows to the employees in their reach today.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(?string $status, ?string $employeeId, ?int $year, int $perPage, int $page, ?string $managerId): array
    {
        $query = LeaveRequest::query()
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            // A request never crosses Dec 31, so its from_date names its year.
            ->when($year !== null, fn (Builder $q) => $q->whereBetween('from_date', ["{$year}-01-01", "{$year}-12-31"]))
            ->when($managerId !== null, fn (Builder $q) => $q->whereIn('employee_id', EmployeeReach::ids((string) $managerId, now('Africa/Cairo')->toDateString())))
            ->orderByRaw("status in ('pending', 'escalated') desc")->orderByDesc('created_at')->orderByDesc('id');
        $result = $this->page($query, $perPage, $page);

        return [...$result, 'data' => $this->named($result['data'])];
    }

    /**
     * The list row plus the decision and, while pending or escalated, the approval preview. Own scope: out of reach today is a 404.
     *
     * @return array<string, mixed>
     */
    public function show(string $id, ?string $ownerId): array
    {
        $request = LeaveRequest::findOrFail($id);
        if ($ownerId !== null && ! EmployeeReach::reaches((string) $request->employee_id, $ownerId, now('Africa/Cairo')->toDateString())) {
            throw new ModelNotFoundException;
        }

        return $this->detail($request);
    }

    /**
     * Recounts the dates (a holiday added since submit drops out), refuses when one is blocked, then writes each date as the request's kind,
     * the dates past the balance as unpaid (L2), all in one transaction. A date holding another leave type is replaced only when it is in
     * $replaceDates, the dates HR saw in the preview (A3).
     *
     * @param  list<string>  $replaceDates
     * @return array<string, mixed> the show shape
     */
    public function approve(string $id, int $version, Authenticatable $by, array $replaceDates = []): array
    {
        return $this->detail(DB::transaction(function () use ($id, $version, $by, $replaceDates): LeaveRequest {
            $request = $this->forDecision($id, $version, $by, false);
            $approval = $this->approval($request);
            if ($approval['dates'] === []) {
                throw new LeaveRefused(LeaveRefusal::NoDays);
            }
            if ($approval['blocked'] !== []) {
                throw new LeaveRefused(LeaveRefusal::DaysBlocked, ['count' => (string) count($approval['blocked'])], $approval['blocked']);
            }
            $unseen = array_values(array_diff(array_column($approval['replaced'], 'date'), $replaceDates));
            if ($unseen !== []) {
                throw new LeaveRefused(LeaveRefusal::DaysReplaced, ['count' => (string) count($unseen)], $unseen);
            }
            $kind = (string) $request->kind;
            $sites = Site::where(['kind' => SiteKind::Leave->value, 'active' => true])->whereIn('leave_code', [$kind, LeaveKind::Unpaid->value])->pluck('id', 'leave_code');
            foreach ($approval['dates'] as $i => $date) {
                $code = $i < $approval['keep'] ? $kind : LeaveKind::Unpaid->value;
                $site = $sites[$code] ?? throw AttendanceRefused::because(AttendanceRefusal::LeaveTypeMissing, ['code' => $code]);
                $this->attendance->writeLeaveDay((string) $request->employee_id, $date, (string) $site, in_array($date, $replaceDates, true));
            }

            return $this->conclude($request, LeaveStatus::Approved, $by, ['days' => $approval['days']]);
        }));
    }

    /** @return array<string, mixed> the show shape */
    public function refuse(string $id, int $version, string $reason, Authenticatable $by): array
    {
        return $this->detail(DB::transaction(fn () => $this->conclude($this->forDecision($id, $version, $by, false), LeaveStatus::Refused, $by, ['decision_reason' => $reason])));
    }

    /**
     * Hands a pending request to the CEO; HR's optional note is kept in decision_reason for the CEO to read.
     *
     * @return array<string, mixed> the show shape
     */
    public function escalate(string $id, int $version, ?string $reason, Authenticatable $by): array
    {
        return $this->detail(DB::transaction(function () use ($id, $version, $reason, $by): LeaveRequest {
            $request = $this->forDecision($id, $version, $by, true);
            $request->fill(['status' => LeaveStatus::Escalated->value, 'decision_reason' => $reason, 'version' => $request->version + 1])->save();
            foreach (array_diff($this->holders->userIds('leave.decide_escalated.all'), [(string) $by->getAuthIdentifier()]) as $userId) {
                $this->notifier->notifyOnce($userId, 'leave_escalated', now('Africa/Cairo')->toDateString(), $this->notice($request), (string) $request->id);
            }

            return $request;
        }));
    }

    /**
     * Withdraws a request. Pending or escalated: its requester or a holder of leave.request.all; approved: a holder of leave.decide.all, and
     * every day it wrote that still holds its kind's or the unpaid leave type with source `leave` is cleared. A locked period, or a day to clear
     * that holds a note or an advance (all such dates listed), refuses the whole cancel. Own scope: out of reach today is a 404, as show. A stale
     * version is a conflict before the permission check: the caller can read the request, and its change since is the news.
     *
     * @return array<string, mixed> the show shape
     */
    public function cancel(string $id, int $version, ?string $reason, Authenticatable $by, ?string $ownerId): array
    {
        $read = LeaveRequest::whereKey($id)->first(['employee_id', 'status', 'requested_by', 'version']) ?? throw new ModelNotFoundException;
        if ($ownerId !== null && ! EmployeeReach::reaches((string) $read->employee_id, $ownerId, now('Africa/Cairo')->toDateString())) {
            throw new ModelNotFoundException;
        }
        if ($read->version !== $version) {
            throw new ConcurrencyConflict;
        }
        $status = LeaveStatus::from((string) $read->status);
        $byId = (string) $by->getAuthIdentifier();
        $allowed = match ($status) {
            LeaveStatus::Pending, LeaveStatus::Escalated => $read->requested_by === $byId || $this->authorize->decide($by, 'leave.request')->allows(Scope::All),
            LeaveStatus::Approved => $this->authorize->decide($by, 'leave.decide')->allows(Scope::All),
            LeaveStatus::Refused, LeaveStatus::Cancelled => throw new ConcurrencyConflict,
        };
        if (! $allowed) {
            throw AuthorizationRefused::of($status === LeaveStatus::Approved ? 'leave.decide' : 'leave.request');
        }

        $employeeId = (string) $read->employee_id;

        return $this->detail(DB::transaction(function () use ($id, $version, $reason, $byId, $status, $employeeId): LeaveRequest {
            LeaveRequestLock::forEmployee($employeeId);
            $request = LeaveRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($request->status !== $status->value || $request->version !== $version) {
                throw new ConcurrencyConflict;
            }
            if ($status === LeaveStatus::Approved) {
                // Approval wrote the kind's or the unpaid leave type and did not store which; a type deleted or deactivated since still counts.
                $types = array_values(Site::withTrashed()->where('kind', SiteKind::Leave->value)->whereIn('leave_code', [(string) $request->kind, LeaveKind::Unpaid->value])
                    ->pluck('id')->map(fn ($id) => (string) $id)->all());
                $blocked = $this->attendance->clearLeaveDays($employeeId, (string) $request->from_date, (string) $request->to_date, $types);
                if ($blocked !== []) {
                    throw new LeaveRefused(LeaveRefusal::CancelBlocked, ['count' => (string) count($blocked)], $blocked);
                }
            }
            $decider = $request->decided_by;
            $request->fill(['status' => LeaveStatus::Cancelled->value, 'decided_by' => $byId, 'decided_at' => now(), 'decision_reason' => $reason, 'version' => $request->version + 1])->save();
            $recipients = [(string) $request->requested_by, ...($decider === null ? [] : [(string) $decider]), ...$this->holders->userIds('leave.decide.all'), ...($status === LeaveStatus::Escalated ? $this->holders->userIds('leave.decide_escalated.all') : [])];
            foreach (array_diff(array_unique($recipients), [$byId]) as $userId) {
                $this->notifier->notifyOnce($userId, 'leave_cancelled', now('Africa/Cairo')->toDateString(), $this->notice($request), (string) $request->id);
            }

            return $request;
        }));
    }

    /** Deleting an employee with a pending or escalated request is refused (D4, 2026-11-04); call inside the delete's transaction. */
    public function assertNoOpenRequests(string $employeeId): void
    {
        $open = count($this->openRequests($employeeId));
        if ($open > 0) {
            throw new LeaveRefused(LeaveRefusal::EmployeeHasOpenRequests, ['count' => (string) $open]);
        }
    }

    /**
     * The employee's pending and escalated requests by from_date, under the lock submit takes: call inside the caller's transaction, so a
     * request filed at the same moment waits for it (a termination date's open items, owner 2026-11-11).
     *
     * @return list<array{kind: string, id: string, status: string, from_date: string, to_date: string}>
     */
    public function openRequests(string $employeeId): array
    {
        LeaveRequestLock::forEmployee($employeeId);

        return array_values(LeaveRequest::where('employee_id', $employeeId)->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Escalated->value])
            ->orderBy('from_date')->orderBy('id')->get()
            ->map(fn (LeaveRequest $r) => ['kind' => 'leave_request', 'id' => (string) $r->id, 'status' => (string) $r->status, 'from_date' => (string) $r->from_date, 'to_date' => (string) $r->to_date])
            ->all());
    }

    /**
     * The request locked for a decision. Permission follows its status: pending (or escalated, to escalate it) needs leave.decide, escalated
     * needs leave.decide_escalated, and a decided request needs either for every action, so only a decider learns it is a conflict. Both are `.all` only.
     * The permission is checked on the unlocked status, so a caller without it never waits on or takes the locks; a status changed
     * before the lock is a conflict.
     */
    private function forDecision(string $id, int $version, Authenticatable $by, bool $pendingOnly): LeaveRequest
    {
        $read = LeaveRequest::whereKey($id)->first(['employee_id', 'status']) ?? throw new ModelNotFoundException;
        $status = LeaveStatus::from((string) $read->status);
        $abilities = match (true) {
            $status === LeaveStatus::Pending, $pendingOnly && $status === LeaveStatus::Escalated => ['leave.decide'],
            $status === LeaveStatus::Escalated => ['leave.decide_escalated'],
            default => ['leave.decide', 'leave.decide_escalated'],
        };
        if (array_filter($abilities, fn (string $a) => $this->authorize->decide($by, $a)->allows(Scope::All)) === []) {
            throw AuthorizationRefused::of($abilities[0]);
        }
        LeaveRequestLock::forEmployee((string) $read->employee_id);
        $request = LeaveRequest::whereKey($id)->lockForUpdate()->firstOrFail();
        $open = $pendingOnly ? [LeaveStatus::Pending] : [LeaveStatus::Pending, LeaveStatus::Escalated];
        if ($request->status !== $status->value || ! in_array($status, $open, true) || $request->version !== $version) {
            throw new ConcurrencyConflict;
        }

        return $request;
    }

    /**
     * Approve or refuse: stores the decision and tells the requester, unless they decided it themselves.
     *
     * @param  array<string, mixed>  $values
     */
    private function conclude(LeaveRequest $request, LeaveStatus $status, Authenticatable $by, array $values): LeaveRequest
    {
        $byId = (string) $by->getAuthIdentifier();
        $request->fill([...$values, 'status' => $status->value, 'decided_by' => $byId, 'decided_at' => now(), 'version' => $request->version + 1])->save();
        if ($request->requested_by !== $byId) {
            $this->notifier->notifyOnce((string) $request->requested_by, 'leave_decided', now('Africa/Cairo')->toDateString(), [...$this->notice($request), 'status' => $status->value], (string) $request->id);
        }

        return $request;
    }

    /** @return array{employee_id: string, request_id: string, kind: string, from_date: string, to_date: string, days: int} ids, codes and dates only */
    private function notice(LeaveRequest $request): array
    {
        return [
            'employee_id' => (string) $request->employee_id, 'request_id' => (string) $request->id, 'kind' => (string) $request->kind,
            'from_date' => (string) $request->from_date, 'to_date' => (string) $request->to_date, 'days' => $request->days,
        ];
    }

    /**
     * What approving now would do, recounted: the dates, the balance split, the dates that block it, and the dates whose stored leave code
     * differs from the one approval would write (the request's kind, or unpaid past the balance).
     *
     * @return array{dates: list<string>, days: int, balance: ?array<string, ?int>, keep: int, unpaid: int, remaining_after: ?int, blocked: list<string>, replaced: list<array{date: string, leave_code: string}>}
     */
    private function approval(LeaveRequest $request): array
    {
        $employeeId = (string) $request->employee_id;
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $from = (string) $request->from_date;
        $dates = $this->days->dates($employee, $from, (string) $request->to_date);
        // The request's own dates are left out: approval overwrites whatever leave day they hold.
        // ponytail: nothing serializes this read against a grid, sheet or import write of annual/casual days (only the employee's leave
        // request lock is held), so a racing write can overbook by its days; take an employee-wide attendance lock if HR reports one.
        $balance = $this->balances->forEmployee($employeeId, (int) substr($from, 0, 4), $dates)['balance'];
        $split = LeaveBalance::split(LeaveKind::from((string) $request->kind), count($dates), $balance);
        $codes = $this->attendance->leaveCodesOn($employeeId, $dates);
        $replaced = [];
        foreach ($dates as $i => $date) {
            $target = $i < $split['keep'] ? (string) $request->kind : LeaveKind::Unpaid->value;
            if (isset($codes[$date]) && $codes[$date] !== $target) {
                $replaced[] = ['date' => $date, 'leave_code' => $codes[$date]];
            }
        }

        return [
            'dates' => $dates, 'days' => count($dates), 'balance' => $balance, ...$split,
            'blocked' => $this->attendance->leaveBlockedDates($employeeId, $dates), 'replaced' => $replaced,
        ];
    }

    /** @return array<string, mixed> the list row, the decision, and the approval preview while undecided */
    private function detail(LeaveRequest $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $request->toArray();
        $undecided = in_array($row['status'], [LeaveStatus::Pending->value, LeaveStatus::Escalated->value], true);

        return [
            ...$this->named([$row])[0],
            'decision_reason' => $row['decision_reason'],
            'decided_by' => $row['decided_by'] === null ? null : ['id' => $row['decided_by'], 'name' => User::withTrashed()->whereKey($row['decided_by'])->value('name')],
            'decided_at' => $row['decided_at'],
            'approval' => $undecided ? $this->approval($request) : null,
        ];
    }

    /**
     * The request's counted dates, or a refusal. Input first, then an unknown id is a 404 (ids are UUIDs, so probing is impractical), then reach,
     * so the later refusals never describe an employee out of reach. Own scope also needs reach today: the balance and the request list show a
     * manager only the employees in reach today.
     *
     * @return non-empty-list<string>
     */
    private function check(string $employeeId, string $from, string $to, ?string $ownerId): array
    {
        if (substr($from, 0, 4) !== substr($to, 0, 4)) {
            throw new LeaveRefused(LeaveRefusal::CrossesYear);
        }
        if ((int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1 > self::MAX_RANGE_DAYS) {
            throw new LeaveRefused(LeaveRefusal::TooLong, ['max' => (string) self::MAX_RANGE_DAYS]);
        }
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $dates = $this->days->dates($employee, $from, $to);
        if ($ownerId !== null) {
            // With no counted date, reach on from_date decides.
            // ponytail: one reach query per date (at most 61); a dated reach range if this shows up in a profile.
            foreach ([now('Africa/Cairo')->toDateString(), ...($dates === [] ? [$from] : $dates)] as $date) {
                if (! EmployeeReach::reaches($employeeId, $ownerId, $date)) {
                    throw new LeaveRefused(LeaveRefusal::NotInReach);
                }
            }
        }
        if (EmployeeRoster::typeInYear($employee, (int) substr($from, 0, 4)) === EmployeeType::DayLaborer->value) {
            throw new LeaveRefused(LeaveRefusal::DayLaborer);
        }
        if ($dates === []) {
            throw new LeaveRefused(LeaveRefusal::NoDays);
        }
        $overlaps = LeaveRequest::where('employee_id', $employeeId)->whereIn('status', array_map(fn (LeaveStatus $s) => $s->value, LeaveStatus::LIVE))
            ->where('from_date', '<=', $to)->where('to_date', '>=', $from)->exists();
        if ($overlaps) {
            throw new LeaveRefused(LeaveRefusal::Overlap);
        }

        return $dates;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  LeaveRequest attributes
     * @return list<array<string, mixed>> in the API row shape, employee and requester named
     */
    private function named(array $rows): array
    {
        $employees = $this->roster->names(array_values(array_unique(array_column($rows, 'employee_id'))));
        $users = User::withTrashed()->whereIn('id', array_unique(array_column($rows, 'requested_by')))->pluck('name', 'id');

        return array_map(fn (array $r) => [
            'id' => $r['id'], 'employee' => $employees[$r['employee_id']] ?? null, 'kind' => $r['kind'], 'from_date' => $r['from_date'], 'to_date' => $r['to_date'],
            'days' => $r['days'], 'status' => $r['status'], 'note' => $r['note'] ?? null,
            'requested_by' => ['id' => $r['requested_by'], 'name' => $users[$r['requested_by']] ?? null], 'created_at' => $r['created_at'], 'version' => $r['version'],
        ], $rows);
    }
}
