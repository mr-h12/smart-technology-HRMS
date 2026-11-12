# Hide archived employees Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An archived employee (termination date before today in Cairo) is in no list, search, picker, count or alert by default; only all-scope users reach them, on a read-only page; HR finishes their last days through one grid switch; locked periods and payroll inputs are unchanged.

**Architecture:** One definition on the `Employee` model (an `archived` scope for SQL, `Employee::isArchived()` for PHP). Sets of employees route through three places: `EmployeeService::visibleTo` (employees list), `EmployeeRoster` (excludes archived by default, `withArchived()` opts in) and `EmployeeReach::apply` (a site manager never reaches an archived employee). By-id writes are guarded once, in the `employee.scope` middleware; the profile PATCH is judged on changed values in `EmployeeService::update`, which also refuses a termination date while items are open (Leave contributes its open requests through an event wired in `AppServiceProvider`). The frontend reads a server `archived` field.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17, PHPUnit 12; React 19 + Vite + TS, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-11-hide-archived-employees-design.md` (approved by the owner; read it first, this plan does not repeat its reasons).

## Deviations from the design

1. **Leave preview/submit by a site manager** answers the existing reach refusal `site_not_in_reach` (422), not `employee_archived`. `LeaveRequests::check` tests reach before anything that describes the employee (its own rule, `LeaveRequests.php:356-360`), so a manager's answer never confirms that an id is an archived employee. HR and the Developer get `employee_archived` (409) as the design says. The design's table names `employee_archived` without a role split.

Nothing else in the design is unbuildable. Decisions the design left to the builder are taken below under "Planner's calls".

## Planner's calls (left open by the design)

- Shared definition: `#[Scope] archived(bool $archived = true)` and `static isArchived(?string $terminationDate): bool` on `Employee`.
- Roster opt-in: `EmployeeRoster::withArchived(bool $include = true): self` returns a clone; every set-returning method reads its flag. No signature of an existing method changes.
- Write-guard exemptions: a middleware parameter, `employee.scope:archived_ok`, on the four routes that judge an archived employee themselves (`PATCH employees/{id}`, `DELETE employees/{id}`, `PUT` and `DELETE attendance/days/{id}/{date}`).
- `EmployeeService::update` runs in a transaction, and Leave's `openRequests()` takes the existing `LeaveRequestLock`, so a request filed at the same moment as the termination save is serialised (same lock `submit` takes).
- Error envelope: `employee_archived` 409 with a message only; `employee_has_open_items` 409 with `error.items`, a list of `{kind: 'leave_request', id, status, from_date, to_date}` and `{kind: 'wage_proposal', id}` (the pattern of `error.dates`).
- Permission names (from the seeds): all-scope viewer = `employee.view.all`; attendance editor at all scope = `attendance.edit.all`.
- The bell rule sits where every line is built (`view()`): any notification whose `data.employee_id` is set and whose `employee` is null gets no link. Besides the manager's leave lines this also unlinks HR's `wage_proposed` and `attendance_past_edit` lines of a deleted employee (today they link).
- The archived employee's attendance calendar opens the day editor in view-only mode with its own note (`attendance.day.archivedNote`), instead of the "no permission" note.
- `leave.tsx:199` («ترك العمل في» on a balances row) is left as is: the list no longer holds archived rows, and its test still passes.

## Global Constraints

- Archived = `termination_date < today` in `Africa/Cairo`; the termination day itself is still worked. No archive flag, endpoint, permission, migration or new dependency.
- Error codes: `employee_archived` (409), `employee_has_open_items` (409). The delete veto keeps `leave_employee_has_open_requests`.
- `with_archived=1` is honoured only for a caller holding `attendance.edit` at `all` scope; otherwise ignored, never an error.
- Archive switch: `employee.view.all`. Grid switch: `attendance.edit.all`. Check permissions by name, never by role.
- Fixed copy (Arabic): «إظهار من تركوا العمل» (grid switch), «ترك العمل» (row mark), «مؤرشف — ترك العمل في {date}» (banner), «تصحيح بيانات الترك» (correction action). Every new string is an i18n key in `frontend/src/locales/ar.json` and `en.json`; backend messages in `backend/lang/{ar,en}/errors.php`.
- Payroll inputs are untouched: the lock, its missing-days confirmation and its derived days still include archived employees employed in the period. Old notifications and the audit log are not changed.
- Owner rules: never use the `dsh` skill; never write to the dev database `hrms` and never run tests in `hrms-app-1`; never commit `CLAUDE_HANDOFF.md`; nothing is pushed, merged or posted without the owner's yes; questions and end-of-slice reports to the owner are in Arabic.
- Each builder loads `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development` and reads `docs/coding-standards.md`; frontend tasks also load `ui-ux-pro-max:ui-ux-pro-max`. Test first, every task.
- Slices: Slice 1 (Tasks 1-9) backend; Slice 2 (Tasks 10-15) and Slice 3 (Tasks 16-18) frontend. Slices 2 and 3 each end at an owner screenshot gate. One branch (`claude/hide-archived-employees-08a8d9`), one PR into `main`, no deploy.

## How to run

Worktree root: `/Users/yousefhasabo/HRMS-Smart TECH/.claude/worktrees/hide-archived-employees-08a8d9` (the path has a space: keep the quotes). The running stack (`hrms-app-1`, `hrms-postgres-1`, …, image `hrms-php`, network `hrms_default`) mounts the main checkout and serves the dev database `hrms`; leave it alone. The shared `hrms_test` in `hrms-postgres-1` may be in use by another session, and `backend/tests/TestCase.php:19` refuses any database not named `hrms_test`, so tests run against a throwaway Postgres of our own, on a network of its own (a wrong `DB_HOST` then fails to resolve instead of reaching the shared one).

Once per session (builders may start these; the planner did not):

```sh
docker network create hrms-archived-net
docker run -d --name hrms-archived-pg --network hrms-archived-net \
  -e POSTGRES_USER=hrms -e POSTGRES_PASSWORD=throwaway -e POSTGRES_DB=hrms_test -e TZ=UTC -e PGTZ=UTC \
  postgres:17.5-bookworm
```

`RUN` below stands for this prefix (shell state does not persist between tool calls, so paste it whole each time):

```sh
docker run --rm --network hrms-archived-net \
  -v "/Users/yousefhasabo/HRMS-Smart TECH/.claude/worktrees/hide-archived-employees-08a8d9/backend":/var/www/html -w /var/www/html \
  -e DB_HOST=hrms-archived-pg -e DB_PORT=5432 -e DB_USERNAME=hrms -e DB_PASSWORD=throwaway \
  -e APP_KEY="base64:$(openssl rand -base64 32)" hrms-php
```

- First: `RUN composer install --no-interaction` (the worktree has no `vendor/`; it is git-ignored). No `backend/.env` is needed: `phpunit.xml` forces `DB_DATABASE=hrms_test`, and container env wins.
- One test class: `RUN php artisan test --filter=ArchivedEmployeesTest`; one test: `RUN php artisan test --filter='ArchivedEmployeesTest::test_name'`.
- Backend gates: `RUN php artisan test`, `RUN vendor/bin/pint --test`, `RUN vendor/bin/phpstan analyse` (add `--memory-limit=1G` if it runs out), `RUN vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`.
- Frontend, in `frontend/` (no `node_modules` yet): `npm ci` once; one file: `npx vitest run src/pages/employees.test.tsx`; one test: `npx vitest run src/pages/employees.test.tsx -t '<name>'`; gates: `npm run test`, `npm run lint`, `npm run build`.
- Clean up after the branch: `docker rm -f hrms-archived-pg && docker network rm hrms-archived-net`.

