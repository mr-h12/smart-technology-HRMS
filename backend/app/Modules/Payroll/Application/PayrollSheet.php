<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Attendance\Application\AttendanceLocks;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Domain\Component;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\DayLaborerEngine;
use App\Modules\Payroll\Domain\DayLine;
use App\Modules\Payroll\Domain\Line;
use App\Modules\Payroll\Domain\Money;
use App\Modules\Payroll\Domain\MonthlyFieldEngine;
use App\Modules\Payroll\Domain\OfficeEngine;
use App\Modules\Payroll\Infrastructure\Eloquent\PayrollEntry;
use App\Modules\Payroll\Infrastructure\Eloquent\PayrollOverride;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One group's payroll for one period, computed on read (design 2026-11-09): the engine of the group's type over the assembled days and
 * HR's entry, then HR's overrides, each kept apart from the computed value it replaces (calculations, "Applies everywhere").
 */
final class PayrollSheet
{
    /** HR's entry fields (payroll_entries): amounts, whole minutes or days, and text. Which ones a type uses is the API's validation. */
    public const AMOUNTS = ['bonus', 'deduction', 'negligence', 'advances', 'insurance_employee', 'insurance_company', 'income_tax'];

    public const COUNTS = ['lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes'];

    public const FIELDS = [...self::AMOUNTS, ...self::COUNTS, 'deduction_reason', 'note'];

    public function __construct(private readonly EmployeeRoster $roster, private readonly PayrollInputs $inputs) {}

    /**
     * A line per employee employed as the group's type on some day of the period (leavers archived since included), by code, the
     * column totals of the final values, and whether the period's attendance is locked (until then the days may still change).
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, locked: bool}
     */
    public function sheet(Period $p): array
    {
        $lines = $this->lines($p, $this->roster->withArchived()->allOfType($p->group, $p->start, $p->end));
        $totals = [];
        foreach ($lines as $line) {
            foreach ($line['components'] as $c => $v) {
                $totals[$c] = Money::add($totals[$c] ?? '0', $v['final']);
            }
        }

        return [
            'lines' => array_map(fn (array $l) => array_diff_key($l, ['days' => true]), $lines),
            'totals' => array_map(Money::round6(...), $totals),
            'locked' => AttendanceLocks::isLocked($p),
        ];
    }

    /**
     * The employee's line plus its days.
     *
     * @return array{employee: array<string, mixed>, components: array<string, array{computed: numeric-string, override: array{id: string, amount: string, reason: string, inputs_changed: bool}|null, final: numeric-string}>, entry: array<string, mixed>, flags: list<string>, days: list<array<string, mixed>>}
     *
     * @throws ModelNotFoundException when the employee holds the group's type on no day of the period
     */
    public function employee(Period $p, string $employeeId): array
    {
        return $this->lines($p, [$this->employeeOn($p, $employeeId)])[0];
    }

