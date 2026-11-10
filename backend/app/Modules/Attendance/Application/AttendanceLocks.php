<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceAuditEvents;
use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** HR's lock and reopen of one group's attendance period. Locking stores every derived day, so a later setting or holiday change cannot rewrite it. */
final class AttendanceLocks
{
    public function __construct(private readonly AttendanceGrid $grid, private readonly EffectiveDays $days, private readonly EmployeeRoster $roster) {}

    /**
     * Also lists, read before the derived days are stored: on a re-lock, the dates no longer official holidays whose stored days a holiday
     * delete or move removed, and the holidays added or changed since the last lock; on any lock, the stored leave days on a holiday (unchanged).
     *
     * @return array<string, mixed> the period header after the lock, plus holidays_removed, holidays_added and leave_on_holidays
     */
    public function lock(EmployeeType $g, string $start, bool $confirmMissing): array
    {
        $p = $this->grid->periodAt($g, $start);
        $changes = DB::transaction(function () use ($p, $confirmMissing): array {
            PeriodGuard::exclusive($p);
            if (PeriodGuard::isLocked($p)) {
                throw AttendanceRefused::because(AttendanceRefusal::PeriodAlreadyLocked);
            }

            $employees = $this->roster->allOfType($p->group, $p->start, $p->end);
            $holidayDates = OfficialHoliday::datesBetween($p->start, $p->end);
            $lock = AttendanceLock::firstOrNew(['employee_type' => $p->group->value, 'period_start' => $p->start]);
            $since = $lock->exists ? $lock->locked_at : null;
            $removed = $since === null ? [] : AttendanceDay::onlyTrashed()->whereIn('employee_id', array_column($employees, 'id'))->whereBetween('date', [$p->start, $p->end])
                ->where('source', 'derived')->where('deleted_at', '>', $since)->whereNotIn('date', $holidayDates)
                ->whereIn('site_id', Site::withTrashed()->where(['kind' => SiteKind::Leave->value, 'leave_code' => LeaveCode::OfficialHoliday->value])->select('id'))
                ->distinct()->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
            $added = $since === null ? [] : OfficialHoliday::datesBetween($p->start, $p->end, $since);
            $names = array_column($employees, null, 'id');
            $leaveCodes = array_map(fn (LeaveCode $c) => $c->value, [LeaveCode::Annual, LeaveCode::Casual, LeaveCode::Sick, LeaveCode::Unpaid]);

            $derived = [];
            $onHolidays = [];
            $missing = 0;
            $open = 0;
            foreach ($this->days->forPeriod($p, $employees) as $days) {
                foreach ($days as $day) {
                    $missing += $day['state'] === 'missing' ? 1 : 0;
                    $open += $day['state'] === 'stored' && $day['in_time'] !== null && $day['out_time'] === null && $day['leave_code'] === null ? 1 : 0;
                    if ($day['state'] === 'derived') {
                        $derived[] = $day;
                    }
                    if ($day['state'] === 'stored' && in_array($day['date'], $holidayDates, true) && in_array($day['leave_code'], $leaveCodes, true)) {
                        $e = $names[$day['employee_id']];
                        $onHolidays[] = ['employee_id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'date' => $day['date'], 'leave_code' => $day['leave_code']];
                    }
                }
            }
            // An arrival with no departure must be completed first, for every field type (owner, 2026-11-04); no confirmation skips it.
            if ($open > 0 && $p->group !== EmployeeType::Office) {
                throw AttendanceRefused::because(AttendanceRefusal::OpenDays, ['count' => (string) $open]);
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

            $old = $lock->exists ? ['is_locked' => false] : null;
            $lock->fill(['period_end' => $p->end, 'locked_by' => Auth::id(), 'locked_at' => now(), 'reopened_by' => null, 'reopened_at' => null, 'reopen_reason' => null]);
            $lock->save();
            AuditLog::record(AttendanceAuditEvents::PERIOD_LOCKED, 'attendance_locks', (string) $lock->getKey(), $old, [
                'employee_type' => $p->group->value, 'period_start' => $p->start, 'is_locked' => true, 'materialized' => count($derived),
                'holidays_removed' => count($removed), 'holidays_added' => count($added),
            ]);

            return ['holidays_removed' => $removed, 'holidays_added' => $added, 'leave_on_holidays' => $onHolidays];
        });

        return [...$this->grid->period($g, $start), ...$changes];
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
