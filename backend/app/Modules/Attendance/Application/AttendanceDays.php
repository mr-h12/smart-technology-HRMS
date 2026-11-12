<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceRequest;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use DateTimeImmutable;
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

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly BusinessRules $rules,
        private readonly SiteService $sites,
    ) {}

    /**
     * PUT semantics: a value absent from $input is stored as null. $ownerId (own scope) limits the work site to theirs (see write()).
     *
     * @param  array<string, mixed>  $input  validated against FORMAT
     * @return array<string, mixed> the day after the write
     */
    public function save(string $employeeId, string $date, array $input, ?string $ownerId = null, string $source = 'entered'): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        try {
            DB::transaction(fn () => $this->write($employee, $date, $input, $ownerId, $source, false));
        } catch (UniqueConstraintViolationException) {
            // A racing insert of the same employee and date won.
            throw new ConcurrencyConflict;
        }

        return $this->days->effectiveDay($employeeId, $date);
    }

    /** Apply an HR decision while the employee is locked by AttendanceRequests. */
    public function decideRequest(AttendanceRequest $request, bool $approve): void
    {
        $date = (string) $request->date;
        $employee = $this->roster->find((string) $request->employee_id) ?? throw new ConcurrencyConflict;
        [, $day] = $this->guard($employee, $date);
        if (! $approve) {
            return;
        }
        if ($day === null || $day->trashed() || $day->site_id !== $request->site_id) {
            throw new ConcurrencyConflict;
        }
        if ($request->type === 'overtime') {
            if ($day->source !== 'manager' || $day->in_time !== $request->in_time || $day->out_time !== $request->out_time) {
                throw new ConcurrencyConflict;
            }
            $day->ot_minutes = $request->minutes;
        } else {
            if ($day->getAttribute('advance_amount') !== null) {
                throw new ConcurrencyConflict;
            }
            $day->advance_amount = $request->amount;
        }
        $day->version++;
        $day->save();
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
     * @param  string  $source  `manager` for the site manager's save (P9)
     * @return list<array{employee_id: string, version: ?int}> in request order; version is null after a clear
     */
    public function bulk(string $date, array $rows, ?string $ownerId, string $source = 'entered'): array
    {
        $employees = $this->roster->findMany(array_map(fn (array $r) => (string) $r['employee_id'], $rows));
        $order = array_keys($rows);
        usort($order, fn (int $a, int $b) => strcmp((string) $rows[$a]['employee_id'], (string) $rows[$b]['employee_id']));
        $settings = $this->rules->all();
        $versions = [];
        DB::transaction(function () use ($date, $rows, $ownerId, $source, $order, $employees, $settings, &$versions): void {
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
                        $versions[$i] = $this->write($employee, $date, $row, $ownerId, $source, true, $settings);
                    }
                } catch (ConcurrencyConflict) {
                    $conflicts[$i] = "rows.{$i}";
                } catch (AttendanceRefused $e) {
                    // A locked period or a future date refuses the whole request, so it names no row.
                    $named = in_array($e->reason, [AttendanceRefusal::EmployeeNotActive, AttendanceRefusal::SiteNotInReach, AttendanceRefusal::LeaveNeedsRequest], true);
                    $detail = ['field' => "rows.{$i}", 'code' => $e->reason->value, 'message' => __('attendance.refusal.'.$e->reason->value, $e->params)];
                    throw AttendanceRefused::because($e->reason, $e->params, $named ? [$detail] : []);
                } catch (UniqueConstraintViolationException) {
                    // A racing insert of this row's day won; the transaction is aborted, so stop at this row.
                    throw new ConcurrencyConflict(["rows.{$i}"]);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[$i]["rows.{$i}.{$field}"] = $messages;
                    }
                }
            }
            ksort($conflicts);
            ksort($errors);
            if ($conflicts !== []) {
                throw new ConcurrencyConflict(array_values($conflicts));
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
     * @throws AttendanceRefused|ConcurrencyConflict|DayRulesViolated
     */
    public function writeCell(array $employee, string $date, array $input, ?string $ownerId, string $source, bool $dryRun = false): void
    {
        try {
            DB::transaction(fn () => $this->write($employee, $date, $input, $ownerId, $source, true, dryRun: $dryRun));
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrencyConflict;
        }
    }

    /**
     * The leave workflow's write (leave spec, Workflow Approve): source `leave`, a future date allowed, the period lock and row lock kept,
     * no reach check (deciders hold `.all`). A stored leave day is replaced, keeping its note and advance (P28), and its source when it
     * already holds this leave code; a work day or office present refuses (`leave_days_blocked`). A day of another leave code (source not
     * `derived`) is a conflict unless $replace: HR ticked it in the approval preview (A3).
     */
    public function writeLeaveDay(string $employeeId, string $date, string $leaveTypeId, bool $replace = false): void
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        try {
            DB::transaction(fn () => $this->write($employee, $date, ['site_id' => $leaveTypeId], null, 'leave', true, leaveDay: true, replace: $replace));
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrencyConflict;
        }
    }

    /**
     * The leave workflow's cancel (leave spec, Workflow Cancel); call inside the caller's transaction. Soft-deletes, as clear() does (version+1,
     * audited), every live day from $from to $to that still holds one of $leaveTypeIds with source `leave`, unless one of them holds a note or
     * an advance (P28): then nothing is cleared and those dates are returned, for the caller to refuse. A date inside employment needs an open
     * period even with nothing to clear; a date outside it (past a termination set since) has no period and is cleared. The employee may be deleted.
     *
     * @param  list<string>  $leaveTypeIds
     * @return list<string> the dates blocked by a note or an advance, in date order
     */
    public function clearLeaveDays(string $employeeId, string $from, string $to, array $leaveTypeIds): array
    {
        $employee = $this->roster->find($employeeId, withTrashed: true) ?? throw new ModelNotFoundException;
        $periods = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $type = Employment::typeOn($employee, $d->format('Y-m-d'));
            if ($type !== null) {
                $period = Period::containing($type, $d->format('Y-m-d'));
                $periods[$period->group->value.$period->start] = $period;
            }
        }
        foreach ($periods as $period) {
            PeriodGuard::assertOpen($period);
        }
        $rows = AttendanceDay::where(['employee_id' => $employeeId, 'source' => 'leave'])->whereBetween('date', [$from, $to])
            ->whereIn('site_id', $leaveTypeIds)->orderBy('date')->lockForUpdate()->get();
        $blocked = array_values($rows->filter(fn (AttendanceDay $r) => $r->getAttribute('note') !== null || $r->getAttribute('advance_amount') !== null)
            ->map(fn (AttendanceDay $r) => substr((string) $r->date, 0, 10))->all());
        if ($blocked === []) {
            foreach ($rows as $row) {
                $row->version = $row->version + 1;
                $row->save();
                $row->delete();
            }
        }

        return $blocked;
    }

    /**
     * Read only: date => leave code of each live day on a leave type (deleted types too). A `derived` day is skipped: it holds no note
     * or advance and is stale on a counted date, so overwriting it loses nothing.
     *
     * @param  list<string>  $dates
     * @return array<string, string>
     */
    public function leaveCodesOn(string $employeeId, array $dates): array
    {
        return AttendanceDay::join('sites', 'sites.id', '=', 'attendance_days.site_id')->where('sites.kind', SiteKind::Leave->value)
            ->where('attendance_days.employee_id', $employeeId)->whereIn('attendance_days.date', $dates)->where('attendance_days.source', '!=', 'derived')
            ->get(['attendance_days.date', 'sites.leave_code'])->mapWithKeys(fn (AttendanceDay $r) => [substr((string) $r->date, 0, 10) => (string) $r->getAttribute('leave_code')])->all();
    }

    /**
     * Read only: the dates a leave approval may not write, those holding a work day or office present, or inside a locked period.
     *
     * @param  list<string>  $dates
     * @return list<string> in $dates order
     */
    public function leaveBlockedDates(string $employeeId, array $dates): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $worked = AttendanceDay::where('employee_id', $employeeId)->whereIn('date', $dates)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhereNotIn('site_id', Site::withTrashed()->where('kind', SiteKind::Leave->value)->select('id')))
            ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $locked = [];

        return array_values(array_filter($dates, function (string $date) use ($employee, $worked, &$locked): bool {
            $type = Employment::typeOn($employee, $date);
            if ($type === null) {
                return false;
            }
            $period = Period::containing($type, $date);

            return in_array($date, $worked, true) || ($locked[$period->group->value.$period->start] ??= PeriodGuard::isLocked($period));
        }));
    }

    /**
     * One day's write; call inside a transaction. $keepUnsent (bulk) applies P28 to the fields absent from $input, else they are stored as null.
     * An unchanged row is not written (P29). $dryRun stops after the last check, before anything is saved. With $ownerId (own scope)
     * the employee must be in that user's reach on $date, and a work site must be one of their sites and, for a worker, the home site
     * on $date (design Part 1), and a change of the day's site must not be to a leave type that needs a leave request (a day already
     * stored as one, by HR or the leave workflow, can be resubmitted). The import takes reach once per period, so
     * this is what checks each cell's own date. $leaveDay is writeLeaveDay(): a future date passes, the version is the live row's,
     * a live row that is not a leave type refuses, and one of another leave code conflicts unless $replace.
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $input  validated against FORMAT
     * @param  array<string, string>|null  $settings  read here when null and needed
     * @return int the row's version after the write
     */
    private function write(array $employee, string $date, array $input, ?string $ownerId, string $source, bool $keepUnsent, ?array $settings = null, bool $dryRun = false, bool $leaveDay = false, bool $replace = false): int
    {
        [$type, $row] = $this->guard($employee, $date, $leaveDay);
        $live = $row !== null && ! $row->trashed() ? $row : null;
        $liveSite = $live?->site_id === null ? null : Site::withTrashed()->find($live->site_id);
        $liveKind = $liveSite?->kind;
        if ($leaveDay && $live !== null && $liveKind !== SiteKind::Leave->value) {
            throw AttendanceRefused::because(AttendanceRefusal::LeaveDaysBlocked);
        }
        if (! $leaveDay) {
            $this->assertVersion($live, $input['version'] ?? null);
        }

        $siteId = $input['site_id'] ?? null;
        $site = $siteId === null ? null : Site::find((string) $siteId);
        $sameCode = $liveSite?->leave_code !== null && $liveSite->leave_code === $site?->leave_code;
        if ($leaveDay && $live !== null && ! $sameCode && $live->source !== 'derived' && ! $replace) {
            // The day changed to another leave type after HR read the approval preview.
            throw new ConcurrencyConflict;
        }
        if ($ownerId !== null) {
            $this->assertInReach($employee, $type, $date, $site?->kind === SiteKind::Work->value ? (string) $site->id : null, $ownerId);
            if ($site?->id !== $live?->site_id && LeaveCode::tryFrom((string) $site?->leave_code)?->needsRequest() === true) {
                throw AttendanceRefused::because(AttendanceRefusal::LeaveNeedsRequest);
            }
        }
        $siteRule = $this->siteRule($site, $live);
        $isLeave = $site?->kind === 'leave';
        $values = array_map(fn (string $f) => $input[$f] ?? null, array_combine(EffectiveDays::VALUES, EffectiveDays::VALUES));
        if ($keepUnsent && $live !== null) {
            $values = [...$values, ...array_diff_key($this->kept($type, $live, $liveKind, $siteRule), $input)];
        }
        if ($type === EmployeeType::PermanentWorker && ! $isLeave) {
            foreach ($this->days->workerDefaults($employee, $date, $settings ?? $this->rules->all()) as $field => $default) {
                $values[$field] ??= $default;
            }
        }
        // The field shift on Present (calculations §13): bulk writes only, on a new day or a change of kind to a work day, and only
        // for a time the row does not send. save() (PUT) stores exactly what it is sent.
        if ($keepUnsent && $type !== EmployeeType::Office && $site !== null && ! $isLeave && ($live === null || $liveKind !== SiteKind::Work->value)) {
            $shift = EffectiveDays::fieldShift($employee, $date, $settings ?? $this->rules->all());
            $filled = [...$values];
            foreach (['in_time' => $shift['start'], 'out_time' => $shift['end']] as $t => $default) {
                if (! array_key_exists($t, $input)) {
                    $filled[$t] = $default;
                }
            }
            // Only a permanent worker's day may cross midnight; elsewhere a default must not fail out_before_in on a time nobody sent.
            $ordered = $filled['in_time'] === null || $filled['out_time'] === null || $filled['out_time'] >= $filled['in_time'];
            if ($type === EmployeeType::PermanentWorker || $ordered) {
                $values = $filled;
            }
        }

        // An engineer's or day laborer's minutes from the times (calculations §13, owner 2026-11-12), checked like typed ones. Single-day
        // entry fills an empty value always; bulk only an unsent one, on a new or newly work day or a change of the deciding time: the
        // arrival for the late minutes, the departure (or an arrival going between empty and set) for overtime. The site manager's is 0.
        if (in_array($type, [EmployeeType::Engineer, EmployeeType::DayLaborer], true) && $site?->kind === SiteKind::Work->value) {
            $was = $liveKind === SiteKind::Work->value;
            $hm = fn (mixed $t) => $t === null ? null : substr((string) $t, 0, 5);
            [$in, $out, $oldIn, $oldOut] = [$values['in_time'], $values['out_time'], $hm($live?->in_time), $hm($live?->out_time)];
            $fill = fn (string $m, bool $changed) => $keepUnsent ? ! array_key_exists($m, $input) && (! $was || $changed) : $values[$m] === null;
            $shift = EffectiveDays::fieldShift($employee, $date, $settings ?? $this->rules->all());
            if ($fill('late_minutes', $in !== $oldIn)) {
                $values['late_minutes'] = $in === null ? null : DayRules::lateMinutes($shift['start'], $in);
            }
            if ($fill('ot_minutes', $out !== $oldOut || ($in === null) !== ($oldIn === null))) {
                // A departure before the arrival is refused as out_before_in; it gives no overtime to refuse as well.
                $values['ot_minutes'] = $in === null || $out === null || $out < $in ? null
                    : ($source === 'manager' ? 0 : DayRules::workerMinutes($shift['start'], $shift['end'], $in, $out)['ot']);
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

        // A leave-written day re-saved on the same leave code stays `leave`, so cancelling the request still clears it; a leave day written
        // over HR's own day of the same leave code (a since-deactivated type too) keeps HR's source, so that cancel never clears it.
        // Over the site manager's own day it becomes `leave`: the approved request now holds it, so the manager can no longer change it
        // (PR #22 review B1).
        if ($live !== null && ($live->site_id === $siteId || $sameCode) && ($live->source === 'leave' || ($leaveDay && $live->source !== 'manager'))) {
            $source = (string) $live->source;
        }
        $row ??= new AttendanceDay(['employee_id' => $employee['id'], 'date' => $date, 'version' => 0]);
        $row->fill([...$values, 'site_id' => $siteId, 'source' => $source, 'deleted_at' => null]);
        if ($row->exists && ! $row->isDirty() && ! ($source === 'manager' && isset($input['advance_request_changed']))) {
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
    private function kept(EmployeeType $type, AttendanceDay $live, ?string $liveKind, ?array $siteRule): array
    {
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
     * Future date (unless $allowFuture: the leave workflow only), employment and open period, then the row (soft-deleted ones too) locked for update.
     *
     * @param  array<string, mixed>  $employee
     * @return array{EmployeeType, ?AttendanceDay}
     */
    private function guard(array $employee, string $date, bool $allowFuture = false): array
    {
        if (! $allowFuture && $date > EffectiveDays::today()) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        $type = Employment::typeOn($employee, $date) ?? throw AttendanceRefused::because(AttendanceRefusal::EmployeeNotActive);
        PeriodGuard::assertOpen(Period::containing($type, $date));
        $row = AttendanceDay::withTrashed()->where(['employee_id' => $employee['id'], 'date' => $date])->lockForUpdate()->first();

        return [$type, $row];
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  string|null  $workSiteId  the day's work site; null for a leave type or office present
     */
    private function assertInReach(array $employee, EmployeeType $type, string $date, ?string $workSiteId, string $ownerId): void
    {
        $worker = in_array($type, [EmployeeType::PermanentWorker, EmployeeType::DayLaborer], true);
        $siteRefused = $workSiteId !== null
            && (! in_array($workSiteId, $this->sites->idsManagedBy($ownerId), true) || ($worker && Employment::homeSiteOn($employee, $date) !== $workSiteId));
        if ($siteRefused || ! EmployeeReach::reaches((string) $employee['id'], $ownerId, $date)) {
            throw AttendanceRefused::because(AttendanceRefusal::SiteNotInReach);
        }
    }

    private function assertVersion(?AttendanceDay $live, mixed $version): void
    {
        $version = $version === null ? null : (int) $version;
        if ($live === null ? $version !== null : $live->version !== $version) {
            throw new ConcurrencyConflict;
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
