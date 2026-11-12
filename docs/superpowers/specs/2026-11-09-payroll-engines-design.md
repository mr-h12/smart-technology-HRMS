# Payroll engines (Release 2 item 2)

Date: 2026-11-09. Status: approved by the owner on 2026-11-09. Amended 2026-11-12 to what was built; each amendment says so.
Formulas: `docs/calculations.md` §0–§6, §10 (the spec for every number; this document does not restate them).
Owner decisions behind it: `docs/hrms-module-plan.md` § Payroll engines: owner decisions of 2026-11-09.

## Why

HR runs pay today on three hand-built workbooks with known errors (`docs/excel-system-analysis.md` §6). Release 2 replaces
the arithmetic: the system computes every employee's pay for a period from the attendance HR and the site managers already
record, and HR corrects any number with a reason. The October 2026 workbooks are the acceptance test: the engines must give
the Excel's result for every employee, except where a documented Excel error or an owner decision says otherwise, and the
client signs that list of differences once before go-live.

## Goals

1. Three engines, one per pay basis, each a pure function of its inputs:
   - **office** (calendar month), `calculations.md` §1;
   - **monthly field**: engineers §2 and permanent workers §3 (26th → 25th);
   - **day laborers** §4 (1–15, 16 → end).
2. A payroll sheet per (group, period) for HR: one line per employee with every component, the net and the flags,
   plus a per-day breakdown for one employee.
3. HR inputs per employee per period (bonus, deduction with reason, office negligence, office lateness exemption,
   office travel and tower days, insurance employee and company share, income tax), typed on the sheet.
4. HR override of any computed component, with a reason; it survives recomputation, warns when its inputs changed and can
   be reverted (`calculations.md`, "Applies everywhere").
5. October 2026 regression tests for the three workbooks, and the Arabic Excel-difference report generated from them.

## Non-goals (later Release 2 items, unchanged)

- Payroll period close, CEO approval, freeze and reopen (item 5). Until then the sheet is computed on read and nothing is
  frozen; the attendance lock still freezes the days it reads.
- The advances ledger with installments (item 3). Until it exists, the advance deduction is the sum of the advances logged on
  the period's attendance days, deducted in full, which is what all three October workbooks do.
- Payout marks (item 6), payslip PDF (item 7), the historical archive (item 8), the fingerprint import (item 1, on hold),
  site-costing screens (§8: each engine returns a day cost, nothing more), end-of-service and leaver settlement screens.
- R17 (who receives what at close), R28 (CEO approval detail) and R29 (go-live, opening balances): they belong to items 5 and
  8.

## Anti-goals

- A number HR cannot trace. Every component on the sheet opens to the days and the formula that made it.
- Silent drift from the Excel. A difference from October that no documented reason explains fails the build.
- Money as floats, intermediate rounding, or business numbers in code: every rate comes from settings in force on the day.
- Site managers seeing any pay. Payroll is HR's and the CEO's only.

## Before any engine code: fix M1 and M2 (owner, 2026-11-09)

Payroll reads the dated employee records, so the two review findings are fixed first, in their own PR
(`docs/reviews/2026-11-08-release-1-review.md`):

