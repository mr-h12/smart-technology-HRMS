<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\OffDays;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What each day of an employee is: the stored row, else (open period only) the derived weekly off or holiday, else future or missing.
 * State order is stored → derived → future → missing, so a future Friday shows as derived. A locked period never derives.
 */
final class EffectiveDays
{
    /** The day's values, in response order. */
    public const VALUES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out', 'late_minutes', 'ot_minutes', 'outside_minutes', 'outside_amount', 'transport', 'advance_amount', 'note'];

    private const TIMES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out'];

    public function __construct(private readonly EmployeeRoster $roster, private readonly BusinessRules $rules) {}

    public static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }

    /**
     * @param  list<array<string, mixed>>  $employees  EmployeeRoster rows
     * @return array<string, array<string, array<string, mixed>>> employee id → date → day
     */
    public function forPeriod(Period $p, array $employees): array
    {
        $locked = PeriodGuard::isLocked($p);
        $stored = [];
        $rows = AttendanceDay::whereIn('employee_id', array_column($employees, 'id'))->whereBetween('date', [$p->start, $p->end])->get();
        foreach ($rows as $row) {
            $stored[$row->employee_id][$row->date] = $row;
        }
        $holidays = $locked ? [] : array_flip(OfficialHoliday::whereBetween('date', [$p->start, $p->end])->pluck('date')->all());
        // A stored day may point at a since-deactivated or deleted leave type; a derived day only at the active one.
        $leaveSites = Site::withTrashed()->where('kind', 'leave')->get(['id', 'leave_code', 'active', 'deleted_at']);
        $codeOf = $leaveSites->pluck('leave_code', 'id')->all();
        $activeSite = $leaveSites->filter(fn (Site $s) => $s->active && ! $s->trashed())->pluck('id', 'leave_code')->all();
        $settings = $this->rules->all();   // once per period: it is a cache round trip, and this loop runs per cell
        $weeklyOff = $this->weeklyOff($p->group, $settings);
        $today = self::today();

        $days = [];
        foreach ($employees as $e) {
            foreach ($p->days() as $date) {
                $type = Employment::typeOn($e, $date);
                $row = $stored[$e['id']][$date] ?? null;
                $day = ['employee_id' => $e['id'], 'date' => $date, 'type' => $type?->value, 'state' => null, 'source' => null, 'version' => null, 'site_id' => null, 'leave_code' => null, ...array_fill_keys(self::VALUES, null)];
                if ($type !== $p->group) {
                    $day['state'] = 'not_employed';
                } elseif ($row !== null) {
                    $day = [...$day, ...$this->storedValues($row), 'state' => 'stored', 'leave_code' => $codeOf[$row->site_id] ?? null];
                } else {
                    $code = $locked ? null : OffDays::leaveCodeFor($date, $weeklyOff, isset($holidays[$date]));
                    $day['state'] = $code !== null ? 'derived' : ($date > $today ? 'future' : 'missing');
                    if ($code !== null) {
                        // A leave day, as save() would store it: no scheduled times, and a permanent worker's transport is none.
                        $day = [...$day, 'leave_code' => $code, 'site_id' => $activeSite[$code] ?? null];
                        if ($type === EmployeeType::PermanentWorker) {
                            $day['transport'] = 'none';
                        }
                    } elseif ($type === EmployeeType::PermanentWorker) {
                        $day = [...$day, ...self::defaults($e, $date, $settings)];
                    }
                }
                $days[$e['id']][$date] = $day;
            }
        }

        return $days;
    }

    /** @return array<string, mixed> the day as the day endpoints return it */
    public function effectiveDay(string $employeeId, string $date): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $type = Employment::typeOn($employee, $date);
        if ($type === null) {
            return [
                'employee_id' => $employeeId, 'date' => $date, 'type' => null, 'state' => 'not_employed', 'source' => null, 'version' => null,
                'site_id' => null, 'leave_code' => null, ...array_fill_keys(self::VALUES, null),
                'fields' => [], 'leave_day_fields' => [], 'hints' => (object) [], 'period' => null,
            ];
        }
        $period = Period::containing($type, $date);
        $day = $this->forPeriod($period, [$employee])[$employeeId][$date];
        $fields = DayRules::FIELDS[$type->value];

        return [
            ...$day,
            'fields' => $fields,
            'leave_day_fields' => array_values(array_intersect($fields, DayRules::LEAVE_DAY_FIELDS)),
            'hints' => (object) $this->hints($type, $employee, $day, $this->rules->all()),
            'period' => ['start' => $period->start, 'end' => $period->end, 'is_locked' => PeriodGuard::isLocked($period)],
        ];
    }

    /**
     * A permanent worker's work day without its own values: the pay-row shift, else the settings, and full transport.
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, string>  $s  the settings, read once by the caller
     * @return array{scheduled_in: string, scheduled_out: string, transport: string}
     */
    public function workerDefaults(array $employee, string $date, array $s): array
    {
        return self::defaults($employee, $date, $s);
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<string, string>  $s  the settings
     * @return array{scheduled_in: string, scheduled_out: string, transport: string}
     */
    private static function defaults(array $employee, string $date, array $s): array
    {
        $shift = Employment::shiftOn($employee, $date);

        return ['scheduled_in' => $shift['start'] ?? $s['worker_shift_start'], 'scheduled_out' => $shift['end'] ?? $s['worker_shift_end'], 'transport' => 'full'];
    }

    /** @return array<string, mixed> */
    private function storedValues(AttendanceDay $row): array
    {
        $values = ['source' => $row->source, 'version' => $row->version, 'site_id' => $row->site_id];
        foreach (self::VALUES as $field) {
            $value = $row->getAttribute($field);
            $values[$field] = in_array($field, self::TIMES, true) && $value !== null ? substr($value, 0, 5) : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $day
     * @param  array<string, string>  $s  the settings, read once by the caller
     * @return array<string, int>
     */
    public function hints(EmployeeType $type, array $employee, array $day, array $s): array
    {
        if ($type === EmployeeType::Office && $day['in_time'] !== null) {
            $start = Employment::shiftOn($employee, $day['date'])['start'] ?? $s['office_shift_start'];

            return ['late_minutes' => DayRules::officeLateMinutes($day['in_time'], $start, (int) $s['office_grace_minutes'])];
        }
        if ($type === EmployeeType::PermanentWorker && $day['site_id'] !== null && $day['leave_code'] === null && $day['in_time'] !== null && $day['out_time'] !== null) {
            $scheduledIn = $day['scheduled_in'] ?? self::defaults($employee, $day['date'], $s)['scheduled_in'];
            /** @var numeric-string $dayHours */
            $dayHours = $s['day_hours'];
            $m = DayRules::workerMinutes($scheduledIn, $day['in_time'], $day['out_time'], $dayHours);

            return ['late_minutes' => $m['late'], 'ot_minutes' => $m['ot']];
        }

        return [];
    }

    /**
     * @param  array<string, string>  $s  the settings
     * @return list<string> lowercase weekday names
     */
    private function weeklyOff(EmployeeType $group, array $s): array
    {
        $setting = $s[$group === EmployeeType::Office ? 'weekly_off_office' : 'weekly_off_field'] ?? '';

        return array_values(array_filter(array_map(fn (string $d) => strtolower(trim($d)), explode(',', $setting))));
    }
}