Screenshot gates (Tasks 15 and 18) are the controller's, with the `verify-hrms` skill, on a throwaway copy of the dev database: `docker exec hrms-archived-pg createdb -U hrms hrms_copy`, then `docker exec hrms-postgres-1 pg_dump -U hrms hrms | docker exec -i hrms-archived-pg psql -U hrms -d hrms_copy` (a read of `hrms`, nothing written to it). Serve this worktree's backend from a one-off `hrms-php` container on `hrms-archived-net` with a published port and a git-ignored `backend/.env` pointing at `hrms-archived-pg` / `hrms_copy` (`php artisan serve` re-reads `backend/.env` and ignores container env), and run `npm run dev` with `API_PROXY_TARGET` at that port.

## Review Focus

1. **The archived profile re-sent unchanged.** The profile form PATCHes every identity field (`employees.tsx:348`); a body equal to the stored record with only `termination_reason` changed must save. Pinned in Task 6 by sending the show payload back.
2. **An archived office employee in HR's site-filtered grid with the switch on.** `officeIdsAt` must opt in with the grid, or they vanish. Pinned in Task 4.
3. **An own-scope attendance editor probing a former employee's day.** `PUT attendance/days/{id}/{date}` and the bulk write must answer 404, never `employee_archived` (which would confirm the id). Pinned in Task 2.
4. **A profile save with an open request that does not touch the termination date.** Only a set or changed termination date is vetoed; other edits save. Pinned in Task 7.
5. **A period where only leavers have missing days.** The header count (switch off) is 0, yet the lock dialog must list their days and require the confirmation. Pinned in Task 17.

---

## Slice 1: Backend rule, guards and tests

### Task 1: The shared definition and the `archived` field

**Files:**
- Modify: `backend/app/Modules/Employees/Infrastructure/Eloquent/Employee.php` (beside `matching`, `:33-48`)
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php:90-103` (`visibleTo`), `:158-171` (`show`)
- Create: `backend/tests/Feature/ArchivedEmployeesTest.php` (`RefreshDatabase`, `AttendanceFixtures`; `signIn('hr')` in `setUp`)

**Interfaces:**
- Produces:
  - `Employee::isArchived(?string $terminationDate): bool` — `$terminationDate !== null && $terminationDate < now('Africa/Cairo')->toDateString()`; docblock points at `CONTEXT.md` (Archive).
  - `#[Scope] protected function archived(Builder $query, bool $archived = true): void` — `archived()` keeps the archived, `archived(false)` the others (null date or `>= today`, grouped in one `where` closure). Use `$query->qualifyColumn('termination_date')`: `EmployeeReach` applies it to queries other modules build.
  - `GET employees/{id}` gains `archived: bool` (`'archived' => Employee::isArchived($e->termination_date)` in `show()`).

