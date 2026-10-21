<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\XlsxSheetRows;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Application\FareService;
use App\Modules\Sites\Application\SiteRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\Name;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Closure;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Loads the reviewed staging workbook (master-data.xlsx) into a system with no employees: residences, sites and their allowance
 * rules, fares, employees, fare overrides and manager assignments. Every row goes through the API's rules and services, so the
 * audit log records it. It all runs in one transaction that commits only when asked and when no row failed; a refusal (employees
 * exist, a malformed workbook) is a DomainException thrown before any write. Errors name sheet, row and column, never a cell value.
 */
final class MasterDataImport
{
    /** What the acting account must hold (fare overrides are an employee edit in the API). */
    public const ABILITIES = ['employee.create', 'employee.edit', 'employee.assign_manager', 'site.manage'];

    private const COLUMNS = [
        'residences' => ['name_ar'],
        'sites' => ['name_ar', 'name_en', 'outside_alexandria', 'allowance_mode', 'allowance_value', 'excel_spellings'],
        'fares' => ['residence', 'site', 'amount'],
        'employees' => [
            'ref', 'type', 'name_ar', 'name_en', 'fingerprint_name', 'gender', 'national_id', 'insurance_no', 'phone', 'address',
            'department', 'residence', 'pay_method', 'bank_account', 'hire_date', 'salary', 'raise', 'insured_basic', 'daily_wage',
            'manager_email', 'hr_notes', 'source',
        ],
        'fare_overrides' => ['ref', 'site', 'amount'],
    ];

    /** Informational columns: allowed in the workbook, never read. */
    private const INFO = ['excel_spellings', 'source'];

    /** Employee columns that must not repeat within the file (the database checks them only against rows already written). */
    private const DISTINCT = ['ref', 'national_id', 'fingerprint_name'];