    /**
     * HR's inputs for the employee and period, PUT semantics: a field absent from $fields is stored blank. $version is the entry's
     * version as read, null while it has none; a stale one writes nothing. An unchanged entry is not written.
     *
     * @param  array<string, mixed>  $fields  validated by the caller
     * @return array<string, mixed> employee() after the write
     *
     * @throws ConcurrencyConflict|ModelNotFoundException
     */
    public function saveEntry(Period $p, string $employeeId, array $fields, ?int $version): array
    {
        $this->employeeOn($p, $employeeId);
        $key = ['employee_id' => $employeeId, 'employee_type' => $p->group->value, 'period_start' => $p->start];
        try {
            DB::transaction(function () use ($key, $fields, $version): void {
                $row = PayrollEntry::where($key)->lockForUpdate()->first();
                if ($row?->version !== $version) {
                    throw new ConcurrencyConflict;
                }
                $row ??= new PayrollEntry([...$key, 'version' => 0]);
                $row->fill(array_map(fn (string $f) => $fields[$f] ?? null, array_combine(self::FIELDS, self::FIELDS)));
                if ($row->exists && ! $row->isDirty()) {
                    return;
                }
                $row->version = $row->version + 1;
                $row->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrencyConflict;   // a racing first save of the same entry won
        }

        return $this->employee($p, $employeeId);
    }

    /**
     * HR's override of one computed component of the employee's line, with a reason. It keeps the computed value of the moment, so a
     * later change of the inputs shows `inputs_changed`. A component has one live override: revert it to override it again.
     *
     * @param  numeric-string  $amount
     * @return array<string, mixed> employee() after the write
     *
     * @throws ValidationException when the type's line has no such component
     * @throws ConcurrencyConflict when the component already has a live override
     * @throws ModelNotFoundException
     */
    public function addOverride(Period $p, string $employeeId, string $component, string $amount, string $reason): array
    {
        $computed = $this->employee($p, $employeeId)['components'][$component]['computed']
            ?? throw ValidationException::withMessages(['component' => __('validation.in', ['attribute' => __('validation.attributes.component')])]);
        try {
            PayrollOverride::create([
                'employee_id' => $employeeId, 'employee_type' => $p->group->value, 'period_start' => $p->start,
                'component' => $component, 'amount' => $amount, 'reason' => $reason, 'computed_at_override' => $computed,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrencyConflict;
        }

        return $this->employee($p, $employeeId);
    }

    /**
     * Reverts an override to the computed value: a soft delete with a reason, audited (the reason as an update, then the delete).
     *
     * @return array<string, mixed> employee() after the write
     *
     * @throws ModelNotFoundException when the employee has no such live override in the period
     */
    public function revertOverride(Period $p, string $employeeId, string $overrideId, string $reason): array
    {
        DB::transaction(function () use ($p, $employeeId, $overrideId, $reason): void {
            $override = PayrollOverride::where(['id' => $overrideId, 'employee_id' => $employeeId, 'employee_type' => $p->group->value, 'period_start' => $p->start])
                ->lockForUpdate()->firstOrFail();
            $override->revert_reason = $reason;
            $override->version = $override->version + 1;
            $override->save();
            $override->delete();
        });

        return $this->employee($p, $employeeId);
    }

    /**
     * @param  list<array<string, mixed>>  $employees  EmployeeRoster rows
     * @return list<array{employee: array<string, mixed>, components: array<string, array{computed: numeric-string, override: array{id: string, amount: string, reason: string, inputs_changed: bool}|null, final: numeric-string}>, entry: array<string, mixed>, flags: list<string>, days: list<array<string, mixed>>}>
     */
    private function lines(Period $p, array $employees): array
    {
        $ids = array_column($employees, 'id');
        $key = ['employee_type' => $p->group->value, 'period_start' => $p->start];
        $entries = PayrollEntry::whereIn('employee_id', $ids)->where($key)->get()->keyBy('employee_id');
        $overrides = PayrollOverride::whereIn('employee_id', $ids)->where($key)->get()->groupBy('employee_id');
        $inputs = $this->inputs->forPeriod($p, $employees);
        $rates = $this->inputs->rates($p->group);

        $lines = [];
        foreach ($employees as $e) {
            $id = (string) $e['id'];
            $in = $inputs[$id];
            $entry = $entries->get($id);
            $values = [...self::engineEntry($entry), 'insured_basic' => $in['insured_basic']];
            $computed = match ($p->group) {
                EmployeeType::Office => OfficeEngine::compute($in['days'], $rates, $values),
                EmployeeType::Engineer, EmployeeType::PermanentWorker => MonthlyFieldEngine::compute($p->group, $in['days'], $rates, $values),
                EmployeeType::DayLaborer => DayLaborerEngine::compute($in['days'], $rates, $values),
            };
            [$components, $flags] = self::withOverrides($computed, $overrides->get($id, collect())->all(), $in['flags']);
            $lines[] = [
                'employee' => ['id' => $id, 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en']],
                'components' => $components,
                'entry' => ['version' => $entry?->getAttribute('version'), ...array_combine(self::FIELDS, array_map(fn (string $f) => $entry?->getAttribute($f), self::FIELDS))],
                'flags' => $flags,
                'days' => array_map(fn (DayLine $d, DayInput $i) => [
                    'date' => $d->date, 'state' => $i->state, 'leave_code' => $i->leaveCode, 'site_id' => $d->siteId,
                    'parts' => $d->parts, 'day_cost' => $d->dayCost, 'flags' => $d->flags,
                ], $computed->days, $in['days']),
            ];
        }

        return $lines;
    }

    /**
     * Each component's computed value, override and final value. The final net is the net override, else the net of the final
     * components (Line::net: the computed net moved by each override's change, so a line with none keeps its computed net exactly).
     * Flags: the engine's, the assembler's, `inputs_changed` when an override's computed value moved since it was made, and
     * `negative_net` on the final net.
     *
     * @param  array<PayrollOverride>  $overrides
     * @param  list<string>  $flags  the assembler's
     * @return array{array<string, array{computed: numeric-string, override: array{id: string, amount: string, reason: string, inputs_changed: bool}|null, final: numeric-string}>, list<string>}
     */
    private static function withOverrides(Line $computed, array $overrides, array $flags): array
    {
        $byComponent = [];
        foreach ($overrides as $o) {
            $byComponent[(string) $o->component] = $o;
        }
        $net = Component::Net->value;
        $components = [];
        $changes = [];
        $inputsChanged = false;
        foreach ($computed->components as $c => $amount) {
            $o = $byComponent[$c] ?? null;
            if ($o === null) {
                $components[$c] = ['computed' => $amount, 'override' => null, 'final' => $amount];

                continue;
            }
            $final = Decimal::of((string) $o->amount, 'amount');
            $changed = bccomp($amount, (string) $o->computed_at_override, 6) !== 0;
            $inputsChanged = $inputsChanged || $changed;
            $components[$c] = [
                'computed' => $amount,
                'override' => ['id' => (string) $o->id, 'amount' => $final, 'reason' => (string) $o->reason, 'inputs_changed' => $changed],
                'final' => $final,
            ];
            if ($c !== $net) {
                $changes[$c] = Money::sub($final, $amount);
            }
        }
        if (! isset($byComponent[$net])) {
            $components[$net] = ['computed' => $computed->components[$net], 'override' => null, 'final' => Money::round6(Money::add($computed->components[$net], Line::net($changes)))];
        }
        $flags = array_values(array_unique([...$computed->flags, ...$flags, ...($inputsChanged ? ['inputs_changed'] : [])]));

        return [$components, (new Line(array_map(fn (array $v) => $v['final'], $components), [], $flags))->withNegativeNetFlag()->flags];
    }

    /**
     * The entry as the engines read it; blank = 0 there.
     *
     * @return array{bonus: numeric-string|null, deduction: numeric-string|null, negligence: numeric-string|null, advances: numeric-string|null, insurance_employee: numeric-string|null, insurance_company: numeric-string|null, income_tax: numeric-string|null, lateness_exemption_minutes: int|null, travel_days: int|null, tower_days: int|null, ot_minutes: int|null}
     */
    private static function engineEntry(?PayrollEntry $entry): array
    {
        $amount = fn (string $f) => $entry?->getAttribute($f) === null ? null : Decimal::of((string) $entry->getAttribute($f), $f);
        $count = fn (string $f) => $entry?->getAttribute($f) === null ? null : (int) $entry->getAttribute($f);

        return [
            'bonus' => $amount('bonus'), 'deduction' => $amount('deduction'), 'negligence' => $amount('negligence'),
            'advances' => $amount('advances'), 'insurance_employee' => $amount('insurance_employee'),
            'insurance_company' => $amount('insurance_company'), 'income_tax' => $amount('income_tax'),
            'lateness_exemption_minutes' => $count('lateness_exemption_minutes'), 'travel_days' => $count('travel_days'),
            'tower_days' => $count('tower_days'), 'ot_minutes' => $count('ot_minutes'),
        ];
    }

    /**
     * @return array<string, mixed> the roster row of an employee who holds the group's type on some day of the period
     *
     * @throws ModelNotFoundException
     */
    private function employeeOn(Period $p, string $employeeId): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        foreach ($p->days() as $date) {
            if (Employment::typeOn($employee, $date) === $p->group) {
                return $employee;
            }
        }

        throw new ModelNotFoundException;
    }
}