- [ ] **Step 1: Write the failing test** `test_the_archive_starts_the_day_after_the_termination_date_in_cairo`: `travelTo('2026-10-20 22:30:00')` (UTC; 2026-10-21 01:30 in Cairo, summer time). `$left` office terminated `2026-10-20`, `$lastDay` terminated `2026-10-21`, `$staying` none. Assert `Employee::isArchived('2026-10-20') === true`, `isArchived('2026-10-21') === false`, `isArchived(null) === false`; `Employee::query()->archived()->pluck('id')->all() === [$left]`; `Employee::query()->archived(false)->orderBy('code')->pluck('id')->all() === [$lastDay, $staying]`; `GET /api/v1/employees/{$left}` → `data.archived` true; `{$lastDay}` → false.
- [ ] **Step 2: Run, expect FAIL** (undefined method `isArchived`). `RUN php artisan test --filter=ArchivedEmployeesTest`
- [ ] **Step 3: Implement** the two model members; `visibleTo` becomes `Employee::query()->archived($archived)` then the existing reach line; add the `show` field.
- [ ] **Step 4: Run, expect PASS**, plus `--filter=EmployeesApiTest` (the list's Cairo-edge test `:225-239` must still pass unchanged).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Employees/Infrastructure/Eloquent/Employee.php backend/app/Modules/Employees/Application/EmployeeService.php backend/tests/Feature/ArchivedEmployeesTest.php` — `Employees: one archive rule, and an archived field on the employee`

### Task 2: A site manager never reaches an archived employee

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeReach.php:11-38` (docblock and `apply`)
- Create: `backend/tests/Feature/ArchivedReachTest.php` (`setUp`: `cairo('2026-10-20 12:00')`, `signIn('hr')`, `leaveTypes()`, one `workSite()`)
- Modify: `backend/tests/Feature/EmployeesApiTest.php:241-253`

**Interfaces:**
- Consumes: the `archived` scope (Task 1).
- Produces: `EmployeeReach::apply()` adds `->archived(false)` whatever `$date`; so do `ids()` and `reaches()`, and through them `EnsureEmployeeInScope`, `isManagedBy`, `seesFiles`, the bulk reach check, `AttendanceDays::assertInReach`, the manager's leave list/show/cancel/check, `NotificationInbox::named` and `FareService::team`. Docblock: "An archived employee is in no one's reach, whatever the date (owner, 2026-11-11)."

- [ ] **Step 1: Write the failing tests**
  - `test_a_site_manager_reaches_nothing_of_an_employee_once_archived`: `$m = managerOf($site)`; engineer `$eng` assigned to `$m` from `2026-01-01` (`EmployeeService::assignManager`), worker `$w` homed on `$site`. As `$m` first: `GET /employees/{$eng}` and `{$w}` → 200 (they used to be reached). HR archives both: `PATCH /employees/{id}` `{termination_date: '2026-10-19'}` → 200. As `$m`: `GET /employees/{id}`, `/employees/{id}/fare`, `/employees/{id}/leave` → 404 for both; `GET /employees?archived=true` and `GET /employees` → `data` `[]`; `GET /employees/summary` → `data.total` 0; `GET /attendance/employees/{$eng}/period` → 403 (the seeded manager holds no `attendance.view`).
  - `test_an_own_scope_attendance_holder_gets_404_for_an_archived_employee_they_used_to_reach`: `$lead = userWith('own', 'own')`, made `$eng`'s manager. As `$lead` first: `GET /attendance/employees/{$eng}/period` → 200. HR archives `$eng` (`2026-10-19`). As `$lead`: that GET → 404; `GET /attendance/days/{$eng}/2026-10-19` → 404; `PUT` same → 404 (assert `error.code` is `not_found`, not `employee_archived`); `POST /attendance/days/bulk` with a valid body (`date: '2026-10-19'`, one row `{employee_id: $eng, site_id: $site}`; validation runs before the reach loop) → 404.
  - `test_a_site_manager_loses_the_requests_and_the_name_in_old_notifications_of_an_archived_employee`: as `$m`, file a request for `$eng` (`2026-10-12`→`2026-10-13`, annual); HR approves it (`POST /leave/requests/{id}/approve` with its version). As `$m`, `GET /notifications` → `data.0.employee.id` is `$eng`. HR archives `$eng`. As `$m`: `GET /leave/requests` → no row; `GET /leave/requests/{id}` → 404; `GET /notifications` → `data.0.employee` null.
- [ ] **Step 2: Update `EmployeesApiTest:241-253`** (`test_a_manager_sees_the_archive_of_their_own_employees_only` → `test_a_manager_sees_no_archived_employee_not_even_their_own`): create `$mine` without a termination date, assign the manager (the POST at `:247` would now be refused on an archived employee), then `PATCH` `termination_date: '2026-10-01'`; as the manager `listed('?archived=true')` is `[]`, `listed()` is `[]`, summary total 0.
- [ ] **Step 3: Run, expect FAIL** (manager still gets 200). `RUN php artisan test --filter='ArchivedReachTest|EmployeesApiTest'`
- [ ] **Step 4: Implement** `->archived(false)` at the head of `apply()`'s chain.
- [ ] **Step 5: Run, expect PASS**, plus `--filter='ManagerReachTest|ManagerScopeTest|TeamFaresTest|LeaveRequestsTest|NotificationsTest|SiteDay'`.
- [ ] **Step 6: Commit** `git add backend/app/Modules/Employees/Application/EmployeeReach.php backend/tests/Feature/ArchivedReachTest.php backend/tests/Feature/EmployeesApiTest.php` — `Reach: a site manager never reaches an archived employee`

### Task 3: The roster leaves archived employees out by default

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeRoster.php` (docblock `:18-22`, `ofTypesInYear` `:64-77`, `onSite` `:102-109`, `officeIdsAt` `:112-118`, `employedAs` `:182-192`, `row` `:204-230`)
- Modify: `backend/app/Modules/Attendance/Application/AttendanceLocks.php:43`, `backend/app/Modules/Attendance/Application/AttendanceImport.php:91`
- Create: `backend/tests/Feature/ArchivedListsTest.php` (`setUp`: `cairo('2026-10-20 12:00')`, `signIn('hr')`, `leaveTypes()`, `$this->site = workSite()`)
- Modify: `backend/tests/Feature/AttendanceLockTest.php`, `backend/tests/Feature/AttendanceImportTest.php` (one new test each), `backend/tests/Feature/AttendanceTemplateTest.php:183-198`, `backend/tests/Feature/LeaveBalancesTest.php:190-203, 345-354, 375-382, 402-408`
- Modify: `docs/calculations.md` §7 (`:211`), §2a (`:110`), §14 (after `:288`)

**Interfaces:**
- Consumes: the `archived` scope (Task 1).
- Produces:
  - `EmployeeRoster::withArchived(bool $include = true): self` — a clone with `private bool $includeArchived` set. `employedAs` (so `ofType`, `allOfType`, `engineersOf`), `ofTypesInYear`, `onSite` and `officeIdsAt` add `->when(! $this->includeArchived, fn (Builder $q) => $q->archived(false))`. `find`, `findMany`, `names`, `hiredBetween` are unchanged.
  - Every roster row gains `'archived' => Employee::isArchived($e->termination_date)` (add it to the row docblock).
  - Opt-in callers: `AttendanceLocks::lock` and `AttendanceImport::plan` use `$this->roster->withArchived()->allOfType(...)`. Every other caller keeps the default (`AttendanceGrid::sheet :170`, `openCount :298`, `AttendanceTemplate::grid :51`, `SiteDay :350, :412-413`, `LeaveBalances::list :53`; the grid and `missingDays` come in Task 4).

- [ ] **Step 1: Write the failing tests** (`ArchivedListsTest`)
  - `test_an_archived_employee_is_on_no_attendance_or_leave_list`: engineer `$left` (hired `2026-01-01`, terminated `2026-10-19`) with a stored day on `2026-10-12` (`putDay`), engineer `$stays`. Assert `$left` is absent from: `GET /attendance/grid?group=engineer&per_page=50` and `group=all` (`data.*.employee.id`); `GET /attendance/sheet?group=engineer&date=2026-10-12` and `group=all` (`data.rows.*.employee.id`), and that engineer sheet's day strip counts only `$stays` on `2026-10-13` (`data.days` entry `missing` 1); `app(AttendanceTemplate::class)->grid(EmployeeType::Engineer, '2026-09-26', null)['rows']` (`employee_id`); `GET /leave/balances` and `?year=2025` (`data.*.id`). Worker `$w` homed on the site from `2026-01-01`, terminated `2026-10-19`; `$m = managerOf($site)`; `cairo('2026-10-20 09:00')` (before noon, so yesterday is open); as `$m`, `GET /attendance/site-day?site_id={site}&date=2026-10-19` → `data.rows.*.employee.id` lacks `$w`.
  - `test_reactivating_brings_the_employee_back_to_every_list`: the same `$left`; HR `PATCH` `{termination_date: null, termination_reason: null}` → 200; `$left` is back in the grid, the sheet of `2026-10-12`, the template rows and `GET /leave/balances`.
  - `AttendanceLockTest::test_the_lock_still_counts_and_stores_the_days_of_an_archived_employee` (its clock is `2026-10-27`): `$left` engineer terminated `2026-10-20`. `putDay($left, '2026-10-05', ['site_id' => $this->site, 'in_time' => '08:00'])` (an open day) → `lock(['confirm_missing' => true])` is 422 `open_days`. Complete that day (`version` 1, `out_time` `16:00`, as `:60-66` does), lock with `confirm_missing` → 201; `AttendanceDay::where(['employee_id' => $left, 'source' => 'derived'])` dates are `['2026-10-02', '2026-10-06', '2026-10-09', '2026-10-16']` (Fridays and the setUp holiday up to the termination date, none after).
  - `AttendanceImportTest::test_the_import_still_takes_an_archived_employees_row_up_to_the_termination_date`: `$left` engineer terminated `2026-10-12`; `$template = download()` has no row for them; `edit($template, [], [[code($left), ['2026-10-05' => 'Site-1', '2026-10-15' => 'Site-1']]])`; `upload('confirm', ...)` → `data.applied` 1, the `2026-10-05` day stored at `$this->site`, one error `employee_not_active` for `2026-10-15` (mirror `test_a_row_typed_in_for_an_employee_missing_from_the_downloaded_template_skips_the_name_check`, `:295`).
- [ ] **Step 2: Update the existing tests that asserted the old visibility** (never delete one):
  - `AttendanceTemplateTest:183-198` `test_grey_cells_follow_termination_and_type_changes` → `test_grey_cells_follow_termination_and_type_changes_and_an_archived_employee_is_not_a_row`: keep the engineer terminated `2026-10-05` (now not a row), add one terminated `2026-10-20` (today, still a row) right after it, then `$movedOut`, `$movedIn`; `assertCount(3, rows)`; greys: row 2 `$d > '2026-10-20'`, row 3 `$d >= '2026-10-16'`, row 4 unchanged.
  - `LeaveBalancesTest:190-203` (assertion `:198`): `$left` (terminated `2026-10-19`) leaves the list → `[$office, $engineer, $worker, $leaving]`.
  - `LeaveBalancesTest:345-354` `test_last_years_list_holds_an_employee_who_left_last_year` → `test_last_years_list_leaves_out_an_employee_who_left_and_their_leave_tab_keeps_the_balance`: `?year=2025` lists `[]`; `$this->balance($left, 2025)` → `data.type` office, `data.balance.entitlement` 21, `data.balance.used` 1, `data.balance.remaining` 20; `listed()` stays `[]`.
  - `LeaveBalancesTest:375-382` `test_the_list_leaves_out_employees_not_employed_during_the_year`: start with `$this->cairo('2024-12-31 12:00')` so nobody is archived and the year window alone decides; the assertion `[$stayed]` for `?year=2025` stays.
  - `LeaveBalancesTest:402-408` `test_a_list_row_carries_the_termination_date`: terminate on `2026-10-20` (today, still listed) → `[null, '2026-10-20']`.
- [ ] **Step 3: Run, expect FAIL.** `RUN php artisan test --filter='ArchivedListsTest|AttendanceLockTest|AttendanceImportTest|AttendanceTemplateTest|LeaveBalancesTest'`
- [ ] **Step 4: Implement** `withArchived`, the default filter, the row key and the two opt-ins.
- [ ] **Step 5: Docs** (`docs/calculations.md`): §7 `:211` — the balances list leaves out archived employees for every year (owner, 2026-11-11); their balance stays on their own page. §2a `:110` — the lock still includes archived employees employed in the period: their missing days need confirming and their derived days are stored. §14 — archived employees are not rows of the template; a row typed in with their code is still matched, and its cells up to the termination date are accepted.
- [ ] **Step 6: Run, expect PASS**, then `--filter='Attendance|Leave|SiteDay|HrSiteFilter|TeamFares'`.
- [ ] **Step 7: Commit** `git add backend/app/Modules/Employees/Application/EmployeeRoster.php backend/app/Modules/Attendance/Application/AttendanceLocks.php backend/app/Modules/Attendance/Application/AttendanceImport.php backend/tests/Feature/ArchivedListsTest.php backend/tests/Feature/AttendanceLockTest.php backend/tests/Feature/AttendanceImportTest.php backend/tests/Feature/AttendanceTemplateTest.php backend/tests/Feature/LeaveBalancesTest.php docs/calculations.md` — `Roster: archived employees are off every list unless a caller asks`

### Task 4: The grid switch, the row flag, the missing list and the 21:00 alert

**Files:**
- Modify: `backend/app/Modules/Attendance/Application/AttendanceGrid.php` (`period :37-52`, `grid :62-82`, `missing :85-100`, `missingDays :349-364`; `missingCounts :273-293` keeps the default and says so in its docblock)
- Modify: `backend/app/Modules/Attendance/Presentation/AttendanceController.php` (constructor `:26-33`, `period :35-40`, `grid :43-49`)
- Modify: `backend/tests/Feature/ArchivedListsTest.php`

**Interfaces:**
- Consumes: `EmployeeRoster::withArchived()`, the row's `archived` (Task 3).
- Produces:
  - `AttendanceGrid::period(EmployeeType $g, ?string $start, ?string $date = null, ?string $ownerId = null, bool $withArchived = false): array` — `missing` counts with the flag.
  - `AttendanceGrid::grid(..., ?string $search = null, bool $withArchived = false): array` — one `$roster = $this->roster->withArchived($withArchived)` serves both `officeIdsAt` and `ofType`; each row's `employee` gains `archived`.
  - `missing()` always includes archived (`missingDays($p, $ownerId, true)`); each row gains `archived`.
  - `private function missingDays(Period $p, ?string $ownerId = null, bool $withArchived = false): array`.
  - Controller: `period` and `grid` validate `'with_archived' => ['sometimes', 'boolean']` and pass `$this->withArchived($request)`; `private function withArchived(Request $request): bool` = `$request->boolean('with_archived')` and the user's `AuthorizeAction::decide($user, 'attendance.edit')->allows(Scope::All)` (inject `AuthorizeAction`, as `EmployeeService::seesFiles` uses it).

- [ ] **Step 1: Write the failing tests** (`ArchivedListsTest`; `$left` engineer terminated `2026-10-19`, `$stays` engineer, both with missing days in the period starting `2026-09-26`)
  - `test_hr_brings_archived_employees_back_to_the_grid_and_its_header_count`: `GET /attendance/period?group=engineer` → `data.missing` equals `$stays`'s missing days only; with `&with_archived=1` it adds `$left`'s; `GET /attendance/grid?group=engineer&with_archived=1` → both rows, `$left`'s `employee.archived` true, `$stays`'s false.
  - `test_with_archived_is_ignored_without_attendance_edit_at_all_scope`: `signIn('ceo')` (holds `attendance.view.all` only) → the grid with `with_archived=1` lists only `$stays`; the header count equals the default.
  - `test_the_site_filtered_grid_keeps_an_archived_office_employee_with_the_switch`: office `$o` with `assignSite` to the site from `2026-01-01`, terminated `2026-10-19` → `GET /attendance/grid?group=office&site_id={site}&with_archived=1` lists `$o`; without the param it does not.
  - `test_the_missing_list_still_lists_archived_employees_flagged`: `GET /attendance/missing?group=engineer&per_page=100` → rows of `$left` with `archived` true and of `$stays` with false.
  - `test_the_evening_alert_leaves_archived_employees_out`: `$left` also has an open day on `2026-10-12` (`in_time` only); `app(AttendanceGrid::class)->missingCounts('2026-10-20')` → the engineer row of period `2026-09-26`: `missing` equals `$stays`'s count, `open` 0.
- [ ] **Step 2: Run, expect FAIL.** `RUN php artisan test --filter=ArchivedListsTest`
- [ ] **Step 3: Implement** as in Interfaces.
- [ ] **Step 4: Run, expect PASS**, then `--filter='Attendance|HrSiteFilter|SiteDayCrew'`.
- [ ] **Step 5: Commit** `git add backend/app/Modules/Attendance/Application/AttendanceGrid.php backend/app/Modules/Attendance/Presentation/AttendanceController.php backend/tests/Feature/ArchivedListsTest.php` — `Attendance: HR's with_archived grid, flagged rows and missing list, leavers out of the alert`

### Task 5: The write guard

**Files:**
- Create: `backend/app/Modules/Employees/Domain/EmployeeArchived.php`
- Modify: `backend/app/Modules/Employees/Presentation/EnsureEmployeeInScope.php:15-29`
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php` (new `isArchived`)
- Modify: `backend/routes/api.php:53, :54, :115, :116`
- Modify: `backend/bootstrap/app.php` (beside `:113-117`), `backend/lang/ar/errors.php`, `backend/lang/en/errors.php`
- Modify: `backend/tests/Feature/ArchivedEmployeesTest.php`

**Interfaces:**
- Produces:
  - `final class EmployeeArchived extends RuntimeException` (no params). Rendered 409 `employee_archived` with `__('errors.employee_archived')`, in `dontReport`. Copy: ar `الموظف ترك العمل (مؤرشف)، فلا يُقبل هذا التعديل.`, en `This employee has left (archived), so this change is not allowed.`
  - `EmployeeService::isArchived(string $employeeId): bool` — reads `termination_date` by id (`Employee::whereKey()->value()`, `null` for an unknown id) through `Employee::isArchived()`.
  - `EnsureEmployeeInScope::handle(Request $request, Closure $next, ?string $archived = null): Response` — the reach check stays first (404); then, when `! $request->isMethodSafe()` and `$archived !== 'archived_ok'` and the employee is archived, throw `EmployeeArchived`. A GET loads nothing, as today.
  - Routes: `employee.scope:archived_ok` on `PATCH employees/{employee}` (Task 6 judges it), `DELETE employees/{employee}`, `PUT` and `DELETE attendance/days/{employee}/{date}`.

- [ ] **Step 1: Write the failing tests** (`ArchivedEmployeesTest`; `cairo('2026-10-20 12:00')`, `$left` office terminated `2026-10-01`)
  - `test_every_write_keyed_by_an_archived_employee_is_refused`: for each of `PATCH /employees/{id}/code`, `POST .../type-changes`, `.../pay-changes`, `.../manager-assignments`, `.../site-assignments`, `.../fare-overrides`, `.../documents`, `PATCH .../documents/{uuid}`, `DELETE .../documents/{uuid}`, `PUT /employees/{id}/leave/2026` (empty bodies; any `Str::uuid()` for the document: the guard runs before the controller) → 409, `error.code` `employee_archived`. One of them with `Accept-Language: en` → the English message. No `audit_log` row for `$left` after its creation.
  - `test_delete_and_days_up_to_the_termination_date_still_write_for_an_archived_employee`: `putDay($left, '2026-10-01', …)` → 200; `'2026-10-02'` → `employee_not_active`; `DELETE /employees/{$left}` → 204.
- [ ] **Step 2: Run, expect FAIL.** `RUN php artisan test --filter=ArchivedEmployeesTest`
- [ ] **Step 3: Implement** as in Interfaces.
- [ ] **Step 4: Run, expect PASS**, then `--filter='EmployeesApiTest|EmployeeDocumentsTest|EmployeeSitesTest|TeamFaresTest|LeaveBalancesTest|AttendanceDaysTest|ArchivedReachTest'` (`EmployeesApiTest:419-427`, the swap with an archived holder, must still pass: the guard looks only at the route's employee).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Employees/Domain/EmployeeArchived.php backend/app/Modules/Employees/Presentation/EnsureEmployeeInScope.php backend/app/Modules/Employees/Application/EmployeeService.php backend/routes/api.php backend/bootstrap/app.php backend/lang/ar/errors.php backend/lang/en/errors.php backend/tests/Feature/ArchivedEmployeesTest.php` — `Employees: an archived employee's record refuses writes keyed by its id`

### Task 6: The profile PATCH on an archived employee

**Files:**
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php:174-186` (`update`)
- Modify: `backend/tests/Feature/ArchivedEmployeesTest.php`

**Interfaces:**
- Consumes: `EmployeeArchived` (Task 5), `Employee::isArchived()` (Task 1).
- Produces: `update()` runs in `DB::transaction`; it notes `Employee::isArchived()` on the stored date before `fill`, and when archived and `array_diff(array_keys($employee->getDirty()), ['termination_date', 'termination_reason']) !== []` throws `EmployeeArchived` (judged on changed values: the form sends every field).

- [ ] **Step 1: Write the failing tests**
  - `test_an_archived_employee_may_only_have_the_termination_date_and_reason_changed`: `$left` office terminated `2026-10-01` with a residence, phone, hire date and notes. `$body` = the `GET /employees/{$left}` data limited to the profile form's keys (`name_ar, name_en, fingerprint_name, gender, national_id, insurance_no, phone, address, department, residence_id, pay_method, bank_account, hire_date, contract_end_date, termination_date, termination_reason, hr_notes`). `PATCH` `$body` with `termination_reason` changed → 200 and saved; `PATCH` `$body` with `name_ar` changed → 409 `employee_archived`, name unchanged; `PATCH {termination_date: '2026-09-30'}` → 200; `PATCH {termination_date: null, termination_reason: null}` → 200, then a name change → 200.
  - `test_an_active_employee_is_archived_and_edited_in_one_save`: an active employee, `PATCH {name_ar: 'Office-X', termination_date: '2026-10-01'}` → 200 and both saved.
- [ ] **Step 2: Run, expect FAIL** (the name change saves). `RUN php artisan test --filter=ArchivedEmployeesTest`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS**, then `--filter='EmployeesApiTest|LeaveCancelTest'` (`EmployeesApiTest:255-268` still writes exactly one audit row).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Employees/Application/EmployeeService.php backend/tests/Feature/ArchivedEmployeesTest.php` — `Employees: an archived employee's profile changes only its termination date and reason`

### Task 7: Open items block a termination date

**Files:**
- Create: `backend/app/Modules/Employees/Domain/EmployeeHasOpenItems.php`, `backend/app/Modules/Employees/Application/EmployeeOpenItems.php`
- Modify: `backend/app/Modules/Employees/Application/EmployeeService.php` (`update`)
- Modify: `backend/app/Modules/Leave/Application/LeaveRequests.php:244-252`
- Modify: `backend/app/Providers/AppServiceProvider.php` (beside `:42-43`)
- Modify: `backend/bootstrap/app.php`, `backend/lang/ar/errors.php`, `backend/lang/en/errors.php`
- Create: `backend/tests/Feature/TerminationOpenItemsTest.php` (`cairo('2026-10-20 12:00')`, `signIn('hr')`, `leaveTypes()`, `$this->site = workSite()`)

**Interfaces:**
- Produces:
  - `final class EmployeeHasOpenItems extends RuntimeException` with `public readonly array $items` (`list<array<string, string>>`). Rendered 409 `employee_has_open_items`, `__('errors.employee_has_open_items', ['count' => (string) count($e->items)])`, `extra: ['items' => $e->items]`; in `dontReport`. Copy: ar `لا يُحفظ تاريخ إنهاء الخدمة: لدى الموظف :count من البنود المفتوحة. قرّر فيها أولًا.`, en `The termination date cannot be saved: the employee has :count open items. Decide them first.`
  - `final class EmployeeOpenItems { public function __construct(public readonly string $employeeId) {} }` — an event; each listener returns a list of items.
  - `LeaveRequests::openRequests(string $employeeId): array` — takes `LeaveRequestLock::forEmployee`, returns the pending and escalated requests by `from_date` as `['kind' => 'leave_request', 'id', 'status', 'from_date', 'to_date']`. `assertNoOpenRequests` counts its result and keeps its code and message.
  - `AppServiceProvider::boot`: `Event::listen(EmployeeOpenItems::class, fn (EmployeeOpenItems $e) => app(LeaveRequests::class)->openRequests($e->employeeId));` with the comment style of `:42`.
  - `EmployeeService::update`: when `isDirty('termination_date')` and the new value is not null, collect the event's lists (`event()` returns each listener's answer) plus the pending `EmployeeWageProposal` ids (`['kind' => 'wage_proposal', 'id']`), and throw `EmployeeHasOpenItems` when any. Clearing the date is never checked. `DayCrew::decide` does not pass here and is untouched.

