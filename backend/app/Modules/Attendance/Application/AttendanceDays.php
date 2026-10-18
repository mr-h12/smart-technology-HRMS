<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes one attendance day (HR entry or import) with optimistic locking; every write is audited by the model. */
final class AttendanceDays
{
    /** Format only; DayRules does the rest. Shared by the controller and the import. */
    public const FORMAT = [
        'version' => ['nullable', 'integer', 'min:1'],
        'site_id' => ['nullable', 'uuid', 'exists:sites,id,deleted_at,NULL'],
        'in_time' => ['nullable', 'date_format:H:i'],
        'out_time' => ['nullable', 'date_format:H:i'],
        'scheduled_in' => ['nullable', 'date_format:H:i'],
        'scheduled_out' => ['nullable', 'date_format:H:i'],
        'late_minutes' => ['nullable', 'integer'],
        'ot_minutes' => ['nullable', 'integer'],
        'outside_minutes' => ['nullable', 'integer'],
        'outside_amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'],
        'advance_amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'],
        'transport' => ['nullable', 'in:full,half,none'],
        'note' => ['nullable', 'string', 'max:500'],
    ];

    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days) {}

    /**
     * PUT semantics: a value absent from $input is stored as null.
     *
     * @param  array<string, mixed>  $input  validated against FORMAT
     * @return array<string, mixed> the day after the write
     */
    public function save(string $employeeId, string $date, array $input, string $source = 'entered'): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        try {
            DB::transaction(function () use ($employee, $employeeId, $date, $input, $source): void {
                [$type, $row] = $this->guard($employee, $date);
                $live = $row !== null && ! $row->trashed() ? $row : null;
                $this->assertVersion($live, $input['version'] ?? null);

                $siteId = $input['site_id'] ?? null;
                $site = $siteId === null ? null : Site::find((string) $siteId);
                $isLeave = $site?->kind === 'leave';
                $values = array_map(fn (string $f) => $input[$f] ?? null, array_combine(EffectiveDays::VALUES, EffectiveDays::VALUES));
                if ($type === EmployeeType::PermanentWorker && ! $isLeave) {
                    $defaults = $this->days->workerDefaults($employee, $date);
                    foreach ($defaults as $field => $default) {
                        $values[$field] ??= $default;
                    }
                }

                $this->assertRules($type, $site, $live, $values);

                if ($isLeave && $type === EmployeeType::PermanentWorker) {
                    $values['transport'] = 'none';
                }
                foreach (['in_time', 'out_time', 'scheduled_in', 'scheduled_out'] as $t) {
                    // Postgres returns time as HH:MM:SS; matching it keeps an unchanged time out of the audit diff.
                    $values[$t] = $values[$t] === null ? null : $values[$t].':00';
                }
                foreach (['late_minutes', 'ot_minutes', 'outside_minutes'] as $m) {
                    $values[$m] = $values[$m] === null ? null : (int) $values[$m];
                }

                $row ??= new AttendanceDay(['employee_id' => $employeeId, 'date' => $date, 'version' => 0]);
                $row->fill([...$values, 'site_id' => $siteId, 'source' => $source, 'deleted_at' => null, 'version' => $row->version + 1]);
                $row->save();
            });
        } catch (UniqueConstraintViolationException) {
            // A racing insert of the same employee and date won.
            throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict);
        }

        return $this->days->effectiveDay($employeeId, $date);
    }

    /** @return array<string, mixed> the day after the clear, now derived or missing */
    public function clear(string $employeeId, string $date, int $version): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        DB::transaction(function () use ($employee, $date, $version): void {
            [, $row] = $this->guard($employee, $date);
            $live = $row !== null && ! $row->trashed() ? $row : null;
            $this->assertVersion($live, $version);
            /** @var AttendanceDay $live */
            $live->version = $version + 1;
            $live->save();
            $live->delete();
        });

        return $this->days->effectiveDay($employeeId, $date);
    }

    /**
     * Future date, employment and open period, then the row (soft-deleted ones too) locked for update.
     *
     * @param  array<string, mixed>  $employee
     * @return array{EmployeeType, ?AttendanceDay}
     */
    private function guard(array $employee, string $date): array
    {
        if ($date > EffectiveDays::today()) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        $type = Employment::typeOn($employee, $date) ?? throw AttendanceRefused::because(AttendanceRefusal::EmployeeNotActive);
        PeriodGuard::assertOpen(Period::containing($type, $date));
        $row = AttendanceDay::withTrashed()->where(['employee_id' => $employee['id'], 'date' => $date])->lockForUpdate()->first();

        return [$type, $row];
    }

    private function assertVersion(?AttendanceDay $live, mixed $version): void
    {
        $version = $version === null ? null : (int) $version;
        if ($live === null ? $version !== null : $live->version !== $version) {
            throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function assertRules(EmployeeType $type, ?Site $site, ?AttendanceDay $live, array $values): void
    {
        $siteRule = $site === null ? null : [
            'kind' => (string) $site->kind,
            'outside_alexandria' => (bool) $site->outside_alexandria,
            // A stored site that is unchanged stays acceptable after it is deactivated.
            'active' => $site->active || $live?->site_id === $site->id,
        ];
        // FIELDS order first, then the fields the type does not take, so the error order is stable.
        $order = array_unique([...DayRules::FIELDS[$type->value], ...EffectiveDays::VALUES]);
        $fields = [];
        foreach ($order as $f) {
            if ($values[$f] !== null) {
                $fields[$f] = $values[$f];
            }
        }

        $messages = [];
        foreach (DayRules::violations($type, $siteRule, $fields) as $v) {
            $messages[$v['field']][] = __('attendance.rule.'.$v['code']);
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
