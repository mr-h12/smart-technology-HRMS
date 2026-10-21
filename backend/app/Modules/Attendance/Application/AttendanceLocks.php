<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceAuditEvents;
use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** HR's lock and reopen of one group's attendance period. Locking stores every derived day, so a later setting or holiday change cannot rewrite it. */
final class AttendanceLocks
{
    public function __construct(private readonly AttendanceGrid $grid, private readonly EffectiveDays $days, private readonly EmployeeRoster $roster) {}

    /** @return array<string, mixed> the period header after the lock */
    public function lock(EmployeeType $g, string $start, bool $confirmMissing): array
    {
        $p = $this->grid->periodAt($g, $start);
        DB::transaction(function () use ($p, $confirmMissing): void {
            PeriodGuard::exclusive($p);
            if (PeriodGuard::isLocked($p)) {
                throw AttendanceRefused::because(AttendanceRefusal::PeriodAlreadyLocked);
            }

            $derived = [];
            $missing = 0;
            foreach ($this->days->forPeriod($p, $this->roster->allOfType($p->group, $p->start, $p->end)) as $days) {
                foreach ($days as $day) {
                    $missing += $day['state'] === 'missing' ? 1 : 0;
                    if ($day['state'] === 'derived') {
                        $derived[] = $day;
                    }
                }
            }
            if ($missing > 0 && ! $confirmMissing) {
                throw AttendanceRefused::because(AttendanceRefusal::MissingDaysUnconfirmed);
            }
            foreach ($derived as $day) {
                if ($day['site_id'] === null) {
                    throw AttendanceRefused::because(AttendanceRefusal::LeaveTypeMissing, ['code' => (string) $day['leave_code']]);
                }
            }

            foreach ($derived as $day) {
                // A day cleared earlier is a soft-deleted row (the unique key is not partial): restore it rather than insert.
                $row = AttendanceDay::withTrashed()->firstOrNew(['employee_id' => $day['employee_id'], 'date' => $day['date']], ['version' => 0]);
                $values = array_intersect_key($day, array_flip(['site_id', ...EffectiveDays::VALUES]));
                $row->fill([...$values, 'source' => 'derived', 'deleted_at' => null, 'version' => $row->version + 1]);
                $row->save();
            }

            $lock = AttendanceLock::firstOrNew(['employee_type' => $p->group->value, 'period_start' => $p->start]);
            $old = $lock->exists ? ['is_locked' => false] : null;
            $lock->fill(['period_end' => $p->end, 'locked_by' => Auth::id(), 'locked_at' => now(), 'reopened_by' => null, 'reopened_at' => null, 'reopen_reason' => null]);
            $lock->save();
            AuditLog::record(AttendanceAuditEvents::PERIOD_LOCKED, 'attendance_locks', (string) $lock->getKey(), $old, [
                'employee_type' => $p->group->value, 'period_start' => $p->start, 'is_locked' => true, 'materialized' => count($derived),
            ]);
        });

        return $this->grid->period($g, $start);
    }

    /** @return array<string, mixed> the period header after the reopen; the materialized days stay stored */
    public function reopen(EmployeeType $g, string $start, string $reason): array
    {
        $p = $this->grid->periodAt($g, $start);
        DB::transaction(function () use ($p, $reason): void {
            PeriodGuard::exclusive($p);
            if (! PeriodGuard::isLocked($p)) {
                throw AttendanceRefused::because(AttendanceRefusal::PeriodNotLocked);
            }
            $lock = AttendanceLock::where(['employee_type' => $p->group->value, 'period_start' => $p->start])->sole();
            $lock->update(['reopened_by' => Auth::id(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
            AuditLog::record(AttendanceAuditEvents::PERIOD_REOPENED, 'attendance_locks', (string) $lock->getKey(), ['is_locked' => true], ['is_locked' => false, 'reason' => $reason]);
        });

        return $this->grid->period($g, $start);
    }
}