    /** Validator keys renamed back to the workbook column the operator sees. */
    private const FIELD = ['residence_id' => 'residence', 'site_id' => 'site', 'mode' => 'allowance_mode', 'value' => 'allowance_value', 'manager_id' => 'manager_email'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array{sheet: string, row: int, field: string, message: string}> */
    private array $errors = [];

    /** @var list<array{ref: string, code: int}> */
    private array $codes = [];

    /** @var array<string, ?string> normalised name => id; '' when that name's own row failed (its error is already listed) */
    private array $residenceIds = [];

    /** @var array<string, ?string> normalised name => id, like the residences */
    private array $siteIds = [];

    /** @var array<string, string> ref => employee id; '' when that row failed */
    private array $employeeIds = [];

    /** @var array<int, string> employees sheet row => employee id; '' when that row failed */
    private array $rowEmployeeIds = [];

    public function __construct(
        private readonly XlsxSheetRows $reader,
        private readonly EmployeeService $employees,
        private readonly SiteService $sites,
        private readonly FareService $fares,
    ) {}

    public function actor(string $email): ?Authenticatable
    {
        return User::where(['email' => $email, 'is_active' => true])->first();
    }

    /**
     * @return array{counts: array<string, int>, errors: list<array{sheet: string, row: int, field: string, message: string}>, codes: list<array{ref: string, code: int}>}
     */
    public function run(string $path, string $from, bool $commit): array
    {
        $this->counts = array_fill_keys(['residences', 'residences reused', 'sites', 'sites reused', 'allowance rules', 'fares', 'employees', 'fare overrides', 'manager assignments'], 0);
        $this->errors = $this->codes = $this->residenceIds = $this->siteIds = $this->employeeIds = $this->rowEmployeeIds = [];

        DB::beginTransaction();
        try {
            // Held to the end, so a second run started meanwhile waits and then sees these employees.
            DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
            if (Employee::withTrashed()->exists()) {
                throw new DomainException('Employees already exist; this import is for an empty system.');
            }
            $sheets = $this->sheets($path);
            $this->residences($sheets['residences'] ?? []);
            $this->siteRows($sheets['sites'] ?? [], $from);
            $this->fareRows($sheets['fares'] ?? [], $from);
            $this->employeeRows($sheets['employees'], $from);
            $this->overrides($sheets['fare_overrides'] ?? [], $from);
            $this->managers($sheets['employees'], $from);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $commit && $this->errors === [] ? DB::commit() : DB::rollBack();

        return ['counts' => $this->counts, 'errors' => $this->errors, 'codes' => $this->codes];
    }

    /** @return array<string, list<array{row: int, cells: array<string, ?string>}>> */
    private function sheets(string $path): array
    {
        foreach ($this->reader->headers($path) as $sheet => $headers) {
            if (count($headers) !== count(array_unique($headers))) {
                throw new DomainException("Sheet {$sheet} repeats a header in row 1.");
            }
            $unknown = array_diff($headers, self::COLUMNS[$sheet] ?? $headers);
            if ($unknown !== []) {
                throw new DomainException("Sheet {$sheet} has an unknown header in column ".((int) array_key_first($unknown) + 1).'.');
            }
        }
        $sheets = $this->reader->read($path);
        if (! isset($sheets['employees'])) {
            throw new DomainException('The workbook has no employees sheet.');
        }

        return $sheets;
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows */
    private function residences(array $rows): void
    {
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $name = self::normalise($c['name_ar'] ?? null);
            if ($this->residenceId($name) !== null) {
                $this->counts['residences reused']++;

                continue;
            }
            $made = $this->attempt('residences', $row, ['name_ar' => $name], SiteRules::residence(false), fn ($v) => $this->fares->createResidence($v));
            $this->settle($this->residenceIds, $name, $made, 'residences');
        }
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows */
    private function siteRows(array $rows, string $from): void
    {
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $name = self::normalise($c['name_ar'] ?? null);
            $id = $this->siteId($name);
            if ($id !== null) {
                $this->counts['sites reused'] += $id === '' ? 0 : 1;
            } else {
                $data = ['name_ar' => $name, 'name_en' => $c['name_en'] ?? null, 'outside_alexandria' => $c['outside_alexandria'] ?? null, 'kind' => SiteKind::Work->value];
                $made = $this->attempt('sites', $row, $data, SiteRules::site(false), fn ($v) => $this->sites->create($v));
                $id = $this->settle($this->siteIds, $name, $made, 'sites');
            }
            if (($c['allowance_mode'] ?? null) !== null) {
                $rule = ['effective_from' => $from, 'mode' => $c['allowance_mode'], 'value' => $c['allowance_value'] ?? null];
                $added = $this->attempt('sites', $row, $rule, SiteRules::allowanceRule(), fn ($v) => $this->sites->addRule((string) $id, $v), $id !== '');
                $this->counts['allowance rules'] += $added === null ? 0 : 1;
            }
        }
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows */
    private function fareRows(array $rows, string $from): void
    {
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $ok = true;
            $data = [
                'residence_id' => $this->id('fares', $row, 'residence', $c['residence'] ?? null, $this->residenceId(self::normalise($c['residence'] ?? null)), $ok),
                'site_id' => $this->id('fares', $row, 'site', $c['site'] ?? null, $this->siteId(self::normalise($c['site'] ?? null)), $ok),
                'effective_from' => $from,
                'amount' => $c['amount'] ?? null,
            ];
            $made = $this->attempt('fares', $row, $data, SiteRules::fare(), fn ($v) => $this->fares->addFare($v), $ok);
            $this->counts['fares'] += $made === null ? 0 : 1;
        }
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows */
    private function employeeRows(array $rows, string $from): void
    {
        $seen = [];
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $ok = true;
            $ref = $c['ref'] ?? null;
            if ($ref === null) {
                $this->error('employees', $row, 'ref', __('validation.required', ['attribute' => 'ref']));
                $ok = false;
            }
            foreach (self::DISTINCT as $col) {
                $value = $c[$col] ?? null;
                if ($value !== null && isset($seen[$col][$value])) {
                    $this->error('employees', $row, $col, __('validation.distinct', ['attribute' => $col]));
                    $ok = false;
                    $c[$col] = null;   // reported once: the unique rule must not repeat it
                } elseif ($value !== null) {
                    $seen[$col][$value] = true;
                }
            }
            $data = [
                ...Arr::except($c, ['ref', 'residence', 'manager_email', ...self::INFO]),
                'residence_id' => $this->id('employees', $row, 'residence', $c['residence'] ?? null, $this->residenceId(self::normalise($c['residence'] ?? null)), $ok),
                'effective_from' => $from,
            ];
            $made = $this->attempt('employees', $row, $data, EmployeeRules::store(), fn ($v) => $this->employees->create($v), $ok);
            $this->rowEmployeeIds[$row] = $made === null ? '' : (string) $made['id'];
            if ($ref !== null) {
                $this->employeeIds[$ref] ??= $this->rowEmployeeIds[$row];   // a repeated ref keeps the first row's employee
            }
            if ($made !== null && $ref !== null) {
                $this->counts['employees']++;
                $this->codes[] = ['ref' => $ref, 'code' => (int) $made['code']];
            }
        }
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows */
    private function overrides(array $rows, string $from): void
    {
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $ok = true;
            $ref = $c['ref'] ?? null;
            if ($ref === null) {
                $this->error('fare_overrides', $row, 'ref', __('validation.required', ['attribute' => 'ref']));
                $ok = false;
            }
            $employee = $this->id('fare_overrides', $row, 'ref', $ref, $ref === null ? null : ($this->employeeIds[$ref] ?? null), $ok);
            $data = [
                'site_id' => $this->id('fare_overrides', $row, 'site', $c['site'] ?? null, $this->siteId(self::normalise($c['site'] ?? null)), $ok),
                'effective_from' => $from,
                'amount' => $c['amount'] ?? null,
            ];
            $made = $this->attempt('fare_overrides', $row, $data, SiteRules::fareOverride(), fn ($v) => $this->fares->addOverride((string) $employee, $v), $ok);
            $this->counts['fare overrides'] += $made === null ? 0 : 1;
        }
    }

    /** @param  list<array{row: int, cells: array<string, ?string>}>  $rows  the employees sheet */
    private function managers(array $rows, string $from): void
    {
        foreach ($rows as ['row' => $row, 'cells' => $c]) {
            $email = $c['manager_email'] ?? null;
            if ($email === null) {
                continue;
            }
            $ok = true;
            $manager = $this->id('employees', $row, 'manager_email', $email, self::key(User::where(['email' => $email, 'is_active' => true])->value('id')), $ok);
            $employee = $this->rowEmployeeIds[$row] ?? '';   // '' when the employee row failed: its error is listed
            $data = ['manager_id' => $manager, 'effective_from' => $from];
            $rules = ['manager_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date_format:Y-m-d']];
            $made = $this->attempt('employees', $row, $data, $rules, fn ($v) => $this->employees->assignManager($employee, $v), $ok && $employee !== '');
            $this->counts['manager assignments'] += $made === null ? 0 : 1;
        }
    }

    /**
     * Validates one row and, when $write is allowed, writes it inside a savepoint; failures become row errors.
     *
     * @param  array<string, ?string>  $data
     * @param  array<string, array<mixed>>  $rules
     * @param  Closure(mixed): array<string, mixed>  $write  given the validated data untyped, as a controller hands on validate()'s result
     * @return array<string, mixed>|null what $write returned; null when nothing was written
     */
    private function attempt(string $sheet, int $row, array $data, array $rules, Closure $write, bool $writable = true): ?array
    {
        // A blank cell means "omit": `sometimes` rules (pay_method, outside_alexandria) reject an explicit null.
        $data = array_filter($data, fn (?string $v) => $v !== null);
        try {
            $valid = Validator::make($data, $rules)->validate();

            return $writable ? DB::transaction(fn () => $write($valid)) : null;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error($sheet, $row, self::FIELD[$field] ?? $field, implode(' ', $messages));
            }

            return null;
        } catch (QueryException) {
            // A constraint no rule mirrors (a varchar length, say). Its message carries the row's values, so it is never shown;
            // the savepoint is already rolled back, so the other rows still run.
            $this->error($sheet, $row, '*', 'The database refused this row; check its lengths and formats.');

            return null;
        }
    }

