# Payroll Engines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every implementer and reviewer follows `ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `docs/coding-standards.md`, and runs on Opus.

**Goal:** Fix review findings M1 and M2, then build the three payroll engines, the payroll sheet (HR inputs and overrides), and the October 2026 regression with its Excel-difference report.

**Architecture:** Two PRs. PR A hardens the dated employee records payroll reads. PR B adds a `Payroll` module: an Application assembler reads attendance days (`EffectiveDays::forPeriod`), dated records and settings, pure Domain engines compute per-day and per-employee components in bcmath, and the sheet applies HR entries and overrides on read. Nothing is stored but HR's inputs; item 5 (close) will freeze results later.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17 (`NUMERIC(18,6)` via the `money()` macro), bcmath, PHPUnit on `hrms_test`, OpenSpout 5.12 (fixture extraction only), React 19 + Vite + react-i18next.

**Spec:** `docs/superpowers/specs/2026-11-09-payroll-engines-design.md`. Formulas: `docs/calculations.md` §0–§7, §10. Read both before any task.

## Global Constraints

- Money is bcmath decimal strings end to end; never `float`. Engine scale 12; every returned amount rounded half-up to 6 decimals; display 2.
- No rounding of the net (question 12).
- Every business number comes from `BusinessRules::all()` (`month_days` 30, `day_hours` 8, `overtime_multiplier` 1.5, `lateness_multiplier_{office,engineer,permanent_worker,day_laborer}` 1/1.5/2/2, `office_grace_minutes` 15 used as the grace for every type, `sick_leave_pay_percent` 100). Divisors are derived: 14,400 = 30 × 8 × 60, 480 = 8 × 60.
- Lateness never wraps midnight; permanent-worker OT = actual out − scheduled out with the +24 h rule (`calculations.md` §3).
- Every UI string has `ar` and `en` keys; Arabic is default and RTL.
- Every write to a new table is audited (`Audited` trait) and versioned (`409 concurrency_conflict` on a stale `version`).
- Permissions are data, checked by name: `payroll.view.all`, `payroll.manage.all`. No `.own` scope.
- Fixtures and the committed report carry labels only (Engineer-N, Worker-N, Person-N): no names, national IDs, phones.
- The October fixture is never edited to make a test pass.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `CLAUDE_HANDOFF.md`. Tests use `hrms_test`; live checks a throwaway DB, never `hrms`, never the VPS.

## Review Focus

1. A type change at a period boundary: the employee appears on each sheet only for the days they hold that type, with the other days `not_employed` (test in Task B6).
2. A day laborer with no pay row on a worked day: wage 0 with flag `no_wage`, never a crash or a skipped day (test in Task B3).
3. Two HR users editing the same entry: the second save gets 409, nothing overwritten (test in Task B7).
4. An override whose inputs change: the line shows `inputs_changed`; reverting returns the computed value and is audited (test in Task B6).
5. A leaver whose not-employed deduction exceeds the salary: the salary part floors at 0; a negative net is shown and flagged `negative_net`, never clipped (test in Task B5).

---

## PR A: dated records payroll reads (M1, M2)

Branch `claude/payroll-m1-m2` from `main`. Spec section "Before any engine code".

### Task A1: refuse dated changes inside a locked period (M1)

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php` (`addTypeChange` :255, `addPayChange` :268, `assignManager` :308, `assignSite` :323, `update` :178 for `termination_date`)
- Modify: `backend/app/Modules/Sites/Application/SiteService.php` (`addRule` :98)
- Modify: `backend/lang/ar/errors.php`, `backend/lang/en/errors.php` (keys `dated_change_period_locked`, `type_change_not_period_start`)
- Test: `backend/tests/Feature/DatedChangeLockTest.php`

**Interfaces:**
- Consumes: `PeriodGuard::lockedFrom(string $date, EmployeeType ...$groups): bool`, `Period::containing(EmployeeType, string): Period`.
- Produces: `EmployeeService::assertUnlocked(string $date, EmployeeType ...$types): void` (private), throwing `ValidationException` on `effective_from` (or `termination_date`).

- [ ] **Step 1: Write the failing tests** (one method each, using `AttendanceFixtures`; lock with `POST attendance/locks` as the existing lock tests do):
  - `test_type_change_inside_a_locked_period_is_refused`: engineer, lock 2026-06-26..07-25, `POST type-changes {day_laborer, 2026-07-01}` → 422 `effective_from`; then day 07-12 still refuses a write (423).
  - `test_type_change_must_start_a_period_of_the_new_type`: office → engineer on 2026-07-16 → 422 `type_change_not_period_start` (engineer periods start on the 26th); office → day_laborer on 2026-07-16 → 201; engineer → day_laborer on 2026-08-01 → 201 (the spec's open-point default: the new type's period start; the engineer's last period simply ends 07-31 for that employee).
  - `test_pay_site_manager_rule_and_termination_inside_a_locked_period_are_refused`: one assertion each for `pay-changes`, `site-assignments`, `manager-assignments`, `sites/{id}/allowance-rules`, `PATCH employees/{id} {termination_date}` → 422.
  - `test_dated_changes_after_the_locked_period_are_accepted` → 201 for each.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter=DatedChangeLockTest` → FAIL (201 where 422 expected).
