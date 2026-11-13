# Release 1: Manager Attendance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Workers belong to a dated home site, and a manager records today's attendance for the people on their sites from a phone. Overtime and advances wait for HR's approval. The manager signs each employee's period, and HR can return it.

**Architecture:**
- **Employees:** `employee_site_assignments` and one reach rule, `EmployeeReach`, on the date read or written; it replaces `CURRENT_MANAGER`.
- **Attendance:** `SiteDay` (the manager's read and save, over the existing `AttendanceDays::bulk`), `ManagerWindow` (pure) and `attendance_signoffs`.
- **Requests** (new module): the overtime and advance `requests`. An approval writes the day through `AttendanceDays::applyApproved`.
- **Notifications:** a `ref` column.
- **Frontend:** `/site-day` (manager) and `/requests` (HR), plus the employee page, the site list, the HR grid and the bell.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17, Redis, PHPUnit 12, Pint, Larastan, deptrac; React 19 + Vite + TS, TanStack Query, Tailwind 4, shadcn/Radix, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-22-manager-attendance-design.md`. It wins over `.superpowers/sdd/manager-attendance/{decisions,blast-radius-site-vs-manager}.md`. Also read `CLAUDE.md`, `backend/CLAUDE.md`, `frontend/CLAUDE.md`, `docs/coding-standards.md` (mandatory), `CONTEXT.md` and `docs/calculations.md` §2–4. House style: `docs/superpowers/plans/release-1c-attendance.md`.

**Not in this plan:**
- The importer's site column for workers (`hrms:import-master`) follows after PR #4 (`feature/migration-step-1`) merges.
- CEO escalation, the paste parser, the scanned sign-off, leave, office staff on the manager screen, and any pay figure for a manager.

## Operating mode (binding)
- The controller session only plans, dispatches, reviews and keeps `.superpowers/sdd/manager-attendance/progress.md`. Every implementer and reviewer is a fresh **Opus** subagent.
- Every brief loads `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `superpowers:test-driven-development`, and reads `docs/coding-standards.md` plus the Global Constraints. UI tasks (5, 6, 9, 12, 13, 17) also load `ui-ux-pro-max:ui-ux-pro-max` and pass its pre-delivery checks: contrast in both themes, visible focus, 44 px targets, labelled icon buttons.
- Each slice ends with a **Gate**, run by a separate tester. The next slice starts only after the owner approves the screenshots.
- Branch `feature/manager-attendance`. Commit once per task: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`. No push or PR without the owner's go.

## Global Constraints
- The 1c Global Constraints apply unchanged: Docker-only PHP; gates `B-test`/`B-pint`/`B-stan`/`B-deptrac`/`F`; TDD; money as `NUMERIC(18,6)` strings; new migrations only; `standardColumns()` + `Audited`; paginated lists; logical `start/end`; `can()` by name; labelled fixtures.
- Verbatim from `CLAUDE.md`: "Every create/update/delete, approval, override, login and period lock/reopen writes an audit-log entry with before/after values." "Every manager action notifies HR: instantly for requests, warnings and edits to past days; in a daily digest for routine attendance."
- **Reach:** D is the date read or written. Out of reach is 404, never 403.
- **No pay figures for a manager:** `transport`, `outside_*` and the day's `advance_amount` never appear in a manager response or body. Their own advance *request* is the exception.
- **i18n:** keys in `ar.json` and `en.json`, in formal Egyptian business Arabic. The design fixes حاضر، غياب، مرضي، «زي امبارح»، «حفظ اليوم»، «تم التوقيع»، «الطلبات»، «مهندسين»، الموقع الأساسي. Tasks list key names, with Arabic where it is fixed.
- **Mockup A, phone-first** (`.superpowers/brainstorm/59350-1792630343/content/manager-day.html`): the status buttons on every row; «⋯» for rarer leave types; `border-s-4 border-s-warning` on a row that differs from yesterday; a sticky bottom bar; one column that widens on a laptop. App tokens only, light and dark, 44 px targets.
- **Permissions (migrations):**
  - slice 1: `employee.assign_site.all` (developer, hr) and `attendance.record.own` (manager);
  - slice 3: `request.create.own` (manager) and `request.decide.all` (developer, hr);
  - slice 4: `attendance.sign.own` (manager) and `attendance.return.all` (developer, hr).
- **Commands:** `--filter=X` means `docker compose exec app php artisan test --filter=X`. "Steps 2–5" means: run it and see FAIL, implement, run it and see PASS with the named gates green, then commit with that subject.
- **Fixtures:**
  - `cairo('2026-10-22 09:00')` is a Thursday; 10-23 is a Friday; the engineers' period is 09-26 → 10-25. The field shift is the `worker_shift_start/_end` setting, seeded 08:00–17:00 (the owner's 09:00–16:00 is set by HR, R19), `day_hours` 8.
  - `tests/AttendanceFixtures.php` gains `managerOf(string ...$siteIds): User` and `homeSite(string $employeeId, string $siteId, string $from): void`.

## Review Focus
1. **Cairo noon and midnight:** yesterday is writable at 11:59 Cairo and not at 12:00. Task 7 `test_yesterday_closes_at_noon_cairo`; Task 8 `test_yesterday_is_writable_until_noon_only`.
2. **A one-day loan read the next day** still belongs to the loan site's manager. Task 2 `test_reach_follows_the_date_read_not_today`.
3. **Two managers save the same person:** the second save is a 409 and writes nothing. Task 8 `test_two_managers_on_one_site_the_second_save_is_a_conflict`.
4. **HR decides a since-changed request:** 409. Changed approved OT goes back to pending, and the day's OT goes to 0. Task 10 `test_a_stale_decision_is_a_conflict`; Task 11 `test_changed_times_send_approved_overtime_back_to_pending`.
5. **A crafted manager body** (another site, out of reach, a derived leave type, hidden or computed fields): 404, 422 or ignored. Task 8 `test_a_manager_body_cannot_reach_hidden_fields_or_other_sites`.

## Planner calls (cited as Pn; list them in the PR)
- **P1.** `manager_sites` is undated: "the manager's sites on D" = the current set.
- **P2.** Office staff leave every manager's reach, since nothing reads their manager link.
- **P3.** The manager screen has its own `attendance.record.own`. `attendance.view/edit.own` would put HR's grid and import in the manager's nav.
- **P4.** HR's own-scope reads (test roles only) take reach on `min(period end, today)`.
- **P5.** A permanent worker's day stores `ot_minutes`. Payroll uses it when set, else the minutes from HR-entered times. A manager's save always sets it: approved, or 0.
- **P6.** Late and OT come from times only on a manager's save. HR still types engineers' and laborers' minutes, so there is no fixture diff.
- **P7.** OT = the existing `workerMinutes` OT (worked − `day_hours`). A request is raised when it is above 0.
- **P8.** The noon cut-off is a setting, `manager_yesterday_until` = `12:00`. **P9.** `source` gains `manager`.
- **P10.** "Yesterday" = the latest stored day in the 7 before D, skipping friday, saturday and official_holiday.
- **P11.** The manager's leave types are absent, sick, rest and other.
- **P12.** A decided advance is final for the manager. OT re-pends automatically, and OT that drops to 0 withdraws its request.
- **P13.** `notifications.ref` joins the unique key. **P14.** Requests reuse `AttendanceRefused` (`request_decided`, 409).
- **P15.** The period view takes `which=current|previous`. Signing opens on the period end, for the manager in reach on that date.
- **P16.** The skipped-day alert and the digest run in the 21:00 `attendance:alert-missing`.
- **P17.** The "site page" = a «العمال» row-action dialog in Settings → Sites.
- **P18.** The gate states come from `AttendanceDemoSeeder`, extended per slice relative to Cairo today. Seeding writes, so the owner approves it.

## API (under `/api/v1`, inside `auth`)
| Method | Path | Permission | Task |
|---|---|---|---|
| POST | `employees/{employee}/site-assignments` | `employee.assign_site` + `employee.scope` | 1 |
| GET | `employees?site_id=` (home site today) | `employee.view` | 1 |
| GET | `attendance/site-day?site_id=&date=` | `attendance.record` | 3 |
| POST | `attendance/site-day` | `attendance.record` (+ `request.create` from Task 11) | 8 |
| GET | `requests?status=&type=&site_id=&date=&employee_id=&per_page=` | `request.decide` | 10 |
| POST | `requests/{request}/approve`, `requests/{request}/refuse` | `request.decide` | 10 |
| GET | `attendance/site-period?site_id=&which=` | `attendance.record` | 14 |
| POST | `attendance/signoffs`, `attendance/signoffs/{signoff}/return` | `attendance.sign`, `attendance.return` | 14 |

---

# Slice 1: Sites own workers

### Task 1: Home sites: schema, permissions and HR's move

**Files:**
- Create: `backend/database/migrations/2026_10_23_000001_create_employee_site_assignments.php`, `backend/app/Modules/Employees/Infrastructure/Eloquent/EmployeeSiteAssignment.php`, `backend/tests/Feature/EmployeeSitesTest.php`
- Modify:
  - Employees: `Infrastructure/Eloquent/Employee.php` (`siteAssignments()`), `Application/EmployeeService.php`, `Presentation/EmployeeController.php` (`assignSite`; `index` validates `site_id`);
  - `backend/routes/api.php`, `backend/lang/{ar,en}/errors.php`, `backend/tests/AttendanceFixtures.php`;
  - `backend/tests/Feature/RbacTest.php` (`test_seeded_matrix`: 23 permissions; manager `['attendance.record.own','employee.view.own','site.view.own']`; hr 16; developer 20);
  - `backend/tests/Feature/HotPathTest.php` (+`employee_site_assignments.site_id`).

**Interfaces:**
- Table `employee_site_assignments`: `standardColumns`; `employee_id` FK; `site_id` FK `->index()`; `effective_from` date; `unique(employee_id, effective_from)`. The next row closes the previous one. The model is `Audited` and has `site()`. The migration seeds the slice-1 permissions with the loop of `2026_10_20_000001`.
- `EmployeeService::assignSite(string $id, array{site_id: string, effective_from: string, returns_on?: ?string} $data): array` runs in one transaction. `returns_on` adds a second row holding the site in force on `effective_from − 1`. The 422s:
  - `effective_from: not_a_worker`: the type on that date is not a worker type;
  - `site_id`: not an active work site;
  - `duplicate_date`;
  - `returns_on: no_previous_site`;
  - `returns_on: later_move_inside`: another move lies in `(effective_from, returns_on]`.
- `show()` adds `current_site: {id,name_ar,name_en}|null` and `site_assignments: list<{id, site_id, site_name_ar, site_name_en, effective_from}>` (oldest first). `list()` takes `site_id`, meaning the home site today.

- [ ] **Step 1: Failing tests** (`EmployeeSitesTest`, HR):
  - `test_hr_moves_a_worker_with_a_dated_audited_row`: 201; `current_site.id` is Site-1; 1 `CREATED` audit row.
  - `test_a_return_date_records_the_return_move`: Site-1 from 10-01, then Site-2 on 10-21 returning 10-22 → [Site-1 10-01, Site-2 10-21, Site-1 10-22].
  - `test_refusals`: one 422 for each code above.
  - `test_only_assign_site_holders_move_people`: manager and ceo → 403.
  - `test_the_list_filters_by_home_site_today`: a worker moved there from tomorrow is not listed.
- [ ] **Steps 2–5:** `--filter='EmployeeSitesTest|RbacTest|HotPathTest'` → FAIL; implement; → PASS + gates B-*; commit "Give workers a dated home site".

### Task 2: Reach by date, and the site write guard

**Files:**
- Create: `backend/app/Modules/Employees/Application/EmployeeReach.php`, `backend/tests/Feature/ManagerReachTest.php`
- Modify: Employees `EmployeeService.php` (drop `CURRENT_MANAGER`), `EmployeeRoster.php`, `EnsureEmployeeInScope.php`; Attendance `Domain/Employment.php`, `Domain/AttendanceRefusal.php` (`SiteNotInReach = 'site_not_in_reach'`, 422), `AttendanceDays.php`, `AttendanceImport.php`, `AttendanceController.php`; `Sites/Application/SiteService.php`; `backend/lang/{ar,en}/attendance.php`.

**Interfaces:**
- `EmployeeReach::apply(Builder $employees, string $managerId, string $date): Builder` adds one `whereRaw`, by the type on D:
  - `engineer` → the manager assignment on D is `$managerId`;
  - `permanent_worker`/`day_laborer` → the home site on D is in `$managerId`'s `manager_sites` (P1);
  - else false (P2).
- `EmployeeReach::reaches(string $employeeId, string $managerId, string $date): bool`.
- Who uses it:
  - `EmployeeService::isManagedBy(string $employeeId, string $userId, ?string $date = null): bool` and `visibleTo`, at today;
  - `EmployeeRoster::employedAs`, at `min($to, today)` (P4);
  - `EnsureEmployeeInScope`, at the route's `date` when present;
  - `AttendanceController::bulk`, at `$data['date']`.
- The roster row gains `sites: list<{site_id, effective_from}>`. `Employment::homeSiteOn(array $employee, string $date): ?string`. `SiteService::idsManagedBy(string $managerId): list<string>`.
- **Write guard:** `AttendanceDays::save`, `bulk`, `writeCell` and `write` take `?string $ownerId`. With an owner and a work site, the site must be in `idsManagedBy`, and a worker's `homeSiteOn` must equal it. Otherwise `AttendanceRefused(SiteNotInReach)` (bulk: `rows.N`). This closes the `AttendanceDays.php:29` gap on every own-scope path.

- [ ] **Step 1: Failing tests** (`ManagerReachTest`; `$m = managerOf($s1)`; Worker-1 homed on Site-1 from 10-01; Engineer-1 assigned to `$m`):
  - `test_a_manager_reaches_workers_on_their_sites_and_their_engineers`: `/employees` as `$m` lists Worker-1 and Engineer-1, not an office employee assigned to `$m`, and not Worker-2 on Site-2.
  - `test_reach_follows_the_date_read_not_today`: Worker-1 moved to Site-2 on 10-21, returning 10-22. On 10-22, `isManagedBy(w1, $m, '2026-10-21')` is false and is true for Site-2's manager. `GET attendance/days/{w1}/2026-10-21` is a 404 for an own-scope `attendance.view` test role covering Site-1.
  - `test_reach_changes_at_cairo_midnight`: a move on 10-23 shows at `travelTo('2026-10-22 21:30')` UTC.
  - `test_an_own_scope_writer_cannot_use_a_site_outside_their_sites` (test role with `attendance.edit.own`):
    - an engineer at Site-2 in bulk → 422 `site_not_in_reach`, `details.0.field` `rows.0`;
    - Worker-1 at a second site of `$m` that is not his home site (PUT) → 422;
    - Site-1 → 200; a leave type → 200.
  - The existing own-scope tests in `ManagerScopeTest`, `AttendanceBulkTest` and `AttendanceSheetTest` stay green unchanged (they use engineers).
- [ ] **Steps 2–5:** `--filter='ManagerReachTest|ManagerScopeTest|AttendanceBulkTest|AttendanceSheetTest|AttendanceImportTest'` → FAIL; implement (mark `// ponytail: correlated subqueries; a reach view if manager lists pass ~1,000`); → PASS + gates B-*; commit "Scope managers by home site and engineer assignment on the date".

### Task 3: The manager's site list (read) and the demo data

**Files:**
- Create: `backend/app/Modules/Attendance/Application/SiteDay.php`, `backend/app/Modules/Attendance/Presentation/SiteDayController.php`, `backend/tests/Feature/SiteDayTest.php`
- Modify: `EmployeeRoster.php`, `backend/routes/api.php`, `backend/database/seeders/AttendanceDemoSeeder.php`, `backend/tests/Feature/AttendanceDemoSeederTest.php`

**Interfaces:**
- `EmployeeRoster::onSite(string $siteId, string $date): list<row>` lists the workers employed on D whose home site on D is `$siteId`. `EmployeeRoster::engineersOf(string $managerId, string $date): list<row>` lists the engineers assigned on D. Both are ordered by code.
- `SiteDay::read(string $siteId, string $date, string $managerId): array`:
  - a site not in `idsManagedBy` → `ModelNotFoundException`;
  - `$date > today` → `future_date`;
  - one `EffectiveDays::forPeriod` call per type present.
- The response:
  `{site:{id,name_ar,name_en}, date, today, rows:[{employee:{id,code,name_ar,name_en,type}, section:'workers'|'engineers', day:{state,version,site_id,leave_code,in_time,out_time,note}}]}`. Workers come first, and there are no pay fields.
- Route `GET attendance/site-day`, `permission:attendance.record`; `site_id` is a required uuid and `date` defaults to today.
- Seeder (P18): it links every `attendance.record.own` holder (`PermissionHolders::userIds`) to Site-1 and Site-2. It homes Worker-1 and Laborer-1 on Site-1 and Worker-2 and Laborer-2 on Site-2 from 2026-01-01. It assigns Engineer-1 and Engineer-2 to the first holder.

- [ ] **Step 1: Failing tests**
  - `test_the_list_holds_the_sites_workers_then_the_managers_engineers`: Site-1 → [Worker-1, Laborer-1, Engineer-1], with sections `workers`, `workers`, `engineers`, and no `transport`/`advance_amount` keys.
  - `test_engineers_appear_on_every_site_of_their_manager_and_show_where_they_are`: Engineer-1 stored at Site-2 → the Site-1 row has `day.site_id` Site-2.
  - `test_another_managers_site_is_404_and_a_future_date_is_refused`.
  - `test_hr_and_ceo_are_refused` → 403.
  - `test_demo_seeder_is_repeatable_and_refuses_production` gains: the manager reaches Worker-1.
- [ ] **Steps 2–5:** `--filter='SiteDayTest|AttendanceDemoSeederTest'` → FAIL; implement; → PASS + gates B-*; commit "Add the manager's site list".

### Task 4: Same-PR docs

**Files:**
- Create: `docs/adr/0004-employees-belong-to-sites.md`
- Modify: `CLAUDE.md`, `CONTEXT.md`, `docs/hrms-module-plan.md`, `docs/client-questions.md`

- [ ] **Step 1:** Write ADR 0004 in the 0002 style. It records:
  - the rules: Part 1's rules, with reach taken on the date read or written;
  - Considered: model A (both links), rejected by the probe because two managers contradicted each other;
  - Consequences: `manager_sites` is undated (P1); the importer needs a site column after PR #4.
- [ ] **Step 2:** In `CLAUDE.md`, replace "A manager sees only employees assigned to them. Each employee has exactly one manager at a time (office staff: their department manager); reassignment is dated." with: "A manager covers one or more sites and reaches, on each date, the workers whose home site is one of their sites and the engineers assigned to them. A worker has one dated home site; only HR moves it. Engineers have one dated manager and work at any of that manager's sites. Office staff keep a dated department manager for reference; HR enters their attendance, leave and requests."
- [ ] **Step 3:** In `CONTEXT.md`, rewrite **Manager** to match. Add **Home site** (الموقع الأساسي): "The site a worker belongs to from a date until HR moves them. A worker helping at another site, even for one day, is moved there first." _Avoid_: current site, assigned site.
- [ ] **Step 4:** In the module plan:
  - the Data entry row: "…for the people on their sites";
  - Employees placement: "home site (workers), manager (engineers; office reference)";
  - Release 1 item 5: "today, and yesterday until 12:00".
  - Client question 19 (Release 1): «هل يمكن أن يكون للموقع الواحد أكثر من مدير؟ *(default: نعم، ويرى كلاهما عمال الموقع، والحفظ الثاني لنفس اليوم يُرفض حتى يُعاد التحميل)*».
- [ ] **Step 5:** Commit "Record that employees belong to sites".

### Task 5: HR screens: home site and a site's workers

**Files:**
- Modify: `frontend/src/pages/employees.tsx`, `frontend/src/pages/sites.tsx`, `frontend/src/pages/audit.tsx` (`ENTITIES` += `employee_site_assignments`), `frontend/src/locales/{ar,en}.json`, `frontend/src/pages/employees.test.tsx`, `frontend/src/pages/settings.test.tsx`

**Interfaces:**
- **Employee page, for a worker** (`current_type` `permanent_worker`/`day_laborer`):
  - The header shows «الموقع الأساسي: X» in place of the manager.
  - The `manager` tab becomes `site` (`?tab=site`), with the history newest first.
  - «نقل إلى موقع» (`can('employee.assign_site')`) is a `FormDialog` with a site select (active work sites), `effective_from` and an optional `returns_on`.
  - Engineers and office staff keep the manager tab.
- **Site list** (P17): the row action «العمال» (`can('employee.view')`) opens a dialog listing `/employees?site_id=<id>&per_page=100`.
- Keys: `site.home` الموقع الأساسي; `site.homeNone`; `site.move` نقل إلى موقع; `site.returnsOn` يعود إلى الموقع السابق في; `site.homeHistory`; `site.workers` العمال; `site.workersNone`; `employee.tab.site`; `perm.employee.assign_site`; `perm.action.assign_site`; `perm.attendance.record`; `perm.action.record`; `audit.entity.employee_site_assignments`.

- [ ] **Step 1: Failing tests**
  - `'a worker shows the home site and moves with a return date'`: the POST body is `{site_id, effective_from:'2026-10-21', returns_on:'2026-10-22'}`.
  - `'an engineer keeps the manager tab'`.
  - `'no move button without employee.assign_site'`.
  - In `settings.test.tsx`: `'a work site lists its workers today'`.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/pages/employees.test.tsx src/pages/settings.test.tsx` → FAIL; implement; → PASS + gate F; commit "Show and move workers' home sites".

### Task 6: The manager's day screen, read-only

**Files:**
- Create: `frontend/src/pages/site-day.tsx`, `frontend/src/pages/site-day.test.tsx`, `frontend/src/lib/site-day.ts`
- Modify: `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/components/layout/AppLayout.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- `lib/site-day.ts` exports `type SiteDayRow` (the Task 3 row).
- NAV: the first item of the HR group is `{ path: '/site-day', key: 'nav.siteDay', icon: ClipboardCheck, abilities: ['attendance.record'] }`, so the manager's `homePath` is `/site-day`.
- `SiteDayPage` keeps its state in the URL (`?site=&date=`):
  - The site picker reads `/sites?kind=work&active=true` (own scope = the manager's sites). It is hidden with one site and defaults to the first.
  - The `h1` is the site name, followed by today's Cairo date.
  - The rows are a `role="list"` labelled `siteDay.rows`, as mockup A cards: name, type, and حاضر/غياب/مرضي shown pressed for the stored state but disabled in this slice.
  - Engineers sit under «مهندسين». One stored at another site shows `siteDay.atSite`, or falls back to `siteDay.otherSite`.
  - Loading, empty, no-sites and error states.
- Keys: `nav.siteDay` حضور الموقع; `siteDay.rows`; `siteDay.site`; `siteDay.today`; `siteDay.engineers` مهندسين; `siteDay.present` حاضر; `siteDay.absent` غياب; `siteDay.sick` مرضي; `siteDay.more`; `siteDay.atSite` في موقع: {{site}}; `siteDay.otherSite`; `siteDay.empty`; `siteDay.noSites`.

- [ ] **Step 1: Failing tests** (permissions `['attendance.record.own','site.view.own']`):
  - `'one site: no picker, workers then the engineers section'`;
  - `'two sites: the picker switches the list'` (the URL gets `site=`);
  - `'an engineer at another site shows that site'`;
  - `'no sites shows the empty state'`;
  - in `AppLayout.test.tsx`: `'a manager lands on /site-day'`.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/pages/site-day.test.tsx src/components/layout/AppLayout.test.tsx` → FAIL; implement; → PASS + gate F; commit "Add the manager's site list screen".

### Gate 1 (tester)
**Every gate's procedure** (later gates say only what differs):
1. Setup, run by the **controller** before the tester's round, because it writes to the local dev database and the tester changes nothing: `docker compose exec app php artisan migrate`, then `… db:seed --class=AttendanceDemoSeeder`. Approving this plan approves these local writes. The `testserver` database is never touched.
2. Checks: `pint --test`, `phpstan`, `deptrac`, `npm run lint`, `npx vitest run`, `npm run build`.
3. Captures with `capture.mjs` at 375/1024/1440 × ar/en × light/dark into `.claude/gate-ma-<n>/`.
4. `php artisan test`, last.
5. A dialog may be opened with `--click` for a shot, never submitted.

- **Feature files:** create `.claude/skills/verify-hrms/features/site-day.md` (README contract; route `/site-day?site=&date=`; wait `main [role="list"]`; `manager@smart.test`). Update `features/employees.md` (the `site` tab and the move dialog) and `README.md`.
- **Captures:**
  1. Manager: `/site-day`.
  2. Manager: `/site-day?site=<Site-2>`.
  3. HR: `/employees/<Worker-1>?tab=site`.
  4. HR: the move dialog (`--click`, not submitted).
  5. HR: `/settings?tab=sites` with Site-1's «العمال» dialog.
  6. Manager: `/employees`.
- **STOP: owner approves the screenshots before the next slice.**

---

# Slice 2: The day screen

### Task 7: Field shift, the manager window, and the formulas doc

**Files:**
- Create: `backend/app/Modules/Attendance/Domain/ManagerWindow.php`, `backend/database/migrations/2026_10_23_000002_add_manager_attendance_rules.php`, `backend/tests/Unit/ManagerWindowTest.php`
- Modify: `DayRules.php` (`FIELDS`), `EffectiveDays.php` (`hints`), `backend/app/Modules/Settings/Presentation/SettingController.php` (`RULES`), `backend/tests/Unit/DayRulesTest.php`, `backend/tests/Feature/SettingsApiTest.php` (17 → 18), `backend/tests/Feature/AttendanceDaysTest.php`, `docs/calculations.md`

**Interfaces:**
- **`DayRules::FIELDS`:**
  - `permanent_worker` gains `ot_minutes` after `out_time` (P5);
  - `day_laborer` becomes `['in_time','out_time','ot_minutes','late_minutes','advance_amount','note']`, and `out_before_in` applies to it.
- **Migration:**
  - the source CHECK becomes `('entered','imported','derived','manager')` (P9);
  - it seeds the setting `manager_yesterday_until` = `12:00` (P8), and `RULES` gains it as `self::TIME`;
  - `down()` reverts both.
- **`ManagerWindow::allows(string $date, string $today, string $now, string $until, bool $returned): bool`** is true when:
  - `$date === $today`;
  - `$date` is the day before and `$now < $until`;
  - or `$returned && $date <= $today`.
- **`ManagerWindow::isPastDayEdit(string $date, string $today, string $now, string $until): bool`:** `$date < $today` outside that yesterday grace.
- **`EffectiveDays::hints`** for an engineer or day-laborer work day, where `start` is `Employment::shiftOn()['start'] ?? worker_shift_start`:
  - `late_minutes = DayRules::officeLateMinutes(in, start, 0)` when `in_time` is set;
  - `ot_minutes = DayRules::workerMinutes(start, in, out, day_hours)['ot']` when both times are set.
- **`docs/calculations.md`:**
  - New §2b **Field shift**: one shift for the three field types (`worker_shift_start/_end`, the pay-row override). On a manager's save, late = max(0, in − shift start) with no grace, and OT = max(0, worked − **8** h) (P7). Each type keeps its lateness multiplier. HR-typed and imported minutes are unchanged (P6), so there is no October fixture diff.
  - §2 and §4: a day may record in/out times.
  - §3: "OT minutes = the day's stored `ot_minutes` when set, else computed from the times".

- [ ] **Step 1: Failing tests**
  - `ManagerWindowTest::test_yesterday_closes_at_noon_cairo`:
    - `allows('2026-10-21','2026-10-22','11:59','12:00',false)` → true; at `'12:00'` → false;
    - `'2026-10-20'` → false, but true when `returned`;
    - `isPastDayEdit` for 10-21 at `'11:00'` → false, at `'13:00'` → true.
  - `DayRulesTest::test_each_type_accepts_its_fields_and_rejects_the_others`:
    - permanent worker: `ot_minutes` accepted, `late_minutes` still `field_not_allowed`;
    - laborer: `in_time` accepted, in 20:00 / out 04:00 → `out_before_in`.
  - `AttendanceDaysTest::test_engineer_and_laborer_hints_come_from_the_field_shift`: an engineer in 08:20, out 17:00 → `{late_minutes:20, ot_minutes:40}`; a pay-row shift of 07:00 makes late 80.
  - `SettingsApiTest`: count 18; `manager_yesterday_until` is `12:00`.
- [ ] **Steps 2–5:** `--filter='ManagerWindowTest|DayRulesTest|AttendanceDaysTest|SettingsApiTest'` → FAIL; implement; → PASS + gates B-*; commit "Compute field lateness and overtime from times and add the manager window".

### Task 8: The manager's save

**Files:**
- Modify: `SiteDay.php`, `SiteDayController.php`, `AttendanceDays.php`, `AttendanceRefusal.php` (`OutsideManagerWindow = 'outside_manager_window'`, 422), `backend/routes/api.php`, `backend/lang/{ar,en}/attendance.php`, `AttendanceDemoSeeder.php`, `backend/tests/Feature/SiteDayTest.php`

**Interfaces:**
- **`SiteDay::FORMAT`:**
  - `site_id` uuid, `date`, `rows` 1–500 with distinct `employee_id`;
  - per row: `version`, `site_id` (present unless `clear`), `in_time`, `out_time`, `note`, `clear`. Nothing else is read (Review Focus 5).
  - A row's `site_id` must equal the request's `site_id`, or be an active leave site with code absent/sick/rest/other (P11). Otherwise 422 `rows.N.site_id`.
- **`SiteDay::save(string $siteId, string $date, array $rows, string $managerId): list<array{employee_id: string, version: ?int}>`,** in one transaction:
  1. An employee not in `read()`'s roster → 404.
  2. `ManagerWindow::allows(...)` fails (Cairo now, `returned` false until Task 15) → `OutsideManagerWindow` naming `rows.N`.
  3. A work row gets:
     - `late_minutes` computed (engineer and laborer, when `in_time` is set);
     - `ot_minutes = 0` when both times are set (the gate; Task 11 replaces the 0);
     - with no times, both null (P6).
  4. `AttendanceDays::bulk($date, $rows, $managerId, 'manager')` (new trailing `string $source = 'entered'`). The P28 keep rule leaves `transport`, `outside_*` and `advance_amount` as stored.
- Route `POST attendance/site-day` (`attendance.record`).
- **`read()` rows gain:**
  - `hints`;
  - `previous: {date,site_id,leave_code,in_time,out_time}|null` (P10, one `distinct on` query);
  - `can_edit` and `why_not: 'window'|'locked'|null`.
- **Seeder:** stored days on the last non-Friday before Cairo today (Worker-1 present at Site-1, 08:00–16:00; Laborer-1 sick; Engineer-1 present at Site-1).

- [ ] **Step 1: Failing tests** (`$m` covers Site-1)
  - `test_a_manager_marks_the_site_in_one_save_audited_with_source_manager`: 3 rows → versions 1, `source` `manager`, 3 `CREATED` audit rows; Worker-1 has transport `full` and `scheduled_in` `08:00`.
  - `test_lateness_is_computed_and_overtime_is_held_at_zero`: Engineer-1 08:20–17:00 → stored late 20, ot 0, read `hints.ot_minutes` 40; a laborer 08:00–16:30 → late 0, ot 0.
  - `test_yesterday_is_writable_until_noon_only`: 10-21 at 11:59 → 200; at 12:00 → 422 `outside_manager_window` (`rows.0`); 10-20 → 422; 10-23 → 422 `future_date`.
  - `test_two_managers_on_one_site_the_second_save_is_a_conflict`: both start from version null → the second is 409, and the stored day is the first manager's.
  - `test_a_manager_body_cannot_reach_hidden_fields_or_other_sites`:
    - `transport`/`advance_amount`/`ot_minutes`/`late_minutes` in the body are not stored, and HR's earlier transport `half` stays;
    - Site-2 → 422 `rows.0.site_id`; the Friday leave site → 422;
    - Worker-2 → 404; another manager's site → 404.
  - `test_a_locked_period_refuses_and_reads_as_not_editable`: 423; `why_not` is `locked`.
  - `test_previous_skips_friday_and_holidays`: at `cairo('2026-10-24 09:00')`, a stored Thursday plus a stored Friday leave → `previous.date` `2026-10-22`.
- [ ] **Steps 2–5:** `--filter=SiteDayTest` → FAIL; implement; → PASS + gates B-*; commit "Let a manager save the site's day".

### Task 9: The day screen: marking, details, «زي امبارح» and the save

**Files:**
- Create: `frontend/src/lib/site-day.test.ts`
- Modify: `frontend/src/lib/site-day.ts`, `frontend/src/pages/site-day.tsx`, `frontend/src/pages/site-day.test.tsx`, `frontend/src/pages/settings.tsx` (`schedule` += `manager_yesterday_until`), `frontend/src/pages/settings.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- **Pure functions** in `lib/site-day.ts`:
  - `type Draft = { site_id: string | null; in_time: string; out_time: string; note: string; clear?: true }`, built by `draftOf(row: SiteDayRow): Draft`.
  - `fillLikeYesterday(rows: SiteDayRow[], drafts: Record<string, Draft>, siteId: string): Record<string, Draft>`: fills only `missing` rows without a draft. A present `previous` becomes `siteId` plus its times, a leave keeps its leave site, and no `previous` leaves the row empty.
  - `differs(draft: Draft, previous: SiteDayRow['previous'], siteId: string): boolean`.
  - `counts(rows, drafts, sickSiteId, absentSiteId): {present, absent, sick, empty}`.
  - `saveBody(siteId, date, rows, drafts): {site_id, date, rows}`, with the changed rows only, each with its version.
- **UI:**
  - The status buttons are `aria-pressed` toggles; «⋯» is a `DropdownMenu` with rest and other.
  - Tapping the name toggles an inline details panel (`aria-expanded`): `TimeInput` arrival and leave (12-hour), the server's late and OT hints, and a note (max 500).
  - The differs edge. A sticky bottom bar (safe-area padding) with the counts and «حفظ اليوم» (disabled with no changes).
  - A 409 shows a banner with «تحميل الأحدث»; the drafts are kept until reload, and on any error.
  - Before 12:00 Cairo, a «أمس»/«اليوم» `SegmentedControl` switches `date`.
  - A row with `can_edit` false is disabled and shows `siteDay.why.<why_not>`.
- **Keys:** `siteDay.likeYesterday` (زي امبارح), `siteDay.save` (حفظ اليوم), `siteDay.saved`, `siteDay.count.{present,absent,sick,empty}` (empty: لم يُسجل {{count}}), `siteDay.changed` (تغيّر عن امبارح), `siteDay.{details,arrival,leave,late,overtime,note,yesterday,conflict,loadLatest}`, `siteDay.why.{window,locked}`, `rule.manager_yesterday_until`, `attendance.source.manager` (المدير). Change the text of `rule.worker_shift_start/_end` to بدء/انتهاء الوردية الميدانية (Field shift start/end).

- [ ] **Step 1: Failing tests**
  - `lib/site-day.test.ts`: `'fills only empty rows from previous, times included'`, `'a leave yesterday stays that leave'`, `'no previous stays empty'`, `'differs compares present vs leave'`, `'counts'`, `'saveBody sends only changed rows with their versions'`.
  - `site-day.test.tsx`:
    - `'one tap marks absent and the count moves'`;
    - `'«زي امبارح» fills, saves nothing until «حفظ اليوم», then one POST'` (assert the body);
    - `'a 409 keeps the drafts and offers reload'`;
    - `'the details panel sends 12-hour times as HH:MM'` (`8:20 ص` → `08:20`);
    - `'a non-editable row is disabled with its reason'`;
    - `'the changed row has the warning edge'`.
  - `settings.test.tsx`: the new field renders.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/lib/site-day.test.ts src/pages/site-day.test.tsx src/pages/settings.test.tsx` → FAIL; implement; → PASS + gate F; commit "Let the manager mark, fill and save the day".

### Gate 2 (tester)
- **Feature file:** update `features/site-day.md`. Gotchas: the status buttons and «حفظ اليوم» write, so never click them; «زي امبارح» and the name toggle store nothing, so `--click` is allowed.
- **Procedure** as in Gate 1. **Captures:**
  1. Manager: `/site-day`.
  2. Manager: «زي امبارح» clicked (the edges and counts).
  3. Manager: Worker-1's details panel.
  4. Manager: the «أمس» toggle (needs a Cairo time before 12:00; otherwise report it skipped).
  5. HR: `/settings?tab=rules`.
  6. HR: Engineer-1's day editor today (source المدير, the hints).
- **STOP: owner approves the screenshots before the next slice.**

---

# Slice 3: Overtime and advance requests

### Decision update · 2026-11-08

- Proposed overtime is work after the employee's scheduled shift end (09:00–16:00 for the standard field shift), starting no earlier than the actual arrival. Only approved minutes are credited.
- Record the advance request and print the paper form. HR records the paper decision. An approved advance is paid immediately in the system and is entered once in attendance for payroll. There is no separate disbursement action.
- The tasks below retain the original plan for reference. Reuse the existing UUID notification reference.

### Task 10: Requests module, notification refs, approve and refuse

**Files:**
- Create:
  - `backend/database/migrations/2026_10_23_000003_create_requests.php`;
  - under `backend/app/Modules/Requests/`: `Infrastructure/Eloquent/EmployeeRequest.php`, `Application/Requests.php`, `Presentation/RequestController.php`;
  - `backend/lang/{ar,en}/requests.php`, `backend/tests/Feature/RequestsTest.php`.
- Modify: `AttendanceDays.php`, `AttendanceRefusal.php` (`RequestDecided = 'request_decided'`, 409; P14), `backend/app/Modules/Notifications/Application/Notifier.php`, `backend/routes/api.php`, `backend/tests/Feature/NotificationsTest.php`, `RbacTest.php` (25; manager +`request.create.own`; hr 17; developer 21), `docs/calculations.md` (§2b gate)

**Interfaces:**
- **Table `requests`** (`Audited`): `standardColumns`; `type` CHECK `overtime|advance`; `employee_id` FK; `date`; `site_id` FK null; `minutes` smallint null (1–960); `amount` money null (> 0); `status` CHECK `pending|approved|refused`; `requested_by` FK users; `decided_by`, `decided_at`, `refusal_reason` null; `version` default 1; partial unique `(employee_id, date, type) where deleted_at is null`; index `(status, date)`.
- **The same migration** seeds the slice-3 permissions and adds `notifications.ref` (varchar 80, not null, default `''`), with the unique key now `(user_id, type, date, ref)` (P13).
- **`Notifier::notifyOnce(string $userId, string $type, string $date, array $data, string $ref = ''): bool`.**
- **`Requests::syncOvertime(string $employeeId, string $date, int $minutes, string $siteId, string $by): int`** runs in the caller's transaction and returns the minutes that count now:
  - none, pending or refused → upsert pending (version+1), or withdraw (soft delete) when `$minutes` is 0; returns 0;
  - approved with the same minutes → returns them;
  - approved with different minutes → back to pending; returns 0.
- **`Requests::syncAdvance(string $employeeId, string $date, ?string $amount, string $siteId, string $by): void`:** null withdraws a pending request; a decided request → `request_decided` (P12).
- **`Requests::decide(string $id, int $version, bool $approve, ?string $reason, string $by): array`:**
  - pending only, else `request_decided`; a stale version → `concurrency_conflict`;
  - approve → `AttendanceDays::applyApproved(string $employeeId, string $date, string $field, int|string $value): int`. That method locks the live row (none → `concurrency_conflict`), runs `PeriodGuard::assertOpen` (423), sets `ot_minutes` or `advance_amount`, and bumps the version (audited).
- **Notifications** go out on `DB::afterCommit`, ids only (§9):
  - `request_created` → every `request.decide.all` holder, with ref `"{id}:{version}"` and data `{request_id, kind, date, site_id}`;
  - `request_decided` → `requested_by`, with ref the id and data `{request_id, kind, status, date, site_id}`.
- **`GET requests`** filters by `status` (default `pending`; `all` allowed), `type`, `site_id`, `date` and `employee_id`. Each row: `{id,type,status,date,minutes,amount,version, employee:{id,code,name_ar,name_en}, site|null, requested_by:{id,name}, decided_by, decided_at, refusal_reason}`.
- **Decide routes:** `approve {version}`; `refuse {version, reason}` (required, max 500).
- **§2b gate text:** "Overtime from a manager's times counts only once HR approves it; the day's `ot_minutes` is the approved figure (0 until then and after a refusal). HR-typed or imported overtime counts as accepted."

- [ ] **Step 1: Failing tests** (requests built with `Requests::sync*` inside `DB::transaction`, after a manager day exists)
  - `test_approving_overtime_writes_the_day_and_notifies_the_manager`: 40 → the day's OT is 40, the version is bumped; 1 `request_decided` to the requester, and 1 `request_created` per HR user.
  - `test_refusing_needs_a_reason_and_leaves_zero`.
  - `test_an_approved_advance_lands_on_the_day`: `"250.5"` → `"250.500000"`.
  - `test_a_stale_decision_is_a_conflict`: re-synced to 50, then approved at the old version → 409, and nothing changes.
  - `test_a_decided_request_cannot_be_decided_again`: 409 `request_decided`.
  - `test_a_locked_period_refuses_approval`: 423.
  - `test_the_list_filters_and_needs_request_decide`: manager and ceo → 403.
  - `NotificationsTest::test_the_same_type_and_date_repeats_with_another_ref`.
- [ ] **Steps 2–5:** `--filter='RequestsTest|NotificationsTest|RbacTest'` → FAIL; implement; → PASS + gates B-*; commit "Add overtime and advance requests with HR approval".

### Task 11: The manager's save raises the requests

**Files:**
- Modify: `SiteDay.php` (`FORMAT` += `rows.*.advance_request`, nullable money string), `backend/routes/api.php` (the POST adds `permission:request.create`), `AttendanceDemoSeeder.php` (one pending overtime and one pending advance today), `backend/tests/Feature/SiteDayTest.php`

**Interfaces:**
- Before `bulk`:
  - each work row with both times gets `ot_minutes = Requests::syncOvertime(...)`;
  - a row with `advance_request` → `syncAdvance`;
  - a cleared or leave row withdraws its pending overtime.
- `read()` rows gain `requests: {overtime: {id,status,minutes}|null, advance: {id,status,amount}|null}`.

- [ ] **Step 1: Failing tests**
  - `test_overtime_becomes_a_pending_request_automatically`: 08:00–17:00 → pending 60, the day's OT is 0, and the read shows `pending`.
  - `test_changed_times_send_approved_overtime_back_to_pending`: approve 60 → OT 60; re-save 08:00–16:30 → pending 30 and OT 0.
  - `test_overtime_dropping_to_zero_withdraws_the_request`.
  - `test_an_advance_request_and_its_decided_lock`: `"300"` is pending; after approval, `"400"` → 409 and nothing is written.
  - `test_a_failed_save_leaves_no_request`.
- [ ] **Steps 2–5:** `--filter=SiteDayTest` → FAIL; implement; → PASS + gates B-*; commit "Raise overtime and advance requests from the manager's save".

### Task 12: HR's «الطلبات» page and the day editor's request status

**Files:**
- Create: `frontend/src/pages/requests.tsx`, `frontend/src/pages/requests.test.tsx`
- Modify: `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx` (HR group: `{ path: '/requests', key: 'nav.requests', icon: Inbox, abilities: ['request.decide'] }`), `frontend/src/pages/attendance-day.tsx`, `frontend/src/pages/attendance-day.test.tsx`, `frontend/src/pages/audit.tsx` (`requests`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- `RequestsPage` keeps `?status=&type=&site=&date=&page=` in the URL, following the list pages (`Filters`, `Table`, cards below `md`, `Pagination`).
  - Money goes through `useMoney`.
  - Approve sends the version. Refuse is a `FormDialog` with a required reason.
  - A 409 → toast `requests.conflict` and a refetch.
- The day editor (`can('request.decide')`) loads `/requests?employee_id=&date=&status=all` and shows a `StatusBadge` beside `ot_minutes` and `advance_amount`.
- Keys: `nav.requests` الطلبات; `requests.title` الطلبات; `requests.type.{overtime,advance}` (وقت إضافي، سلفة); `requests.status.{pending,approved,refused}` (قيد المراجعة، مقبول، مرفوض); `requests.{approve,refuse,reason,minutes,requestedBy,conflict,empty}`; `perm.request.{create,decide}`; `perm.resource.request`; `perm.action.decide`; `audit.entity.requests`.

- [ ] **Step 1: Failing tests**
  - `'pending requests list with approve sending the version'`;
  - `'refuse requires a reason'`;
  - `'a 409 refreshes'`;
  - `'filters go to the URL and the query'`;
  - `'no nav item without request.decide'`;
  - in `attendance-day.test.tsx`: `'the editor shows the overtime request status'`.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/pages/requests.test.tsx src/pages/attendance-day.test.tsx` → FAIL; implement; → PASS + gate F; commit "Add HR's requests page".

### Task 13: The manager's advance, request chips, and the bell's request lines

**Files:**
- Modify: `frontend/src/pages/site-day.tsx`, `frontend/src/pages/site-day.test.tsx`, `frontend/src/lib/site-day.ts` (`Draft.advance_request`, `saveBody`), `frontend/src/components/layout/NotificationBell.tsx`, `frontend/src/components/layout/NotificationBell.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- **Details panel:** «طلب سلفة» (`inputMode="decimal"`, sent as a string, never `Number()`), read-only once decided.
- **Row chips** show the overtime and advance request statuses.
- **Bell:** replace the single-type filter with `export function describe(n: Notification, t: TFunction): { title: string; lines: { text: string; to: string | null }[] } | null`. An unknown type → null, neither shown nor counted.
  - `attendance_missing_days` is unchanged.
  - `request_created` → `/requests?status=pending&date=`.
  - `request_decided` → `/site-day?date=&site=`.
- Keys: `siteDay.advance` طلب سلفة; `siteDay.req.{overtime,advance}`; `notifications.request.{created,decided}`.

- [ ] **Step 1: Failing tests**
  - `'an advance request is sent as a string with the day'`;
  - `'a decided advance is read-only'`;
  - `'request chips show their status'`;
  - in the bell test: `'request notifications are counted and open their screen'` and `'an unknown type is ignored'`.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/pages/site-day.test.tsx src/components/layout/NotificationBell.test.tsx` → FAIL; implement; → PASS + gate F; commit "Let the manager request advances and see request status".

### Gate 3 (tester)
- **Feature files:** create `features/requests.md` (Approve and Refuse write). Update `features/site-day.md`, `features/app-shell.md` (the bell) and `README.md`.
- **Procedure** as in Gate 1. **Captures:**
  1. HR: `/requests`.
  2. HR: the refuse dialog.
  3. HR: the day editor with the pending overtime.
  4. Manager: `/site-day` with the chips.
  5. Manager: the details panel with the advance.
  6. HR: the bell open.
- **STOP: owner approves the screenshots before the next slice.**

---

# Slice 4: Sign, return, skipped days and the digest

The owner confirmed the existing attendance periods and the HR workflow on 2026-11-08.
Manager sign-off is optional for HR. HR can review and correct attendance and lock the
period even when some or all employees have no manager sign-off. Sign-off limits only
manager edits, and a return reopens those edits for the affected employee. The existing
attendance checks still apply when HR locks the period.

Acceptance: HR corrects a day for an employee with no manager sign-off, then locks the
period successfully through the existing lock endpoint.

The implementation uses the existing `attendance.record` and `attendance.lock`
permissions for manager submission and HR return respectively. The scheduled
missing-days command also dispatches the manager digest.

### Task 14: Sign-offs, return, and the period view

**Files:**
- Create:
  - `backend/database/migrations/2026_10_23_000004_create_attendance_signoffs.php`;
  - under `backend/app/Modules/Attendance/`: `Infrastructure/Eloquent/AttendanceSignoff.php`, `Application/Signoffs.php`, `Presentation/SignoffController.php`;
  - `backend/tests/Feature/SignoffsTest.php`.
- Modify: `SiteDay.php` (`period`), `SiteDayController.php`, `AttendanceGrid.php` (grid rows gain `signoff`), `AttendanceRefusal.php` (`PeriodSigned = 'period_signed'` 423; `SignoffNotDue = 'signoff_not_due'` 422; `NotSigned = 'not_signed'` 409), `backend/routes/api.php`, `backend/lang/{ar,en}/attendance.php`, `RbacTest.php` (27; manager +`attendance.sign.own`; hr 18; developer 22)

**Interfaces:**
- **Table `attendance_signoffs`** (`Audited`): `standardColumns`; `employee_id` FK; `employee_type` CHECK on the 4 types; `period_start`; `status` CHECK `signed|returned`; `signed_by` FK users; `signed_at`; `returned_by`, `returned_at`, `return_note` null; `version` default 1; `unique(employee_id, employee_type, period_start)`. The migration seeds the slice-4 permissions.
- **`Signoffs::statusOf(string $employeeId, Period $p): ?string`.**
- **`Signoffs::sign(string $employeeId, string $periodStart, ?int $version, string $managerId): array`:**
  - today ≥ the period end, else `signoff_not_due`;
  - the employee must be in reach on the period end, else 404 (P15);
  - it creates the row or flips `returned` → `signed`; a stale version → 409.
- **`Signoffs::returnToManager(string $id, int $version, string $note, string $by): array`:** `signed` only, else `not_signed`. After commit, `period_returned` goes to `signed_by` with ref `"{id}:{version}"` and data `{signoff_id, employee_type, period_start, period_end}`.
- **`SiteDay::period(string $siteId, string $which, string $managerId): array`** returns rows of `{employee, period:{start,end}, marked, empty, pending_requests, signoff:{id,status,return_note,version}|null, can_sign}`:
  - each type's period containing today (`current`) or the one before (`previous`);
  - reach on `min(end, today)`;
  - `empty` = missing days up to `min(end, today)`.
- **Routes:** `GET attendance/site-period`; `POST attendance/signoffs {employee_id, period_start, version}`; `POST attendance/signoffs/{signoff}/return {version, note}`.
- **HR grid rows** gain `signoff: {id,status,version,return_note}|null`.

- [ ] **Step 1: Failing tests**
  - `test_a_manager_signs_from_the_periods_last_day`: 10-24 → 422 `signoff_not_due`; at `cairo('2026-10-25 18:00')` → 201 `signed`, audited.
  - `test_hr_returns_with_a_note_and_the_manager_is_notified`: no note → 422; with a note → `returned` and 1 `period_returned` to the signer.
  - `test_resigning_after_a_return`: back to `signed`, version 3.
  - `test_another_managers_employee_cannot_be_signed`: 404.
  - `test_the_period_view_counts_marked_empty_and_pending`: on 10-22, 3 stored days and 1 pending request → `marked` 3, `pending_requests` 1, and `empty` 21 (27 days 09-26 → 10-22, less 3 Fridays, less 3 stored).
  - `test_the_grid_shows_the_signoff_to_hr`.
- [ ] **Steps 2–5:** `--filter='SignoffsTest|RbacTest'` → FAIL; implement; → PASS + gates B-*; commit "Add the period sign-off and HR return".

### Task 15: Signed and returned periods on manager writes, and past-day alerts

**Files:**
- Modify: `SiteDay.php`, `AttendanceDemoSeeder.php` (Engineer-2 signed for the previous engineers' period; Worker-1 returned with the note `Gate return`), `backend/tests/Feature/SiteDayTest.php`

**Interfaces:**
- In `save()`:
  - a signed row → `PeriodSigned` naming `rows.N`;
  - a returned row → `ManagerWindow::allows(..., returned: true)`.
- After commit, each row where `isPastDayEdit` is true → `past_day_edited` to every `attendance.edit.all` holder, with ref `"{employee_id}:{date}:{version}"` and data `{employee_id, date, group, manager_id}`.
- `read()`: `why_not` gains `signed`.

- [ ] **Step 1: Failing tests**
  - `test_a_signed_period_refuses_the_manager_but_not_hr`: 423 `period_signed`; an HR PUT → 200.
  - `test_a_returned_period_reopens_past_days_for_that_employee_only`: Worker-1 is signed on 10-25 and HR returns it. At `cairo('2026-10-27 09:00')`, Worker-1 on 10-10 → 200; Laborer-1 on 10-10 → 422 `outside_manager_window`.
  - `test_past_day_edits_notify_hr_instantly_but_yesterday_before_noon_does_not`: that 10-10 edit → 1 `past_day_edited` per HR user. At `cairo('2026-10-22 11:00')`, an edit to 10-21 → 0.
- [ ] **Steps 2–5:** `--filter=SiteDayTest` → FAIL; implement; → PASS + gates B-*; commit "Honour signed and returned periods on the manager's save".

### Task 16: Skipped-day alert and the daily digest

**Files:**
- Create: `backend/app/Modules/Attendance/Application/SendManagerDigest.php`
- Modify: `SendMissingDaysAlert.php`, `AttendanceGrid.php` (`skippedBySite`), `backend/app/Modules/Attendance/Presentation/AlertMissingDaysCommand.php` (also dispatches the digest), `backend/CLAUDE.md` (the Scheduled line names both jobs; P16), `backend/tests/Feature/NotificationsTest.php`

**Interfaces:**
- **`AttendanceGrid::skippedBySite(string $date): array{sites: list<{site_id,name_ar,name_en,missing}>, engineers: list<{manager_id,manager_name,missing}>}`:**
  - per site with a manager: its workers (home site on D) whose day is `missing`;
  - per manager: their missing engineers;
  - only entries with `missing > 0`.
  - Add `// ponytail: one roster read per site; batch past ~100 sites`.
- **`SendMissingDaysAlert::handle`** also sends `attendance_skipped_days` once per (holder, D) when either list is non-empty.
- **`SendManagerDigest`** (`maintenance`, 3 tries, backoff `[60, 300]`, `failed()` logs `attendance.manager_digest_failed`) sends `attendance_manager_digest` to each `attendance.edit.all` holder:
  - data `{date, managers:[{manager_id, manager_name, days}]}`;
  - `days` = live `attendance_days` with `source = 'manager'` and date D, grouped by `updated_by`;
  - nothing when the list is empty.

- [ ] **Step 1: Failing tests**
  - `test_skipped_days_are_grouped_by_site_and_by_engineers_manager`: Site-1 with 2 workers, 1 saved → `missing` 1; an unsaved engineer appears in the engineers entry.
  - `test_the_digest_counts_each_managers_saved_days_once`: run twice → 1 row per HR user, `days` 3.
  - `test_both_jobs_are_queued_on_maintenance`.
- [ ] **Steps 2–5:** `--filter=NotificationsTest` → FAIL; implement, then `docker compose restart worker-maintenance scheduler`; → PASS + gates B-*; commit "Add the skipped-day alert and the manager digest".

### Task 17: Manager period tab, HR return, and the remaining bell lines

**Files:**
- Create: `frontend/src/pages/site-period.tsx`, `frontend/src/pages/site-period.test.tsx`
- Modify: `frontend/src/pages/site-day.tsx` (`?tab=day|period`), `frontend/src/pages/attendance.tsx` (`Grid`), `frontend/src/pages/attendance.test.tsx`, `NotificationBell.tsx` and its test, `frontend/src/pages/audit.tsx` (`attendance_signoffs`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- **`SitePeriod`:**
  - `which` switches between current and previous.
  - Rows show marked, empty and pending, and the signoff state.
  - «تم التوقيع» (`can('attendance.sign')`, enabled by `can_sign`) posts the version.
  - A returned row shows the note and links each empty day to `/site-day?date=`.
- **HR `Grid`:** the employee cell shows a `StatusBadge` (signed or returned). «إعادة للمدير» (`can('attendance.return')`) opens a `FormDialog` with a required note.
- **Bell `describe()`:**
  - `period_returned` → `/site-day?tab=period&which=`;
  - `past_day_edited` → `/attendance?tab=sheet&group=&date=`;
  - `attendance_skipped_days` → one line per site or manager → `/attendance?tab=sheet&group=&date=`;
  - `attendance_manager_digest` → one line per manager, `to: null`.
- **Keys:** `sitePeriod.tab`; `sitePeriod.current` الفترة الحالية; `sitePeriod.previous` الفترة السابقة; `sitePeriod.{marked,empty,pending}`; `sitePeriod.sign` تم التوقيع; `sitePeriod.returned` أُعيدت للتعديل; `sitePeriod.notDue`; `attendance.signoff.{return,note}` (إعادة للمدير); `notifications.periodReturned`; `notifications.pastDay`; `notifications.skipped.{title,site,engineers}`; `notifications.digest.{title,line}`; `perm.attendance.{sign,return}`; `perm.action.{sign,return}`; `audit.entity.attendance_signoffs`.

- [ ] **Step 1: Failing tests**
  - `'rows show counts and sign posts the version'`;
  - `'sign is disabled before the last day with the reason'`;
  - `'a returned row shows the note and links empty days'`;
  - `attendance.test.tsx`: `'HR returns a signed period with a required note'`;
  - bell: `'returned, past-day, skipped and digest lines render and link'`.
- [ ] **Steps 2–5:** `cd frontend && npx vitest run src/pages/site-period.test.tsx src/pages/attendance.test.tsx src/components/layout/NotificationBell.test.tsx` → FAIL; implement; → PASS + gate F; commit "Add the manager's period sign-off and HR return".

### Gate 4 (tester)
- **Feature files:** create `features/site-period.md` («تم التوقيع» writes). Update `features/attendance-grid.md` (the badge and the Return dialog), `features/app-shell.md` and `README.md`.
- **Procedure** as in Gate 1. The controller's setup also runs `docker compose exec app php artisan attendance:alert-missing <today>` (it writes notifications). **Captures:**
  1. Manager: `/site-day?tab=period&which=previous`.
  2. Manager: `/site-day?tab=period` (not due).
  3. Manager: Worker-1's returned period.
  4. HR: the engineers grid, previous period (the badge).
  5. HR: the Return dialog.
  6. HR: the bell (skipped, digest, past day).
  7. Manager: the bell (returned).
- **STOP: owner approves the screenshots before the PR.**

## Finish (controller)
1. Run one whole-branch Opus review from `b8e8a83..HEAD`, with the Review Focus and P1–P18, then one fix round.
2. Confirm that the October fixtures are untouched (no payroll code yet; §2b keeps HR-typed and imported minutes). The PR says so.
3. Only on the owner's go: `gh pr create --base main`. The body lists P1–P18 with the owner's answers and ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