    /**
     * The id behind a name, ref or email cell. A blank cell gives null and leaves "required" to the rules; an unknown value is a row
     * error on $field; a value whose own row failed gives null quietly. Either way the row is no longer written.
     *
     * @param  ?string  $found  the lookup's answer: an id, '' for a row that failed, null for unknown
     */
    private function id(string $sheet, int $row, string $field, ?string $cell, ?string $found, bool &$ok): ?string
    {
        if ($cell === null) {
            return null;
        }
        if ($found === null) {
            $this->error($sheet, $row, $field, __('validation.exists', ['attribute' => $field]));
        }
        if ($found === null || $found === '') {
            $ok = false;

            return null;
        }

        return $found;
    }

    private function residenceId(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return $this->residenceIds[$name] ??= self::key(Residence::where('name_ar', $name)->value('id'));
    }

    private function siteId(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return $this->siteIds[$name] ??= self::key(Site::where('name_ar', $name)->value('id'));
    }

    /**
     * Records what a residence or site row produced, so later rows resolve its name.
     *
     * @param  array<string, ?string>  $ids
     * @param  array<string, mixed>|null  $made
     * @return string the new id, or '' when the row failed
     */
    private function settle(array &$ids, ?string $name, ?array $made, string $count): string
    {
        $id = $made === null ? '' : (string) $made['id'];
        if ($name !== null) {
            $ids[$name] = $id;
        }
        $this->counts[$count] += $id === '' ? 0 : 1;

        return $id;
    }

    private function error(string $sheet, int $row, string $field, mixed $message): void
    {
        $this->errors[] = ['sheet' => $sheet, 'row' => $row, 'field' => $field, 'message' => (string) $message];
    }

    private static function key(mixed $id): ?string
    {
        return $id === null ? null : (string) $id;
    }

    private static function normalise(?string $name): ?string
    {
        return $name === null ? null : (string) Name::normalise($name);
    }
}
