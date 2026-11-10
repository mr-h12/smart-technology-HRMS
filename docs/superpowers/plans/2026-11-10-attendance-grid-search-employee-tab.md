# Attendance: grid name search + the employee's period table

Owner request, 2026-11-10: «عايز search بالاسم في جدول الفتره و عايز داخل شاشه كل موظف شاشه نتيجه فيها جدول حضور الشهر بتاعو كلو».

## Owner decisions (2026-11-10)

1. The employee's table follows **the employee's own payroll period** (office: calendar month; engineer and permanent worker: 26th→25th; day laborer: 1–15, 16–end).
2. A click on a day **opens the existing day editor** (`DayEditor`): HR edits there, a user without `attendance.edit` reads only, a locked period stays read-only.
3. A summary above the table shows **day counts only**. No minute totals, so nothing can disagree with payroll later.

## Planner's calls (not asked)

- «جدول الفترة» is the grid tab of the attendance page (`attendance.tab.grid`). The day sheet already has its own search and is not touched.
- The search matches what the employees list matches: either name (Arabic or English, part of it) or the exact code. It reuses `Employee::matching()`.
- The new tab shows to a holder of `attendance.view` (today: Developer, HR, CEO, all-scope). Site managers hold only `attendance.record.own`, so they do not get the tab; no permission is added or changed. The day carries no wage.
- The period position in the tab is local state (as the leave tab's year), not a URL parameter.

## 1. Grid search

Backend
- `GET /attendance/grid` takes `search` (`sometimes, nullable, string, max:100`).
- `EmployeeRoster::ofType()` gets a trailing `?string $search = null` and applies `matching($search)` when it is not blank. It combines with `site_id`, `group=all` and the own-scope narrowing. Pagination totals count the matches.

Frontend (`pages/attendance.tsx`)
- A `SearchInput` in the bar, on the grid tab only. URL state `?q=`, the box instant and the request debounced 250 ms (the employees list pattern: `useDebounced`).
- `Grid` takes `search`; it is part of its `key`, so the page returns to 1.
- No match → the existing `attendance.sheet.noMatch` empty state.
- Changing the group keeps the search; nothing else changes.

## 2. Employee period table

Backend
- `GET /attendance/employees/{employee}/period?date=YYYY-MM-DD` (`date` optional, default today in Cairo), middleware `permission:attendance.view` + `employee.scope`, `whereUuid('employee')`.
- The type is the employee's type on `date`; a date outside the employment is clamped to the hire date, the first type's start, or the termination date. The period is `Period::containing(type, date)`.
- Answer (one envelope `single`):
  - `period`: `group, start, end, prev_date, next_date, is_locked`. `prev_date` / `next_date` are the days just outside the period's employed days, passed back as `?date=`: a period start would skip days across a type change (a laborer who becomes an engineer on the 26th has 1st→25th days no engineer period start reaches). `prev_date` is null before the employment; `next_date` is null after today or after termination.
  - `fields`: `DayRules::FIELDS[group]`.
  - `days`: one per date from `EffectiveDays::forPeriod()` (state, source, version, site_id, leave_code and the day values), each with its `hints`.
  - `summary`: counts over the period's days, computed on the server:
    - `worked`: a stored day with no leave code;
    - `absent`: leave code `absent`;
    - `leave`: leave code `sick`, `annual`, `casual`, `unpaid` or `other`;
    - `off`: leave code `friday`, `saturday`, `official_holiday` or `rest` (stored or derived);
    - `missing`: state `missing`.
    Future and not-employed days are in no count.
- The logic lives in the Attendance Application layer (a method beside the grid's); the controller only validates and calls it.
- Do not edit the lines the holiday-periods branch touches (`OfficialHolidays.php`, the two holiday-reading lines of `EffectiveDays.php`, `AttendanceLocks.php`, `HolidayController.php`).

Frontend
- New `pages/employee-attendance.tsx`, tab `attendance` (label «الحضور») in the employee page's `TABS`, shown when `can('attendance.view')`.
- Header: previous / next period arrows, the period range, the locked badge (existing `attendance.prevPeriod`, `nextPeriod`, `periodRange`, `locked` keys).
- Summary: the five counts in a `StatRow`.
- Table, one row per day: weekday + date; site or day type (site name, the full leave name, or the missing / future / not-employed state); then one column per entry of `fields` with the existing `attendance.day.*` labels. Office adds a late column from `hints.late_minutes`. A minutes cell shows the stored value, else the hint. Amounts show 2 decimals; `transport` shows its `attendance.transport.*` label.
- Today's row, off days and missing days use the grid's looks (`OFF`, the warning tint).
- A row opens `DayEditor` for that date (not for future or not-employed days). The table refreshes after a save.
- Arabic RTL first; usable at 375 px in the project's existing table pattern.

## i18n

New keys in both `ar.json` and `en.json`, under `attendance` and `employee.tab` only (the holiday branch edits `holiday.*`): `employee.tab.attendance`, the grid search placeholder, the five summary labels, the day and late column heads if no existing key fits.

## Tests

- PHPUnit: grid `search` (name part, code, with `site_id`, totals, own scope still narrowed); the employee period for each type's period, a type change, a terminated employee's clamp, the summary counts, `403` without `attendance.view`, the `employee.scope` refusal.
- Vitest: the grid sends `search` and keeps `?q=`; the employee tab shows only with `attendance.view`, renders the days, moves periods, opens the day editor; locale parity.

## Verify

`npm run test`, `npm run lint`, `npm run build`; pint, phpstan, deptrac, `php artisan test`; then a `verify-hrms` capture: the grid filtered by a typed name, and an employee's period table, Arabic, desktop and 375 px.

## Out of scope

Minute and money totals, export or print of the table, a manager view of it, search on the import tab.