- [ ] **Step 1: Write the failing tests**
  - `test_a_termination_date_is_refused_while_a_leave_request_is_pending_and_the_refusal_lists_it`: engineer `$e`, pending request `2026-11-02`→`2026-11-03` (`POST /leave/requests`); `PATCH {termination_date: '2026-10-25'}` → 409, `error.code` `employee_has_open_items`, `error.items` `[{kind: 'leave_request', id, status: 'pending', from_date: '2026-11-02', to_date: '2026-11-03'}]`; the date is not saved; no `UPDATED` audit row.
  - `test_an_escalated_request_also_blocks_it`: escalate it first (`POST /leave/requests/{id}/escalate` with its version) → the item's `status` is `escalated`.
  - `test_a_pending_wage_proposal_blocks_it`: `app(DayCrew::class)->propose(['name_ar' => 'Laborer-P', 'gender' => 'male'], $site, '2026-10-20', '350')` → `PATCH` the laborer's termination → 409 with `[{kind: 'wage_proposal', id: proposal_id}]`.
  - `test_the_date_saves_once_the_items_are_decided`: approve the request, approve the wage → each `PATCH` → 200.
  - `test_clearing_a_termination_date_is_never_refused`: `$e` created with `termination_date: '2026-11-30'`, a pending request in November → `PATCH {termination_date: null}` → 200.
  - `test_other_fields_save_while_items_are_open`: pending request; `PATCH {name_ar: 'Engineer-X'}` → 200.
