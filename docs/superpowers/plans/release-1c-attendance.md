# Release 1c: HR Attendance, Excel Import and Missing-Day Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR records one attendance day per employee per date for all four employee types, imports days from an Excel template with a preview, keeps an official-holiday list, locks each group's period, and gets a daily in-app missing-days alert. Nothing computes or shows money.

**Architecture:** A new backend module `Attendance` (tables `attendance_days`, `attendance_locks`, `official_holidays`). Its Domain holds the pure rules (`Period`, `DayRules`, `OffDays`, `Employment`), its Application holds the use cases (`EffectiveDays`, `AttendanceDays`, `AttendanceGrid`, `AttendanceLocks`, `OfficialHolidays`, then the import and the alert job), and its Infrastructure holds the Eloquent models, `PeriodGuard` (advisory lock plus lock check) and the OpenSpout reader and writer. Employees exposes a read model, `EmployeeRoster`, so Attendance never queries Employees' tables. A small `Notifications` module stores one row per (user, type, date) and serves the bell. The frontend adds `/attendance` (period grid, day editor sheet, lock, import tab), a holidays tab in Settings and a bell in the `AppLayout` header.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17, Redis queues, PHPUnit 12, Pint, Larastan, deptrac, `openspout/openspout` 5.12.2 (slice 3); React 19 + Vite + TS, TanStack Query, Tailwind 4, react-i18next, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-10-18-hr-attendance-design.md` (approved; every rule in it is settled). Also read `CLAUDE.md`, `backend/CLAUDE.md`, `frontend/CLAUDE.md`, `docs/coding-standards.md` (mandatory), `CONTEXT.md` and `docs/calculations.md` §1–4. Format precedent: `docs/superpowers/plans/release-1b-accounts.md` (backend), `release-1b-ui-slices.md` (UI slices, gates, key tables), `perf-architecture.md` (operating mode).

**Not in 1c** (one line each, from the spec):
- Manager mobile screen, «زي امبارح», the «تم التوقيع» sign-off, returning a period to a manager, the skipped-day alert: later plan.
- Pasting site attendance messages: later plan.
- Scanned paper sign-off upload: Release 3.
- Money of any kind, the advances ledger, leave balances, email, WhatsApp.
- Making `SiteService::visibleTo` public: not needed until the manager plan.

## Operating mode (binding)