- [ ] **Step 3: Implement.** Guard each write inside its transaction with `PeriodGuard::lockedFrom($date, $oldType, $newType)` (the types in force before and on the date; for the site rule, every type). Type change: the date must equal `Period::containing($newType, $date)->start`; when the old type's period does not also start there, accept (default of the spec's open point) — the old type's employment simply ends the day before, which `Employment::typeOn` already yields.
- [ ] **Step 4: Run** the filter, then the full suite → PASS.
- [ ] **Step 5: Commit** `Refuse dated employee changes inside a locked period (M1)`.

### Task A2: a pay change keeps the fields it does not send (M2)

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php` (`addPayChange`, `payRow` :391)
- Modify: `frontend/src/pages/employees.tsx` (`ChangeDialog` :705 opens from `current_pay`)
- Test: `backend/tests/Feature/EmployeesApiTest.php`, `frontend/src/pages/employees.test.tsx`

**Interfaces:**
- Produces: `addPayChange` stores `[...in-force row on effective_from (salary, raise, insured_basic, daily_wage, shift_start, shift_end), ...sent fields]`.

- [ ] **Step 1: Failing test** `test_pay_change_keeps_unsent_fields`: office salary 9000, raise 500, insured basic 5000, shift 10:00–18:00; `POST pay-changes {effective_from: 2026-11-01, salary: 9500}` → the new row has raise `500.000000`, insured_basic `5000.000000`, shift `10:00`–`18:00`. Sending `raise: ""` explicitly stores 0 (blank means 0, as today).
- [ ] **Step 2: Failing UI test** `pay dialog opens with the current pay`: the salary, raise, insured basic and shift inputs show the current values.
- [ ] **Step 3: Implement** with `EffectiveDated::at` over the employee's pay rows; `array_key_exists` decides "sent".
- [ ] **Step 4: Run** backend filter + `cd frontend && npm test -- employees` → PASS; full gates (pint, phpstan, deptrac, lint, build).
- [ ] **Step 5: Commit** `Start a pay change from the pay in force (M2)`, push, open PR A, run `code-review`, merge per the owner's merge rule.

---

## PR B: payroll engines

Branch `claude/payroll-engines` from `main` after PR A merges.

### Task B1: read services Payroll needs

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeRoster.php` (`row` :201: add `pays`)
- Create: `backend/app/Modules/Sites/Application/AllowanceRules.php`
- Create: `backend/app/Modules/Payroll/Domain/Money.php`
- Test: `backend/tests/Unit/Payroll/MoneyTest.php`, `backend/tests/Feature/PayrollReadServicesTest.php`

**Interfaces:**
- Produces:
  - roster row key `pays: list<{effective_from: string, salary: ?string, raise: string, insured_basic: ?string, daily_wage: ?string}>`, sorted by date; read with `EffectiveDated::at($row['pays'], $date)`.
  - `AllowanceRules::inForce(array $siteIds, string $from, string $to): array<string, list<{effective_from, mode: AllowanceMode, value: string}>>` (one query for a period).
  - `Money::{add,sub,mul,div}(string $a, string $b): string` at scale 12; `Money::round6(string $a): string` half-up (negatives symmetric); `Money::sum(string ...$xs): string`.