- [ ] **Step 2: Run, expect FAIL.** `RUN php artisan test --filter=TerminationOpenItemsTest`
- [ ] **Step 3: Implement** as in Interfaces.
- [ ] **Step 4: Run, expect PASS**, then `--filter='LeaveCancelTest|SiteDayCrewTest|RefusedLaborerEdgeTest|EmployeesApiTest'` (the delete veto at `LeaveCancelTest:243` keeps `leave_employee_has_open_requests`; a refused laborer still ends on the refusal day).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Employees/Domain/EmployeeHasOpenItems.php backend/app/Modules/Employees/Application/EmployeeOpenItems.php backend/app/Modules/Employees/Application/EmployeeService.php backend/app/Modules/Leave/Application/LeaveRequests.php backend/app/Providers/AppServiceProvider.php backend/bootstrap/app.php backend/lang/ar/errors.php backend/lang/en/errors.php backend/tests/Feature/TerminationOpenItemsTest.php` — `Employees: a termination date waits until open requests and wage proposals are decided`

### Task 8: Leave: no new request, and the requests list hides decided ones

**Files:**
- Modify: `backend/app/Modules/Leave/Application/LeaveRequests.php` (`list :100-112`, `check :362-394`)
- Modify: `backend/app/Modules/Employees/Application/EmployeeRoster.php` (new `archivedIds`)
- Create: `backend/tests/Feature/ArchivedLeaveTest.php` (`cairo('2026-10-20 12:00')`, `signIn('hr')`, `leaveTypes()`)

**Interfaces:**
- Consumes: `EmployeeArchived` (Task 5), the row's `archived` (Task 3).
- Produces:
  - `check()`: right after the reach block (`:372-379`), `if ($employee['archived']) throw new EmployeeArchived;` (after reach: see Deviation 1).
  - `EmployeeRoster::archivedIds(): Builder` — `Employee::query()->select('employees.id')->archived()`, a `whereIn` subquery like `EmployeeReach::ids`.
  - `list()`: when `$employeeId === null`, keep a request when its status is pending or escalated or its employee is not in `archivedIds()` (`whereNotIn`, so a deleted employee's requests list as today).

- [ ] **Step 1: Write the failing tests**
  - `test_no_new_request_can_be_previewed_or_filed_for_an_archived_employee`: engineer terminated `2026-10-19`; `GET /leave/requests/preview` and `POST /leave/requests` for `2026-10-12`→`2026-10-13` → 409 `employee_archived`.
  - `test_the_list_hides_an_archived_employees_decided_requests_but_keeps_the_open_ones`: engineer `$e` terminated `2026-10-25` (future); request A `2026-10-21`→`2026-10-22` approved, request B `2026-10-24` pending; `cairo('2026-10-26 12:00')` (now archived). `GET /leave/requests` → B only; `GET /leave/requests?employee_id={$e}` → A and B; HR approves B → 200; then `GET /leave/requests` → none of `$e`'s.
- [ ] **Step 2: Run, expect FAIL.** `RUN php artisan test --filter=ArchivedLeaveTest`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS**, then `--filter='Leave'`.
- [ ] **Step 5: Commit** `git add backend/app/Modules/Leave/Application/LeaveRequests.php backend/app/Modules/Employees/Application/EmployeeRoster.php backend/tests/Feature/ArchivedLeaveTest.php` — `Leave: no new request for an archived employee; the list keeps only their open ones`

### Task 9: Docs and the backend gate

**Files:**
- Modify: `CONTEXT.md:32-34` (Archive), `docs/hrms-module-plan.md` (new section after "Payroll engines: owner decisions of 2026-11-09", `:247-257`)

- [ ] **Step 1: `CONTEXT.md`**: the Archive entry gains: archived employees are hidden everywhere by default (no list, search, picker, count or alert); only all-scope viewers reach them, through the Archive view, on a read-only page whose only actions are Reactivate and correcting the termination date or reason; HR shows them on the period grid to finish their last days; the lock and payroll still include them.
- [ ] **Step 2: `docs/hrms-module-plan.md`**: `### Hidden archived employees: owner decisions of 2026-11-11`, one bullet per design decision 1-6 with its rule, plus the two codes and the spec path.
- [ ] **Step 3: Gate**: `RUN php artisan test` (all green), `RUN vendor/bin/pint --test`, `RUN vendor/bin/phpstan analyse`, `RUN vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`. Fix and re-run until clean.
- [ ] **Step 4: Commit** `git add CONTEXT.md docs/hrms-module-plan.md` (plus any file a gate fix touched, by path) — `Docs: archived employees are hidden everywhere`