- The session that runs this plan is the **controller only**. It plans, dispatches, reviews diffs, runs the gates, takes the gate screenshots and keeps the ledger `.superpowers/sdd/release-1c-attendance/progress.md`. It never edits code.
- One fresh implementer subagent per task, on the model the task names. Every review is a fresh **Opus** subagent. The loop per task is: brief + BASE sha → implementer → Opus review → at most one fix round → scoped re-review → ledger line.
- **Every implementer brief** starts with: load `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and (for code) `superpowers:test-driven-development`; read `docs/coding-standards.md` and this plan's Global Constraints. UI tasks (6–9, 12, 14) also load `ui-ux-pro-max:ui-ux-pro-max` and run its pre-delivery checks (contrast, visible focus, 44 px targets, `aria-label` on icon-only buttons). **Every reviewer brief** starts with `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and the `superpowers:requesting-code-review` rubric, plus this plan's Review Focus.
- Each slice ends with an **owner gate**. No task of the next slice starts before the owner approves.
- At ~35 % context the controller writes the handoff and ends the turn.
- Branch: `feature/release-1c-attendance` (stacked on PR #2, `feature/perf-architecture`). One PR at the end, only on the owner's go.

## Global Constraints

- No PHP on the host. Backend commands run from the repo root as `docker compose exec app …`; frontend commands run on the host in `frontend/`.
- **Gates** (each task lists the ones it must leave green; run them before its commit):
  - `B-test`: `docker compose exec app php artisan test`
  - `B-pint`: `docker compose exec app vendor/bin/pint --test`
  - `B-stan`: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G`
  - `B-deptrac`: `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`
  - `F`: `cd frontend && npm test && npm run build && npm run lint`
- TDD: the failing test is written and seen failing before the code. The PHPUnit tests use `RefreshDatabase`, `signIn()` and a fixed Cairo clock.
- Verbatim from `CLAUDE.md`:
  - "Money: PostgreSQL `NUMERIC(18,6)` with bcmath decimal strings (as the CRM's `Precision`/`Decimal`). Never use floats. Do not round intermediate values (the Excel doesn't); display 2 decimals."
  - "Time zone `Africa/Cairo`; store UTC."
  - "Every create/update/delete, approval, override, login and period lock/reopen writes an audit-log entry with before/after values."
  - "Every UI string goes through i18n keys in both Arabic (RTL, default) and English."
  - "Roles and permissions are data (CRM-style `resource.action.scope`), editable from the developer console. Check permissions by name, never by role name."
  - "These numbers are **settings** seeded with the values below. Read them from settings and keep them out of code literals" (Business rules).
- Attendance dates are plain `date` columns; times are `time` columns holding Cairo wall-clock time and travel through the API as `HH:MM`. "Today" is `now('Africa/Cairo')->toDateString()`. Every clock read uses Carbon `now()` so tests can `travelTo`.
- Money columns use the `money()` macro (`NUMERIC(18,6)`), models cast them with `Precision::CAST_MONEY`, the API returns them as strings (`"500.000000"`), and the frontend never calls `Number()` or `parseFloat` on them.
- **New migrations only.** Never edit an applied migration (coding-standards §7). Permissions and setting keys are added in a new migration that copies the seeding loop of `2026_10_19_000001_create_settings.php`. `MigrationsEnded` already clears the grant and settings caches.
- Every new business table uses `standardColumns()`; its model uses `HasStandardColumns` and, unless the task says otherwise, `Audited`. Rows are never hard-deleted.
- Module layout `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`. Domain imports nothing from `Illuminate\*`, Carbon or `App\Support`; it uses `DateTimeImmutable` and plain bcmath (scale 6). Presentation never touches Infrastructure. OpenSpout lives in Infrastructure only.
- The lock table's column is `employee_type`, not `group` (a reserved SQL word). The API parameter stays `group`, with values `office|engineer|permanent_worker|day_laborer`.
- Every response goes through `ApiEnvelope`; lists are paginated (`per_page` max 100). Routes sit in the `auth` group with `permission:resource.action`.
- Frontend: the 1b UI-slices Global Constraints apply unchanged (logical `start/end` classes only, native `<select>` through `SelectField`, `RowActions` with `modal={false}`, tabs synced to `?tab=`, Latin digits, `useList`/`useApi`/`useSave` only, `can()` by permission name, queries by role and accessible name in tests, `mockFetch`/`renderWithAuth` from `src/test-utils.tsx`).
- i18n: every new key exists in both `ar.json` and `en.json` (the locale test enforces it). Arabic is the default, in formal Egyptian business Arabic (MSA) matching `ar.json`'s existing wording.
- **No personal data.** Fixtures and demo data use labels: `Engineer-1`, `Worker-1`, `Office-1`, `Laborer-1`, `Site-1`, `Holiday-1`.
- Ponytail: no speculative abstraction, fewest files, comments only for a non-obvious why.
- Commit once per task, with the task's subject and the trailer as a second paragraph: `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

## Review Focus

1. **A template downloaded before someone else edited a day, then uploaded.** The stale cell must be a `concurrency_conflict` cell error, never an overwrite. Test: Task 11 `test_a_cell_changed_since_the_download_is_a_conflict_not_an_overwrite`.
2. **Excel rewriting the template's cells** (grid template, 2026-10-20): header dates turned into date cells or serials, Arabic-Indic digits in codes, extra spaces in site names. They are all read the same as what was downloaded. Test: Task 11 `test_header_dates_in_any_excel_form_are_read_the_same`.
3. **Cairo midnight.** At 00:30 Cairo, UTC is still on the previous day. That Cairo date is today and may be entered; the next day is future. Test: Task 3 `test_today_follows_the_cairo_date`.
4. **Someone hired or terminated mid-period.** The days outside employment are `not_employed`: they are never missing, never counted in the alert, never in the template, and a write to them is refused. Test: Task 3 `test_days_outside_employment_are_not_missing`.
5. **A holiday on a date where one group's period is locked and another's is open** (periods differ per group). It is refused. Test: Task 4 `test_a_holiday_is_refused_when_any_groups_period_containing_it_is_locked`.

## Spec test map (slice 1)

Each test the spec lists for slice 1 maps to exactly one named test:

| Spec item | Test | Task |
|---|---|---|
| The fields each type accepts or rejects | `DayRulesTest::test_each_type_accepts_its_fields_and_rejects_the_others` | 2 |
| Leave-type restrictions | `DayRulesTest::test_a_leave_day_rejects_times_minutes_outside_and_transport_but_allows_an_advance` | 2 |
| Future dates | `AttendanceDaysTest::test_a_future_date_is_refused` | 3 |
| Writes to a locked period refused | `AttendanceLockTest::test_writes_to_a_locked_period_are_refused` | 4 |
| Reopen with a reason | `AttendanceLockTest::test_reopen_requires_a_reason_and_keeps_materialized_rows` | 4 |
| Derived Fridays and holidays, holiday over Friday | `AttendanceDaysTest::test_fridays_and_holidays_are_derived_and_a_holiday_wins_over_friday` | 3 |
| Holiday edits refused inside a locked period | `OfficialHolidaysTest::test_holiday_edits_inside_a_locked_period_are_refused` | 4 |
| A stale version returns 409 | `AttendanceDaysTest::test_a_stale_version_returns_409_concurrency_conflict` | 3 |
| An inactive employee on the date | `AttendanceDaysTest::test_an_employee_not_active_on_the_date_is_refused` | 3 |
| The night shift | `AttendanceDaysTest::test_a_night_shift_crosses_midnight_and_belongs_to_the_in_date` | 3 |
| The missing-days count | `AttendanceDaysTest::test_missing_days_are_counted_and_never_treated_as_absence` | 3 |
| An audit entry on every write | `AttendanceDaysTest::test_every_write_is_audited_with_before_and_after_values` (days) and `AttendanceLockTest::test_lock_reopen_and_holidays_are_audited` (lock, reopen, holidays) | 3, 4 |

## API (all under `/api/v1`, inside `auth`)

| Method | Path | Permission | Task |
|---|---|---|---|
| GET | `attendance/period?group=&period_start=` | `attendance.view` | 3 |
| GET | `attendance/grid?group=&period_start=&per_page=&page=` | `attendance.view` | 3 |
| GET | `attendance/missing?group=&period_start=&per_page=` | `attendance.view` | 3 |
| GET | `attendance/days/{employee}/{date}` | `attendance.view` + `employee.scope` | 3 |
| PUT | `attendance/days/{employee}/{date}` | `attendance.edit` + `employee.scope` | 3 |
| DELETE | `attendance/days/{employee}/{date}?version=` | `attendance.edit` + `employee.scope` | 3 |
| POST | `attendance/locks` | `attendance.lock` | 4 |
| POST | `attendance/locks/reopen` | `attendance.lock` | 4 |
| GET | `attendance/holidays?year=&per_page=` | `attendance.view` | 4 |
| POST / PATCH / DELETE | `attendance/holidays`, `attendance/holidays/{holiday}` | `holiday.manage` | 4 |
| GET | `attendance/template?group=&period_start=` | `attendance.edit` | 10 |
| POST | `attendance/import/preview`, `attendance/import/confirm` (multipart) | `attendance.edit` | 11 |
| GET | `notifications?per_page=` | auth only (own rows) | 13 |
| PATCH | `notifications/{notification}/read` | auth only (own rows) | 13 |

`{date}` is `where('date', '\d{4}-\d{2}-\d{2}')` and must be a real date (else 422 `date`). `period_start` absent means the period containing today; one that is not a boundary of the group is 422 under `period_start`.

---

# Slice 1 — Backend core (Opus implements, Opus reviews)

### Task 1: Attendance schema, permissions, settings keys and periods

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md`.

**Files:**
- Create: `backend/database/migrations/2026_10_20_000001_create_attendance_tables.php`
- Create: `backend/app/Modules/Attendance/Domain/Period.php`
- Create: `backend/database/migrations/2026_10_19_000003_add_saturday_leave_code.php` (sorts before the index migration below)
- Create: `backend/app/Modules/Attendance/Infrastructure/Eloquent/{AttendanceDay,AttendanceLock,OfficialHoliday}.php`
- Modify: `backend/app/Modules/Sites/Domain/LeaveCode.php` (`case Saturday = 'saturday';` after `Friday`), `backend/app/Modules/Settings/Presentation/SettingController.php` (`RULES`), `backend/app/Modules/Sites/Application/SiteService.php` (`assertLeaveCode`), `backend/lang/{ar,en}/errors.php`, `backend/lang/{ar,en}/validation.php` (`attributes`)
- Modify tests: `backend/tests/Feature/RbacTest.php` (`test_seeded_matrix`), `backend/tests/Feature/SettingsApiTest.php` (count 15 → 17), `backend/tests/Feature/HotPathTest.php` (index list)
- Test: `backend/tests/Unit/PeriodTest.php`, `backend/tests/Feature/AttendanceSchemaTest.php`, `backend/tests/Feature/SaturdayLeaveCodeMigrationTest.php`

**Interfaces:**
- Produces:
  - `LeaveCode::Saturday = 'saturday'`. `sites.leave_code` has no CHECK constraint (`2026_10_17_000001` has none); the API accepts the new value through `SiteController`'s existing `Rule::enum(LeaveCode::class)`.
  - Migration `2026_10_19_000003_add_saturday_leave_code.php` (idempotent; a data migration, so it runs on every environment, test DB included):
    1. Recode: every live, active `kind = 'leave'` site with `leave_code = 'friday'` and `name_ar like '%السبت%'` gets `leave_code = 'saturday'`.
    2. If no live, active `saturday` leave site exists afterwards, insert `{name_ar: 'اجازة السبت', kind: 'leave', leave_code: 'saturday', active: true, outside_alexandria: false}` with a `Str::uuid7()` id.
    3. Audit. No existing migration writes `audit_log`; the grant and setting migrations insert straight into their tables. Since this one changes business rows that HR edits, it inserts its own `audit_log` rows through `DB::table`: `UPDATED` per recoded site (`{leave_code: 'friday'}` → `{leave_code: 'saturday'}`) and `CREATED` for an inserted one. Each row has `user_id` null, `entity_type` `sites` and `occurred_at` now. A re-run matches nothing, so it writes nothing.
    4. `down()` recodes `saturday` back to `friday`, restoring the earlier codes. A row inserted by `up()` stays, as a leave type HR can deactivate.
  - Table `attendance_days`: `standardColumns`; `employee_id` FK `employees` restrictOnDelete; `date` date; `site_id` uuid null FK `sites` restrictOnDelete, `->index()`; `in_time`, `out_time`, `scheduled_in`, `scheduled_out` time null; `late_minutes`, `ot_minutes`, `outside_minutes` smallint null; `outside_amount` money null; `transport` varchar(4) null; `advance_amount` money null; `note` text null; `source` varchar(10); `version` integer default 1; `unique(employee_id, date)` (plain, not partial: a cleared row is restored, never re-inserted).
    - CHECKs: each minutes column `is null or between 0 and 960`; `transport is null or transport in ('full','half','none')`; `source in ('entered','imported','derived')`; `outside_amount is null or outside_amount > 0`; `advance_amount is null or advance_amount > 0`; `version >= 1`.
  - Table `attendance_locks`: `standardColumns`; `employee_type` varchar(20) CHECK in the four types; `period_start` date; `period_end` date; `locked_by` uuid null; `locked_at` timestamptz null; `reopened_by` uuid null; `reopened_at` timestamptz null; `reopen_reason` text null; `unique(employee_type, period_start)`. A period is locked when its row exists and `reopened_at is null`.
  - Table `official_holidays`: `standardColumns`; `date` date; `name` varchar(128); partial unique `official_holidays_date_unique on (date) where deleted_at is null`.
  - Index `sites_active_leave_code_unique on sites (leave_code) where kind = 'leave' and active and deleted_at is null`.
  - Permissions `attendance.view.all`, `attendance.edit.all`, `attendance.lock.all`, `holiday.manage.all`. Grants: developer and hr get all four; ceo gets `attendance.view.all`; manager gets nothing. `down()` deletes the four permissions (the grants cascade), the two settings rows, the index and the three tables.
  - Settings `worker_shift_start` = `08:00`, `worker_shift_end` = `16:00`; `SettingController::RULES` gains both as `self::TIME`.
  - Models: `AttendanceDay` (`Audited`, `HasStandardColumns`, casts both money columns `Precision::CAST_MONEY`, `version` integer); `OfficialHoliday` (`Audited`, `HasStandardColumns`); `AttendanceLock` (`HasStandardColumns` only: Task 4 audits it with explicit events), casts `locked_at`/`reopened_at` datetime.
  - `final readonly class Period { public function __construct(public EmployeeType $group, public string $start, public string $end) }` with:
    - `static containing(EmployeeType $group, string $date): self`: office = calendar month; engineer and permanent_worker = the 26th to the 25th; day_laborer = the 1st–15th or the 16th to month end.
    - `static startingAt(EmployeeType $group, string $start): self`, which throws `InvalidArgumentException` unless `$start` is that group's period boundary.
    - `previous(): self`, `next(): self`, `days(): list<string>` (Y-m-d, inclusive), `contains(string $date): bool`.
  - `SiteService::assertLeaveCode` also refuses a second active leave type with the same code: 422 under `leave_code` with `__('errors.leave_code_taken')`.
  - `errors.leave_code_taken`: ar `يوجد نوع إجازة نشط آخر بنفس الكود.`, en `Another active leave type already uses this code.`
  - `validation.attributes` (ar / en): `in_time` وقت الحضور / in time; `out_time` وقت الانصراف / out time; `scheduled_in` الحضور المقرر / scheduled in; `scheduled_out` الانصراف المقرر / scheduled out; `late_minutes` دقائق التأخير / late minutes; `ot_minutes` دقائق الوقت الإضافي / overtime minutes; `outside_minutes` دقائق العمل خارج الإسكندرية / minutes outside Alexandria; `outside_amount` مبلغ العمل خارج الإسكندرية / outside-Alexandria amount; `transport` المواصلات / transport; `advance_amount` السلفة / advance; `version` رقم النسخة / version; `group` المجموعة / group; `period_start` بداية الفترة / period start; `confirm_missing` تأكيد القفل / lock confirmation; `reason` السبب / reason; `file` الملف / file; `year` السنة / year; `worker_shift_start` بدء وردية العمال المثبتين / permanent workers' shift start; `worker_shift_end` انتهاء وردية العمال المثبتين / permanent workers' shift end.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/PeriodTest.php`:
```php
public function test_periods_per_group(): void
{
    $p = Period::containing(EmployeeType::Engineer, '2026-10-20');
    $this->assertSame(['2026-09-26', '2026-10-25'], [$p->start, $p->end]);
    $this->assertCount(30, $p->days());
    $this->assertSame(['2026-09-26', '2026-10-25'], [$p->days()[0], $p->days()[29]]);
    $this->assertSame(['2026-10-26', '2026-11-25'], [Period::containing(EmployeeType::PermanentWorker, '2026-10-26')->start, Period::containing(EmployeeType::PermanentWorker, '2026-10-26')->end]);
    $this->assertSame('2026-12-26', Period::containing(EmployeeType::Engineer, '2027-01-10')->start);
    $this->assertSame(['2026-02-01', '2026-02-28'], [Period::containing(EmployeeType::Office, '2026-02-14')->start, Period::containing(EmployeeType::Office, '2026-02-14')->end]);
    $this->assertSame('2026-10-15', Period::containing(EmployeeType::DayLaborer, '2026-10-15')->end);
    $this->assertSame(['2026-10-16', '2026-10-31'], [Period::containing(EmployeeType::DayLaborer, '2026-10-16')->start, Period::containing(EmployeeType::DayLaborer, '2026-10-16')->end]);
    $this->assertSame('2028-02-29', Period::containing(EmployeeType::DayLaborer, '2028-02-20')->end);
    $this->assertSame('2026-08-26', $p->previous()->start);
    $this->assertSame('2026-10-26', $p->next()->start);
    $this->assertSame('2026-09-16', Period::containing(EmployeeType::DayLaborer, '2026-10-01')->previous()->start);
    $this->assertTrue($p->contains('2026-10-25'));
    $this->assertFalse($p->contains('2026-10-26'));
}

public function test_a_period_must_start_on_its_groups_boundary(): void
{
    $this->assertSame('2026-10-25', Period::startingAt(EmployeeType::Engineer, '2026-09-26')->end);
    $this->expectException(InvalidArgumentException::class);
    Period::startingAt(EmployeeType::Engineer, '2026-10-01');
}
```

`tests/Feature/AttendanceSchemaTest.php`:
- `test_attendance_days_enforce_their_constraints`: a second row for the same (employee, date) throws `UniqueConstraintViolationException`. `late_minutes` 961, `transport` `'bus'`, `source` `'typed'` and `advance_amount` `'0'` each throw `QueryException`. Insert through `DB::table`; create the employee with `EmployeeService::create`.
- `test_one_active_leave_type_per_code`: signed in as `hr`, `POST /sites {name_ar: 'Leave-1', kind: 'leave', leave_code: 'friday'}` → 201. A second with `name_ar: 'Leave-2'` → 422, `error.details.0.field` = `leave_code`. `PATCH` the first to `active: false`, then the second → 201. `POST` a `saturday` leave type → 422 (the migration's «اجازة السبت» already holds the code).

`tests/Feature/SaturdayLeaveCodeMigrationTest.php` runs the migration with `$up = fn () => (require database_path('migrations/2026_10_19_000003_add_saturday_leave_code.php'))->up();`. Setup for both cases: `DB::statement('drop index sites_active_leave_code_unique')` (DDL rolls back with the test transaction), then `DB::table('sites')->where('leave_code', 'saturday')->delete()` (removes the row the migration made during `RefreshDatabase`).
- `test_a_saturday_row_coded_friday_is_recoded`:
  - insert active leave sites «اجازة الجمعة» and «اجازه السبت», both `friday`;
  - `$up()` twice;
  - «اجازه السبت» is `saturday` and «اجازة الجمعة» stays `friday`;
  - exactly one active `saturday` site, so nothing was inserted;
  - exactly one `audit_log` row with `event` UPDATED, `entity_type` `sites`, `old_values` `{"leave_code":"friday"}` and `new_values` `{"leave_code":"saturday"}`.
- `test_a_missing_saturday_leave_type_is_created`:
  - insert only «اجازة الجمعة» `friday`;
  - `$up()` twice;
  - exactly one active `saturday` site, named `اجازة السبت`;
  - one `audit_log` CREATED row for it;
  - «اجازة الجمعة» is untouched.

In `RbacTest::test_seeded_matrix`: `Permission::count()` 21; ceo `['attendance.view.all', 'audit_log.view.all', 'employee.view.all', 'setting.view.all', 'site.view.all']`; hr count 15 and contains the four new triples; developer count 19; manager unchanged. In `SettingsApiTest`: `assertJsonCount(17, 'data')`, `data.worker_shift_start` = `08:00`, `data.worker_shift_end` = `16:00`. In `HotPathTest::test_foreign_keys_and_search_columns_are_indexed`, add `attendance_days.site_id` to the column list.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --filter='PeriodTest|AttendanceSchemaTest|SaturdayLeaveCodeMigrationTest|RbacTest|SettingsApiTest|HotPathTest'`
Expected: FAIL (no `Period`, no tables, no `saturday` code, counts 17/15).

- [ ] **Step 3: Write the two migrations, `LeaveCode::Saturday`, the three models, `Period`, the `RULES` keys, the leave-code check and the lang keys listed in Interfaces**

The migration aborts with a clear message when existing data already has two active leave types with the same code. `Period` uses `DateTimeImmutable` only.

- [ ] **Step 4: Run them and see them pass, then prove the migration reverses**

Run: `docker compose exec app php artisan test --filter='PeriodTest|AttendanceSchemaTest|SaturdayLeaveCodeMigrationTest|RbacTest|SettingsApiTest|HotPathTest'` → PASS.
Then, on the test database only: `docker compose exec -e DB_DATABASE=hrms_test app php artisan migrate:fresh`, then `… migrate:rollback --step=2`, then `… migrate`. All three must succeed.
On the dev database, which holds the owner's «اجازه السبت» coded `friday`, run `docker compose exec app php artisan migrate`. It must pass the index migration, because the recode runs first. Then `select name_ar, leave_code from sites where kind = 'leave' and active` must show «اجازه السبت» as `saturday`.

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 5: Commit** — `git add backend && git commit -m "Add the attendance tables, permissions, worker shift settings and periods" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 2: Day rules (pure domain)

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md`.

**Files:**
- Create: `backend/app/Modules/Attendance/Domain/{DayRules,OffDays,Employment}.php`
- Test: `backend/tests/Unit/DayRulesTest.php`

**Interfaces:**
- Consumes: `Employees\Domain\{EmployeeType,EffectiveDated}`, `Sites\Domain\SiteKind`.
- Produces:
  - `DayRules::MAX_MINUTES = 960`.
  - `DayRules::FIELDS` (work-day fields per type):
    - `office` → `['in_time','out_time']`
    - `engineer` → `['in_time','out_time','late_minutes','ot_minutes','outside_minutes','advance_amount','note']`
    - `permanent_worker` → `['scheduled_in','scheduled_out','in_time','out_time','outside_amount','transport','advance_amount','note']`
    - `day_laborer` → `['ot_minutes','late_minutes','advance_amount','note']`
  - `DayRules::LEAVE_DAY_FIELDS = ['advance_amount','note']`. On a leave day a type keeps only these of its own fields; `transport` is accepted only as `none`.
  - `DayRules::violations(EmployeeType $type, ?array $site, array $fields): list<array{field: string, code: string}>`, in field order.
    - `$site` is `array{kind: string, outside_alexandria: bool, active: bool}|null`; null means no site.
    - `$fields` holds only the non-null values (minutes as int, amounts and times as strings).
    - Codes:
      - `site_required`: a non-office type has no site.
      - `site_not_allowed`: office with a work site.
      - `site_inactive`: the site is not active.
      - `field_not_allowed`: the field is not in the type's set, it is a leave day, or it is `outside_*` on a site without `outside_alexandria`.
      - `out_of_bounds`: minutes outside 0–960, or an amount ≤ 0 (`bccomp` at scale 6).
      - `out_before_in`: out < in for any type but permanent_worker.
      - `times_need_both`: a permanent worker has only one of the actual in/out times.
  - `DayRules::officeLateMinutes(string $in, string $shiftStart, int $graceMinutes): int` = max(0, in − shift start − grace).
  - `DayRules::workerMinutes(string $scheduledIn, string $in, string $out, string $dayHours): array{late: int, ot: int}`:
    - worked = out − in, plus 1440 when out < in (night shift);
    - late = max(0, in − scheduled in);
    - ot = max(0, worked − dayHours × 60), using bcmath for `dayHours` (`'7.5'` → 450).
  - `OffDays::leaveCodeFor(string $date, list<string> $weeklyOff, bool $isHoliday): ?string` returns:
    - `official_holiday` if the date is a holiday;
    - else the lowercase weekday name itself if it is in `$weeklyOff` (`friday` → `friday`, `saturday` → `saturday`);
    - else null.
    - `SettingController::RULES` lets `weekly_off_*` hold any of the seven weekdays. A weekday with no matching active leave type (e.g. `thursday`) is still derived. It then has `site_id` null, and the lock refuses it with `leave_type_missing` (Task 4).
    - This is the smaller option: no rule, settings-UI or test changes. The alternative would restrict `RULES` to `friday|saturday` and change the Settings checkboxes.
  - `Employment::typeOn(array $employee, string $date): ?EmployeeType`. `$employee` is the `EmployeeRoster` shape (Task 3). It returns null unless all three hold: `hire_date` is null or ≤ date, `termination_date` is null or ≥ date, and a type is in force on the date (`EffectiveDated::at` over `types`).
  - `Employment::shiftOn(array $employee, string $date): array{start: ?string, end: ?string}`, from `EffectiveDated::at` over `shifts`.

- [ ] **Step 1: Write the failing tests** (`tests/Unit/DayRulesTest.php`)

```php
private const WORK = ['kind' => 'work', 'outside_alexandria' => true, 'active' => true];
private const LEAVE = ['kind' => 'leave', 'outside_alexandria' => false, 'active' => true];

public function test_each_type_accepts_its_fields_and_rejects_the_others(): void
{
    $this->assertSame([], DayRules::violations(EmployeeType::Engineer, self::WORK, ['in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => 10, 'ot_minutes' => 60, 'outside_minutes' => 30, 'advance_amount' => '500', 'note' => 'n']));
    $this->assertSame([['field' => 'transport', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Engineer, self::WORK, ['transport' => 'full']));
    $this->assertSame([['field' => 'in_time', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['in_time' => '08:00']));
    $this->assertSame([['field' => 'late_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['late_minutes' => 5]));
    $this->assertSame([['field' => 'advance_amount', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Office, null, ['advance_amount' => '1']));
    $this->assertSame([['field' => 'site_id', 'code' => 'site_not_allowed']], DayRules::violations(EmployeeType::Office, self::WORK, []));
    $this->assertSame([], DayRules::violations(EmployeeType::Office, null, ['in_time' => '09:40', 'out_time' => '17:00']));
    $this->assertSame([['field' => 'site_id', 'code' => 'site_required']], DayRules::violations(EmployeeType::Engineer, null, []));
    $this->assertSame([['field' => 'site_id', 'code' => 'site_inactive']], DayRules::violations(EmployeeType::Engineer, [...self::WORK, 'active' => false], []));
    $this->assertSame([['field' => 'outside_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Engineer, [...self::WORK, 'outside_alexandria' => false], ['outside_minutes' => 30]));
    $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['scheduled_in' => '08:00', 'scheduled_out' => '16:00', 'in_time' => '08:00', 'out_time' => '16:00', 'outside_amount' => '75.5', 'transport' => 'half', 'advance_amount' => '200', 'note' => 'n']));
}

public function test_a_leave_day_rejects_times_minutes_outside_and_transport_but_allows_an_advance(): void
{
    $this->assertSame([
        ['field' => 'scheduled_in', 'code' => 'field_not_allowed'],
        ['field' => 'in_time', 'code' => 'field_not_allowed'],
        ['field' => 'transport', 'code' => 'field_not_allowed'],
    ], DayRules::violations(EmployeeType::PermanentWorker, self::LEAVE, ['scheduled_in' => '08:00', 'in_time' => '08:00', 'transport' => 'full']));
    $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::LEAVE, ['transport' => 'none', 'advance_amount' => '200', 'note' => 'n']));
    $this->assertSame([['field' => 'ot_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::DayLaborer, self::LEAVE, ['ot_minutes' => 30]));
    $this->assertSame([], DayRules::violations(EmployeeType::Engineer, self::LEAVE, ['advance_amount' => '500']));
}

public function test_bounds(): void
{
    $this->assertSame([], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['late_minutes' => 960, 'ot_minutes' => 0]));
    $this->assertSame([['field' => 'late_minutes', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['late_minutes' => 961]));
    $this->assertSame([['field' => 'ot_minutes', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['ot_minutes' => -1]));
    $this->assertSame([['field' => 'advance_amount', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['advance_amount' => '0']));
    $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['outside_amount' => '0.000001']));
}

public function test_times_order(): void
{
    $this->assertSame([['field' => 'out_time', 'code' => 'out_before_in']], DayRules::violations(EmployeeType::Engineer, self::WORK, ['in_time' => '20:00', 'out_time' => '04:00']));
    $this->assertSame([['field' => 'out_time', 'code' => 'times_need_both']], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['in_time' => '08:00']));
    $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['in_time' => '20:00', 'out_time' => '04:00']));
}

public function test_computed_minutes(): void
{
    $this->assertSame(['late' => 30, 'ot' => 30], DayRules::workerMinutes('20:00', '20:30', '05:00', '8'));
    $this->assertSame(['late' => 0, 'ot' => 10], DayRules::workerMinutes('08:00', '07:50', '16:00', '8'));
    $this->assertSame(['late' => 15, 'ot' => 0], DayRules::workerMinutes('08:00', '08:15', '15:00', '8'));
    $this->assertSame(['late' => 0, 'ot' => 30], DayRules::workerMinutes('08:00', '08:00', '16:00', '7.5'));
    $this->assertSame(25, DayRules::officeLateMinutes('09:40', '09:00', 15));
    $this->assertSame(0, DayRules::officeLateMinutes('09:15', '09:00', 15));
    $this->assertSame(0, DayRules::officeLateMinutes('08:50', '09:00', 15));
}

public function test_off_days_and_employment(): void
{
    $this->assertSame('friday', OffDays::leaveCodeFor('2026-10-02', ['friday'], false));
    $this->assertSame('saturday', OffDays::leaveCodeFor('2026-10-03', ['friday', 'saturday'], false));
    $this->assertSame('thursday', OffDays::leaveCodeFor('2026-10-01', ['thursday'], false));
    $this->assertNull(OffDays::leaveCodeFor('2026-10-03', ['friday'], false));
    $this->assertSame('official_holiday', OffDays::leaveCodeFor('2026-10-09', ['friday'], true));
    $e = ['hire_date' => '2026-10-10', 'termination_date' => '2026-10-20', 'types' => [['type' => 'day_laborer', 'effective_from' => '2026-10-10'], ['type' => 'engineer', 'effective_from' => '2026-10-16']], 'shifts' => []];
    $this->assertNull(Employment::typeOn($e, '2026-10-09'));
    $this->assertSame(EmployeeType::DayLaborer, Employment::typeOn($e, '2026-10-15'));
    $this->assertSame(EmployeeType::Engineer, Employment::typeOn($e, '2026-10-20'));
    $this->assertNull(Employment::typeOn($e, '2026-10-21'));
}
```

- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter=DayRulesTest` → FAIL (classes missing).

- [ ] **Step 3: Implement `DayRules`, `OffDays` and `Employment` with the signatures above** — no framework imports; times are compared as minutes since midnight.

- [ ] **Step 4: Run them and see them pass** — same command → PASS.

**Gates:** B-test, B-pint, B-stan, B-deptrac (Domain must stay at 0 violations).

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance day rules" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 3: Day reads and writes, derived days, the grid and missing days

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md`.

**Files:**
- Create: `backend/app/Modules/Employees/Application/EmployeeRoster.php`
- Create: `backend/app/Modules/Attendance/Domain/{AttendanceRefusal,AttendanceRefused}.php`
- Create: `backend/app/Modules/Attendance/Infrastructure/PeriodGuard.php`
- Create: `backend/app/Modules/Attendance/Application/{EffectiveDays,AttendanceDays,AttendanceGrid}.php`
- Create: `backend/app/Modules/Attendance/Presentation/AttendanceController.php`
- Create: `backend/lang/{ar,en}/attendance.php`
- Create: `backend/tests/AttendanceFixtures.php` (trait)
- Modify: `backend/routes/api.php`, `backend/bootstrap/app.php` (render `AttendanceRefused`, `dontReport`)
- Test: `backend/tests/Feature/AttendanceDaysTest.php`

**Interfaces:**
- Consumes: Task 1 tables, models and `Period`; Task 2 `DayRules`, `OffDays`, `Employment`; `BusinessRules::all()`; `RequestScope`, `employee.scope`.
- Produces:
  - `EmployeeRoster` returns employee arrays shaped `array{id: string, code: int, name_ar: string, name_en: ?string, hire_date: ?string, termination_date: ?string, types: list<array{type: string, effective_from: string}>, shifts: list<array{effective_from: string, shift_start: ?string, shift_end: ?string}>}`. Live employees only; `shift_*` are trimmed to `HH:MM`. Methods:
    - `ofType(EmployeeType $type, string $from, string $to, int $perPage, int $page): array{data: list<…>, pagination: …}`: employees employed as `$type` on at least one day of [from, to], ordered by `code`, in one query plus eager loads;
    - `allOfType(EmployeeType $type, string $from, string $to): list<…>`;
    - `find(string $id): ?array`;
    - `byCodes(list<int> $codes): array<int, array>`.
  - `enum AttendanceRefusal: string`, with `status(): int`:
    - `ConcurrencyConflict = 'concurrency_conflict'` → 409;
    - `PeriodLocked = 'period_locked'` → 423;
    - `FutureDate = 'future_date'` → 422;
    - `EmployeeNotActive = 'employee_not_active'` → 422;
    - `MissingDaysUnconfirmed = 'missing_days_unconfirmed'` → 422;
    - `PeriodAlreadyLocked = 'period_already_locked'` → 409;
    - `PeriodNotLocked = 'period_not_locked'` → 422;
    - `LeaveTypeMissing = 'leave_type_missing'` → 422.
  - `AttendanceRefused::because(AttendanceRefusal $r, array $params = []): self`. It is rendered as `ApiEnvelope::error($req, status, value, __('attendance.refusal.'.value, $params))`.
  - `PeriodGuard::assertOpen(Period $p): void`: inside the caller's transaction it takes `pg_advisory_xact_lock_shared(hashtextextended('attendance:'||group||':'||start, 0))`, then throws `PeriodLocked` if the period is locked. `PeriodGuard::isLocked(Period $p): bool` reads without locking.
  - `EffectiveDays`:
    - `forPeriod(Period $p, list<array> $employees): array<string, array<string, array>>`: employee id → date → day.
    - `effectiveDay(string $employeeId, string $date): array`.
    - Day `state` ∈ `stored|derived|missing|not_employed|future`:
      - `not_employed` when `Employment::typeOn` ≠ the period's group (or null);
      - otherwise `stored` when a live row exists;
      - in an **open** period, `derived` when `OffDays::leaveCodeFor` is non-null (weekly off from `weekly_off_office` for office, `weekly_off_field` for the rest; holidays from `official_holidays`);
      - otherwise `future` after today, else `missing` (so a future Friday is `derived`).
    - A **locked** period never derives: it has only stored rows, `future` and `missing`.
    - A derived day carries `leave_code` and `site_id` = the active leave site for that code, or null when none exists.
  - Day response (`GET`/`PUT`/`DELETE attendance/days/{employee}/{date}`):
    - identity and state: `employee_id`, `date`, `type`, `state`, `source`, `version` (null unless stored), `site_id`, `leave_code`;
    - values: `in_time`, `out_time`, `scheduled_in`, `scheduled_out`, `late_minutes`, `ot_minutes`, `outside_minutes`, `outside_amount`, `transport`, `advance_amount`, `note`;
    - `fields`: `DayRules::FIELDS[type]`; `leave_day_fields`: its intersection with `LEAVE_DAY_FIELDS`;
    - `hints`:
      - office present with `in_time`: `{late_minutes}` from `office_shift_start` or the pay-row `shift_start`, and `office_grace_minutes`;
      - permanent worker on a work site with both actual times: `{late_minutes, ot_minutes}` from `DayRules::workerMinutes` with `day_hours`;
      - otherwise `{}`;
    - `period`: `{start, end, is_locked}`.
    - When a permanent worker has no stored day, `scheduled_in`/`scheduled_out` show the defaults (the pay-row shift, else `worker_shift_start`/`worker_shift_end`). A work-day save without them stores those defaults; a leave-day save stores them null.
  - `AttendanceDays::save(string $employeeId, string $date, array $input, string $source = 'entered'): array`. One `DB::transaction` runs, in order:
    1. a date after today → `FutureDate`;
    2. `Employment::typeOn` null → `EmployeeNotActive`;
    3. `PeriodGuard::assertOpen(Period::containing(type, date))`;
    4. lock the row `withTrashed()->lockForUpdate()`;
    5. version check: a live row whose version ≠ `input.version`, or a live row with `input.version` null, or no live row with a non-null `input.version` → `ConcurrencyConflict`;
    6. `DayRules::violations` → `ValidationException` with `__('attendance.rule.<code>')` per field (a stored site that is unchanged counts as active);
    7. write: a leave day stores `transport = 'none'`; a soft-deleted row is restored; `version + 1` on update; `source` = `$source`.
    - A `UniqueConstraintViolationException` from a racing insert becomes `ConcurrencyConflict`.
    - PUT replaces every field: a field absent from the body becomes null.
  - `AttendanceDays::clear(string $employeeId, string $date, int $version): array`: the same guards, then a soft delete (version + 1 saved first). It returns the day, now `derived` or `missing`.
  - `AttendanceGrid`:
    - `period(EmployeeType $g, ?string $start): array{group, start, end, prev_start, next_start, is_locked, lock: ?array, missing: int}`. `next_start` is null when the next period starts after today. `lock` is `{locked_by: {id, name}, locked_at, reopened_by, reopened_at, reopen_reason}` or null.
    - `grid(EmployeeType $g, string $start, int $perPage, int $page)`: rows `{employee: {id, code, name_ar, name_en}, days: list<{date, state, source, site_id, leave_code}>}`.
    - `missing(EmployeeType $g, string $start, int $perPage)`: rows `{employee_id, code, name_ar, name_en, date}`, by code then date.
    - `missingCounts(string $date): list<array{group, period_start, period_end, missing}>`: per group, the period containing `$date` and the one before it, each only if unlocked, counted up to `$date`.
  - Validation in `AttendanceController` (format only; `DayRules` does the rest):
    - `version` `nullable|integer|min:1`;
    - `site_id` `nullable|uuid|exists:sites,id,deleted_at,NULL`;
    - the four times `nullable|date_format:H:i`;
    - the three minutes `nullable|integer`;
    - the two amounts `nullable|string|regex:/^\d{1,12}(\.\d{1,6})?$/D`;
    - `transport` `nullable|in:full,half,none`;
    - `note` `nullable|string|max:500`.
  - The rules live in `AttendanceDays::FORMAT` (public const, Application), so the controller and the Task 11 import share them without Application depending on Presentation.
  - Lang `attendance.php` (ar / en):
    - `refusal.concurrency_conflict`: عدّل مستخدم آخر هذا السجل. حمّل آخر نسخة ثم أعد المحاولة. / Someone else changed this record. Load the latest version and try again.
    - `refusal.period_locked`: فترة الحضور مقفلة ولا يمكن تعديلها. / This attendance period is locked and cannot be edited.
    - `refusal.future_date`: لا يمكن تسجيل حضور لتاريخ لم يأتِ بعد. / Attendance cannot be recorded for a future date.
    - `refusal.employee_not_active`: الموظف غير مُعيَّن في هذا التاريخ. / The employee is not employed on this date.
    - `refusal.missing_days_unconfirmed`: توجد أيام غير مسجلة في الفترة؛ أكّد القفل للمتابعة. / Some days in this period are missing; confirm to lock anyway.
    - `refusal.period_already_locked`: الفترة مقفلة بالفعل. / The period is already locked.
    - `refusal.period_not_locked`: الفترة غير مقفلة. / The period is not locked.
    - `refusal.leave_type_missing`: لا يوجد نوع إجازة نشط بالكود :code. / No active leave type has the code :code.
    - `rule.field_not_allowed`: هذا الحقل لا ينطبق على هذا اليوم. / This field does not apply to this day.
    - `rule.out_of_bounds`: القيمة خارج الحدود المسموح بها. / The value is outside the allowed range.
    - `rule.site_required`: اختر موقعًا أو نوع يوم. / Choose a site or a day type.
    - `rule.site_not_allowed`: لا يُسجَّل لموظفي الشركة موقع عمل. / Office staff are not recorded on a work site.
    - `rule.site_inactive`: هذا الموقع غير نشط. / This site is inactive.
    - `rule.out_before_in`: وقت الانصراف قبل وقت الحضور. / The out time is before the in time.
    - `rule.times_need_both`: أدخل وقتي الحضور والانصراف معًا. / Enter both the in and out times.
    - `invalid_period`: بداية الفترة غير صحيحة لهذه المجموعة. / This is not a period start for this group.
  - `Tests\AttendanceFixtures` (trait):
    - `leaveTypes(): array<string, string>`: leave_code → site id for all seven codes. It reuses the active `saturday` row the migration created («اجازة السبت»), so it does not collide with the unique index, and creates the other six as `Leave-<code>`;
    - `workSite(string $name = 'Site-1', bool $outside = false, bool $active = true): string`;
    - `employee(string $type, string $from = '2026-01-01', array $attributes = []): string`, via `EmployeeService::create`, with `name_ar` `Engineer-1`/`Worker-1`/`Office-1`/`Laborer-1` numbered per type, `hire_date` = `$from`, salary `'9000'` or daily_wage `'330'`;
    - `holiday(string $date, string $name = 'Holiday-1'): string`;
    - `cairo(string $local): void`, which travels to that Cairo wall time;
    - `putDay(string $employeeId, string $date, array $body): TestResponse`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/AttendanceDaysTest.php`, `use RefreshDatabase, AttendanceFixtures`)

`setUp`: `cairo('2026-10-20 12:00')`, `signIn('hr')`, `$this->leave = $this->leaveTypes()`, `$this->site = $this->workSite('Site-1')`, `$this->outside = $this->workSite('Site-2', true)`.

```php
public function test_each_type_saves_its_own_fields(): void
{
    $eng = $this->employee('engineer');
    $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->outside, 'in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => 10, 'ot_minutes' => 60, 'outside_minutes' => 30, 'advance_amount' => '500', 'note' => 'n'])
        ->assertOk()->assertJsonPath('data.state', 'stored')->assertJsonPath('data.source', 'entered')
        ->assertJsonPath('data.version', 1)->assertJsonPath('data.advance_amount', '500.000000')->assertJsonPath('data.in_time', '08:00');
    $this->putDay($this->employee('day_laborer'), '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:00'])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'in_time');
    $this->putDay($this->employee('office'), '2026-10-01', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
}

public function test_a_leave_day_stores_no_transport_and_keeps_the_advance(): void
{
    $w = $this->employee('permanent_worker');
    $this->putDay($w, '2026-10-02', ['site_id' => $this->leave['friday'], 'transport' => 'half'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'transport');
    $this->putDay($w, '2026-10-02', ['site_id' => $this->leave['friday'], 'advance_amount' => '200'])
        ->assertOk()->assertJsonPath('data.transport', 'none')->assertJsonPath('data.scheduled_in', null)->assertJsonPath('data.leave_code', 'friday');
}

public function test_a_future_date_is_refused(): void
{
    $this->putDay($this->employee('engineer'), '2026-10-21', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.code', 'future_date');
}

public function test_today_follows_the_cairo_date(): void
{
    $eng = $this->employee('engineer');
    $this->cairo('2026-10-21 00:30');                       // UTC is still 2026-10-20
    $this->putDay($eng, '2026-10-21', ['site_id' => $this->site])->assertOk();
    $this->putDay($eng, '2026-10-22', ['site_id' => $this->site])->assertJsonPath('error.code', 'future_date');
}

public function test_an_employee_not_active_on_the_date_is_refused(): void
{
    $late = $this->employee('engineer', '2026-10-10');
    $this->putDay($late, '2026-10-05', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.code', 'employee_not_active');
    $left = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-12']);
    $this->putDay($left, '2026-10-12', ['site_id' => $this->site])->assertOk();
    $this->putDay($left, '2026-10-13', ['site_id' => $this->site])->assertJsonPath('error.code', 'employee_not_active');
}

public function test_a_night_shift_crosses_midnight_and_belongs_to_the_in_date(): void
{
    $this->putDay($this->employee('permanent_worker'), '2026-10-01', ['site_id' => $this->site, 'scheduled_in' => '20:00', 'scheduled_out' => '04:00', 'in_time' => '20:30', 'out_time' => '05:00', 'transport' => 'full'])
        ->assertOk()->assertJsonPath('data.date', '2026-10-01')->assertJsonPath('data.hints.late_minutes', 30)->assertJsonPath('data.hints.ot_minutes', 30);
    $this->putDay($this->employee('engineer'), '2026-10-01', ['site_id' => $this->site, 'in_time' => '20:00', 'out_time' => '04:00'])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'out_time');
}

public function test_scheduled_times_default_from_the_pay_row_or_the_settings(): void
{
    $this->putDay($this->employee('permanent_worker'), '2026-10-01', ['site_id' => $this->site])->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.scheduled_out', '16:00');
    $own = $this->employee('permanent_worker', '2026-01-01', ['shift_start' => '07:00', 'shift_end' => '15:00']);
    $this->getJson("/api/v1/attendance/days/{$own}/2026-10-01")->assertJsonPath('data.state', 'missing')->assertJsonPath('data.scheduled_in', '07:00');
}

public function test_office_lateness_hint_uses_the_shift_and_the_grace(): void
{
    $o = $this->employee('office');
    $this->putDay($o, '2026-10-01', ['in_time' => '09:40', 'out_time' => '17:00'])->assertJsonPath('data.hints.late_minutes', 25);
    $this->putDay($o, '2026-10-04', ['in_time' => '09:10'])->assertJsonPath('data.hints.late_minutes', 0);
    $later = $this->employee('office', '2026-01-01', ['shift_start' => '10:00', 'shift_end' => '18:00']);
    $this->putDay($later, '2026-10-01', ['in_time' => '10:20'])->assertJsonPath('data.hints.late_minutes', 5);
}

public function test_a_stale_version_returns_409_concurrency_conflict(): void
{
    $eng = $this->employee('engineer');
    $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertJsonPath('data.version', 1);
    $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site, 'late_minutes' => 20])->assertJsonPath('data.version', 2);
    $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
    $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertStatus(409);
    $this->assertSame(20, AttendanceDay::where('employee_id', $eng)->value('late_minutes'));
}

public function test_clearing_a_day_soft_deletes_it_and_the_day_is_missing_again(): void
{
    $eng = $this->employee('engineer');
    $this->putDay($eng, '2026-10-01', ['site_id' => $this->site]);
    $id = AttendanceDay::where('employee_id', $eng)->value('id');
    $this->deleteJson("/api/v1/attendance/days/{$eng}/2026-10-01?version=1")->assertOk()->assertJsonPath('data.state', 'missing');
    $this->assertSoftDeleted('attendance_days', ['id' => $id]);
    $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertOk()->assertJsonPath('data.version', 3);
    $this->assertSame(1, AttendanceDay::withTrashed()->where('employee_id', $eng)->count());
}

public function test_fridays_and_holidays_are_derived_and_a_holiday_wins_over_friday(): void
{
    $this->holiday('2026-10-06');
    $this->holiday('2026-10-09');
    $eng = $this->employee('engineer');
    $day = fn (string $e, string $d) => $this->getJson("/api/v1/attendance/days/{$e}/{$d}");
    $day($eng, '2026-10-02')->assertJsonPath('data.state', 'derived')->assertJsonPath('data.leave_code', 'friday')
        ->assertJsonPath('data.site_id', $this->leave['friday'])->assertJsonPath('data.version', null);
    $day($eng, '2026-10-06')->assertJsonPath('data.leave_code', 'official_holiday');
    $day($eng, '2026-10-09')->assertJsonPath('data.leave_code', 'official_holiday');   // Friday and holiday
    $day($eng, '2026-10-03')->assertJsonPath('data.state', 'missing');               // Saturday, field staff
    $day($this->employee('office'), '2026-10-03')->assertJsonPath('data.leave_code', 'saturday')   // office weekly off
        ->assertJsonPath('data.site_id', $this->leave['saturday']);                                  // «اجازة السبت»
    $this->putDay($eng, '2026-10-02', ['site_id' => $this->site])->assertJsonPath('data.state', 'stored');   // worked the Friday
}

public function test_missing_days_are_counted_and_never_treated_as_absence(): void
{
    $this->holiday('2026-10-06');
    $eng = $this->employee('engineer');
    $this->putDay($eng, '2026-10-01', ['site_id' => $this->site]);
    $this->putDay($eng, '2026-10-03', ['site_id' => $this->leave['absent']]);
    $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-09-26')->assertJsonPath('data.missing', 19);
    $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-04")->assertJsonPath('data.state', 'missing')->assertJsonPath('data.leave_code', null);
    $states = array_count_values(array_column($this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26')->json('data.0.days'), 'state'));
    ksort($states);
    $this->assertSame(['derived' => 5, 'future' => 4, 'missing' => 19, 'stored' => 2], $states); // Friday 10-23 is derived ahead of time
    $this->assertSame(2, AttendanceDay::count());                                                // a missing day is never stored as absent
}

public function test_days_outside_employment_are_not_missing(): void
{
    $this->employee('engineer', '2026-10-10');
    $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-09-26')->assertJsonPath('data.missing', 10); // 10-10..10-20 less Friday 10-16
    $days = $this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26')->json('data.0.days');
    $this->assertSame('not_employed', $days[0]['state']);   // 2026-09-26
    $this->assertSame('not_employed', $days[13]['state']);  // 2026-10-09
}

public function test_grid_lists_the_groups_employees_by_code_with_one_cell_per_day(): void
{
    $a = $this->employee('engineer'); $b = $this->employee('engineer'); $this->employee('office');
    $r = $this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26&per_page=50')->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('data.0.employee.id', $a)->assertJsonPath('data.1.employee.id', $b);
    $this->assertCount(30, $r->json('data.0.days'));
}

public function test_period_navigation(): void
{
    $this->getJson('/api/v1/attendance/period?group=engineer')->assertJsonPath('data.start', '2026-09-26')->assertJsonPath('data.end', '2026-10-25')
        ->assertJsonPath('data.prev_start', '2026-08-26')->assertJsonPath('data.next_start', null)->assertJsonPath('data.is_locked', false);
    $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-08-26')->assertJsonPath('data.next_start', '2026-09-26');
    $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'period_start');
}

public function test_ceo_reads_and_a_manager_is_refused(): void
{
    $eng = $this->employee('engineer');
    $this->signIn('ceo');
    $this->getJson('/api/v1/attendance/grid?group=engineer')->assertOk();
    $this->putDay($eng, '2026-10-01', ['site_id' => $this->site])->assertStatus(403);
    $this->signIn('manager');
    $this->getJson('/api/v1/attendance/grid?group=engineer')->assertStatus(403);
    $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-01")->assertStatus(403);
}

public function test_every_write_is_audited_with_before_and_after_values(): void
{
    $eng = $this->employee('engineer');
    $this->putDay($eng, '2026-10-01', ['site_id' => $this->site, 'late_minutes' => 10]);
    $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site, 'late_minutes' => 20]);
    $this->deleteJson("/api/v1/attendance/days/{$eng}/2026-10-01?version=2");
    $rows = DB::table('audit_log')->where('entity_type', 'attendance_days')->orderBy('occurred_at')->get();
    $this->assertSame(['CREATED', 'UPDATED', 'UPDATED', 'DELETED'], $rows->pluck('event')->all()); // the clear bumps the version, then soft-deletes
    $this->assertSame(['late_minutes' => 10, 'version' => 1], json_decode($rows[1]->old_values, true));
    $this->assertSame(['late_minutes' => 20, 'version' => 2], json_decode($rows[1]->new_values, true));
}
```
State order in `EffectiveDays` is stored → derived → future → missing, so a future Friday shows as derived.

- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter=AttendanceDaysTest` → FAIL (routes missing).

- [ ] **Step 3: Implement `EmployeeRoster` (one query plus eager loads, no N+1), `PeriodGuard`, `AttendanceRefusal`/`AttendanceRefused` and its render**

- [ ] **Step 4: Implement `EffectiveDays`, `AttendanceDays`, `AttendanceGrid`, `AttendanceController`, the routes from the API table and `lang/{ar,en}/attendance.php`**

`AttendanceGrid::period` and `missingCounts` load the whole group's roster (`allOfType`). Mark that with `// ponytail: in-PHP count over ~100 employees × 31 days; move to SQL if a group grows past ~1,000`.

- [ ] **Step 5: Run them and see them pass** — `docker compose exec app php artisan test` → PASS (all suites, including `RbacTest::test_every_api_route_requires_auth_and_a_permission`).

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 6: Commit** — `git commit -m "Add attendance days, derived days, the period grid and missing days" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 4: Period lock, reopen and official holidays

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md`.

**Files:**
- Create: `backend/app/Modules/Attendance/Domain/AttendanceAuditEvents.php`
- Create: `backend/app/Modules/Attendance/Application/{AttendanceLocks,OfficialHolidays}.php`
- Create: `backend/app/Modules/Attendance/Presentation/{AttendanceLockController,HolidayController}.php`
- Modify: `backend/app/Modules/Attendance/Infrastructure/PeriodGuard.php` (`exclusive`), `backend/routes/api.php`
- Test: `backend/tests/Feature/AttendanceLockTest.php`, `backend/tests/Feature/OfficialHolidaysTest.php`

**Interfaces:**
- Consumes: Task 3 `EffectiveDays`, `AttendanceGrid::missing`, `PeriodGuard`, `AttendanceRefused`, fixtures.
- Produces:
  - `AttendanceAuditEvents::PERIOD_LOCKED = 'ATTENDANCE_LOCKED'` and `PERIOD_REOPENED = 'ATTENDANCE_REOPENED'`, recorded with `AuditLog::record(event, 'attendance_locks', lockId, old, new)`:
    - lock: old `null` or `{is_locked: false}`, new `{employee_type, period_start, is_locked: true, materialized: int}`;
    - reopen: old `{is_locked: true}`, new `{is_locked: false, reason}`.
  - `PeriodGuard::exclusive(Period $p): void`: the exclusive form of the same advisory key.
  - `AttendanceLocks::lock(EmployeeType $g, string $start, bool $confirmMissing): array` (the lock object from `AttendanceGrid::period`). One transaction: `exclusive`, then:
    1. already locked → `PeriodAlreadyLocked`;
    2. missing > 0 and not confirmed → `MissingDaysUnconfirmed`;
    3. a derived day with no active leave type for its code → `LeaveTypeMissing` (`:code`). This covers a deactivated leave type and a weekly-off weekday with no leave code (e.g. `thursday`);
    4. insert every derived day as an `AttendanceDay` (`source = 'derived'`, `version = 1`, through the model so each is audited);
    5. upsert the lock row (`locked_by/at` set, `reopened_*` null);
    6. record `PERIOD_LOCKED`.
  - `AttendanceLocks::reopen(EmployeeType $g, string $start, string $reason): array`: `exclusive`, then not locked → `PeriodNotLocked`; set `reopened_by/at/reason`; record `PERIOD_REOPENED`. Materialized rows stay.
  - `OfficialHolidays`:
    - `list(?int $year, int $perPage)`: by date ascending;
    - `create(array{date, name})`;
    - `update(string $id, array)`;
    - `delete(string $id)` (soft).
    - Inside the transaction, every write calls `PeriodGuard::assertOpen(Period::containing($type, $d))` for each of the four types and for both the old and the new date. A live duplicate date → 422 under `date` (`errors.duplicate_date`).
  - Requests: lock `{group: required|in:…, period_start: required|date_format:Y-m-d, confirm_missing: sometimes|boolean}` → 201. Reopen `{group, period_start, reason: required|string|max:500}` → 200. Holiday `{date: required|date_format:Y-m-d, name: required|string|max:128}` (`sometimes` on PATCH) → 201/200; DELETE → 204.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttendanceLockTest.php` (`setUp`: `cairo('2026-10-27 12:00')`, `signIn('hr')`, `$this->leave = leaveTypes()`, `$this->eng = employee('engineer')`, `holiday('2026-10-06')`, `$this->site = workSite()`):
```php
private function lock(array $body = []): TestResponse
{
    return $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', ...$body]);
}

public function test_lock_lists_missing_days_and_needs_confirmation(): void
{
    $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site]);
    $this->getJson('/api/v1/attendance/missing?group=engineer&period_start=2026-09-26')->assertJsonPath('meta.pagination.total', 24);
    $this->lock()->assertStatus(422)->assertJsonPath('error.code', 'missing_days_unconfirmed');
    $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.is_locked', true);
}

public function test_lock_materializes_derived_days_as_stored_rows(): void
{
    $this->lock(['confirm_missing' => true]);
    $this->assertSame(['2026-10-02', '2026-10-06', '2026-10-09', '2026-10-16', '2026-10-23'],
        AttendanceDay::where('source', 'derived')->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
    app(BusinessRules::class)->update(['weekly_off_field' => 'thursday']);
    $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-02")->assertJsonPath('data.state', 'stored')->assertJsonPath('data.source', 'derived');
    $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-08")->assertJsonPath('data.state', 'missing'); // a locked period never re-derives
}

public function test_writes_to_a_locked_period_are_refused(): void
{
    $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site]);
    $this->lock(['confirm_missing' => true]);
    $this->putDay($this->eng, '2026-10-04', ['site_id' => $this->site])->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
    $this->deleteJson("/api/v1/attendance/days/{$this->eng}/2026-10-01?version=1")->assertStatus(423);
    $this->lock(['confirm_missing' => true])->assertStatus(409)->assertJsonPath('error.code', 'period_already_locked');
}

public function test_reopen_requires_a_reason_and_keeps_materialized_rows(): void
{
    $this->lock(['confirm_missing' => true]);
    $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');
    $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction'])
        ->assertOk()->assertJsonPath('data.is_locked', false)->assertJsonPath('data.lock.reopen_reason', 'Correction');
    $this->assertSame(5, AttendanceDay::where('source', 'derived')->count());
    $this->putDay($this->eng, '2026-10-04', ['site_id' => $this->site])->assertOk();
    $this->lock(['confirm_missing' => true])->assertCreated();
}

public function test_lock_refuses_a_derived_day_without_a_leave_type(): void
{
    Site::whereKey($this->leave['friday'])->update(['active' => false]);
    $this->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'leave_type_missing');
    $this->assertSame(0, AttendanceDay::count());                    // nothing materialized
}

public function test_lock_refuses_a_weekly_off_weekday_that_has_no_leave_type(): void
{
    app(BusinessRules::class)->update(['weekly_off_field' => 'friday,thursday']);
    $this->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'leave_type_missing')
        ->assertJsonPath('error.message', 'لا يوجد نوع إجازة نشط بالكود thursday.');
}

public function test_locking_needs_attendance_lock(): void
{
    $this->signIn('ceo');
    $this->lock(['confirm_missing' => true])->assertStatus(403);
}

public function test_lock_reopen_and_holidays_are_audited(): void
{
    $this->lock(['confirm_missing' => true]);
    $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction']);
    $locked = DB::table('audit_log')->where('event', 'ATTENDANCE_LOCKED')->first();
    $this->assertSame(['employee_type' => 'engineer', 'period_start' => '2026-09-26', 'is_locked' => true, 'materialized' => 5], json_decode($locked->new_values, true));
    $this->assertSame(['is_locked' => false, 'reason' => 'Correction'], json_decode(DB::table('audit_log')->where('event', 'ATTENDANCE_REOPENED')->value('new_values'), true));
    $this->assertSame(5, DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'CREATED'])->count());
    $this->assertTrue(DB::table('audit_log')->where(['entity_type' => 'official_holidays', 'event' => 'CREATED'])->exists());
}
```
`tests/Feature/OfficialHolidaysTest.php` (`setUp`: `cairo('2026-11-02 12:00')`, `signIn('hr')`, `leaveTypes()`, `employee('engineer')`, `employee('office')`):
```php
public function test_holidays_crud(): void
{
    $id = $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-06', 'name' => 'Holiday-1'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-06', 'name' => 'Holiday-2'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
    $this->patchJson("/api/v1/attendance/holidays/{$id}", ['name' => 'Holiday-3'])->assertOk()->assertJsonPath('data.name', 'Holiday-3');
    $this->getJson('/api/v1/attendance/holidays?year=2026')->assertJsonPath('data.0.date', '2026-10-06');
    $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertNoContent();
    $this->signIn('ceo');
    $this->getJson('/api/v1/attendance/holidays')->assertOk();
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-07', 'name' => 'Holiday-4'])->assertStatus(403);
}

public function test_holiday_edits_inside_a_locked_period_are_refused(): void
{
    $id = $this->holiday('2026-10-06');
    $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-20', 'name' => 'Holiday-2'])->assertStatus(423);
    $this->patchJson("/api/v1/attendance/holidays/{$id}", ['name' => 'Holiday-3'])->assertStatus(423);
    $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertStatus(423);
    $this->patchJson("/api/v1/attendance/holidays/{$id}", ['date' => '2026-10-28'])->assertStatus(423);   // the old date is locked
}

public function test_a_holiday_is_refused_when_any_groups_period_containing_it_is_locked(): void
{
    $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true]);
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-24', 'name' => 'Holiday-1'])->assertStatus(423); // office October is open
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-28', 'name' => 'Holiday-2'])->assertCreated();
    $this->postJson('/api/v1/attendance/locks', ['group' => 'office', 'period_start' => '2026-10-01', 'confirm_missing' => true]);
    $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-29', 'name' => 'Holiday-3'])->assertStatus(423); // engineers' 10-26 period is open
}
```

- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter='AttendanceLockTest|OfficialHolidaysTest'` → FAIL.