- [ ] **Step 1: Failing tests:** `Money::round6('0.1234565') === '0.123457'`, `round6('-0.1234565') === '-0.123457'`, `div('9000','14400') === '0.625000000000'`; roster row of an employee with two pay rows returns both under `pays`; `inForce` returns the rule whose date is on or before each day.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Add the pay and allowance read services payroll needs`.

### Task B2: overtime from the shift end (question 20)

**Files:**
- Modify: `backend/app/Modules/Attendance/Domain/DayRules.php` (`workerMinutes`)
- Modify: `backend/app/Modules/Attendance/Application/EffectiveDays.php:170` (`hints` passes the scheduled out)
- Test: existing `DayRules` unit test and `AttendanceDaysTest`

**Interfaces:**
- Produces: `DayRules::workerMinutes(string $scheduledIn, string $scheduledOut, string $in, string $out): array{late: int, ot: int}`; late = max(0, in − scheduledIn) with no wrap; ot = max(0, out′ − scheduledOut′) where out′ = out + 1440 if out < in and scheduledOut′ = scheduledOut + 1440 if scheduledOut < scheduledIn.

- [ ] **Step 1: Failing tests:** (09:00, 16:00, 09:00, 17:30) → ot 90; (09:00, 16:00, 09:20, 15:00) → late 20, ot 0; (22:00, 06:00, 22:00, 07:00) → ot 60; (00:00, 08:00, 23:50, 08:00) → late 1430.
- [ ] **Step 2–4:** run, implement, run. Hints on the day screen now suggest OT from the shift end.
- [ ] **Step 5: Commit** `Start overtime at the shift end (client question 20)`.

### Task B3: day laborer engine

**Files:**
- Create: `backend/app/Modules/Payroll/Domain/{DayInput.php, Rates.php, Component.php, Line.php, DayLine.php, DayLaborerEngine.php}`
- Test: `backend/tests/Unit/Payroll/DayLaborerEngineTest.php`

**Interfaces:**
- Produces (shared by B4–B6):
  - `DayInput` readonly: `date`, `state` (`work|leave|missing|not_employed|open|future`), `leaveCode: ?string`, `siteId: ?string`, `in`, `out`, `scheduledIn`, `scheduledOut: ?string`, `lateMinutes`, `otMinutes`, `outsideMinutes: int`, `outsideAmount: ?string`, `transport: string`, `advance: ?string`, `wage: ?string` (monthly salary + raise, or daily wage, in force), `allowance: string`, `fare: string`.
  - `Rates` readonly from settings: `monthDays`, `dayHours`, `overtime`, `grace` (int), `multiplier` (for the type), `sickPercent`.
  - `Component` enum: `Salary, Wages, Allowance, Transport, Travel, Tower, Overtime, Outside, Bonus, Lateness, Absence, UnpaidLeave, SickCut, NotEmployed, Deduction, Negligence, InsuranceEmployee, IncomeTax, Advances, InsuranceCompany, InsuredBasic, Incentives, Net`.
  - `DayLine` readonly: `date`, `siteId`, `parts: array<string, string>` (component value → amount), `dayCost: string`, `flags: list<string>`.
  - `Line` readonly: `components: array<string, string>` (computed), `days: list<DayLine>`, `flags: list<string>`.
  - `DayLaborerEngine::compute(array $days /* list<DayInput> */, Rates $r, array $entry): Line`; `$entry` keys `bonus`, `deduction`.

- [ ] **Step 1: Failing tests** (`calculations.md` §4):
  - `test_a_worked_day`: wage 330, allowance 0, fare 35 full, ot 60, late 0 → day total `426.875000`; `Wages 330`, `Transport 35`, `Overtime 61.875`.
  - `test_lateness_after_grace`: late 20 → `Lateness 6.875000`; late 15 → 0.
  - `test_allowance_share`: allowance passed 82.5 (330 × 0.25) appears in `Allowance`.
  - `test_leave_and_absent_days_earn_nothing`: Friday and غ → all parts 0, no transport.
  - `test_missing_and_open_days_are_flagged`: missing → flag `missing`, nothing paid; open → wage paid, OT 0, flag `open_day`.
  - `test_early_departure_is_flagged_not_deducted` (R25): out 15:00 against 16:00 → flag `early_departure:60`, net unchanged.
  - `test_no_wage_on_a_worked_day` (Review Focus 2): `wage: null` → wage 0, flag `no_wage`.
  - `test_net`: Σ wage + Σ allowance + Σ transport + Σ OT + bonus − Σ lateness − Σ advances − deduction.
- [ ] **Step 2–4:** run, implement, run.
- [ ] **Step 5: Commit** `Add the day laborer payroll engine`.

### Task B4: monthly field engine (engineers, permanent workers)

**Files:**
- Create: `backend/app/Modules/Payroll/Domain/MonthlyFieldEngine.php`, `backend/app/Modules/Payroll/Domain/MonthlyBase.php` (salary, not-employed, absence, leave pay: shared with B5)
- Test: `backend/tests/Unit/Payroll/MonthlyFieldEngineTest.php`

**Interfaces:**
- Consumes: B3 types; `DayRules::workerMinutes` (B2).
- Produces: `MonthlyFieldEngine::compute(EmployeeType $type, array $days, Rates $r, array $entry): Line`; `$entry` keys `deduction`, `insurance_employee`, `insurance_company`, `income_tax`, plus `insured_basic` from the pay row. `MonthlyBase::salary(array $days, Rates $r): array{salary: string, not_employed: string}` and `MonthlyBase::leave(array $days, Rates $r): array{absence, unpaid, sick_cut: string}`.

- [ ] **Step 1: Failing tests** (S = 9000, minute rate 0.625):
  - `test_engineer_day_extra`: ot 60, late 25, outside 30 → day extra `65.625000`, day cost `365.625000`.
  - `test_engineer_transport_from_override_only`: fare 50 on two work days and a Friday → `Transport 100`; fare 0 → 0.
  - `test_permanent_worker_day`: scheduled 09:00–16:00, in 09:20, out 17:30, transport full, fare 40, outside amount 15 → `Lateness 6.25`, `Overtime 84.375` (+ `Outside 15`), `Transport 40`; half → 20.
  - `test_absence_deducts_a_day_rate` (R21): one غ → `Absence 300`.
  - `test_leave_pay`: annual and casual 0; unpaid → `UnpaidLeave 300`; sick at 75% → `SickCut 75`.
  - `test_salary_change_mid_period`: 9000 until day 10, 12000 from day 11 → `Salary 11000`.
  - `test_insured_basic_split`: insured basic 5000 → `InsuredBasic 5000`, `Incentives 4000`; net unchanged.
  - `test_net` per `calculations.md` §2 and §3.
- [ ] **Step 2–4:** run, implement, run.
- [ ] **Step 5: Commit** `Add the engineer and permanent worker payroll engine`.

### Task B5: office engine

**Files:**
- Create: `backend/app/Modules/Payroll/Domain/OfficeEngine.php`
- Test: `backend/tests/Unit/Payroll/OfficeEngineTest.php`

**Interfaces:**
- Consumes: `MonthlyBase` (B4).
- Produces: `OfficeEngine::compute(array $days, Rates $r, array $entry): Line`; per-day chargeable late minutes come from each day's in time against the shift (`DayRules::officeLateMinutes`, already net of grace); `$entry` keys `lateness_exemption_minutes`, `ot_minutes`, `travel_days`, `tower_days`, `bonus`, `negligence`, `advances`, insurance and tax.

- [ ] **Step 1: Failing tests** (`calculations.md` §1, S = 9000):
  - `test_lateness_absence_overtime`: Σ chargeable 60 → `Lateness 37.5`; 2 غ → `Absence 600`; `ot_minutes` 120 → `Overtime 112.5`; net `8475`.
  - `test_raise_is_paid` (Excel error #2): basic 9000 + raise 500 → `Salary 9500`.
  - `test_exemption_reduces_late_minutes`, `test_travel_and_tower`: 2 travel days → 600, 4 tower days → 300 (9000 ÷ 30 ÷ 4 × 4).
  - `test_hired_mid_month`: hired Oct 11 → `NotEmployed 3000`.
  - `test_leaver_negative_net` (Review Focus 5): terminated Oct 5 → `NotEmployed 7800` (26 × 300); advances 5000 → net `-3800`, flag `negative_net`, not clipped.
  - `test_not_employed_floors_the_salary_at_zero`: hired Oct 31 → `NotEmployed 9000`, salary part 0, never negative.
- [ ] **Step 2–4:** run, implement, run.
- [ ] **Step 5: Commit** `Add the office payroll engine`.

### Task B6: Payroll module, tables and the sheet service

**Files:**
- Create: `backend/database/migrations/2026_11_10_000001_create_payroll_tables.php` (`payroll_entries`, `payroll_overrides` per the spec's Data table; unique live `(employee_id, employee_type, period_start)` on entries)
- Create: `backend/database/migrations/2026_11_10_000002_add_payroll_permissions.php` (pattern: `2026_11_05_000001_add_fare_manage_permission.php`; `view.all` to every role holding `employee.view.all` (HR, CEO, developer; site managers hold only `.own`), `manage.all` to every role holding `site.manage.all` (HR, developer))
- Create: `backend/app/Modules/Payroll/Infrastructure/Eloquent/{PayrollEntry.php, PayrollOverride.php}` (`Audited`, `HasStandardColumns`)
- Create: `backend/app/Modules/Payroll/Application/{PayrollInputs.php, PayrollSheet.php}`
- Test: `backend/tests/Feature/PayrollSheetTest.php`

**Interfaces:**
- Consumes: `EmployeeRoster::allOfType`, `EffectiveDays::forPeriod(Period, array)`, `FareLookup::for`, `AllowanceRules::inForce`, `BusinessRules::all`, engines B3–B5.
- Produces: `PayrollSheet::sheet(Period $p): array{lines: list<array>, totals: array<string,string>, locked: bool}` and `PayrollSheet::employee(Period $p, string $employeeId): array` (line + days). Each line: `employee {id, code, name_ar, name_en}`, `components: array<string, {computed, override: ?{id, amount, reason, inputs_changed: bool}, final}>`, `entry`, `flags`. Final net = net override, else recomputed from final components.

- [ ] **Step 1: Failing tests:**
  - `test_sheet_lists_each_employee_of_the_group_with_totals`.
  - `test_type_change_at_boundary_splits_sheets` (Review Focus 1): engineer → day laborer on 2026-08-01: the engineer sheet 07-26..08-25 shows 07-26..07-31 worked and the rest `not_employed`; the day-laborer sheet 08-01..15 lists them.
  - `test_override_survives_and_warns` (Review Focus 4): override `Overtime` 100 with reason; change the day's OT → line shows `inputs_changed: true`, final 100; revert → final = computed, audit entry written.
  - `test_net_override_wins`.
  - `test_unlocked_period_is_marked`: `locked: false` before the attendance lock, `true` after.
- [ ] **Step 2–4:** run, implement, run (plus deptrac: Payroll Domain imports nothing but `Attendance\Domain\DayRules`, `Employees\Domain\EmployeeType` and its own classes).
- [ ] **Step 5: Commit** `Add the payroll sheet service and tables`.

### Task B7: payroll API

**Files:**
- Create: `backend/app/Modules/Payroll/Presentation/{PayrollController.php, PayrollRules.php}`
- Modify: `backend/routes/api.php` (prefix `payroll`)
- Modify: `backend/lang/{ar,en}/errors.php`
- Test: `backend/tests/Feature/PayrollApiTest.php`

**Interfaces:**
- Produces routes (spec "API and screen"): `GET payroll/{group}/{period_start}`, `GET payroll/{group}/{period_start}/employees/{employee}` (`permission:payroll.view`), `PUT …/entry {version, …fields}`, `POST …/overrides {component, amount, reason}`, `DELETE …/overrides/{override} {reason}` (`permission:payroll.manage`). `period_start` must be a real period start for the group (`Period::startingAt`), else 422.

- [ ] **Step 1: Failing tests:**
  - `test_site_manager_gets_403_everywhere`; `test_ceo_reads_but_cannot_write`.
  - `test_entry_fields_a_type_does_not_use_are_refused`: `insurance_employee` on a day laborer → 422; `travel_days` on an engineer → 422.
  - `test_stale_entry_version_conflicts` (Review Focus 3): two PUTs with the same version → second 409 `concurrency_conflict`.
  - `test_override_needs_a_reason`; `test_bad_period_start_is_422`.
- [ ] **Step 2–4:** run, implement, run.
- [ ] **Step 5: Commit** `Add the payroll API`.

### Task B8: payroll page

**Files:**
- Create: `frontend/src/pages/payroll.tsx`, `frontend/src/pages/payroll.test.tsx`
- Modify: `frontend/src/App.tsx` (route `/payroll`, nav item shown with `payroll.view`), `frontend/src/locales/{ar,en}.json` (`payroll.*`)

**Interfaces:**
- Consumes: B7 routes; the group and period pickers of `attendance-sheet.tsx`.

- [ ] **Step 1: Failing tests:** renders a row per line and a totals row; an overridden cell is highlighted and shows its reason on hover; editing an input cell sends `PUT …/entry` with the version; the override dialog requires a reason; a 409 shows the reload message; the side panel lists the days; the nav item is hidden without `payroll.view`.
- [ ] **Step 2–4:** run, implement, run `npm test && npm run lint && npm run build`.
- [ ] **Step 5: Capture** 375/1024/1440, ar/en, light/dark with `verify-hrms` on a throwaway DB, for the owner's approval.
- [ ] **Step 6: Commit** `Add the payroll page`.

### Task B9: October 2026 fixtures

**Files:**
- Create: `scripts/payroll-october-fixtures.php` (run once in the owner's checkout: `php scripts/payroll-october-fixtures.php <dir with the workbooks> <out dir> <name-map out path>`)
- Create: `backend/tests/Fixtures/payroll/2026-10/{field.json, day-laborers.json, office.json}`

**Interfaces:**
- Produces JSON: `{"period": {...}, "sites": [{"label", "outside_alexandria", "allowance": {"mode","value"}}], "people": [{"label", "type", "salary"|"daily_wage", "raise", "insured_basic", "days": [{"date","site","in","out","scheduled_in","scheduled_out","late","ot","outside_minutes","outside_amount","transport","advance"}], "excel": {"<component>": "<amount>", "net": "<amount>"}}]}`. The name map (label → name) is written outside the repository and never committed.

- [ ] **Step 1:** Read with OpenSpout (cached formula results). Labels follow `excel-system-analysis.md` (Engineer-N, Worker-N, Person-N). Day laborers: one wage per worker = the wage on most of their days; one allowance rule per site = the rule most worker-days there used (spec, my call).
- [ ] **Step 2: Verify** the extracted Excel totals: Σ field `excel.net` = 315,451 (rounded to the pound, as المجمع shows), Σ day-laborer `excel.net` = 391,948. Stop and report if either differs.
- [ ] **Step 3: Check** the fixture for names: `grep` every name in the map against the JSON → zero hits.
- [ ] **Step 4: Commit** the JSON and the script (no workbook, no name map) `Add anonymized October 2026 payroll fixtures`.

### Task B10: October regression and the Excel-difference report

**Files:**
- Create: `backend/tests/Feature/PayrollOctoberTest.php`, `backend/tests/Fixtures/payroll/2026-10/expected-differences.json`
- Create: `backend/app/Modules/Payroll/Presentation/Console/ExcelDifferencesCommand.php` (`payroll:excel-differences {--names=}`)
- Create: `docs/reports/2026-10-excel-differences.md` (generated)

**Interfaces:**
- Consumes: B9 fixtures; `PayrollSheet` (B6), `OfficeEngine` (B5).
- Produces: `expected-differences.json` = `list<{label, component, amount, reason}>` with `reason` one of: `excel_2_raise`, `excel_4_lateness_sign`, `excel_5_other_sheet`, `excel_6_bonus_in_minutes`, `excel_7_pasted_values`, `excel_10_short_subtotal`, `excel_14_other_sheet`, `grace_field`, `lateness_minutes`, `ot_shift_end`, `absence_monthly_field`, `transport_permanent`, `per_worker_table`.

- [ ] **Step 1: Failing test** `test_field_october`: seed `field.json` (sites, employees, pay rows, days via the models; lock the period), compute both sheets, compare per person and component with `excel`; each difference must equal an entry; leftover entries fail; Σ system net = 315,451 + Σ field differences.
- [ ] **Step 2:** same for `test_day_laborers_october` (391,948) and `test_office_october` at engine level from `office.json` totals.
- [ ] **Step 3: Run** → FAIL listing every unexplained difference. Trace each to the Excel cell (`excel-system-analysis.md` §6) or a decision; add it to `expected-differences.json` with its reason. A difference with no reason is an engine bug: fix the engine, never the fixture.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5:** `payroll:excel-differences` renders the Arabic report (per workbook: totals, then one row per difference: label, component, Excel, system, difference, reason in Arabic) to `docs/reports/2026-10-excel-differences.md`; with `--names=<map>` it writes the client copy with names to a path outside the repository.
- [ ] **Step 6: Commit** `Add the October 2026 payroll regression and Excel-difference report`.

### Task B11: docs and PR

**Files:**
- Modify: `CONTEXT.md` (Payroll entry, Payroll override, Payroll sheet), `docs/calculations.md` (any rule the build made precise), `docs/hrms-module-plan.md` (item 2 status)

- [ ] **Step 1:** Update the docs; full gates (backend test, pint, phpstan, deptrac; frontend test, lint, build).
- [ ] **Step 2:** `code-review` and `security-review` (permissions), fix findings.
- [ ] **Step 3:** Push, open PR B with the October differences summarised, report to the owner in Arabic with the screenshots and the report path.