---

## Slice 2: Employees screens (frontend)

### Task 10: The Archive switch needs `employee.view.all`

**Files:**
- Modify: `frontend/src/pages/employees.tsx:87-90, :104, :136-143`
- Test: `frontend/src/pages/employees.test.tsx`

**Interfaces:**
- Produces: `archive` is true only with `can('employee.view.all')` and `?view=archived` (move `office` above it); the `SegmentedControl` renders only with that permission.

- [ ] **Step 1: Write the failing test** `test('the archive switch shows only with employee.view.all, and ?view=archived without it lists the active employees')`: `renderWithAuth(<EmployeesPage />, ['employee.view.own'], { path: '/employees?view=archived' })` → no `group` named «عرض الموظفين»; the list request has no `archived`; the status column header is «الحالة».
- [ ] **Step 2: Run, expect FAIL.** `npx vitest run src/pages/employees.test.tsx -t 'archive switch shows only'`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the file, expect PASS** (`:585-600` keeps passing with `employee.view.all`).
- [ ] **Step 5: Commit** `git add frontend/src/pages/employees.tsx frontend/src/pages/employees.test.tsx` — `Employees: only all-scope viewers get the Archive switch`

### Task 11: The archived page: banner and read-only tabs

**Files:**
- Modify: `frontend/src/pages/employees.tsx` (`EmployeeForm :325-498`, `History :713-772`)
- Modify: `frontend/src/pages/employee-documents.tsx:64-75`, `frontend/src/pages/employee-leave.tsx:26-109`, `frontend/src/pages/employee-attendance.tsx:54, :135`, `frontend/src/pages/attendance-day.tsx:33-79, :129`
- Modify: `frontend/src/locales/ar.json`, `frontend/src/locales/en.json`
- Test: `frontend/src/pages/employees.test.tsx`, `employee-documents.test.tsx`, `employee-leave.test.tsx`, `employee-attendance.test.tsx`

**Interfaces:**
- Consumes: `GET employees/{id}` `archived` (Task 1).
- Produces:
  - In `EmployeeForm`, the one place the page decides it: `const archived = emp?.archived === true`. `editable` also needs `!archived`. Hidden when archived: `ChangeCodeDialog`, `MoveSiteDialog`, `AssignManagerDialog`. `ReactivateDialog` shows on `can('employee.edit') && archived` (replaces the `cairoToday()` comparison at `:469`).
  - Banner under the header card: `<Alert tone="warning" icon={Archive}>` holding `ltrDates(t('employee.archive.banner', { date }))` and, when set, `t('employee.archive.reason', { reason })`.
  - Tabs take `readOnly={archived}`: `History({ emp, readOnly })` hides `ChangeDialog`; `Documents({ emp, readOnly })` sets `editor = can('employee_document.edit') && !readOnly` (viewing and download stay); `Leave({ emp, initialYear, readOnly })` and `Requests` hide `AdjustDialog` and «طلب إجازة»; `EmployeeAttendance({ emp, readOnly })` passes `archived={readOnly}` to `DayEditor`.
  - `DayEditor({ employee, date, onOpenChange, archived })` → `DayBody` → `DayForm`: `readOnly` also when `archived`, and the note at `:129` reads `attendance.day.archivedNote` then.
  - Keys: `employee.archive.banner` (ar «مؤرشف — ترك العمل في {{date}}», en "Archived — left on {{date}}"), `employee.archive.reason` (ar «السبب: {{reason}}», en "Reason: {{reason}}"), `attendance.day.archivedNote` (ar «للعرض فقط: الموظف ترك العمل.», en "View only: this employee has left.").