- [ ] **Step 3: Implement `PeriodGuard::exclusive`, `AttendanceLocks`, `OfficialHolidays`, `AttendanceAuditEvents`, the two controllers and their routes**

- [ ] **Step 4: Run them and see them pass** — `docker compose exec app php artisan test` → PASS.

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance period lock, reopen and official holidays" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 5: Same-PR docs and the demo seeder

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development` (for the seeder); reads `docs/coding-standards.md` and the spec.

**Files:**
- Modify: `CONTEXT.md`, `docs/hrms-module-plan.md`, `docs/calculations.md`
- Create: `backend/database/seeders/AttendanceDemoSeeder.php` (not called by `DatabaseSeeder`)
- Test: `backend/tests/Feature/AttendanceDemoSeederTest.php`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces:
  - `CONTEXT.md`, under "Work and attendance", four entries in the file's style (term, one-line meaning, `_Avoid_`):
    - **Attendance day** (يوم حضور): one employee's record for one date: one site, one leave day-type, or (office) present. A day with none is a missing day, never an absence. _Avoid_: timesheet row, punch.
    - **Attendance lock** (قفل الحضور): HR's freeze of one group's period; nobody edits until HR reopens with a reason. _Avoid_: close (that is the Release 2 payroll period close).
    - **Official holiday** (إجازة رسمية): a dated day off for everyone, kept in Settings. _Avoid_: public holiday, vacation.
    - **Derived day** (يوم تلقائي): a weekly off day or official holiday the system fills in until HR records the day; stored at lock. _Avoid_: default day, auto row.
  - `docs/hrms-module-plan.md`:
    - Release 1 item 5 becomes "HR attendance (1c)": HR records every type for now; the Excel template; holidays; the lock; the missing-days bell.
    - Settled-decisions rows: HR-only entry for now; one record per day; absent is a day-type; no future dates; derived days materialized on lock; the per-group periods; the version-based 409.
    - The job spec (coding-standards §12): `attendance:alert-missing` at 21:00 Cairo; input the date; idempotent on (user, type, date); 3 tries with 60 s / 300 s backoff on `maintenance`; failures logged; counts are cumulative, so the next run covers a failed day.
    - Two later items: the manager mobile screen (today only; past days HR-only; «زي امبارح»; own sites; the «تم التوقيع» sign-off per employee period; HR returns a period with a notification; an alert when a manager skips a day); and pasting site attendance messages.
    - Release 3: the scanned paper sign-off upload.
  - `docs/calculations.md`:
    - §1 adds "Office lateness from in time: max(0, in − shift start − **15** grace), where shift start is **09:00** (`office_shift_start`) or the pay-row override. It is shown as a minutes hint; the fingerprint import fills the same fields in Release 2."
    - §3 adds "Scheduled in/out are stored on each day; they default to **08:00–16:00** (`worker_shift_start`/`worker_shift_end`) or the pay-row override. Late and OT minutes are computed from actual vs scheduled and shown as hints."
    - A new §2a "Derived off days": weekly off per type (**Friday + Saturday** office, **Friday** field), each weekday derived as the leave type with the same code (Friday → «اجازة الجمعة», Saturday → «اجازة السبت»), official holidays from the holiday list, holiday wins over a weekly off day; derived on read while the period is open, stored at lock; never absence.
  - `CONTEXT.md` **Leave day-type**: the list becomes "Friday, Saturday, official holiday, sick, rest, absent (غ)".
  - `AttendanceDemoSeeder`:
    - It throws when `app()->environment('production')`.
    - It calls `SitesSeeder`, then creates work sites `Site-1` and `Site-2` (outside Alexandria).
    - Employees from 2026-01-01: `Office-1`, `Office-2`, `Engineer-1`…`Engineer-3`, `Worker-1`, `Worker-2` (shift 07:00–15:00), `Laborer-1`, `Laborer-2`.
    - The official holiday 2026-10-06 `Holiday-1`.
    - Through `AttendanceDays::save`: `Engineer-1` on `Site-1` on 2026-10-01 and 10-04, on `غ` 10-05, and an advance `'500'` on 10-04.
    - Running it twice is a no-op (it finds rows by name).

- [ ] **Step 1: Write the failing test** — `test_demo_seeder_is_repeatable_and_refuses_production`: seed twice → 9 employees, 1 holiday, 3 stored days; with `app()->detectEnvironment(fn () => 'production')` it throws `RuntimeException`.
- [ ] **Step 2: Run it and see it fail** — `docker compose exec app php artisan test --filter=AttendanceDemoSeederTest` → FAIL.
- [ ] **Step 3: Write the seeder and the three docs edits**
- [ ] **Step 4: Run it and see it pass**, then check the docs: `grep -n "Attendance day\|Derived day" CONTEXT.md`, `grep -n "alert-missing" docs/hrms-module-plan.md` and `grep -n "worker_shift_start" docs/calculations.md` each print a match.

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 5: Commit** — `git commit -m "Document Release 1c attendance and add a demo seeder" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Gate 1 (controller, no screenshots)

