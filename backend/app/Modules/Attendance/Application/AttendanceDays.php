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
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes attendance days (HR entry, the day sheet's bulk write, the Excel import) with optimistic locking; every write is audited by the model. */
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

    /** A technical cap (P30), not a business setting: the day sheet refuses bigger selections. */
    public const BULK_MAX_ROWS = 500;

    /** Many employees' days for one date; a row is a FORMAT body plus employee_id, or {employee_id, version, clear: true}. */
    public const BULK_FORMAT = [
        'date' => ['required', 'date_format:Y-m-d'],
        'rows' => ['required', 'list', 'min:1', 'max:'.self::BULK_MAX_ROWS],
        'rows.*.employee_id' => ['required', 'uuid', 'distinct'],
        'rows.*.clear' => ['sometimes', 'accepted'],
        'rows.*.version' => ['nullable', 'integer', 'min:1', 'required_with:rows.*.clear'],
        'rows.*.site_id' => ['present_unless:rows.*.clear,true,1,yes,on', ...self::FORMAT['site_id']],
        'rows.*.in_time' => self::FORMAT['in_time'],
        'rows.*.out_time' => self::FORMAT['out_time'],
        'rows.*.scheduled_in' => self::FORMAT['scheduled_in'],
        'rows.*.scheduled_out' => self::FORMAT['scheduled_out'],
        'rows.*.late_minutes' => self::FORMAT['late_minutes'],
        'rows.*.ot_minutes' => self::FORMAT['ot_minutes'],
        'rows.*.outside_minutes' => self::FORMAT['outside_minutes'],
        'rows.*.outside_amount' => self::FORMAT['outside_amount'],
        'rows.*.advance_amount' => self::FORMAT['advance_amount'],
        'rows.*.transport' => self::FORMAT['transport'],
        'rows.*.note' => self::FORMAT['note'],
    ];

    private const TIMES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out'];

    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days, private readonly BusinessRules $rules) {}

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
            DB::transaction(fn () => $this->write($employee, $date, $input, $source, false));
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
        DB::transaction(fn () => $this->clearRow($employee, $date, $version));

        return $this->days->effectiveDay($employeeId, $date);
    }

    /**
     * All or nothing (P27): every stale row is reported (409), then every rule violation (422, rows.N.field); any other refusal
     * stops at its row. An unsent value is kept by the P28 rule. Rows are locked in employee_id order so two requests cannot deadlock.
     *
     * @param  list<array<string, mixed>>  $rows  validated against BULK_FORMAT
     * @return list<array{employee_id: string, version: ?int}> in request order; version is null after a clear
     */
    public function bulk(string $date, array $rows): array
    {
        $employees = $this->roster->findMany(array_map(fn (array $r) => (string) $r['employee_id'], $rows));
        $order = array_keys($rows);
        usort($order, fn (int $a, int $b) => strcmp((string) $rows[$a]['employee_id'], (string) $rows[$b]['employee_id']));
        $settings = $this->rules->all();
        $versions = [];
        DB::transaction(function () use ($date, $rows, $order, $employees, $settings, &$versions): void {
            $conflicts = [];
            $errors = [];
            // ponytail: ~6 queries per row; preload sites and locks if 500-row requests get slow
            foreach ($order as $i) {
                $row = $rows[$i];
                $employee = $employees[(string) $row['employee_id']] ?? throw new ModelNotFoundException;
                try {
                    if (isset($row['clear'])) {
                        $this->clearRow($employee, $date, (int) $row['version']);
                        $versions[$i] = null;
                    } else {
                        $versions[$i] = $this->write($employee, $date, $row, 'entered', true, $settings);
                    }
                } catch (AttendanceRefused $e) {
                    $detail = ['field' => "rows.{$i}", 'code' => $e->reason->value, 'message' => __('attendance.refusal.'.$e->reason->value, $e->params)];
                    if ($e->reason !== AttendanceRefusal::ConcurrencyConflict) {
                        // A locked period or a future date refuses the whole request, so it names no row.
                        throw AttendanceRefused::because($e->reason, $e->params, $e->reason === AttendanceRefusal::EmployeeNotActive ? [$detail] : []);
                    }
                    $conflicts[$i] = $detail;
                } catch (UniqueConstraintViolationException) {
                    // A racing insert of this row's day won; the transaction is aborted, so stop at this row.
                    $reason = AttendanceRefusal::ConcurrencyConflict;
                    throw AttendanceRefused::because($reason, [], [['field' => "rows.{$i}", 'code' => $reason->value, 'message' => __('attendance.refusal.'.$reason->value)]]);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[$i]["rows.{$i}.{$field}"] = $messages;
                    }
                }
            }
            ksort($conflicts);
            ksort($errors);
            if ($conflicts !== []) {
                throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict, [], array_values($conflicts));
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(array_merge(...array_values($errors)));
            }
        });

        return array_map(fn (int $i) => ['employee_id' => (string) $rows[$i]['employee_id'], 'version' => $versions[$i]], array_keys($rows));
    }

    /**
     * One cell of the Excel import (P33): the bulk write's semantics (P28 keeps the day's unsent fields) with $source. Each call is its
     * own savepoint, so a refused cell never aborts the caller's transaction. $dryRun runs every check and saves nothing (the preview).
     *
     * @param  array<string, mixed>  $employee  an EmployeeRoster row
     * @param  array{version: ?int, site_id: ?string}  $input  the Day sheet's bulk row for a site, a leave type or office present
     *
     * @throws AttendanceRefused|DayRulesViolated
     */
    public function writeCell(array $employee, string $date, array $input, string $source, bool $dryRun = false): void
    {
        try {
            DB::transaction(fn () => $this->write($employee, $date, $input, $source, true, dryRun: $dryRun));
        } catch (UniqueConstraintViolationException) {
            throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict);
        }
    }

    /**
     * One day's write; call inside a transaction. $keepUnsent (bulk) applies P28 to the fields absent from $input, else they are stored as null.
     * An unchanged row is not written (P29). $dryRun stops after the last check, before anything is saved.
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $input  validated against FORMAT
     * @param  array<string, string>|null  $settings  read here when null and needed
     * @return int the row's version after the write
     */
    private function write(array $employee, string $date, array $input, string $source, bool $keepUnsent, ?array $settings = null, bool $dryRun = false): int
    {
        [$type, $row] = $this->guard($employee, $date);
        $live = $row !== null && ! $row->trashed() ? $row : null;
        $this->assertVersion($live, $input['version'] ?? null);

        $siteId = $input['site_id'] ?? null;
        $site = $siteId === null ? null : Site::find((string) $siteId);
        $siteRule = $this->siteRule($site, $live);
        $isLeave = $site?->kind === 'leave';
        $values = array_map(fn (string $f) => $input[$f] ?? null, array_combine(EffectiveDays::VALUES, EffectiveDays::VALUES));
        if ($keepUnsent && $live !== null) {
            $values = [...$values, ...array_diff_key($this->kept($type, $live, $siteRule), $input)];
        }
        if ($type === EmployeeType::PermanentWorker && ! $isLeave) {
            foreach ($this->days->workerDefaults($employee, $date, $settings ?? $this->rules->all()) as $field => $default) {
                $values[$field] ??= $default;
            }
        }

        $this->assertRules($type, $siteRule, $values);
        if ($dryRun) {
            return $live->version ?? 0;
        }

        if ($isLeave && $type === EmployeeType::PermanentWorker) {
            $values['transport'] = 'none';
        }
        foreach (self::TIMES as $t) {
            // Postgres returns time as HH:MM:SS; matching it keeps an unchanged time out of the audit diff.
            $values[$t] = $values[$t] === null ? null : $values[$t].':00';
        }
        foreach (['late_minutes', 'ot_minutes', 'outside_minutes'] as $m) {
            $values[$m] = $values[$m] === null ? null : (int) $values[$m];
        }

        $row ??= new AttendanceDay(['employee_id' => $employee['id'], 'date' => $date, 'version' => 0]);
        $row->fill([...$values, 'site_id' => $siteId, 'source' => $source, 'deleted_at' => null]);
        if ($row->exists && ! $row->isDirty()) {
            return $row->version;
        }
        $row->version = $row->version + 1;
        $row->save();

        return $row->version;
    }

    /** @param  array<string, mixed>  $employee */
    private function clearRow(array $employee, string $date, int $version): void
    {
        [, $row] = $this->guard($employee, $date);
        $live = $row !== null && ! $row->trashed() ? $row : null;
        $this->assertVersion($live, $version);
        /** @var AttendanceDay $live */
        $live->version = $version + 1;
        $live->save();
        $live->delete();
    }

    /**
     * P28: what the live row lends to unsent fields. The same kind of day (work, leave, office present) keeps what the new site allows;
     * a change of kind keeps only the note and the advance.
     *
     * @param  array{kind: string, outside_alexandria: bool, active: bool}|null  $siteRule
     * @return array<string, int|string>
     */
    private function kept(EmployeeType $type, AttendanceDay $live, ?array $siteRule): array
    {
        $liveKind = $live->site_id === null ? null : Site::withTrashed()->whereKey($live->site_id)->value('kind');
        $candidates = $liveKind === ($siteRule['kind'] ?? null) ? EffectiveDays::VALUES : ['note', 'advance_amount'];
        $kept = [];
        foreach ($candidates as $f) {
            /** @var int|string|null $value */
            $value = $live->getAttribute($f);
            if ($value !== null && DayRules::allows($type, $siteRule, $f, $value)) {
                $kept[$f] = in_array($f, self::TIMES, true) ? substr((string) $value, 0, 5) : $value;
            }
        }

        return $kept;
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

    /** @return array{kind: string, outside_alexandria: bool, active: bool}|null */
    private function siteRule(?Site $site, ?AttendanceDay $live): ?array
    {
        return $site === null ? null : [
            'kind' => (string) $site->kind,
            'outside_alexandria' => (bool) $site->outside_alexandria,
            // A stored site that is unchanged stays acceptable after it is deactivated.
            'active' => $site->active || $live?->site_id === $site->id,
        ];
    }

    /**
     * @param  array{kind: string, outside_alexandria: bool, active: bool}|null  $siteRule
     * @param  array<string, mixed>  $values
     */
    private function assertRules(EmployeeType $type, ?array $siteRule, array $values): void
    {
        // FIELDS order first, then the fields the type does not take, so the error order is stable.
        $order = array_unique([...DayRules::FIELDS[$type->value], ...EffectiveDays::VALUES]);
        $fields = [];
        foreach ($order as $f) {
            if ($values[$f] !== null) {
                $fields[$f] = $values[$f];
            }
        }

        $violations = DayRules::violations($type, $siteRule, $fields);
        if ($violations !== []) {
            throw DayRulesViolated::of($violations);
        }
    }
}