- [ ] **Step 1: Write the failing tests**
  - `employees.test.tsx`: `test('an archived employee’s page shows the banner with the date and reason and changes nothing but its termination')`: `{ ...archived, archived: true }` with `[...ALL, 'employee.edit_code.all', 'employee.assign_manager.all', 'employee.assign_site.all']` → text «مؤرشف — ترك العمل في 2026-01-31» (date `dir="ltr"`) and «السبب: استقالة»; the name field is disabled; no «حفظ», «تغيير الرقم الوظيفي», «تعيين مدير», «نقل إلى موقع»; «إعادة التفعيل» shows; on `?tab=history` no «إضافة تغيير» (the `change.add` label).
  - `employee-documents.test.tsx`: `test('an archived employee’s documents open but offer no upload, edit or delete')`: render `<Documents emp={…} readOnly />` with `employee_document.edit.all` → no upload button and no «الإجراءات»; opening a document still works.
  - `employee-leave.test.tsx`: `test('an archived employee’s leave tab shows the balance and requests without request or adjust')`.
  - `employee-attendance.test.tsx`: `test('an archived employee’s day opens view only')`: `<EmployeeAttendance emp={emp} readOnly />` with `attendance.edit.all`; open a missing day → the editor shows «للعرض فقط: الموظف ترك العمل.», its fields are disabled and it has no «حفظ».
- [ ] **Step 2: Update the existing tests** in `employees.test.tsx` (the server now says who is archived): the `archived` fixture (`:612`) gains `archived: true`; in `:614-628` the reactivated record is `{ ...archived, termination_date: null, termination_reason: null, archived: false }`; in `:645-656` the terminated-today case is `{ ...archived, termination_date: today, archived: false }`. Names unchanged.
- [ ] **Step 3: Run, expect FAIL.** `npx vitest run src/pages/employees.test.tsx src/pages/employee-documents.test.tsx src/pages/employee-leave.test.tsx src/pages/employee-attendance.test.tsx`
- [ ] **Step 4: Implement**, then the same run → PASS, plus `npx vitest run src/pages/attendance-day.test.tsx src/lib` (locale parity).
- [ ] **Step 5: Commit** `git add frontend/src/pages/employees.tsx frontend/src/pages/employee-documents.tsx frontend/src/pages/employee-leave.tsx frontend/src/pages/employee-attendance.tsx frontend/src/pages/attendance-day.tsx frontend/src/locales/ar.json frontend/src/locales/en.json frontend/src/pages/employees.test.tsx frontend/src/pages/employee-documents.test.tsx frontend/src/pages/employee-leave.test.tsx frontend/src/pages/employee-attendance.test.tsx` — `Employees: an archived employee's page is read-only under a banner`

### Task 12: «تصحيح بيانات الترك»

**Files:**
- Modify: `frontend/src/pages/employees.tsx` (beside `ReactivateDialog :500-523`), locales
- Test: `frontend/src/pages/employees.test.tsx`

**Interfaces:**
- Produces: `CorrectTerminationDialog({ emp, onDone }: { emp: Row; onDone: (saved: Row) => void })` — a `FormDialog` (trigger: outline button, label `employee.archive.correct`), `init` from the record's two fields, fields `termination_date` (date) and `termination_reason`, submit `PATCH /employees/{id}` with only `{ termination_date, termination_reason }` (blank → `null`), `shown` those two. Shown with `can('employee.edit') && archived`. `onDone` writes the saved values into the open profile form, as `ReactivateDialog` does. Key `employee.archive.correct`: ar «تصحيح بيانات الترك», en "Correct leaving details".

- [ ] **Step 1: Write the failing test** `test('correcting the leaving details sends only the termination date and reason')`: archived record, open «تصحيح بيانات الترك», change the date to `2026-01-30` and the reason → the PATCH body `toEqual({ termination_date: '2026-01-30', termination_reason: '…' })`; not shown without `employee.edit`.
- [ ] **Step 2: Run, expect FAIL.** `npx vitest run src/pages/employees.test.tsx -t 'correcting the leaving details'`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the file, expect PASS.**
- [ ] **Step 5: Commit** `git add frontend/src/pages/employees.tsx frontend/src/locales/ar.json frontend/src/locales/en.json frontend/src/pages/employees.test.tsx` — `Employees: HR corrects an archived employee's termination date or reason`

### Task 13: The open-items refusal lists its items

**Files:**
- Modify: `frontend/src/lib/api.ts:3-18, :73`, `frontend/src/components/form.tsx:20-35` (`ErrorBanner`), locales
- Test: `frontend/src/pages/employees.test.tsx`