1. All four backend gates are green on HEAD. Paste the `php artisan test` summary line.
2. API demo against the dev stack:
   - Run `docker compose exec app php artisan migrate`, then `… db:seed --class=AttendanceDemoSeeder`.
   - Sign in as the HR dev account from `.claude/dev-accounts.md`. Read the credentials into shell variables; never echo them.
   - Run, printing only the status and `error.code`/key fields:
     1. `GET /attendance/period?group=engineer`: `missing` > 0;
     2. `PUT` an engineer day on `Site-1` with `version: null` → 200 `version: 1`;
     3. the same `PUT` with `version: null` again → 409 `concurrency_conflict`;
     4. `PUT` on tomorrow's date → 422 `future_date`;
     5. `POST /attendance/locks` for the previous engineer period → 422 `missing_days_unconfirmed`, then with `confirm_missing: true` → 201;
     6. `PUT` a day in that period → 423 `period_locked`;
     7. `POST /attendance/locks/reopen` with a reason → 200;
     8. `GET /audit-log?entity_type=attendance_days` lists the writes with old and new values;
     9. as the CEO dev account, `PUT` → 403.
3. Save the transcript to `.claude/gate-1c-1/api-demo.txt`, post it with the Open points below, and wait for the owner's approval.

---

# Slice 2 — HR period grid, day editor, holidays tab, lock (Sonnet implements, Opus reviews)

### Task 6: Attendance page, period picker and grid

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Create: `frontend/src/pages/attendance.tsx`, `frontend/src/pages/attendance.test.tsx`
- Modify: `frontend/src/App.tsx` (lazy `AttendancePage`, route `attendance`), `frontend/src/components/layout/AppLayout.tsx` (`NAV`: HR group item `{ path: '/attendance', key: 'nav.attendance', icon: CalendarCheck, abilities: ['attendance.view'] }`; the Settings item's abilities gain `'attendance.view'`), `frontend/src/components/layout/AppLayout.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 3 `GET /attendance/period`, `GET /attendance/grid`; `/sites?per_page=100` (cached; names work sites).
- Produces:
  - `AttendancePage`, with URL state `?group=<type>&period=<start>&tab=grid|import`. The group defaults to `engineer` (the first group HR fills from timesheets); the period defaults to the current one.
  - `PageHeader` with the title, the subtitle and a `MiniStat` showing `attendance.missingCount`.
  - Picker: `SelectField` for the group (`attendance.group.*`), prev/next icon buttons (`aria-label`s `attendance.prevPeriod`/`nextPeriod`, `rtl:-scale-x-100`; next disabled when `next_start` is null), and the label `attendance.periodRange` with dates as stored. A locked period shows `StatusBadge` `attendance.locked`.
  - Grid: `Card` + `Table`. The first column (employee code + name) is sticky: `sticky start-0 z-10 bg-card`. One column per day, headed by the day number and a narrow weekday (`Intl.DateTimeFormat(intlLocale, { weekday: 'narrow' })`). The table scrolls sideways inside its Card at 375 px. Pagination uses `Pagination` with `per_page=50`.
  - Each cell is a `<button type="button">` with accessible name `"{name_ar} {date}: {label}"`. The label is:
    - the site name for a work site;
    - `attendance.short.<leave_code>` for a leave day, with `defaultValue` = the code, so a weekday code without a leave type (e.g. `thursday`) still renders;
    - `attendance.present` for office present;
    - `attendance.state.missing`, `attendance.state.future` or `attendance.state.notEmployed` for those states.
  - Cell look (tokens only):
    - missing: `bg-warning/15 text-warning ring-1 ring-warning/40`;
    - derived: `border border-dashed text-muted-foreground`;
    - `future` and `not_employed`: disabled and dimmed;
    - stored: plain.
    - A legend row under the table names the three looks.
  - Clicking a cell calls `onOpenDay(employee, date)`. Task 7 renders the editor; until then it is a no-op prop.
  - Empty state `attendance.empty`. Loading uses `TableSkeleton`; errors use `ErrorBanner`.

- [ ] **Step 1: Write the failing tests**

```tsx
const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 19 }
const cell = (date: string, state: string, extra = {}) => ({ date, state, source: state === 'stored' ? 'entered' : null, site_id: null, leave_code: null, ...extra })
const rows = [{ employee: { id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1' },
  days: [cell('2026-10-01', 'stored', { site_id: 's1' }), cell('2026-10-02', 'derived', { leave_code: 'friday' }), cell('2026-10-03', 'missing'), cell('2026-10-21', 'future')] }]
const sites = [{ id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', outside_alexandria: false, active: true }]
const routes = { 'GET /api/v1/attendance/period': () => json(200, { data: period }), 'GET /api/v1/attendance/grid': () => json(200, { data: rows, meta: { pagination } }), 'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }) }

test('the grid shows each employee per day with missing and derived days marked', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-02: جمعة' })).toHaveClass('border-dashed')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' })).toHaveClass('bg-warning/15')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
  expect(screen.getByRole('group', { name: 'أيام غير مسجلة' })).toHaveTextContent('19')
})

test('the period arrows move between periods and keep the group in the URL', async () => {
  const grid = vi.fn(() => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('period_start=2026-08-26'))
})
```
In `AppLayout.test.tsx`: `test('the Attendance link shows for attendance.view')` — present with `['attendance.view.all']`, absent with `['employee.view.all']`.

- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/pages/attendance.test.tsx src/components/layout/AppLayout.test.tsx` → FAIL.
- [ ] **Step 3: Implement the page, route, nav entry and these keys**

| Key | ar | en |
|---|---|---|
| `nav.attendance` | الحضور والانصراف | Attendance |
| `attendance.subtitle` | سجل أيام كل موظف حسب فترة مجموعته. | Each employee's days, by their group's period. |
| `attendance.group` | المجموعة | Group |
| `attendance.group.office` | موظفو الشركة | Office staff |
| `attendance.group.engineer` | المهندسون | Engineers |
| `attendance.group.permanent_worker` | العمال المثبتون | Permanent workers |
| `attendance.group.day_laborer` | عمال اليومية | Day laborers |
| `attendance.prevPeriod` | الفترة السابقة | Previous period |
| `attendance.nextPeriod` | الفترة التالية | Next period |
| `attendance.periodRange` | من {{start}} إلى {{end}} | {{start}} to {{end}} |
| `attendance.employee` | الموظف | Employee |
| `attendance.missingCount` | أيام غير مسجلة | Missing days |
| `attendance.locked` | مقفلة | Locked |
| `attendance.present` | حاضر | Present |
| `attendance.state.missing` | غير مسجل | Missing |
| `attendance.state.derived` | تلقائي | Automatic |
| `attendance.state.future` | لم يأتِ بعد | Upcoming |
| `attendance.state.notEmployed` | خارج فترة العمل | Not employed |
| `attendance.short.friday` | جمعة | Fri |
| `attendance.short.saturday` | سبت | Sat |
| `attendance.short.official_holiday` | رسمية | Hol |
| `attendance.short.sick` | مرضي | Sick |
| `attendance.short.rest` | راحة | Rest |
| `attendance.short.absent` | غ | Abs |
| `attendance.short.other` | أخرى | Other |
| `attendance.legend` | دليل الخانات | Legend |
| `attendance.empty` | لا يوجد موظفون في هذه المجموعة خلال الفترة. | No employees in this group during this period. |
| `attendance.tab.grid` | جدول الفترة | Period grid |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance page with the period grid" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 7: Day editor sheet

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Create: `frontend/src/pages/attendance-day.tsx`, `frontend/src/pages/attendance-day.test.tsx`
- Modify: `frontend/src/pages/attendance.tsx` (renders `DayEditor` from `onOpenDay`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 3 `GET/PUT/DELETE /attendance/days/{employee}/{date}` (response fields, `fields`, `leave_day_fields`, `hints`, `period.is_locked`); `/sites?per_page=100`.
- Produces:
  - `DayEditor({ employee, date, onOpenChange })`: a `Sheet` with `side="right"` (full width and full height below `sm`). It loads with `useApi(path, { fresh: true })` and starts its form only when `ready`.
  - Site select (`SelectField`), with two `<optgroup>`s: work sites (active, `kind=work`) and leave types (active, `kind=leave`).
    - For `type === 'office'` there is no work-site group; the first option `attendance.present` has value `''`.
    - For the other types the first option is a blank placeholder (`attendance.day.chooseSite`).
  - Which fields show:
    - a work site or office present → every name in `fields`;
    - a leave type → only the names in `leave_day_fields`;
    - `outside_minutes` / `outside_amount` only when the chosen site has `outside_alexandria`.
  - Inputs:
    - times: `TextField type="time"`;
    - minutes: `inputMode="numeric"`;
    - amounts: `inputMode="decimal"`;
    - `transport`: `SelectField` over `full|half|none`;
    - `note`: textarea.
  - Values stay strings, except that minutes are sent as integers (`Number.parseInt`) or null.
  - Hints show under their field: `attendance.day.hintLate` / `hintOt` with `{{minutes}}`.
  - Notes: `state === 'derived'` shows `attendance.day.derivedNote` (with the leave label); `state === 'missing'` shows `missingNote`.
  - Save: `PUT` with the full body and `version`. On success, toast `common.saved`, close, and invalidate `attendance`.
  - On `409`: keep the typed values and show `ErrorBanner`-style text `attendance.day.conflict` with a button `attendance.day.loadLatest` that refetches and resets the form.
  - Clear (`attendance.day.clear`, only for stored days): `DELETE ?version=`.
  - Read-only (every input disabled, no footer) when `!can('attendance.edit')` or `period.is_locked`; a locked period shows `attendance.day.lockedNote`.
  - Server field errors show under their fields through `toErrs`.

- [ ] **Step 1: Write the failing tests**

```tsx
const day = (over = {}) => ({ employee_id: 'e1', date: '2026-10-01', type: 'engineer', state: 'missing', source: null, version: null, site_id: null, leave_code: null,
  in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null,
  fields: ['in_time', 'out_time', 'late_minutes', 'ot_minutes', 'outside_minutes', 'advance_amount', 'note'], leave_day_fields: ['advance_amount', 'note'], hints: {},
  period: { start: '2026-09-26', end: '2026-10-25', is_locked: false }, ...over })
const sites = [{ id: 's1', name_ar: 'Site-1', kind: 'work', outside_alexandria: false, active: true }, { id: 's2', name_ar: 'Site-2', kind: 'work', outside_alexandria: true, active: true },
  { id: 'lf', name_ar: 'Leave-friday', kind: 'leave', leave_code: 'friday', active: true }]

test('an engineer day saves its fields with the version', async () => {
  const put = vi.fn(() => json(200, { data: day({ state: 'stored', version: 1, site_id: 's2' }) }))
  mockFetch({ 'GET /api/v1/attendance/days/e1/2026-10-01': () => json(200, { data: day() }), 'GET /api/v1/sites': list(sites), 'PUT /api/v1/attendance/days/e1/2026-10-01': put })
  renderWithAuth(<DayEditor employee={{ id: 'e1', name_ar: 'Engineer-1' }} date="2026-10-01" onOpenChange={() => {}} />, ['attendance.view.all', 'attendance.edit.all'])
  await user.selectOptions(await screen.findByLabelText('الموقع أو نوع اليوم'), 's2')
  await user.type(screen.getByLabelText('دقائق العمل خارج الإسكندرية'), '٣٠')
  await user.type(screen.getByLabelText('السلفة'), '500')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(put)).toMatchObject({ version: null, site_id: 's2', outside_minutes: 30, advance_amount: '500' })
})
```
Copy the `list` (line 13, with its `pg` helper) and `body` (line 16) helpers from `settings.test.tsx`; `DayEditor` takes `employee: { id, name_ar }`. Plus:
- `'choosing a leave type hides times and minutes'`: select `lf` → `queryByLabelText('وقت الحضور')` is null; `السلفة` stays.
- `'the outside-Alexandria field shows only for an outside site'`: `s1` → absent; `s2` → present.
- `'a 409 keeps the typed values and offers the latest version'`: the PUT answers 409 `concurrency_conflict` → the typed advance is still `500`, the conflict text shows, and clicking `تحميل آخر نسخة` refetches the GET.
- `'read-only when the period is locked or without attendance.edit'`: two renders → inputs disabled, no save button.
- `'clearing a stored day sends its version'`: DELETE URL contains `version=3`.

- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/pages/attendance-day.test.tsx` → FAIL.
- [ ] **Step 3: Implement `DayEditor`, wire it into the grid, and add these keys**