- **M1:** every dated change (type, pay, home site, engineer's manager, site allowance rule, termination date) whose
  effective date falls in a locked attendance period of the old or new type is refused, with the same guard fares already use.
  A type change must start on the first day of a period of both the old and the new type (the 1st of a month always does for
  office and day laborers; engineers and permanent workers start on the 26th, so monthly field ↔ other types can only change
  on a date that is a boundary for both: ⚠️ see "Open points"). Residence stays undated (the review's open sub-question is
  not part of this decision).
- **M2:** a new pay row starts from the row in force on its effective date and applies only the fields sent; the change
  dialog opens filled from the current pay.

## Shape

A new module `Payroll` (`Domain / Application / Infrastructure / Presentation`; deptrac enforces the layers). It reads
other modules only through their Application read services, as Attendance reads Employees through `EmployeeRoster`
(plain arrays, "so other modules never query Employees' tables"); no other module reads Payroll.

Two read services are missing and are added by their owning modules, in the same style:
- **Employees:** the pay rows on `EmployeeRoster` rows (`salary`, `raise`, `insured_basic`, `daily_wage` with
  `effective_from`, as `shifts` already are), read in force on a date with `EffectiveDated::at`.
- **Sites:** the allowance rule in force on a date for a site (`site_allowance_rules`), returning mode and value for
  `Allowance::for`. Fares already have `FareLookup::for(employee, site, date)`.

```
Attendance days ─┐
Employee history ├─► PayrollInputs (per employee, per period) ─► Engine (pure, per type) ─► PayrollLine + DayLines
Sites, fares     │                                                                          │
Settings by date ┘   payroll_entries (HR inputs)  ─────────────────────────────────────────►│
                     payroll_overrides ──────────────────────────────────────────────────── applied last, flagged
```

- **Inputs assembler** (Application): for one group and period (`Attendance\Domain\Period`, one group per employee type, so
  engineers and permanent workers are two sheets on the same dates), loads every employee of that type on any day of the
  period (`EmployeeRoster::allOfType`), their days through `EffectiveDays::forPeriod` (stored, derived, missing,
  not employed: exactly what the attendance sheet shows), the dated records in force on each day, the settings
  (`BusinessRules::all()`), and the HR entries. It is the only part that touches the database.
- **Engines** (Domain): `OfficeEngine`, `MonthlyFieldEngine` (engineer and permanent-worker day rules differ, the net shape
  is the same), `DayLaborerEngine`. Pure PHP, bcmath strings, no Laravel. Each returns per-day lines (with the day cost) and
  per-employee components. bcmath runs at scale 12 inside the engine and each returned amount is rounded half-up to 6
  decimals (the `NUMERIC(18,6)` scale; display is 2). My call: the codebase has no decimal arithmetic class
  (`App\Support\Money\Decimal` only validates) and bcmath truncates, so a small helper with `add/sub/mul/div/round6` lives
  in Payroll's Domain; it moves to `App\Support\Money` only when a second module needs it.
- **Settings are not dated in the code** (`settings(key, value)`, no effective date, although `calculations.md` calls them
  dated): the engine reads the current values. Item 5 freezes them with the result at close, which is what makes a closed
  period immune to later setting changes.
- **Computed on read** while payroll close does not exist: no stored results to go stale. Item 5 stores the result at close.
- **Open attendance periods** can be computed (HR previews mid-period), using derived days as the sheet does. The sheet
  shows "attendance not locked" until the attendance lock exists. The lock stores the derived days (weekly off, holidays);
  missing and future days stay unstored (amended 2026-11-12).

## Data (new tables)

| Table | Purpose | Key columns |
|---|---|---|
| `payroll_entries` | HR's per-period inputs | employee, group, period_start; bonus, deduction, deduction_reason, negligence, lateness_exemption_minutes, travel_days, tower_days, ot_minutes, advances, insurance_employee, insurance_company, income_tax (all nullable `NUMERIC(18,6)` or integer minutes/days; blank = 0); note; version |
| `payroll_overrides` | HR's replacement of one computed component | employee, group, period_start, component, amount, reason (required), computed_at_override, created_by; soft delete = revert, with a required `revert_reason` |

- One row per (employee, group, period_start), created on HR's first edit. Fields a type does not use are refused for that
  type (insurance and tax for day laborers; negligence, exemption, travel and tower days, `ot_minutes` and `advances`
  outside office).
  Amended 2026-11-12, as built: also bonus for engineers and permanent workers, and `deduction` with its reason for office,
  whose net has the negligence deduction and no general one (`calculations.md` §1). A value in a field the type does not use,
  or in an unknown key, is a 422, never ignored (null passes); an array or object in any field is a 422.
- One live override per (employee, group, period_start, component), a partial unique index (amended 2026-11-12): a second
  POST is a 409; HR changes an override by reverting it and overriding again, two audited writes.
- **Office overtime and advances live on the entry** (my call). An office day stores only in and out times
  (`DayRules::FIELDS['office']`), and the Excel types both as period totals (columns W and M). HR types the period's
  approved OT minutes and advances on the entry; the fingerprint import (item 1) will later suggest the OT minutes, and the
  ledger (item 3) replaces `advances` for every type. Office lateness is computed per day from the stored in time
  (`calculations.md` §1), less the exemption.
- `computed_at_override` drives the warning: when today's computed value differs from it, the line shows "inputs changed
  after your edit".
- Both tables are audited (before/after) and carry a `version` (stale write → `409 concurrency_conflict`), like attendance
  days.

## Components (the line)

Every line carries the components its type uses, each with: computed value, override (if any), final value.

| Component | Office | Engineer | Permanent worker | Day laborer |
|---|---|---|---|---|
| salary / Σ daily wage | ✓ | ✓ | ✓ | ✓ |
| insured basic, incentives (display split) | ✓ | ✓ | ✓ | |
| allowance (site rule) | | | | ✓ |
| transport | | ✓ fare override, full, per work day | ✓ full/half per day | ✓ |
| travel, tower (office days) | ✓ | | | |
| overtime | ✓ | ✓ (day extra) | ✓ | ✓ |
| outside-Alexandria | | ✓ minutes | ✓ money | |
| bonus | ✓ | | | ✓ |
| lateness | ✓ | ✓ | ✓ | ✓ |
| absence, unpaid leave, sick-pay cut, not-employed days | ✓ | ✓ | ✓ | (no wage that day) |
| deduction (office: negligence, amended 2026-11-12) | ✓ | ✓ | ✓ | ✓ |
| insurance employee, income tax | ✓ | ✓ | ✓ | |
| insurance company (cost, not in net) | ✓ | ✓ | ✓ | |
| advances | ✓ | ✓ | ✓ | ✓ |
| net | ✓ | ✓ | ✓ | ✓ |

Net formulas: `calculations.md` §1–§4. Totals per column at the foot of the sheet (§9).

## Flags (shown, never deducted on their own)

Missing days, open days (arrival without departure), early-departure minutes (R25), days with no wage or no fare found,
leave days on a holiday, an override whose inputs changed, and imported history rows carrying an Excel-error warning.
Amended 2026-11-12, as built: this build does not raise the imported-history warning (it waits for the historical
archive, module plan Release 2 item 8); it adds `negative_net` and `leave_to_check`. "Leave days on a holiday" is the
line flag `leave_on_holiday`: a stored annual, casual, sick or unpaid day on an official holiday, the attendance lock's
own list (`calculations.md` §2a); an absence (غ) is not flagged (owner question). The flags as built are listed under
"API and screen".

## Rules the engines follow (decided)

- Settings and dated records are read **per day** (a wage or salary change splits the period at its date).
- Lateness: §0a, grace once per day, multiplier per type. No wrap around midnight (question 14).
- Overtime: stored approved minutes for office, engineers and day laborers; permanent workers from actual out − scheduled out
  (question 20).
- Absence: غ days × day rate for every monthly type (R21); day laborers earn nothing that day.
- Engineers' transport: the per-worker fare override in force for the day's site, in full, on every day worked at a work
  site; nothing on a leave day, and nothing without an override (owner, 2026-11-09). October has no engineer override, so
  it moves no October number.
- Not-employed days and salary changes, one rule (`calculations.md` §10): the salary pays 30 day rates for a full period;
  each not-employed day (before hire, after termination), counted in calendar days, deducts one day rate, floored so the
  salary part is never below 0. A salary change works the same way: the days before the change, in calendar days, are paid
  at the old salary ÷ 30, and the rest of the 30 at the new one.
- Early departure: flagged only (R25).
- Leave pay: §7 "Leave pay" (annual and casual paid, sick at `sick_leave_pay_percent`, unpaid deducts a day rate).
  `rest` and `other` (the two remaining leave codes in `LeaveCode`) are paid for monthly staff and flagged for HR; my call,
  since no rule names them.
- Weekly off and holidays: paid for monthly staff; no wage for day laborers.
- No rounding anywhere, the net included (question 12). Display 2 decimals.

## API and screen

- `GET /api/v1/payroll/{group}/{period_start}` → lines with components, flags and column totals.
- `GET /api/v1/payroll/{group}/{period_start}/employees/{employee}` → the line plus the per-day breakdown.
- `PUT /api/v1/payroll/{group}/{period_start}/employees/{employee}/entry` → HR inputs (versioned).
- `POST …/overrides` (component, amount, reason) and `DELETE …/overrides/{id}` (revert, reason).
- Permissions (data, CRM style): `payroll.view.all` (HR, CEO, developer), `payroll.manage.all` (HR, developer). No
  `.own` scope: site managers get 403.
- Frontend: «المرتبات» page with the group and period pickers the attendance pages already use; a table in the shape of the
  Excel المجمع (one row per employee, a column per component, totals row), editable input cells, a highlighted overridden
  cell with its reason on hover, and a side panel with the per-day breakdown. Arabic and English keys.
  Amended 2026-11-12: inputs and overrides stay editable while the period's attendance is locked. The attendance lock
  freezes the days; payroll close (item 5), not in this build, is what makes a period read-only.

**Requests and responses, as built (amended 2026-11-12).** Paths are under `/api/v1/payroll/{group}/{period_start}`;
`group` is an employee type and `period_start` the first day of one of its periods, else 422 on that field (as the
attendance pages). Every answer uses the response envelope; money is a decimal string with 6 decimals.

| Method, path | Permission | Body | Success | Errors besides 401/403 |
|---|---|---|---|---|
| `GET /` | `payroll.view` | | 200 `{lines, totals, locked}` | |
| `GET /employees/{employee}` | `payroll.view` | | 200 the line + `days` | 404 |
| `PUT /employees/{employee}/entry` | `payroll.manage` | `{version, …the type's fields, note}` | 200 the line + `days` | 404; 422 per field; 409 `concurrency_conflict` |
| `POST /employees/{employee}/overrides` | `payroll.manage` | `{component, amount, reason}` | 201 the line + `days` | 404; 422 per field; 409 `concurrency_conflict` (a live override exists) |
| `DELETE /employees/{employee}/overrides/{override}` | `payroll.manage` | `{reason}` | 200 the line + `days` | 404 (no such live override); 422 `reason` |

- 404: `{employee}` or `{override}` is not a UUID, or the employee holds the group's type on no day of the period. The body
  is validated first, so a bad body for such an employee is a 422.
- A line: `employee {id, code, name_ar, name_en}`; `components`, each `{computed, override, final}` with `override`
  `{id, amount, reason, inputs_changed}` or null; `entry` (`version` and every entry field, null when blank); `flags`.
  `days`, one per calendar day of the period in date order: `{date, state, leave_code, site_id, parts, day_cost, flags}`,
  `state` one of `work`, `leave`, `missing`, `not_employed`, `open`, `future`. `totals`: per component, the sum of the
  final values. `locked`: whether the period's attendance is locked.
- Flags: `missing`, `open_day`, `early_departure`, `no_wage`, `no_fare`, `leave_to_check`, `leave_on_holiday` (line
  only), `negative_net`, `inputs_changed`. A day's flag may carry a value (`early_departure:<minutes>`, `leave_to_check:<code>`); a line's carries
  the name only.
- The sheet GET is not paginated: one sheet is one document with a totals row, as `GET /api/v1/attendance/sheet`.
- Entry body, PUT semantics: a field left out is cleared. `version` is the entry's as read, null while it has none; a stale
  one is a 409. Amounts are decimal strings ≥ 0 with at most 6 decimals; minutes and days JSON integers ≥ 0; `note` and
  `deduction_reason` at most 500 characters; a non-zero `deduction` needs a `deduction_reason`. Fields per type, besides
  `note`: office `bonus, negligence, lateness_exemption_minutes, travel_days, tower_days, ot_minutes, advances,
  insurance_employee, insurance_company, income_tax`; engineers and permanent workers `deduction, deduction_reason,
  insurance_employee, insurance_company, income_tax`; day laborers `bonus, deduction, deduction_reason`.
- Override body: `component` is one of the line's components (else 422) and not one of the type's entry fields above
  (422 too: HR changes those on the entry, where the page shows them); `amount` a decimal string ≥ 0, negative only for
  `net` (only a net override may be negative: every other component is an amount the net formula adds or deducts);
  `reason` is required. A net override replaces the net; any other moves the final net by its change. Revert body:
  `reason`, required.

## October 2026 regression and the Excel-difference report

- **Fixtures:** a one-off local PHP script (OpenSpout, already installed, reading the cached formula results) reads the three October workbooks (in the owner's checkout, never committed) and
  writes anonymized JSON under `backend/tests/Fixtures/payroll/2026-10/`: per person a label (Engineer-N, Worker-N,
  Person-N as in the analysis doc), type, salary or wage, raise, insured basic, the daily rows (site label, times, minutes,
  advances) and the Excel's own per-person results (the المجمع columns). No names, national IDs or phone numbers.
  The label → name map stays in the owner's checkout.
  Amended 2026-11-12, as built: every المجمع row is kept, and each field and day-laborer person carries `in_excel_total`
  (provisional, client question 22) (the office fixture has no such key; the test counts it as true), because the Excel totals are SUBTOTAL(9) under an
  AutoFilter that hides rows, three of them paid (analysis §6 #17). Work sites are labels `Site-N`; leave day-types keep the
  Excel's Arabic name, mapped to a `leave_code`. The real site names go to the map.
- **Test:** seeds the fixture, runs the engines for engineers + permanent workers (26 Sep → 25 Oct) and day laborers
  (1–15 Oct) through the API (amended 2026-11-12: it reads each sheet through `GET /api/v1/payroll/{group}/{period_start}`),
  and compares each person's components with the Excel's. Every difference must match a
  line in `expected-differences.json` (label, component, amount, reason code). An unexplained difference, or an expected one
  that no longer occurs, fails. The fixtures are never edited to make a test pass.
- **Targets:** engineers + permanent workers net **315,451**, day laborers net **391,948** (1–15 Oct). The test asserts:
  system total = Excel total + Σ expected differences, per workbook. These two are the build-failing gates (`CLAUDE.md`).
  The Excel total is Σ net over the rows with `in_excel_total` (amended 2026-11-12).
- **Office** (my call): the office workbook holds only per-person totals (late minutes already net of grace, R18, and
  taken from a 26 Sep → 25 Oct fingerprint sheet, not calendar October), so it has no days to seed. The office engine takes
  period totals from the assembler, and its regression runs at engine level on the workbook's totals, with the same
  expected-differences file (#2 raise). It is a test, not a named fixture gate. Amended 2026-11-12: the office fixture
  holds only those totals, no fingerprint days.
- **Day-laborer wage and allowance in the fixture** (my call): each worker gets the daily wage on most of their October
  days as one pay row, and each site gets the allowance rule that most worker-days there used. Every day priced otherwise
  in the Excel (a per-worker tier table, a ×2 / ×3 Nestlé table) is an expected difference with reason "per-worker table"
  (analysis §8), listed per worker, so the client sees exactly which days moved and why.
- **Reason codes:** each Excel error from analysis §6 that touches money (#2 raise, #4 sign of lateness, #5 and #14 rows
  reading another person's sheet, #6 bonus typed as minutes, #7 pasted values, #10 short SUBTOTAL), and each decision that
  moves money: 15-minute grace for field types (§0a), permanent-worker lateness in minutes (§3), overtime from the shift end
  (question 20), absence for monthly field types (R21), permanent-worker transport (§3), per-worker wage or allowance tables
  that differ from the system's one wage and site rule (analysis §8). Amended 2026-11-12, as built: also
  `excel_filtered_row` (provisional, client question 22: a row the AutoFilter hides: the system pays it, the Excel total
  leaves it out),
  `stored_amount_six_decimals` (a typed day amount stored at 6 decimals, `calculations.md` "Applies everywhere"), and one
  code for each Excel error the build found (analysis §6 #18–#24).
- **Report:** a command renders the comparison as an Arabic Markdown document, `docs/reports/2026-10-excel-differences.md`,
  with labels (committed). Run in the owner's checkout with the name map, it writes the client copy with names outside the
  repository; that copy is what the client signs (question 13).

## Open points

- ⚠️ **Type change boundary across cycles (M1).** Engineers and permanent workers run 26th → 25th; office and day laborers
  run from the 1st. A change between a monthly field type and another type has no date that starts a period of both. Default
  until the owner answers: the change starts at the new type's period start, and the old type's last period is cut short at
  the day before (its salary pro-rated by the not-employed rule of §10). Asked in the PR.
- Questions still open with the client that touch pay but have working defaults: R18 (raw late minutes; built as raw),
  question 8 (sick pay 100%), question 15 (end of service, Release 3).

## Left to the builder

Class and method names, the per-day breakdown's exact columns, the fixture JSON layout, and the table's column order and
widths, within `docs/coding-standards.md`.