**Interfaces:**
- Consumes: the 409 `employee_has_open_items` with `error.items` (Task 7).
- Produces:
  - `export type OpenItem = { kind: 'leave_request' | 'wage_proposal'; id: string; status?: string; from_date?: string; to_date?: string }`; `ApiError` gains `items: OpenItem[]` (constructor's last parameter, default `[]`, read from `e?.items`).
  - `ErrorBanner` lists `err.items` under the message, each a router `Link`: a leave request to `/leave?request={id}` with `t('employee.openItems.leave_request', { from, to, status: t('leave.status.' + status) })` (dates `ltrDates`), a wage proposal to `/wage-proposals?proposal={id}` with `t('employee.openItems.wage_proposal')`. Rendered only when there are items.
  - Keys: `employee.openItems.leave_request` (ar «طلب إجازة {{from}} – {{to}} ({{status}})», en "Leave request {{from}} – {{to}} ({{status}})"), `employee.openItems.wage_proposal` (ar «يومية مقترحة بانتظار القرار», en "Proposed daily wage awaiting a decision").

- [ ] **Step 1: Write the failing test** `test('saving a termination date with open items shows the refusal and links to each item')`: active record; PATCH answers 409 `{ error: { code: 'employee_has_open_items', message: 'M', items: [{ kind: 'leave_request', id: 'r1', status: 'pending', from_date: '2026-11-12', to_date: '2026-11-13' }, { kind: 'wage_proposal', id: 'p1' }] } }`; type a termination date and save → the alert shows «M», a link to `/leave?request=r1` named with both dates and «قيد المراجعة», and a link to `/wage-proposals?proposal=p1`.
- [ ] **Step 2: Run, expect FAIL.** `npx vitest run src/pages/employees.test.tsx -t 'open items'`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS**, plus `npx vitest run src/lib/api.test.ts`.
- [ ] **Step 5: Commit** `git add frontend/src/lib/api.ts frontend/src/components/form.tsx frontend/src/locales/ar.json frontend/src/locales/en.json frontend/src/pages/employees.test.tsx` — `Forms: an open-items refusal links to each open item`

### Task 14: The bell does not link a notification whose employee is unknown

**Files:**
- Modify: `frontend/src/components/layout/NotificationBell.tsx:97`
- Test: `frontend/src/components/layout/NotificationBell.test.tsx`

**Interfaces:**
- Produces: `view(n, ctx)` returns every line with `to: null` when `n.data` has `employee_id` and `n.employee` is null (the missing-days alert names no employee and keeps its links).

- [ ] **Step 1: Update the existing tests that asserted the old link** (never delete one):
  - `:218-226` `a leave request about someone the reader cannot name still opens the request` → `a leave request about someone the reader cannot name reads as a generic line, and choosing it only marks it read`: render with `{ path: '/leave' }`, click → the `status` stays `/leave`.
  - `:255-262` `an approved and a refused leave are told apart, and each opens its request` → `…, and a named one opens its request`: click the approved line (PATCH mock key `d-approved`) → `/leave?request=r2`.
- [ ] **Step 2: Run, expect FAIL** (both still navigate). `npx vitest run src/components/layout/NotificationBell.test.tsx`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `git add frontend/src/components/layout/NotificationBell.tsx frontend/src/components/layout/NotificationBell.test.tsx` — `Bell: a notification about an employee the reader cannot name is not a link`

### Task 15: Slice 2 gate

- [ ] **Step 1:** In `frontend/`: `npm run test`, `npm run lint`, `npm run build` — all clean.
- [ ] **Step 2 (controller):** screenshots with `verify-hrms` on the throwaway copy (see How to run): the employees list as HR with the Archive switch and as a site manager without it; an archived employee's page (banner, read-only profile, Reactivate, «تصحيح بيانات الترك»); the open-items refusal. Arabic and English, 375 and 1440 px, light and dark. The owner approves (in Arabic) before Slice 3.

---

## Slice 3: Attendance screens (frontend)

### Task 16: The grid switch and the «ترك العمل» mark

**Files:**
- Modify: `frontend/src/pages/attendance.tsx` (`AttendancePage :64-212`: the period request `:91`, the bar `:113`, the `Grid` mount `:180`; `Grid :231-305`: the request `:234`, the name cell `:272-276`), locales
- Test: `frontend/src/pages/attendance.test.tsx`

**Interfaces:**
- Consumes: `with_archived` and the row's `employee.archived` (Task 4).
- Produces:
  - `withArchived = tab === 'grid' && can('attendance.edit.all') && params.get('with_archived') === '1'`, kept in the URL like `q` (a group change keeps it).
  - Beside the grid's `SearchInput`, shown only with `attendance.edit.all`: a native checkbox in a `label`, as the lock dialog's (`:377-380`), labelled `attendance.withArchived`.
  - When on, both `/attendance/period` and `/attendance/grid` get `with_archived: '1'`; `Grid` takes `withArchived` and it is part of its `key`.
  - A row whose `employee.archived` is true shows `<StatusBadge variant="neutral" dot={false} label={t('attendance.leftMark')} />` after the name. Its cells open the day editor as usual.
  - Keys: `attendance.withArchived` (ar «إظهار من تركوا العمل», en "Show those who left"), `attendance.leftMark` (ar «ترك العمل», en "Left").

- [ ] **Step 1: Write the failing tests**
  - `test('HR’s switch asks the grid and the header for with_archived=1, keeps it in the URL, and marks the rows of those who left')`: with `lockPerms`, tick «إظهار من تركوا العمل» → the last grid and period requests carry `with_archived=1`; the grid answers a row with `employee.archived: true` → its name cell shows «ترك العمل»; a fresh render with `?with_archived=1` starts ticked.
  - `test('the switch needs attendance.edit.all and stays on the grid tab')`: `['attendance.view.all']` → no switch and no `with_archived` in any request; on the sheet tab no switch.
- [ ] **Step 2: Run, expect FAIL.** `npx vitest run src/pages/attendance.test.tsx -t 'switch'`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the file, expect PASS.**
- [ ] **Step 5: Commit** `git add frontend/src/pages/attendance.tsx frontend/src/locales/ar.json frontend/src/locales/en.json frontend/src/pages/attendance.test.tsx` — `Attendance: HR shows those who left on the grid, marked`

### Task 17: The lock dialog lists from the missing list

**Files:**
- Modify: `frontend/src/pages/attendance.tsx` (`LockDialog :311-392`, `MissingDays :394-421`)
- Test: `frontend/src/pages/attendance.test.tsx` (new test; `routes` `:20-24`)

**Interfaces:**
- Consumes: the missing rows' `archived` (Task 4).
- Produces: `LockDialog` loads `useList('/attendance/missing?group&period_start&per_page=100')` itself; `missing = total > 0` replaces `period.missing > 0` (`:318`); `MissingDays` takes `{ rows, total, err, loading }`; the Lock button is also disabled while the list loads. A row whose `archived` is true shows the same «ترك العمل» badge.

- [ ] **Step 1: Write the failing test** `test('the lock dialog lists the missing days from the missing list even when the header counts none, and marks those who left')`: period `missing: 0`; the missing list answers one row `{ …, archived: true }` → the dialog lists it with «ترك العمل», the confirm checkbox shows, Lock is disabled until it is ticked.
- [ ] **Step 2: Update the shared `routes` (`:20-24`)**: add `'GET /api/v1/attendance/missing': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } })`, because the dialog now always reads the list; the tests at `:154` and `:177` (no missing days) rely on it, and `:129` and `:186` keep their own answer.
- [ ] **Step 3: Run, expect FAIL.** `npx vitest run src/pages/attendance.test.tsx -t 'lock'`
- [ ] **Step 4: Implement**, then the whole file → PASS.
- [ ] **Step 5: Commit** `git add frontend/src/pages/attendance.tsx frontend/src/pages/attendance.test.tsx` — `Attendance: the lock dialog confirms from the missing list and marks those who left`

### Task 18: Slice 3 gate

- [ ] **Step 1:** In `frontend/`: `npm run test`, `npm run lint`, `npm run build`; in `backend/`: the Task 9 gates once more.
- [ ] **Step 2 (controller):** screenshots with `verify-hrms` on the throwaway copy: the grid with the switch off and on (a marked row), as HR and as the CEO (no switch); the lock dialog listing a leaver's missing days with the mark. Arabic and English, 375 and 1440 px, light and dark. The owner approves (in Arabic); then `superpowers:requesting-code-review` and `security-review` (permissions) before the PR.

---

## Spec coverage

| Design row | Task |
|---|---|
| Shared rule, Cairo edge; `visibleTo` | 1 |
| `archived` field on show | 1 |
| `EmployeeReach` excludes archived (scope middleware, `isManagedBy`, leave lists, writes in reach, team fares, manager's list, inbox names) | 2 |
| `archived=true` empty for a site manager | 2 |
| Attendance period/day read and writes: manager 403 (seeded) / 404 (own scope) | 2 |
| Roster default + `withArchived`; lock and import opt in | 3 |
| Day sheet, site-day, template, balances (every year) hidden | 3 |
| Lock unchanged (missing confirm, open day, derived days) | 3 |
| Import unchanged up to the termination date | 3 |
| Reactivate brings everything back | 3 |
| Grid `with_archived` (edit.all only), row flag, `officeIdsAt`, header count | 4 |
| Missing list includes and flags; 21:00 alert excludes | 4 |
| Write guard on every by-id write; DELETE and day PUT/DELETE exempt; swap with an archived holder allowed | 5 |
| PATCH judged on changed values | 6 |
| Termination refused with open items (pending, escalated, wage); clearing never refused; `DayCrew::decide` untouched; delete code unchanged; transaction | 7 |
| Leave preview/submit refused; requests list (open kept, employee filter shows all, manager none) | 8 (manager: 2) |
| Request show/approve/refuse/escalate/cancel unchanged for all scope | 8 |
| `CONTEXT.md`, `docs/calculations.md`, `docs/hrms-module-plan.md` | 9, 3 |
| Archive switch by permission; `?view=archived` for a manager | 10 |
| Banner; read-only profile, header actions, history, documents (download stays), leave, calendar day editor | 11 |
| «تصحيح بيانات الترك» | 12 |
| Open-items refusal with links | 13 |
| Bell: unknown employee is not a link | 14 |
| Grid switch in the URL, mark, cells open the editor | 16 |
| Lock dialog from the missing list, mark | 17 |
| Gates and screenshots per UI slice | 9, 15, 18 |

Unchanged on purpose (design): wage proposals list and decisions, team fares, notifications already stored, audit log, the employee picker in the request dialog, `DayCrew`/`FareService` window copies, `hiredBetween`, `find`/`findMany`/`names`.