| Key | ar | en |
|---|---|---|
| `attendance.day.title` | يوم {{date}} — {{name}} | {{date}} — {{name}} |
| `attendance.day.siteOrType` | الموقع أو نوع اليوم | Site or day type |
| `attendance.day.chooseSite` | اختر موقعًا أو نوع يوم | Choose a site or day type |
| `attendance.day.workSites` | مواقع العمل | Work sites |
| `attendance.day.leaveTypes` | أنواع الإجازات | Leave types |
| `attendance.day.in_time` | وقت الحضور | In time |
| `attendance.day.out_time` | وقت الانصراف | Out time |
| `attendance.day.scheduled_in` | الحضور المقرر | Scheduled in |
| `attendance.day.scheduled_out` | الانصراف المقرر | Scheduled out |
| `attendance.day.late_minutes` | دقائق التأخير | Late minutes |
| `attendance.day.ot_minutes` | دقائق الوقت الإضافي | Overtime minutes |
| `attendance.day.outside_minutes` | دقائق العمل خارج الإسكندرية | Minutes outside Alexandria |
| `attendance.day.outside_amount` | مبلغ العمل خارج الإسكندرية | Outside-Alexandria amount |
| `attendance.day.transport` | المواصلات | Transport |
| `attendance.transport.full` | كاملة | Full |
| `attendance.transport.half` | نصف | Half |
| `attendance.transport.none` | بدون | None |
| `attendance.day.advance_amount` | السلفة | Advance |
| `attendance.day.note` | ملاحظات | Note |
| `attendance.day.hintLate` | تأخير محسوب: {{minutes}} دقيقة | Computed lateness: {{minutes}} min |
| `attendance.day.hintOt` | وقت إضافي محسوب: {{minutes}} دقيقة | Computed overtime: {{minutes}} min |
| `attendance.day.derivedNote` | سُجِّل هذا اليوم تلقائيًا ({{type}}). اختر موقعًا إذا عمل الموظف فيه. | This day was set automatically ({{type}}). Choose a site if the employee worked. |
| `attendance.day.missingNote` | لم يُسجَّل هذا اليوم بعد. | This day is not recorded yet. |
| `attendance.day.lockedNote` | الفترة مقفلة، ولا يمكن تعديل أيامها. | The period is locked; its days cannot be edited. |
| `attendance.day.clear` | مسح اليوم | Clear day |
| `attendance.day.conflict` | عدّل مستخدم آخر هذا اليوم. حمّل آخر نسخة ثم أعد إدخال تغييراتك. | Someone else changed this day. Load the latest version, then re-enter your changes. |
| `attendance.day.loadLatest` | تحميل آخر نسخة | Load latest |
| `attendance.source.entered` | إدخال يدوي | Entered |
| `attendance.source.imported` | مستورد من Excel | Imported |
| `attendance.source.derived` | تلقائي | Automatic |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance day editor" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 8: Lock and reopen controls

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Modify: `frontend/src/pages/attendance.tsx`, `frontend/src/pages/attendance.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 4 `POST /attendance/locks`, `POST /attendance/locks/reopen`; Task 3 `GET /attendance/missing`, the `period.lock` object.
- Produces:
  - With `can('attendance.lock')`, the `PageHeader` actions show either the `attendance.lock` button (open period) or the `attendance.reopen` button (locked).
  - The lock dialog (`Dialog`) shows `attendance.lockBody`. When `missing > 0` it also shows `attendance.lockMissing`, a list of up to 100 missing days from `/attendance/missing?per_page=100` (code, name, date; with the `common.tooMany` notice past 100), and a required native checkbox `attendance.lockConfirmMissing`. The submit stays disabled until that checkbox is checked. It posts `{group, period_start, confirm_missing}`.
  - The reopen dialog is a `FormDialog` with a required textarea `attendance.reopenReason`; it posts `{group, period_start, reason}`.
  - Both invalidate `attendance` and toast `common.saved`.
  - A locked period shows `attendance.lockedBy` with the name and the `useCairoTime()` timestamp under the picker.

- [ ] **Step 1: Write the failing tests** (append to `attendance.test.tsx`)
  - `'locking with missing days lists them and needs the confirm checkbox'`: open the dialog → the missing row `Engineer-1` / `2026-10-03` shows; `قفل الفترة` (submit) is disabled; check `أؤكد القفل رغم وجود أيام غير مسجلة` → enabled; the POST body equals `{ group: 'engineer', period_start: '2026-09-26', confirm_missing: true }`.
  - `'reopen sends the reason'`: a locked period → the reopen dialog → type `Correction` → the body has `reason: 'Correction'`; a 422 under `reason` shows under the field.
  - `'lock controls need attendance.lock'`: with `['attendance.view.all', 'attendance.edit.all']`, neither button renders.
- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/pages/attendance.test.tsx` → FAIL.
- [ ] **Step 3: Implement the two dialogs and these keys**

| Key | ar | en |
|---|---|---|
| `attendance.lock` | قفل الفترة | Lock period |
| `attendance.reopen` | إعادة فتح الفترة | Reopen period |
| `attendance.lockTitle` | قفل حضور الفترة | Lock this period's attendance |
| `attendance.lockBody` | بعد القفل لا يمكن لأي مستخدم تعديل أيام هذه الفترة حتى يُعاد فتحها. | Once locked, nobody can edit this period's days until it is reopened. |
| `attendance.lockMissing` | ما زالت هذه الأيام غير مسجلة: | These days are still missing: |
| `attendance.lockConfirmMissing` | أؤكد القفل رغم وجود أيام غير مسجلة | Lock anyway, with missing days |
| `attendance.reopenTitle` | إعادة فتح حضور الفترة | Reopen this period's attendance |
| `attendance.reopenReason` | سبب إعادة الفتح | Reason for reopening |
| `attendance.lockedBy` | قفلها {{name}} في {{time}} | Locked by {{name}} at {{time}} |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance lock and reopen controls" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 9: Holidays tab, worker shift rules, permission and audit labels

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Create: `frontend/src/pages/holidays.tsx`
- Modify: `frontend/src/pages/sites.tsx` (export `ListCard`; `LEAVE_CODES` gains `'saturday'` after `'friday'`), `frontend/src/pages/settings.tsx` (`TABS` gains `holidays` with ability `attendance.view`; `GROUPS.schedule` gains `worker_shift_start`, `worker_shift_end` as `type="time"`), `frontend/src/pages/settings.test.tsx` (the `rules` fixture gains both keys; new holiday tests), `frontend/src/pages/audit.tsx` (`ENTITIES` gains `attendance_days`, `attendance_locks`, `official_holidays`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 4 holidays endpoints; Task 1 settings keys and permissions.
- Produces:
  - `HolidaysTab`: a `ListCard` of `/attendance/holidays?per_page=100`, with columns date (as stored) and name, plus `RowActions` (edit, delete).
  - Its controls show only with `can('holiday.manage')`: `holiday.add`, a `FormDialog` with `date` (`type="date"`) and `name`; and a delete confirm `Dialog` with `holiday.deleteConfirm`.
  - A `423 period_locked` answer shows in the dialog's `ErrorBanner`.

- [ ] **Step 1: Write the failing tests** (append to `settings.test.tsx`)
  - `'holidays tab lists, adds and shows a locked-period refusal'`: list `[{ id: 'h1', date: '2026-10-06', name: 'Holiday-1' }]`; add `2026-10-20` / `Holiday-2` → the POST body is exact; a second add answered 423 shows `فترة الحضور مقفلة ولا يمكن تعديلها.`
  - `'holiday controls need holiday.manage'`: with `['attendance.view.all']`, the tab shows and has no add button.
  - `'the leave-type dialog offers the Saturday code'`: the `كود الإجازة` select has an option `السبت` with value `saturday`.
  - Update `'rules tab loads the settings, saves typed values and toasts'`: the fixture gains `worker_shift_start: '08:00', worker_shift_end: '16:00'`, and the expected PUT body includes them.
- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/pages/settings.test.tsx` → FAIL.
- [ ] **Step 3: Implement the tab, the two rule fields, the `ENTITIES` additions and these keys**

| Key | ar | en |
|---|---|---|
| `settings.tab.holidays` | الإجازات الرسمية | Official holidays |
| `settings.desc.holidays` | أيام تُسجَّل تلقائيًا إجازة رسمية لكل الموظفين. | Days recorded automatically as an official holiday for everyone. |
| `holiday.add` | إضافة إجازة رسمية | Add holiday |
| `holiday.edit` | تعديل الإجازة الرسمية | Edit holiday |
| `holiday.date` | التاريخ | Date |
| `holiday.name` | اسم الإجازة | Holiday name |
| `holiday.delete` | حذف | Delete |
| `holiday.deleteConfirm` | حذف الإجازة الرسمية «{{name}}»؟ | Delete the official holiday "{{name}}"? |
| `enum.leave_code.saturday` | السبت | Saturday |
| `rule.worker_shift_start` | بدء وردية العمال المثبتين | Permanent workers' shift start |
| `rule.worker_shift_end` | انتهاء وردية العمال المثبتين | Permanent workers' shift end |
| `perm.attendance.view` | الحضور: عرض | Attendance: view |
| `perm.attendance.edit` | الحضور: تعديل | Attendance: edit |
| `perm.attendance.lock` | الحضور: قفل الفترة | Attendance: lock period |
| `perm.holiday.manage` | الإجازات الرسمية: إدارة | Official holidays: manage |
| `perm.resource.attendance` | الحضور | Attendance |
| `perm.resource.holiday` | الإجازات الرسمية | Official holidays |
| `perm.action.lock` | قفل | Lock |
| `audit.entity.attendance_days` | أيام الحضور | Attendance days |
| `audit.entity.attendance_locks` | قفل الحضور | Attendance locks |
| `audit.entity.official_holidays` | الإجازات الرسمية | Official holidays |
| `audit.event.ATTENDANCE_LOCKED` | قفل حضور فترة | Attendance period locked |
| `audit.event.ATTENDANCE_REOPENED` | إعادة فتح حضور فترة | Attendance period reopened |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the official holidays tab and the worker shift rules" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Gate 2 (controller)

Setup: `docker compose up -d`, then `docker compose exec app php artisan migrate`, then `… db:seed --class=AttendanceDemoSeeder`; start the preview with `preview_start` `frontend`. Check the console and network panels before the shots. Sign in as the HR dev account, and as the CEO account for shot 8.

Take every screen at 375, 1024 and 1440 px, in ar and en, light and dark (12 shots each). Save them as `.claude/gate-1c-2/<n>-<screen>-<width>-<lang>-<theme>.png`:
1. Engineers grid for 26-09 → 25-10, showing stored, derived (Fridays and the 10-06 holiday) and missing cells. At 375 px, scroll sideways to show the pinned employee column.
2. The day editor for an engineer on `Site-2` (outside Alexandria field showing), full height at 375 px.
3. The day editor for `Worker-1`, with the late and OT hints.
4. The day editor for `Office-1`, with the lateness hint.
5. The day editor on a derived Friday, before overriding it with a site.
6. The lock dialog, with the missing list and the confirm checkbox.
7. A locked period, with the badge, the locked-by line and the reopen dialog.
8. The CEO's view: read-only grid and editor, no lock button.
9. Settings → الإجازات الرسمية, with its add dialog.
10. Settings → قواعد العمل, showing the two worker shift fields.

Also confirm as the Manager dev account that there is no الحضور والانصراف nav entry. Post the shots and wait for the owner's approval.

---

# Slice 2b — Day sheet (roll-call tab) + Gate 2 fixes

Owner-approved 2026-10-19. Source: the owner's mockup `~/hrms-attendance/index.html` (interaction reference only; do
not copy its Tailwind CDN / vanilla JS). Rulings P26–P32 are in the ledger. Every implementer and reviewer is Opus;
UI tasks also load `ui-ux-pro-max:ui-ux-pro-max`. Order: 15, 16, 18, 17, 19, 20, 21, 30, 24, 25, 22, 23, 27, 26, 29, 32, 33, 28, 31, then Gate 2b.

**Slice 2b constraints (in addition to the Global Constraints above):**
- Every day-sheet write goes through `POST /attendance/days/bulk`, including single-row quick actions and the inline time, so undo has one shape.
- Keyboard shortcuts match on `event.code` (`KeyJ`, `KeyK`, `KeyP`, `KeyA`, `KeyS`, `KeyE`, `Slash`, `KeyZ`, plus `ArrowUp`/`ArrowDown`/`Escape`). They never fire while focus is in an input, textarea, select or contenteditable, never while a `[role="dialog"]` is open, and never with Alt/Ctrl/Meta except Ctrl/Cmd+Z.
- Status colours on the new screen use `bg-<status>-bg border-<status>-border text-<status>` (tokens added in Task 17). Other components are not migrated.
- Every new focusable class string carries `focus-visible:outline-solid` (P24).
- `<kbd>` glyphs (`J`, `K`, `P`, `A`, `S`, `E`, `/`, `Ctrl+Z`) are code constants; their descriptions go through i18n.
- "Missing" (no record) and "absent" (leave code `absent`, غ) are always different states with different looks.

**API added in this slice (under `/api/v1`, inside `auth`):**

`GET /attendance/sheet?group=<type>&date=YYYY-MM-DD` — `permission:attendance.view`; `date > today` (Cairo) → 422 `future_date`. One unpaginated resource (P26), `ApiEnvelope::single`.
```json
{"data":{
  "group":"engineer","date":"2026-10-18",
  "days":[{"date":"2026-09-26","missing":3}],
  "rows":[{
    "employee":{"id":"uuid","code":12,"name_ar":"…","name_en":"Engineer-1","department":null},
    "day":{"state":"stored|derived|missing","source":"entered","version":3,"site_id":"uuid","leave_code":null,
           "in_time":"08:00","out_time":null,"scheduled_in":null,"scheduled_out":null,"late_minutes":null,
           "ot_minutes":null,"outside_minutes":null,"outside_amount":null,"transport":null,"advance_amount":null,"note":null},
    "hints":{"late_minutes":25},
    "last_site_id":"uuid|null",
    "totals":{"worked":12,"leave":4,"absent":1,"missing":2}
  }]
}}
```
`days` has one entry per period date (future dates → 0). Rows `not_employed` on the date are left out; ordered by code.

`GET /attendance/period?group=&date=` — new optional `date` (`prohibits:period_start`); returns the period containing the date, same shape as today.

`POST /attendance/days/bulk` — `permission:attendance.edit` plus a per-row own-scope check.
```json
{"date":"2026-10-18","rows":[
  {"employee_id":"uuid","version":3,"site_id":"uuid"},
  {"employee_id":"uuid","version":null,"site_id":null,"in_time":"09:20"},
  {"employee_id":"uuid","version":4,"clear":true}
]}
```
- 200 `{"data":[{"employee_id":"uuid","version":4},{"employee_id":"uuid","version":1},{"employee_id":"uuid","version":null}]}` in request order (`version` null after a clear).
- 409 `concurrency_conflict` with `details:[{"field":"rows.0","code":"concurrency_conflict","message":"…"}, …]` listing every stale row; nothing written (P27).
- 422 `validation_failed` (`rows.N.site_id`, `rows`, `rows.N.employee_id`), or `future_date` / `employee_not_active` with `details` naming `rows.N`.
- 423 `period_locked`. 404 unknown employee or outside the caller's own scope.

### Task 15: Day sheet read endpoint

**Files:**
- Modify `backend/app/Modules/Attendance/Application/AttendanceGrid.php`: add `sheet()`, private `lastSites()`, `public const LAST_SITE_DAYS = 7`, `periodAt(EmployeeType $g, ?string $start, ?string $date = null): Period`; inject `BusinessRules`.
- Modify `backend/app/Modules/Attendance/Application/EffectiveDays.php`: `hints()` becomes public and takes `array $settings` (read once by the caller); `effectiveDay()` passes `$this->rules->all()`.
- Modify `backend/app/Modules/Employees/Application/EmployeeRoster.php`: `row()` adds `'department' => $e->department`.
- Modify `backend/app/Modules/Attendance/Presentation/AttendanceController.php`: add `sheet()`; add `date` to `periodRules()` (`prohibits:period_start`).
- Modify `backend/routes/api.php`: `Route::get('sheet', 'sheet')->middleware('permission:attendance.view')`.
- Create `backend/tests/Feature/AttendanceSheetTest.php` (use `AttendanceFixtures`, `cairo('2026-10-20 12:00')`, `signIn('hr')` as the existing attendance tests do).

**Interfaces:**
- `AttendanceGrid::sheet(EmployeeType $g, string $date): array{group: string, date: string, days: list<array{date: string, missing: int}>, rows: list<array>}` — response shape in the slice header.
- `EffectiveDays::hints(EmployeeType $type, array $employee, array $day, array $settings): array<string,int>`
- `sheet()` loads the roster once (`allOfType($g, $p->start, $p->end)`), calls `EffectiveDays::forPeriod` once, reads settings once, runs `lastSites` as one query: `select distinct on (d.employee_id) d.employee_id, d.site_id … join sites s on s.id=d.site_id and s.kind='work' and s.active and s.deleted_at is null where d.deleted_at is null and d.employee_id in (…) and d.date between date−7 and date−1 order by d.employee_id, d.date desc`. Skip it for the office group (office → null).
- P31 last site: latest live stored day in `[date−7, date−1]` with an active work site; derived and leave days skipped; may cross period boundaries.
- P32 totals over the whole period as of today: worked = stored and `leave_code` null (work site or office present); leave = stored or derived with `leave_code` set and ≠ `absent`; absent = stored `absent`; missing = state `missing` (future never counted).
- The day row carries every stored value plus `version` (null when not stored). Office rows carry `hints.late_minutes` from the same function `EffectiveDays::hints` uses today (per-employee shift override honoured).

- [ ] **Step 1: failing tests**
  - `test_the_sheet_lists_the_groups_employees_for_the_date_with_their_day_and_version` — stored engineer → `rows.0.day.state` `stored`, `day.version` 1, `day.in_time` `08:00`, `employee.department` set; ordered by code.
  - `test_not_employed_rows_are_left_out` — hired after the date absent; mid-period type change hides the row on the other type's dates.
  - `test_last_site_is_the_latest_stored_work_site_in_the_seven_days_before` — Thursday Site-1 + derived Friday → Saturday `last_site_id` Site-1; a stored sick day between is skipped; site deactivated since → null; work day 8 days back → null; office → null.
  - `test_period_totals_count_worked_leave_absent_and_missing` — 2 work, 1 derived Friday, 1 stored sick, 1 stored absent, rest missing up to today → exact; future not counted; office present = worked.
  - `test_the_day_strip_counts_missing_days_per_date` — one entry per period date; counts match.
  - `test_office_rows_carry_the_lateness_hint` — in 09:40, shift 09:00, grace 15 → 25; per-employee shift 10:00 honoured.
  - `test_a_future_date_is_refused` — 422 `future_date`.
  - `test_ceo_reads_the_sheet_and_a_manager_is_refused` — 200 / 403.
  - `test_the_sheet_runs_the_same_number_of_queries_for_one_or_three_employees` — `DB::enableQueryLog`, equal counts.
  - `test_period_accepts_a_date` — `period?group=engineer&date=2026-10-01` → `data.start` `2026-09-26`.
- [ ] **Step 2:** `docker compose exec app php artisan test --filter=AttendanceSheetTest` → FAIL.
- [ ] **Step 3:** implement. Mark `// ponytail: whole group in PHP (~1,000 × 31 cells); move counts to SQL if a group outgrows it`.
- [ ] **Step 4: Gates** B-test, B-pint, B-stan, B-deptrac.
- [ ] **Step 5:** commit "Add the attendance day sheet endpoint".

### Task 16: Bulk day write

**Files:**
- Modify `backend/app/Modules/Attendance/Application/AttendanceDays.php`: extract `private function write(array $employee, string $date, array $input, string $source, bool $keepUnsent, ?array $settings = null): int` and `private function clearRow(...)` from `save`/`clear` (behaviour unchanged except P29); add `public const BULK_MAX_ROWS = 500`, `public const BULK_FORMAT`, `public function bulk(string $date, array $rows): array`.
- Modify `backend/app/Modules/Attendance/Domain/DayRules.php`: expose `allows(EmployeeType $type, ?array $site, string $field, int|string $value): bool` (`isLeave` derived inside).
- Modify `backend/app/Modules/Attendance/Domain/AttendanceRefused.php`: `public readonly array $details`; `because($reason, $params = [], $details = [])`. Modify `backend/bootstrap/app.php` render to pass `$e->details`.
- Modify `backend/app/Modules/Employees/Application/EmployeeRoster.php`: `findMany(list<string> $ids): array<string, array>` (one query).
- Modify `AttendanceController.php`: `bulk()` validates `BULK_FORMAT`; if `RequestScope::ownerId($request)` is not null, every row must pass `EmployeeService::isManagedBy` else `ModelNotFoundException` (404), as `EnsureEmployeeInScope` does.
- Modify `routes/api.php`: `Route::post('days/bulk', 'bulk')->middleware('permission:attendance.edit')`, placed before the `{employee}` routes.
- Create `backend/tests/Feature/AttendanceBulkTest.php`; modify `backend/tests/Unit/DayRulesTest.php`.

**Interfaces / rules:**
- `BULK_FORMAT`: `date` required `date_format:Y-m-d`; `rows` required array min 1 max 500; `rows.*.employee_id` required uuid distinct; `rows.*.clear` sometimes accepted; `rows.*.version` nullable integer min 1, required when `clear`; `rows.*.site_id` present unless clear, plus `FORMAT['site_id']`; every other `FORMAT` field prefixed `rows.*.`.
- `bulk()` returns `list<array{employee_id: string, version: ?int}>` in request order. One `DB::transaction`; rows processed in `employee_id` order (stable lock order); `ConcurrencyConflict` and `ValidationException` collected per row (re-keyed `rows.N.`) and thrown once after the loop so nothing commits; conflicts are reported before validation errors. A `UniqueConstraintViolationException` → 409 without details. Other refusals (423, 422 future_date, employee_not_active) abort at the first row and name it in `details`.
- Settings read once for the whole request (`workerDefaults` takes them).
- **P28 keep rule** (`$keepUnsent`, bulk only): same kind of day as the live row (work→work, leave→leave, office present→present) keeps every unsent value that `DayRules::allows()` accepts on the new site; kind change keeps only `note` and `advance_amount` (those the type has). Look up the live row's site kind with `Site::withTrashed()`. Single PUT keeps PUT semantics. A permanent worker moving from a leave day to a work site gets transport `full` again (not the kept `none`).
- **P29:** fill everything except `version`; if the row is not dirty, return its version unwritten (applies to the shared `write()`, so the single PUT also stops bumping unchanged rows).

- [ ] **Step 1: failing tests** (date 2026-10-18; Site-1 work site; leave types from fixtures)
  - `test_bulk_writes_every_row_in_one_request_and_returns_new_versions` — 3 missing engineers → 200, versions 1, stored.
  - `test_a_permanent_worker_marked_present_gets_shift_defaults_and_full_transport` — `{site_id}` only → `scheduled_in` `08:00`, transport `full`.
  - `test_a_stale_row_fails_the_whole_request_and_lists_every_conflict` — rows 1 and 2 stale → 409, `error.details.*.field` = [`rows.1`,`rows.2`]; row 0 not written; audit_log count unchanged.
  - `test_rule_violations_are_reported_per_row_and_nothing_is_written` — office row with a work site → 422 `rows.0.site_id`.
  - `test_a_status_change_keeps_note_and_advance_and_drops_times` — engineer stored with in/out, note, advance 500 → absent site → note kept, `advance_amount` `500.000000`, `in_time` null.
  - `test_an_office_time_edit_keeps_the_out_time` — office present 09:00–17:00 → `{site_id:null,in_time:'09:20'}` → out_time `17:00`.
  - `test_a_sick_permanent_worker_marked_present_gets_full_transport_again`.
  - `test_clear_rows_restore_the_missing_or_derived_day` — missing → stored → clear → `missing`; Friday → clear → `derived`.
  - `test_an_unchanged_row_writes_nothing` — same values incl. advance → version unchanged, no audit row.
  - `test_a_locked_period_refuses_the_whole_request` — 423.
  - `test_a_future_date_is_refused`.
  - `test_more_than_500_rows_is_refused` — 422 field `rows`.
  - `test_duplicate_employee_rows_are_refused`.
  - `test_every_written_day_has_its_own_audit_entry` — 3 rows → 3 CREATED entries.
  - `test_ceo_and_manager_are_refused` — 403.
  - `test_an_own_scope_user_cannot_reach_another_managers_employee` — test-created `attendance.edit.own` grant (as `ManagerScopeTest`) → 404.
  - Unit `test_allows_matches_the_field_rules` — engineer `outside_minutes` on inside site false; permanent worker `transport:'none'` on leave true, `'full'` false; office `note` false.
- [ ] **Step 2:** run → FAIL. **Step 3:** implement (`// ponytail: ~6 queries per row; preload sites and locks if 500-row requests get slow`). **Step 4:** gates B-*. **Step 5:** commit "Add the bulk attendance day write".

### Task 17: Status tokens and the day sheet layout (read-only)

**Files:**
- Modify `frontend/src/index.css`: in `:root` add `--success-bg #eef8f1`, `--success-border #bbf7d0`, `--warning-bg #fff6ed`, `--warning-border #fed7aa`, `--info-bg #eff6ff`, `--info-border #bfdbfe`, `--destructive-bg #fef3f2`, `--destructive-border #fecdca`; in `.dark` add `color-mix(in srgb, var(--<status>) 14%, var(--card))` for `-bg` and `40%` for `-border`; map all 8 in `@theme inline` as `--color-<status>-bg` / `--color-<status>-border`. Check text contrast ≥ 4.5:1 on the new bg tokens in both themes.
- Modify `frontend/src/components/form.tsx`: `localName(row: Row, lang: string, fallback?: string): string` — `name_en` in English when set, else `fallback`, else `name_ar`.
- Modify `frontend/src/pages/attendance.tsx`: `TABS = ['sheet','grid']`, default `sheet`; URL `?date=` (default `cairoToday()`; malformed or future → today); on the sheet tab the header period comes from `/attendance/period?group&date=`; render `<DaySheet>`; `onOpenDay` shared with the grid (DayEditor reused).
- Modify `frontend/src/locales/{ar,en}.json` (keys under `attendance.tab.sheet` and `attendance.sheet.*`, see table at the end of the slice).
- Create `frontend/src/pages/attendance-sheet.tsx` and `frontend/src/pages/attendance-sheet.test.tsx`.

**Interfaces:**
- `export type SheetRow` = the API `rows[]` item. `export function DaySheet({ group, date, period, onDate, onOpenDay })`.
- Layout, top to bottom (information hierarchy from the mockup):
  - KPI buttons (`aria-pressed`, act as filters). Field groups: total (with "N of M recorded" and a progress bar), missing, worked (at a site), leave, absent. Office: total, missing, on time, late, leave, absent (on time = office present with `hints.late_minutes` 0 or absent; late > 0).
  - Day strip: one button per period date (day number, narrow weekday, missing count); selected date `aria-current="date"`; future disabled; clicking sets `date=`.
  - Cohort strip: field groups by `last_site_id` (site name via `SITES`, else `noLastSite`); office by `department` (null → `noDepartment`); each shows done/total with a bar and filters on click.
  - Rows: ≥md a `Table` — checkbox placeholder (Task 19), ID, employee (`<span class="flex items-center gap-2"><span class="tabular">{code}</span><bdi>{name}</bdi></span>`, department below), office in-time (text; Task 19 makes it editable), status (status pill using the new tokens + site chip), actions placeholder, then the **period totals cell last** (worked / leave / absent / missing). <md cards with the totals block at the **bottom**. Switch with CSS (`hidden md:block` / `md:hidden`), no JS media query.
  - Client-side pagination 50 rows (`paginate`, `Pagination` from `components/tables/Pagination.tsx`); page resets when a filter changes.
  - Locked period → banner `attendance.sheet.locked`. Loading `TableSkeleton`, errors `ErrorBanner`, empty `EmptyState`.
- Missing rows look different from absent rows (missing = warning tokens + "؟"/missing label; absent = destructive tokens + غ).

- [ ] **Step 1: tests first** (fixture: 3 engineers — missing / stored Site-1 / stored absent; office fixture with department + late hint)
  - `the day sheet is the default tab and ?tab=grid shows the grid`
  - `the KPI cards count the rows and filter them` (click Missing → only the missing row; `aria-pressed` true)
  - `period totals sit in the last cell of each row and at the bottom of each card`
  - `the day strip marks the date and disables future days` + clicking a day puts `date=` in the fetch URL
  - `field cohorts group by last site and office cohorts by department` (incl. both "none" labels)
  - `51 rows paginate at 50`
  - `the employee code sits in its own element apart from the name` (`getByText('Engineer-1').tagName === 'BDI'`)
  - `a locked period shows the read-only banner`
- [ ] **Steps 2–4:** run → FAIL, implement, gate F. **Step 5:** commit "Add the attendance day sheet tab".

### Task 18: Pure day-sheet helpers

**Files:** Create `frontend/src/lib/day-sheet.ts`, `frontend/src/lib/day-sheet.test.ts`.

**Interfaces:**
- `export const MAX_BATCH = 500`
- `export function parseBatch(input: string, rows: SheetRow[]): { matches: SheetRow[]; unknown: string[]; capped: boolean }` — run `asciiDigits` (`components/form.tsx`) first; split on `/[\s,;،]+/`; if every token matches `/^\d+([-–]\d+)?$/` it is an ID batch: ranges min..max, expansion capped at 500 across the whole input (`capped` true when hit), `unknown` = single IDs not on the sheet, matches de-duplicated in input order. Otherwise a case-insensitive substring search over `${code} ${name_ar} ${name_en} ${department}`.
- `export type BulkRow = { employee_id: string; version: number | null; clear?: true; site_id?: string | null } & Partial<Record<DayValue, string | number | null>>`
- `export type SheetAction = {kind:'present'} | {kind:'leave', code:'absent'|'sick'} | {kind:'site', siteId:string} | {kind:'time', in_time:string}`
- `export function bulkRows(action: SheetAction, rows: SheetRow[], leaveSite: (code: string) => string | undefined): { rows: BulkRow[]; skipped: SheetRow[] }` — present: office → `site_id:null`; field → `last_site_id`, else skipped. Rows already in the target state are left out. `version` passed through from `row.day.version`.
- `export function undoRows(before: SheetRow[], after: {employee_id: string; version: number | null}[]): BulkRow[]` — stored before → `site_id` + every value with the new version; otherwise → `{clear:true, version}`; rows whose version did not change are left out.
- `export function statusOf(r: SheetRow): 'missing'|'present'|'late'|'absent'|'leave'|'derived'` — office late from `hints.late_minutes > 0`.
- `SheetRow` may be declared here and imported by `attendance-sheet.tsx` (Task 17) — keep one declaration.

- [ ] **Tests first:** parseBatch — `'12'`; `'1, 3, 6'`; `'1-5'`; `'5-1'`; `'1-99999'` → ≤500 + `capped`; `'١٢'`; `'1،3'`; newline-separated; `'99999'` → `unknown:['99999']`; blank → none; `'engineer-1'` case-insensitive; department match. bulkRows — present uses `last_site_id`, skips rows without; office present `site_id:null`; already present at that site left out; absent uses `leaveSite('absent')` + version; time → `{site_id:null,in_time}`. undoRows — stored before restores every value + new version; missing/derived before → clear; unchanged version left out. statusOf — office late from hints.
- Gate F. Commit "Add the day sheet parser and row builders".

### Task 19: Row actions, selection, sweeps, office in-time

**Files:** `frontend/src/pages/attendance-sheet.tsx`, its test, locales.

**Behaviour:**
- One writer `apply(action, rows, label)`: `bulkRows` → over 500 → toast `tooMany`; else `useSave` POST `/attendance/days/bulk` `{date, rows}`; success → toast `applied` (+ `skippedNoSite` when skipped); 409 → toast `conflict` naming the employees from `details` (map `rows.N` back), then refetch; keeps a `pending` set of employee ids and never sends a row already in flight. Returns the before/after needed by Task 21's undo.
- Row actions: Present (P), Absent (A), Sick (S), Details buttons, each `aria-label` includes the name; desktop `h-9`, mobile `h-11`; Sick disabled when no active `sick` leave type. Details opens the existing `DayEditor`.
- Selection: checkbox column (raw `<input type="checkbox">` in a `label` ≥ 44×44), select-all for the visible page (`indeterminate`), a bulk bar while anything is selected: Present, Absent, Sick, "Set site" (`SelectField` of active work sites, field groups only), Clear.
- Sweeps: each cohort has a button applying Present with the cohort's site to its **missing** members; a "Mark the rest" button applies Present to the missing rows of the current filter.
- Office: inline `<input type="time">`; on change applies `{kind:'time'}`.
- Read-only (`period.is_locked` or `!can('attendance.edit')`): no checkboxes, actions, sweeps or time inputs; Details opens a read-only DayEditor.

- [ ] **Tests first:** `P on a missing engineer posts his last site in one bulk request` (assert body); `a cohort sweep marks only the missing members of that site`; `selected rows get Absent with the absent leave type and the bar shows the count`; `select-all picks only the visible page`; `a 409 names the conflicting employees and refetches the sheet`; `an office in-time edit posts site null and the time`; `a row without a last site is skipped with a toast`; `a locked period or a reader sees no write controls` (both).
- Gate F. Commit "Add quick marking and sweeps to the day sheet".

### Task 20: Command bar and paste dialog

**Files:** `frontend/src/pages/attendance-sheet.tsx`, its test, locales.

**Behaviour:**
- Command input above the list (label `attendance.sheet.command`): typing filters the list to `parseBatch` matches and shows a preview chip (N matched, unknown IDs, capped notice); Enter on an ID batch applies Present, on a name search selects the matches and writes nothing; the input clears after an apply.
- Paste button → `Dialog` (`components/ui/dialog.tsx`) with a textarea; newlines become spaces before parsing; Present / Absent / Sick buttons apply to the matches; toast reports unknown IDs.
- [ ] **Tests first:** `Enter on a range marks those employee IDs present`; `a name search selects the matches and writes nothing`; `the paste dialog applies Absent to the pasted list and reports unknown IDs`; `a capped range says only the first 500 were used`.
- Gate F. Commit "Add the command bar and paste to the day sheet".

### Task 21: Keyboard and undo

**Files:** `frontend/src/pages/attendance-sheet.tsx`, its test, locales.

**Behaviour:**
- One `keydown` listener on `document` inside `DaySheet`, following the slice 2b key rules: `Slash` focuses the command input; `KeyJ`/`ArrowDown`, `KeyK`/`ArrowUp` move the cursor over the visible page (`aria-current="true"`, ring class, `scrollIntoView?.({block:'nearest'})`); `KeyP`/`KeyA`/`KeyS` act on the cursor row then advance; `KeyE` opens the DayEditor; Ctrl/Cmd+`KeyZ` undoes; `Escape` clears selection and cursor and blurs. Read-only: navigation and E only.
- Undo: `useRef` stack, max 15, oldest dropped; entry `{label, date, before: SheetRow[], after}`; undo sends `undoRows(before, after)` to the bulk endpoint for that entry's date; 409 → toast `undoConflict` and drop the entry. Undo button shows "Undo (n)", disabled when empty. A shortcuts line (`<kbd>` + i18n descriptions) shows at lg and up.
- [ ] **Tests first:** `J then P marks the first row and moves the cursor to the second`; `a status key acts on an Arabic layout` (`fireEvent.keyDown(document,{key:'ح',code:'KeyP'})`); `keys do nothing while typing in the command bar or while the day editor is open`; `Ctrl+Z restores the previous day` (body `{clear:true,version:1}` for a previously missing row); `an undo refused with 409 is dropped with a message`; `the undo stack keeps the last 15`; `Escape clears the selection`; `Ctrl+P is left to the browser` (no request).
- Gate F. Commit "Add keyboard marking and undo to the day sheet".

### Task 22: Keep the employee code apart from the name in the grid (Gate 2 defect 1)
- `frontend/src/pages/attendance.tsx`: the grid's sticky employee cell becomes `<span class="flex items-center gap-2"><span class="tabular text-muted-foreground">{code}</span><bdi>{name}</bdi></span>`.
- Test first (`attendance.test.tsx`): `getByText('Engineer-1').tagName === 'BDI'` (use a Latin-named fixture). Gate F. Commit.

### Task 23: Keep the period range on one line (Gate 2 defect 2)
- `attendance.tsx`: `whitespace-nowrap` on the period range label; the picker row gets `flex-wrap`. Test first: the label has `whitespace-nowrap`. Gate F. Commit.

### Task 24: Mobile nav close button overlaps the brand (Gate 2 defect 3)
- `frontend/src/components/layout/AppLayout.tsx`: `SidebarHeader` accepts `className`; the mobile sheet passes `pe-14`. Test first (`AppLayout.test.tsx`): the header inside the open sheet has `pe-14`. Gate F. Commit.

### Task 25: Settings tabs truncate at 1024 (Gate 2 defect 4)
- `frontend/src/pages/settings.tsx`: `TabsList className="sm:h-auto sm:flex-wrap"` (phones keep the scroll). Test first (`settings.test.tsx`): tablist has `sm:flex-wrap`. Gate F. Commit.

### Task 26: Read-only day editor looks editable (Gate 2 defect 5)
- `frontend/src/pages/attendance-day.tsx`: when read-only and not locked, show a note `attendance.day.readOnlyNote`; the form container adds `[&_input:disabled]:bg-muted [&_textarea:disabled]:bg-muted [&_select:disabled]:bg-muted`. Locales ar/en.
- Test first: the CEO (view only) sees the note, inputs disabled, no Save. Gate F. Commit.

### Task 27: Derived cells are faint in dark mode (Gate 2 defect 6)
- `attendance.tsx`: `LOOK.derived` = `border border-dashed border-muted-foreground/60 text-muted-foreground` (legend too). Test first: derived cell has `border-muted-foreground/60`. Gate F. Commit.

### Task 28: English screens show Arabic names (Gate 2 defect 7)
- Use `localName` (Task 17) for site names in the grid, the day editor, the sheet chips and cohorts, and for employee names. Leave types seeded without `name_en` fall back to new keys `attendance.leaveName.<code>` (friday, saturday, official_holiday, sick, rest, absent, other).
- Files: `pages/attendance.tsx`, `attendance-day.tsx`, `attendance-sheet.tsx`, locales, tests. Test first: in `en` the grid shows `Site-1 EN` and a seeded leave type shows `Friday`. Gate F. Commit.

### Task 29: Notes keep their trailing zeros (Task 7 minor)
- `attendance-day.tsx`: `asText` strips trailing zeros only for `outside_amount` and `advance_amount`. Test first: note `3.50` stays `3.50`; advance `500.000000` shows `500`. Gate F. Commit.

### Task 30: Refresh attendance after a refused save (Task 8 minor)
- `frontend/src/lib/query.ts`: `useSave` invalidates in `finally`, so a refusal also refetches. Test first (`lib/query.test.tsx`): a 409 save still invalidates `['attendance']` and the audit log. Gate F. Commit.

### Task 32: Manager `/attendance` shows a permission-error shell (capture defect #6)
- `frontend/src/pages/attendance.tsx` (check first how other gated routes/pages handle a missing permission and reuse that): without `attendance.view`, render the app's existing no-access / not-found page instead of the page shell. Test first: a user without `attendance.view` sees no group select and no 403 banner. Gate F. Commit.

### Task 33: Long lock dialog hides the confirm checkbox (capture defect #8)
- `attendance.tsx` `MissingDays`: the list sits in a `max-h-64 overflow-y-auto` region (focusable, labelled) so the confirm checkbox and the Lock button stay visible below it. Test first: with many missing days the list container has the scroll classes and the checkbox renders after it. Gate F. Commit.

### Task 31: Same-PR docs
- `docs/calculations.md`: new §13 "Attendance day sheet (display only)" — P32 totals, P31 last site, office on time / late = the §1 lateness hint is 0 / above 0.
- `CONTEXT.md` ("Work and attendance"): **Day sheet** (كشف اليوم) — one group's attendance for one date, where HR marks many employees at once. _Avoid_: roll call, check-in screen. **Last site** (آخر موقع) — the work site of an employee's latest recorded work day in the 7 days before a date. _Avoid_: yesterday's site.
- `docs/hrms-module-plan.md` "Attendance entry (1c)" row: add "a day sheet per group and date with bulk marking (cohort sweeps, ID lists, ranges, paste) and a session undo; the period grid stays".
- Gate F. Commit "Document the attendance day sheet".

**Locale keys (Tasks 17–21; ar | en):**

| Key | ar | en |
|---|---|---|
| `attendance.tab.sheet` | كشف اليوم | Day sheet |
| `attendance.sheet.kpi.total` | الإجمالي | Total |
| `attendance.sheet.kpi.accounted` | {{done}} من {{total}} مسجل | {{done}} of {{total}} recorded |
| `attendance.sheet.kpi.worked` | في موقع | At a site |
| `attendance.sheet.kpi.onTime` | في الموعد | On time |
| `attendance.sheet.kpi.late` | متأخر | Late |
| `attendance.sheet.kpi.leave` | إجازة | Leave |
| `attendance.sheet.kpi.absent` | غياب | Absent |
| `attendance.sheet.days` | أيام الفترة | Period days |
| `attendance.sheet.cohortsSite` | حسب آخر موقع | By last site |
| `attendance.sheet.cohortsDept` | حسب القسم | By department |
| `attendance.sheet.noLastSite` | بدون موقع سابق | No last site |
| `attendance.sheet.noDepartment` | بدون قسم | No department |
| `attendance.sheet.sweep` | تسجيل الباقين | Mark the rest |
| `attendance.sheet.command` | الرقم الوظيفي أو الاسم أو قائمة أو نطاق | Employee ID, name, list or range |
| `attendance.sheet.matched` | {{count}} مطابق | {{count}} matched |
| `attendance.sheet.unknown` | أرقام غير موجودة: {{ids}} | Unknown IDs: {{ids}} |
| `attendance.sheet.capped` | استُخدمت أول 500 رقم فقط | Only the first 500 IDs were used |
| `attendance.sheet.paste` | لصق أرقام | Paste IDs |
| `attendance.sheet.action.present` | حاضر | Present |
| `attendance.sheet.action.absent` | غائب | Absent |
| `attendance.sheet.action.sick` | مرضي | Sick |
| `attendance.sheet.action.details` | التفاصيل | Details |
| `attendance.sheet.action.setSite` | تعيين موقع | Set site |
| `attendance.sheet.selected` | {{count}} محدد | {{count}} selected |
| `attendance.sheet.selectRow` | تحديد {{name}} | Select {{name}} |
| `attendance.sheet.selectPage` | تحديد الصفوف الظاهرة | Select visible rows |
| `attendance.sheet.clearSelection` | إلغاء التحديد | Clear selection |
| `attendance.sheet.applied` | تم تسجيل {{count}} | {{count}} saved |
| `attendance.sheet.skippedNoSite` | {{count}} بدون موقع سابق؛ اختر الموقع من التفاصيل | {{count}} have no last site; choose it in Details |
| `attendance.sheet.conflict` | تغيّرت أيام بعد التحميل ({{names}})؛ لم يُحفظ شيء وتم تحديث الكشف | Days changed after loading ({{names}}); nothing was saved and the sheet was refreshed |
| `attendance.sheet.tooMany` | الحد الأقصى 500 موظف في المرة الواحدة | At most 500 employees at once |
| `attendance.sheet.undo` | تراجع | Undo |
| `attendance.sheet.undoCount` | تراجع ({{count}}) | Undo ({{count}}) |
| `attendance.sheet.undone` | تم التراجع | Undone |
| `attendance.sheet.undoConflict` | تغيّر اليوم منذ ذلك الحين؛ تعذّر التراجع | The day changed since; undo is not possible |
| `attendance.sheet.inTime` | وقت حضور {{name}} | In time for {{name}} |
| `attendance.sheet.lateBy` | متأخر {{minutes}} د | Late {{minutes}} min |
| `attendance.sheet.lastSite` | آخر موقع: {{site}} | Last site: {{site}} |
| `attendance.sheet.totals` | إجمالي الفترة | Period totals |
| `attendance.sheet.locked` | الفترة مقفلة؛ الكشف للعرض فقط. | This period is locked; the sheet is read-only. |
| `attendance.sheet.noMatch` | لا يوجد موظفون مطابقون. | No employees match. |
| `attendance.sheet.shortcuts.*` | بحث، صف، حاضر، غائب، مرضي، تفاصيل، تراجع | Search, row, present, absent, sick, details, undo |

Reuse existing `attendance.state.missing` / `attendance.short.absent` where they fit.

### Gate 2b (controller)
1. Gates B-test, B-pint, B-stan, B-deptrac, F.
2. `docker compose up -d`, `php artisan migrate`, `db:seed --class=AttendanceDemoSeeder`; `preview_start frontend`; Playwright from the npx cache (install nothing).
3. Every screen at 375 / 1024 / 1440 × ar / en × light / dark into `.claude/gate-1c-2b/<n>-<screen>-<width>-<lang>-<theme>.png`, HR unless noted. New: S1 sheet engineer; S2 sheet office; S3 selection (3 rows); S4 command `1-3`; S5 paste dialog (not submitted); S6 after one P (toast, "Undo (1)"), then undo; S7 keyboard cursor on row 3; S8 locked (previous engineer period, locked then reopened with "Gate 2b screenshots"); S9 CEO read-only sheet + editor note. Re-shoot 1–12 from Gate 2.
4. Console + network checked; data writes in `capture-log.md`. Post the shots; wait for the owner. Slice 3 waits.

# Slice 3 — Excel grid template and import with preview

> **Owner decision (2026-10-20, supersedes the spec's one-row-per-employee-day template):** the template is a **grid like HR's current timesheet**. It has one row per employee and one column per period day. Each cell holds that day's site or day type (a leave type), chosen from a dropdown. Times, overtime, outside-Alexandria minutes and amount, transport, advances and notes are **not** in the file; HR keeps them in the day editor.

**Grid rulings (controller, 2026-10-20; each is in the ledger with its cost if wrong):**
- **G1 One write path.** Each changed cell goes through the shared `AttendanceDays` row write the bulk endpoint uses, in the exact row shape the Day sheet's Present or Set site sends for that group (see `frontend/src/lib/day-sheet.ts` `bulkRows`), with `source = 'imported'`. The P28 keep-by-kind rule therefore applies unchanged: a site → site change keeps the day's other fields; a change of kind applies the same defaults as the Day sheet.
- **G2 A blank cell is no change**, never a clear. Clearing a day stays in the app.
- **G3 An unchanged cell writes nothing.** A cell equal to the effective day (a stored day, or a derived Friday or holiday compared by its leave type) counts as `unchanged`.
- **G4 Stale protection, per cell.** A hidden sheet `versions` has the same shape as the grid and holds each stored day's `version` (blank = no stored row). A mismatch is a `concurrency_conflict` error for that cell, and a replayed confirm comes back as conflicts (coding-standards §8 idempotency).
- **G5 Cells the employee cannot take** are blank and greyed in the template: days outside employment as the group's type, and future days. A value there is refused (`employee_not_active`, `future_date`).
- **G6 Office cells** take «حاضر» / Present (either language) or a leave type; office has no sites. Present writes what the Day sheet's office Present writes (no times = on time).
- **G7 The header row** holds the code label, the name label, then the period's dates as text `Y-m-d`, in order. It must match the requested group's period exactly, and the `versions` sheet must have the same shape; anything else is `template_mismatch`. A header date that Excel turned into a date cell or a serial reads the same as the text.
- **G8 Every change and every error is per cell:** `{row, column}` are the Excel row number and column letter, so HR can find the cell.
- **G9 Limits:** 5 MB, `.xlsx` only, never stored (spec); `AttendanceImport::MAX_ROWS = 2000` employee rows.

---

### Task 10: Grid template download

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md`.

**Files:**
- Modify: `backend/composer.json` (root `"php"` from `^8.3` to `^8.4`; Open point 19), `backend/composer.lock` (`docker compose exec app composer require openspout/openspout:5.12.2`; checked on 2026-10-18: v5.12.2 is the latest stable and requires `php ~8.4.0 || ~8.5.0 || ~8.6.0`, with ext-zip, xmlreader, dom and libxml, all present in the image; the image runs PHP 8.4.24)
- Create: `backend/app/Modules/Attendance/Application/AttendanceTemplate.php`
- Create: `backend/app/Modules/Attendance/Infrastructure/XlsxTemplateWriter.php`
- Modify: `backend/app/Modules/Attendance/Presentation/AttendanceController.php` (`template`), `backend/routes/api.php`, `backend/lang/{ar,en}/attendance.php` (`template.*`)
- Test: `backend/tests/Feature/AttendanceTemplateTest.php`

**Interfaces:**
- Consumes: `EmployeeRoster` (the group's employees, own-scope aware as the sheet is), `EffectiveDays` (the period's effective days per employee), the sites and leave types.
- Produces:
  - `AttendanceTemplate::grid(EmployeeType $g, string $start, ?string $ownerId): array{dates: list<string>, rows: list<array{employee_id: string, code: int, name: string, cells: list<?string>, versions: list<?int>, grey: list<int>}>, choices: list<string>}`:
    - `dates`: the period's dates, `Y-m-d`;
    - one row per employee employed as the group's type on at least one period day, ordered by code;
    - `cells[i]`: for a stored day, its site's or leave type's `name_ar`; for a derived day, the leave type's `name_ar`; otherwise null;
    - `versions[i]`: the stored day's version, else null;
    - `grey`: the 0-based day indexes the employee cannot take (G5);
    - `choices`: for field groups, active work sites then active leave types by `name_ar`; for office, the present word in the request language, then active leave types.
  - `XlsxTemplateWriter::write(array $grid, string $locale): string` (the path of a temp file):
    - **Sheet `attendance`:**
      - row 1 is the header (G7), in bold;
      - every cell is written as **text** (number format `@`);
      - grey fill on the `grey` cells;
      - right-to-left when the locale is `ar` (XLSX `SheetView`);
      - freeze the header row and the two name columns if OpenSpout supports it (skip it otherwise and say so);
      - a `ListValidationRule` on the day cells points at `lists!A:A` (check the class in `vendor/openspout/openspout/src/Writer/XLSX/Validation/Rules/`).
    - **Sheet `lists`**, hidden: column A holds `choices`.
    - **Sheet `versions`**, hidden: the same header, then one row per employee in the same order, with each version or blank.
  - `GET attendance/template?group=&period_start=` (`permission:attendance.edit`) streams the file with `response()->download($path, "attendance-{$group}-{$start}.xlsx")->deleteFileAfterSend()`. The content type is `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
  - `template.code` الرقم الوظيفي / Employee ID; `template.name` الاسم / Name; `template.present` حاضر / Present.

- [ ] **Step 1: Write the failing tests** (`cairo('2026-10-20 12:00')`, `signIn('hr')`; read the download back with `OpenSpout\Reader\XLSX\Reader`)
  - `test_template_is_a_grid_of_employees_by_period_days_prefilled`:
    - Setup: two engineers (Engineer-2 hired 2026-10-10), a holiday on 10-06, and Engineer-1's stored day on 10-01 at `Site-1`, version 1.
    - Row 1 is `['الرقم الوظيفي', 'الاسم', '2026-09-26', …, '2026-10-25']` (30 dates), and there are 2 data rows.
    - Engineer-1: 10-01 reads `Site-1`; 10-02 (a Friday) reads the `friday` leave type's `name_ar`; 10-06 reads the `holiday` leave type's `name_ar`; 10-03 is blank.
    - Engineer-2: every cell before 10-10 is blank and grey.
    - Both rows: every cell after 10-20 is blank and grey.
  - `test_template_hides_lists_and_versions`:
    - `lists` and `versions` are hidden.
    - `versions` holds `1` at Engineer-1 × 10-01 and is blank elsewhere.
    - `lists` holds `Site-1` and every active leave type.
  - `test_office_template_offers_present_and_leave_types`: the office `lists` starts with `حاضر` and holds no work site.
  - `test_template_headers_follow_the_request_language`: with `Accept-Language: en`, row 1 starts `['Employee ID', 'Name', '2026-09-26']`.
  - `test_template_needs_attendance_edit`: the CEO gets 403.
  - `test_template_follows_own_scope`: a caller with only `attendance.view.own` + `attendance.edit` gets only their own employees (same rule as the sheet).
- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter=AttendanceTemplateTest` → FAIL.
- [ ] **Step 3: Add the dependency, then implement `AttendanceTemplate`, `XlsxTemplateWriter`, the route and the lang keys**
- [ ] **Step 4: Run them and see them pass** — `docker compose exec app php artisan test` → PASS.

**Gates:** B-test, B-pint, B-stan, B-deptrac, plus `grep -rl OpenSpout backend/app` listing only `Infrastructure` files (deptrac has no OpenSpout layer; adding one is an owner decision).

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance Excel grid template download" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 11: Grid import preview and confirm (trust boundary)

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md` (§5, §8, §9 for files).

**Files:**
- Create: `backend/app/Modules/Attendance/Infrastructure/XlsxAttendanceReader.php`
- Create: `backend/app/Modules/Attendance/Application/AttendanceImport.php`
- Modify: `backend/app/Modules/Attendance/Domain/AttendanceRefusal.php` (three cases), `backend/app/Modules/Attendance/Presentation/AttendanceController.php` (`preview`, `confirm`), `backend/routes/api.php`, `backend/lang/{ar,en}/attendance.php` (`import.*`, `refusal.*`), `docs/calculations.md` (new §14 "Excel import": G1–G9 as rules, no formulas)
- Test: `backend/tests/Feature/AttendanceImportTest.php`

**Interfaces:**
- Consumes: Task 10 `AttendanceTemplate` (the header labels and choices), the shared `AttendanceDays` row write (G1), `EffectiveDays`, `PeriodGuard`, `AttendanceRefusal`.
- Produces:
  - `XlsxAttendanceReader::read(string $path, int $maxRows): array{header: list<?string>, rows: list<array{row: int, cells: list<?string>}>, versions: list<list<?string>>}`. It reads the sheets `attendance` and `versions`.
    - Each cell is normalised to `?string`: trimmed, inner whitespace collapsed, Arabic-Indic and Eastern digits converted to ASCII, and empty → null.
    - In the header, a `DateTimeInterface` or an int or float Excel serial (1899-12-30 + n days) becomes `Y-m-d`.
    - More than `$maxRows` data rows throws `AttendanceRefused::because(TooManyRows, ['max' => $maxRows])`.
    - A file OpenSpout cannot open throws `AttendanceRefused::because(UnreadableFile)`.
  - `AttendanceRefusal` gains `TemplateMismatch = 'template_mismatch'`, `UnreadableFile = 'unreadable_file'` and `TooManyRows = 'too_many_rows'`, all 422.
  - `AttendanceImport::MAX_ROWS = 2000`.
  - `AttendanceImport::preview(EmployeeType $g, string $start, string $path, ?string $ownerId): array{summary: array{cells: int, changes: int, unchanged: int, errors: int}, changes: list<array{row: int, column: string, employee_id: string, code: int, name_ar: string, date: string, from: ?string, to: string}>, errors: list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string}>}`.
    - `from`/`to` are display names in the request language.
    - `cells` counts non-blank day cells.
  - `AttendanceImport::confirm(…same…): array{applied: int, unchanged: int, errors: list<…>}`. It re-parses, then writes every valid changed cell in **one** transaction through the shared write (which audits). Error cells are skipped and returned.
  - **Cell rules, in order:**
    1. A blank cell is skipped and counted nowhere (G2).
    2. The row's code must belong to a live employee in the caller's scope (`unknown_employee`, `column: null`). A code that appears on two rows gives `duplicate_row` on the second.
    3. A date after today gives `future_date`; a day the employee is not employed as the group's type gives `employee_not_active`.
    4. A locked period gives `period_locked`, on every changed cell.
    5. The value must match a choice for the group (G6): a site's or leave type's `name_ar` (whitespace collapsed) or `name_en` (case-insensitive), or for office the present word in either language. Otherwise `unknown_site`.
    6. The cell's `versions` value must equal the stored version, where blank means no stored row (G4). Otherwise `concurrency_conflict`.
    7. Any refusal from the shared write (for example `site_inactive`) becomes that cell's error with its own code.
    - A cell equal to the effective day is `unchanged` and writes nothing (G3).
  - **Whole-file refusals** are 422 envelopes through `AttendanceRefused`:
    - `template_mismatch` (G7);
    - `unreadable_file`;
    - `too_many_rows` (`:max`).
  - **Request (both endpoints):** `group`, `period_start`, `file: required|file|max:5120|mimes:xlsx|extensions:xlsx`. The upload is read from its PHP temp path and never stored (no `store()`, no `Storage`). Permission: `attendance.edit`, plus the own-scope rule of the bulk write.
  - **`import.<code>` messages** (ar / en):
    - `unknown_employee`: الرقم الوظيفي غير موجود. / Unknown employee ID.
    - `unknown_site`: الموقع أو نوع اليوم غير موجود. / Unknown site or day type.
    - `duplicate_row`: هذا الموظف مكرر في الملف. / This employee appears twice in the file.
    - The remaining codes reuse `attendance.refusal.*` and `attendance.rule.*`.
  - **New `refusal.*` messages** (ar / en):
    - `template_mismatch`: الملف لا يطابق قالب الحضور لهذه الفترة. / The file does not match this period's attendance template.
    - `unreadable_file`: تعذّرت قراءة الملف. / The file could not be read.
    - `too_many_rows`: يحتوي الملف على أكثر من :max صف. / The file has more than :max rows.

- [ ] **Step 1: Write the failing tests**

Setup: `cairo('2026-10-20 12:00')`, `signIn('hr')`, `leaveTypes()`, `workSite('Site-1')`, `$this->eng = employee('engineer')`, `holiday('2026-10-06')`. Helpers:
- `download(string $group = 'engineer'): string` saves the Task 10 template to a temp path;
- `edit(string $path, array $changes): UploadedFile` rewrites cells keyed `[code][date] => value` with the OpenSpout writer, keeps the hidden sheets, and returns `new UploadedFile($path, 'attendance.xlsx', null, null, true)`;
- `post(string $endpoint, UploadedFile $f)`.

The tests:
- `test_round_trip_download_edit_upload`:
  - Edit Engineer-1's cells: 10-01 `Site-1`, 10-03 the absent leave type's `name_ar`, 10-04 `Site-1`, 10-05 `No-Such-Site`.
  - Preview gives `changes` 3 and `errors` 1, with `errors.0.error` `unknown_site` and `errors.0.column` the 10-05 column letter.
  - The preview saves nothing.
  - Confirm gives `applied` 3; every stored `source` is `imported`; there are 3 `CREATED` audit rows.
  - Replaying the confirm gives `applied` 0, and the stored count stays 3.
- `test_an_untouched_template_writes_nothing`: uploading the download as it is (derived Fridays included) gives `applied` 0 and no rows.
- `test_a_cell_changed_since_the_download_is_a_conflict_not_an_overwrite`: store 10-01 at `Site-1`, download (version 1), then change the day in the app to `Site-2` (version 2). Upload with 10-01 set to `Site-1` → `concurrency_conflict`, and the stored site is still `Site-2`.
- `test_the_import_changes_only_the_site_and_keeps_the_rest`: an engineer's stored 10-01 at `Site-1` with `late_minutes` 10 → set to `Site-2` → `late_minutes` is still 10 (G1, P28).
- `test_office_present_word_in_either_language`: `حاضر` and `Present` on office rows write the office present day; `Site-1` on an office row is `unknown_site`.
- `test_a_blank_cell_never_clears_a_stored_day`: blank out a stored day's cell → `applied` 0 and the day is unchanged (G2).
- `test_header_dates_in_any_excel_form_are_read_the_same`: rewrite the header dates as date cells and as serial numbers → the preview has no errors and the same changes.
- `test_each_cell_error_kind` (data provider): `unknown_employee` (a row with code 9999), `duplicate_row`, `future_date` (2026-10-21), `employee_not_active` (before the hire date), `unknown_site`, `site_inactive`.
- `test_a_locked_period_refuses_every_changed_cell`: lock, then confirm → every changed cell is `period_locked`, and `applied` is 0.
- `test_the_file_is_checked_for_size_type_and_template`:
  - a file of 5 MB + 1 KB → 422 `file`;
  - `attendance.csv` → 422 `file`;
  - an xlsx whose header dates belong to another period → 422 `template_mismatch`;
  - a file without the `versions` sheet → 422 `template_mismatch`;
  - `Storage::fake('local')` sees no files afterwards.
- `test_too_many_rows_is_refused`: with `MAX_ROWS` overridden to 3 through the container, a 4-employee file → 422 `too_many_rows`.
- `test_import_follows_own_scope`: an own-scope caller's file that names another manager's employee → `unknown_employee` for that row.

- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter=AttendanceImportTest` → FAIL.
- [ ] **Step 3: Implement `XlsxAttendanceReader` with its normaliser**
- [ ] **Step 4: Implement `AttendanceImport`, the two endpoints, the lang keys and `docs/calculations.md` §14**
- [ ] **Step 5: Run them and see them pass** — `docker compose exec app php artisan test` → PASS.

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 6: Commit** — `git commit -m "Add the attendance Excel grid import with preview" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 12: Import tab UI

**Model:** Opus implementer (the owner's rule this session; the earlier plan said Sonnet) · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Create: `frontend/src/pages/attendance-import.tsx`, `frontend/src/pages/attendance-import.test.tsx`
- Modify: `frontend/src/lib/api.ts`, `frontend/src/lib/api.test.ts`, `frontend/src/pages/attendance.tsx` (a third tab after Day sheet and Period grid, `import`, shown only with `can('attendance.edit')`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 10 `GET /attendance/template`; Task 11 `POST /attendance/import/{preview,confirm}`; the page's group and period state.
- Produces:
  - `api.ts`: `call()` sends a `FormData` body as-is, with no JSON content type. A new `apiBlob(path: string): Promise<Blob>` shares the headers and the 401 handling.
  - `ImportTab({ group, periodStart })`, in three steps on one Card:
    1. `attendance.import.download`: `apiBlob`, then a temporary object-URL anchor named `attendance-{group}-{start}.xlsx`.
    2. A file input (`accept=".xlsx"`, label `attendance.import.file`). Before any upload it refuses a file over 5 MB or not ending in `.xlsx`, showing `attendance.import.badFile`.
    3. `attendance.import.preview` posts `FormData {group, period_start, file}`. It shows `attendance.import.summary` and the `attendance.import.notSaved` note. Below them are two tables:
       - changes: the cell (column + row, e.g. `F7`, as an LTR isolate), the employee, the date, and `from` → `to`;
       - errors: the cell, the employee code, the date and the message.

       Each sits in a Card that follows the Day sheet's responsive pattern: a table from xl, stacked cards below, and no overflow at any width.
  - `attendance.import.confirm` (disabled when `changes === 0`) re-sends **the same `File`**. It shows `attendance.import.done`, invalidates `attendance` and clears the preview. Whole-file 422s show in `ErrorBanner`.

- [ ] **Step 1: Write the failing tests**
  - `api.test.ts`: `'FormData bodies are sent without a JSON content type'` and `'apiBlob returns the body as a Blob with the bearer token'`.
  - `'upload shows the preview and saves nothing until confirm'`:
    - The preview mock returns `{ summary: { cells: 30, changes: 3, unchanged: 26, errors: 1 }, changes: [...], errors: [{ row: 3, column: 'G', code: 12, date: '2026-10-05', error: 'unknown_site', message: 'الموقع أو نوع اليوم غير موجود.' }] }`.
    - The summary text shows, and the error message and the cell `G3` are in the table.
    - No confirm call has been made yet.
  - `'confirm re-sends the same file and shows the result'`: the confirm `FormData.get('file')` is the same `File` object, and `تم حفظ 3 يومًا، وتُركت 1 خانة بها أخطاء.` shows.
  - `'a file over 5 MB or not xlsx is refused before upload'`: no fetch happens.
  - `'the import tab needs attendance.edit'`.
- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/pages/attendance-import.test.tsx src/lib/api.test.ts` → FAIL.
- [ ] **Step 3: Implement `apiBlob`, the FormData body, `ImportTab`, the tab and these keys**

| Key | ar | en |
|---|---|---|
| `attendance.tab.import` | الاستيراد من Excel | Import from Excel |
| `attendance.import.desc` | نزّل قالب الفترة (موظف في كل صف ويوم في كل عمود)، اختر الموقع أو نوع اليوم في كل خانة، ثم ارفعه لمراجعة التغييرات قبل الحفظ. | Download the period's template (one employee per row, one day per column), pick the site or day type in each cell, then upload it to review the changes before saving. |
| `attendance.import.download` | تنزيل القالب | Download template |
| `attendance.import.file` | ملف Excel (‎.xlsx، حتى 5 ميجابايت) | Excel file (.xlsx, up to 5 MB) |
| `attendance.import.badFile` | اختر ملف ‎.xlsx لا يتجاوز 5 ميجابايت. | Choose an .xlsx file of 5 MB or less. |
| `attendance.import.preview` | معاينة التغييرات | Preview changes |
| `attendance.import.notSaved` | لم يُحفظ أي شيء بعد. راجع المعاينة ثم أكّد الحفظ. | Nothing is saved yet. Review the preview, then confirm. |
| `attendance.import.summary` | تغييرات: {{changes}} · بدون تغيير: {{unchanged}} · أخطاء: {{errors}} | Changes: {{changes}} · Unchanged: {{unchanged}} · Errors: {{errors}} |
| `attendance.import.changes` | التغييرات | Changes |
| `attendance.import.errors` | الأخطاء | Errors |
| `attendance.import.cell` | الخانة | Cell |
| `attendance.import.from` | القيمة الحالية | Current value |
| `attendance.import.to` | القيمة الجديدة | New value |
| `attendance.import.message` | الخطأ | Error |
| `attendance.import.confirm` | تأكيد الحفظ | Confirm and save |
| `attendance.import.done` | تم حفظ {{applied}} يومًا، وتُركت {{skipped}} خانة بها أخطاء. | Saved {{applied}} days; skipped {{skipped}} cells with errors. |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the attendance import tab" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Gate 3 (controller)

Run the import walkthrough in the browser as HR, for engineers 26-09 → 25-10:
1. Download the template and open it once to check the grid, the greyed cells, the hidden `lists` and `versions` sheets and the dropdowns. Put one picture of the open file into the gate folder: a screenshot from Excel or LibreOffice if either is installed, otherwise a rendering of the sheet, labelled as such.
2. Edit 3 cells plus 1 bad cell, then upload.
3. The preview must show 3 changes and 1 error, with the bad cell's address.
4. Confirm. Only the 3 are saved, and the Day sheet and the grid show them.

Take the screens at 375, 1024 and 1440 px, ar and en, light and dark, saved as `.claude/gate-1c-3/<n>-<screen>-<width>-<lang>-<theme>.png`:
1. The import tab, empty.
2. The preview, with the changes and errors tables.
3. The result after confirm.
4. A whole-file refusal (`template_mismatch`).

Post the shots and wait for approval.

---

# Slice 4 — Notifications

### Task 13: Notifications module and the daily missing-days job

**Model:** Opus implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`; reads `docs/coding-standards.md` (§10, §12).

**Files:**
- Create: `backend/database/migrations/2026_10_22_000001_create_notifications.php`
- Create: `backend/app/Modules/Notifications/Infrastructure/Eloquent/Notification.php`
- Create: `backend/app/Modules/Notifications/Application/{Notifier,NotificationInbox}.php`
- Create: `backend/app/Modules/Notifications/Presentation/NotificationController.php`
- Create: `backend/app/Modules/Identity/Application/Rbac/PermissionHolders.php`
- Create: `backend/app/Modules/Attendance/Application/SendMissingDaysAlert.php` (the job)
- Create: `backend/app/Modules/Attendance/Presentation/AlertMissingDaysCommand.php`
- Modify: `backend/bootstrap/app.php` (`withCommands`), `backend/routes/console.php` (schedule), `backend/routes/api.php`, `backend/tests/Feature/RbacTest.php` (`$authOnly` gains `api/v1/notifications` and `api/v1/notifications/{notification}/read`)
- Test: `backend/tests/Feature/NotificationsTest.php`

**Interfaces:**
- Consumes: Task 3 `AttendanceGrid::missingCounts`.
- Produces:
  - Table `notifications`: `standardColumns`; `user_id` FK `users` cascadeOnDelete; `type` varchar(64); `date` date; `data` jsonb; `read_at` timestamptz null; `unique(user_id, type, date)`. The model uses `Audited` and `HasStandardColumns`, and casts `data` to array and `read_at` to datetime.
  - `Notifier::notifyOnce(string $userId, string $type, string $date, array $data): bool`. It creates the row and returns true; it returns false when one exists. A `UniqueConstraintViolationException` from a race also returns false.
  - `NotificationInbox::list(string $userId, int $perPage)`: the caller's rows, newest `date` first. `markRead(string $userId, string $id): array`: another user's id → `ModelNotFoundException` (404). Marking a row read twice keeps the first `read_at`.
  - `PermissionHolders::userIds(string $triple): list<string>`: active, live users whose role holds the triple, in one join query.
  - `SendMissingDaysAlert implements ShouldQueue`:
    - `public function __construct(public string $date)`;
    - `$tries = 3`, `backoff(): [60, 300]`, `onQueue('maintenance')` in the constructor;
    - `handle(AttendanceGrid, Notifier, PermissionHolders)`: `$groups` = the `missingCounts($date)` entries with `missing > 0`. When `$groups` is empty, return. Otherwise, for each id in `userIds('attendance.edit.all')`, call `notifyOnce($id, 'attendance_missing_days', $date, ['date' => $date, 'groups' => $groups])`;
    - `failed(Throwable $e)`: `Log::error('attendance.missing_days_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()])`.
    - The data holds counts only, never names (§9).
  - `attendance:alert-missing {date?}`: the date defaults to Cairo today; a non-`Y-m-d` value fails with exit code 1; it dispatches the job.
  - `routes/console.php`: `Schedule::command('attendance:alert-missing')->dailyAt('21:00')->timezone('Africa/Cairo');`
  - `GET notifications?per_page=` → rows `{id, type, date, data, read_at}`. `PATCH notifications/{notification}/read` → the row.

- [ ] **Step 1: Write the failing tests** (`cairo('2026-10-20 21:00')`; `employee('engineer', '2026-09-26')`, which leaves 22 missing days: 09-26 → 10-20 is 25 days, less 3 Fridays)

```php
public function test_the_alert_creates_one_notification_per_permission_holder_per_date(): void
{
    $hr1 = $this->makeUser('hr'); $hr2 = $this->makeUser('hr'); $this->makeUser('ceo'); $this->makeUser('manager');
    $this->makeUser('hr', ['is_active' => false]);
    $clerk = Role::create(['slug' => 'clerk', 'name' => 'Clerk', 'name_ar' => 'كاتب']);
    $clerk->permissions()->attach(Permission::where(['resource' => 'attendance', 'action' => 'edit', 'scope' => 'all'])->value('id'));
    $c = $this->makeUser('clerk');
    $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();
    $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();   // a re-run is a no-op
    $this->assertEqualsCanonicalizing([$hr1->id, $hr2->id, $c->id], Notification::pluck('user_id')->all());
    $this->assertSame(['date' => '2026-10-20', 'groups' => [['group' => 'engineer', 'period_start' => '2026-09-26', 'period_end' => '2026-10-25', 'missing' => 22]]], Notification::first()->data);
}

public function test_no_notification_when_nothing_is_missing(): void   // no employees → 0 rows
public function test_the_job_is_queued_on_maintenance_with_retries(): void
{
    Queue::fake();
    $this->artisan('attendance:alert-missing')->assertSuccessful();
    Queue::assertPushedOn('maintenance', SendMissingDaysAlert::class, fn ($j) => $j->date === '2026-10-20' && $j->tries === 3 && $j->backoff() === [60, 300]);
}
public function test_the_schedule_runs_daily_at_21_cairo(): void
{
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'attendance:alert-missing'));
    $this->assertSame(['0 21 * * *', 'Africa/Cairo'], [$event->expression, $event->timezone]);
}
public function test_a_failed_run_is_logged(): void
{
    Log::spy();
    (new SendMissingDaysAlert('2026-10-20'))->failed(new RuntimeException('down'));
    Log::shouldHaveReceived('error')->with('attendance.missing_days_alert_failed', ['date' => '2026-10-20', 'error' => 'down']);
}
public function test_users_read_and_mark_only_their_own_notifications(): void
{
    // Two users with one row each: GET as A lists only A's; PATCH B's id → 404; PATCH A's → read_at set; a second PATCH keeps it.
}
```
`phpunit.xml` sets `QUEUE_CONNECTION=sync`, so the artisan call runs the job inline.

- [ ] **Step 2: Run them and see them fail** — `docker compose exec app php artisan test --filter='NotificationsTest|RbacTest'` → FAIL.
- [ ] **Step 3: Implement the migration, the model, `Notifier`, `NotificationInbox`, `PermissionHolders` and the controller with its routes**
- [ ] **Step 4: Implement the job, the command and the schedule line**
- [ ] **Step 5: Run them and see them pass** — `docker compose exec app php artisan test` → PASS. Then restart the workers: `docker compose restart worker-maintenance scheduler`.

**Gates:** B-test, B-pint, B-stan, B-deptrac.

- [ ] **Step 6: Commit** — `git commit -m "Add notifications and the daily missing-days alert" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Task 14: Notification bell

**Model:** Sonnet implementer · Opus reviewer. **Brief loads:** `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development`, `ui-ux-pro-max:ui-ux-pro-max`; reads `docs/coding-standards.md`.

**Files:**
- Create: `frontend/src/components/layout/NotificationBell.tsx`, `frontend/src/components/layout/NotificationBell.test.tsx`
- Modify: `frontend/src/lib/query.ts` (`useList(path, { refetchInterval }?)`), `frontend/src/components/layout/AppLayout.tsx` (bell before `ProfileMenu`), `frontend/src/pages/audit.tsx` (`ENTITIES` gains `notifications`), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 13 `GET /notifications?per_page=10`, `PATCH /notifications/{id}/read`.
- Produces:
  - `useList` takes an optional second argument `{ refetchInterval?: number }`, passed to `useQuery`. Existing callers are unchanged.
  - `NotificationBell`: an icon button with `aria-label` `notifications.open` (`{{count}}` unread) and a badge with the unread count among the latest 10 (hidden at 0, `9+` above 9). It refetches every 5 minutes and on window focus.
  - It opens a `DropdownMenu modal={false}` listing each notification: the title `notifications.missingDays.title` with `{{date}}`, then one menu item per group, `notifications.missingDays.line` with `{{group}}` (`attendance.group.*`) and `{{count}}`. An unread row is bold with a dot. With no rows it shows `notifications.empty`.
  - Selecting a line sends `PATCH …/read` (when unread), invalidates `notifications`, and navigates to `/attendance?group=<group>&period=<period_start>`.

- [ ] **Step 1: Write the failing tests**
  - `'the bell shows the unread count and the missing-days lines'`: two notifications, one unread → the button name is `فتح الإشعارات (1 غير مقروء)`; opening it shows `أيام غير مسجلة في المهندسون: 22`.
  - `'choosing a line marks it read and opens the grid'`: the PATCH is called for `n1`, and the location becomes `/attendance?group=engineer&period=2026-09-26`.
  - `'no unread notifications, no badge'`.
  - `query.test.tsx`: `'useList passes refetchInterval through'`.
- [ ] **Step 2: Run them and see them fail** — `cd frontend && npx vitest run src/components/layout/NotificationBell.test.tsx src/lib/query.test.tsx` → FAIL.
- [ ] **Step 3: Implement the bell, the `useList` option and these keys**

| Key | ar | en |
|---|---|---|
| `notifications.title` | الإشعارات | Notifications |
| `notifications.open` | فتح الإشعارات ({{count}} غير مقروء) | Open notifications ({{count}} unread) |
| `notifications.empty` | لا توجد إشعارات. | No notifications. |
| `notifications.missingDays.title` | أيام حضور غير مسجلة حتى {{date}} | Missing attendance days as of {{date}} |
| `notifications.missingDays.line` | أيام غير مسجلة في {{group}}: {{count}} | Missing days, {{group}}: {{count}} |
| `audit.entity.notifications` | الإشعارات | Notifications |

- [ ] **Step 4: Run every frontend gate** — `cd frontend && npm test && npm run build && npm run lint` → PASS.

**Gates:** F.

- [ ] **Step 5: Commit** — `git commit -m "Add the notification bell" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`

---

### Gate 4 (controller)

1. Run `docker compose exec app php artisan attendance:alert-missing <today>` twice. `docker compose logs worker-maintenance` shows the job processed both times, and `notifications` holds one row per HR user.
2. The bell shows that notification as HR; as Manager there is none.
3. Take the screens at 375, 1024 and 1440 px, ar and en, light and dark: the bell with its badge, and the bell open. Save them as `.claude/gate-1c-4/<n>-<screen>-<width>-<lang>-<theme>.png`.
4. Click a line: it lands on the grid with the group and period set.
5. Post the shots and wait for approval.

---

## Finish (controller)

1. Run one final whole-branch review on Opus over `03d2394..HEAD`, with the ledger's deferred lines and this plan's Review Focus. Make one fix dispatch, then one scoped re-review.
2. Both gate sets are green on HEAD and `git status` is clean. The October payroll fixtures are untouched: no formula changed, so there is no fixture diff.
3. Run the spec's Verification walkthrough once more end to end, including the audit-log check of every action.
4. Only on the owner's go: push, then `gh pr create --base feature/perf-architecture --head feature/release-1c-attendance`. The body summarises the four slices and lists the Open points with the owner's answers. It ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Open points for the controller

Each point names the default this plan uses.

1. **Clearing a day.** The spec says clearing is "an audited update". Default: a soft delete (version bumped, then `deleted_at` set, audited as UPDATED then DELETED). Re-entry restores the same row, so `unique(employee_id, date)` stays plain.
2. **Office fields.** The spec lists only in and out times. Default: office days take no advance and no note.
3. **Office Saturday** (owner, 2026-10-18). A weekly-off day derives the leave type whose code equals its weekday name: Friday → «اجازة الجمعة» (`friday`), Saturday → «اجازة السبت» (new `LeaveCode::Saturday`). `weekly_off_*` still accepts any weekday. One without a leave type (e.g. `thursday`) is derived with no site, and the lock refuses it with `leave_type_missing`. I chose this over restricting `RULES`, as the smaller change.
4. **No active leave type for a derived code.** Default: the grid still shows the derived day, and the lock refuses with `leave_type_missing`.
5. **Employment dates.** Default: an employee is active from `hire_date` (null: from the first type change) through `termination_date` inclusive.
6. **Alert scope.** Default: per group, the period containing the date and the one before it, each only if unlocked. No notification when every count is 0. Catch-up comes from the cumulative counts; past dates are not backfilled.
7. **Notification endpoints.** Default: auth-only (each user sees only their own rows), with no new permission; `RbacTest`'s auth-only list grows.
8. **Locking before the period ends.** Default: allowed; it materializes derived days for the whole period, future days included.
9. **Excel cell semantics** (revised 2026-10-20 for the owner's grid template). A blank cell is no change, never a clear; a non-blank cell sets only the day's site or day type, and the rest follows the P28 keep rule (Slice 3 G1–G3).
10. **Stale template protection** (revised 2026-10-20). A hidden `versions` sheet in the grid's shape, compared per cell on upload; a mismatch is a `concurrency_conflict` cell error (Slice 3 G4).
11. **The leave-code unique index.** It covers every code, `other` included, as the spec says. `2026_10_19_000003_add_saturday_leave_code` runs first and is idempotent:
    - it recodes every active `friday` leave site whose `name_ar` contains «السبت» to `saturday`, which clears the dev DB's known duplicate (the owner's «اجازه السبت»);
    - it inserts «اجازة السبت» if no active `saturday` exists;
    - it writes its own `audit_log` rows. No earlier migration audits data, so this is the first one that does.
    - The index migration still aborts on any other real duplicate; check the test machine before deploying.
12. **The lock table column.** Default: named `employee_type` (`group` is a reserved word); the API parameter stays `group`.
13. **Out before in.** Default: for office and engineer it is 422 `out_before_in`; only permanent workers cross midnight.
14. **Inactive sites.** Default: an inactive site cannot be newly chosen (`site_inactive`); an unchanged stored one is kept.
15. **Permanent workers' actual times.** Default: both or neither (`times_need_both`).
16. **HTTP status of a locked period.** Default: 423 `period_locked`; 409 is kept for `concurrency_conflict` and `period_already_locked`.
17. **The bell.** Default: it lists the latest 10, and its badge counts the unread among them.
18. **The spec's "coding-standards §111".** Read as §7 (optimistic locking).
19. **Root `composer.json`.** `"php": "^8.3"` becomes `^8.4`, matching the stack and OpenSpout's `~8.4` floor.
20. **Demo seeder.** `AttendanceDemoSeeder` (dev only, refuses production) feeds Gates 1–4; it is not called by `DatabaseSeeder`.
