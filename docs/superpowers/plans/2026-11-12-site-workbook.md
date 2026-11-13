# Site workbook (ملف الموقع) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every task follows `ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `docs/coding-standards.md`.

**Goal:** HR downloads an empty per-site Excel file for a range of days, the site manager fills it, HR uploads it, previews, chooses on conflicts and confirms; every row goes through `AttendanceDays::writeCell` as HR.

**Architecture:** Two new Attendance application classes (`SiteWorkbookTemplate`, `SiteWorkbookImport`) over the existing write path, roster, reader and writer. The preview runs the confirm's writes in a transaction and rolls back; a token over the stored days it read guards the confirm. One frontend component behind a per-type / per-site switch.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17, OpenSpout 5.12.2, PHPUnit 12; React 19, Vitest, react-i18next.

**Spec:** `docs/superpowers/specs/2026-11-12-site-workbook-design.md` (rules W1–W17 in `docs/calculations.md` §14). The approved sample is `build.php` / `verify.py` in `~/Library/Application Support/Claude/side-session-notes/local_1f0d74f4-f6eb-4e26-9ecd-8cabfce3922b/site-workbook-sample/`; its OpenSpout calls are the reference for the writer.

Paths are under `backend/app/Modules/` unless they start with `backend/`, `frontend/` or `docs/`. One PR into `main`, three slices, each ending in an owner gate. `$WT` = `/Users/yousefhasabo/HRMS-Smart TECH/.claude/worktrees/eloquent-fermi-6b88fe`; `$NOTES` = `/Users/yousefhasabo/Library/Application Support/Claude/side-session-notes/local_5a658923-76a4-44f6-9d87-2f49326409dc`; `$SCRATCH` = the executing session's scratchpad (dumps and fill scripts live there, never in the repo).

## Global Constraints
- No migration, no change to the day rules, payroll, the October fixtures, the per-type template or import, or their tests (`AttendanceTemplateTest.php`, `AttendanceImportTest.php`, `PayrollOctoberTest.php` and `backend/tests/Fixtures/payroll/` show nothing in `git diff main --stat`).
- Every row: `AttendanceDays::writeCell($employee, $date, $input, null, 'imported')`. The three routes refuse any caller holding either attendance permission at own scope (403 `permission_denied`) before reading or writing a file.
- The file is Arabic whatever the request language; API messages follow the request language (`lang/{ar,en}`); every UI string in `ar.json` and `en.json`.
- Money is a decimal string: `advances_total` is `bcadd(…, Precision::MONEY_SCALE)` from `'0'`; no float anywhere.
- "Today" is `EffectiveDays::today()` (Cairo). The upload is read from PHP's temp path and never stored.
- Tests and gates never use the compose stack: no `docker compose`, no `hrms_default` network, no `artisan migrate`/`serve` on the worktree `.env` as it stands (it points at the real-data `hrms`). Never stage `CLAUDE_HANDOFF.md`.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus
1. A new laborer whose earliest row is after today or inside a locked day-laborer period: those rows are refused (`future_date`, `period_locked`) before the hire date is chosen; the hire date is the earliest remaining date; no remaining row, nobody is created (test in B4). See "Pinned here" below.
2. Times as Excel stores them (a fraction, a `DateTime`, a `DateInterval`), `9:05` without the leading zero, and Arabic-Indic digits in times and national IDs: all read as `HH:MM` / ASCII (tests in B1, B2, B4).
3. A range that crosses a month end (an engineer's 26→25 period): each sheet maps to its own date (test in B2).
4. Day sheets reordered by the site manager: "date order" is the sheet's date, never its position (test in B4).
5. A site with no residences, or a name list that is empty: the writer adds no dropdown for that column instead of an invalid range (test in A3).

## Decisions taken here
Where the code offered two ways, the smaller one:
- **Managers of the site:** `SiteService::show($siteId)['managers']` (L62-71) already returns them with the site's name, kind and active flag; no new `SiteService` read.
- **Writer:** extract the temp-file lifecycle of `XlsxTemplateWriter::write` (L38-58) into one private `save()` and add `writeWorkbook()`; the two layouts share nothing else, and a generic sheet description would be a format with one caller each.
- **Reader:** a new `XlsxAttendanceReader::sheets()` beside `read()`, both over one loop; `read()`'s output does not change. `lists` is read to row 3 only: the name list is rebuilt from the database (W4), so a company of more than 2000 people never hits the row cap there.
- **Status matching:** make `AttendanceImport::choices()` and `AttendanceImport::key()` public and call `choices(EmployeeType::Office)`: it is exactly the workbook's vocabulary (the present word in either language, then every leave day-type; inactive ones match so the write refuses `site_inactive`).
- **Refusal to error:** extract `AttendanceImport::reason()` from the three catches of `AttendanceImport::write` (L180-188) and call it from both imports.
- **Switch:** in `frontend/src/pages/attendance.tsx`, not `attendance-import.tsx` as the spec says: the group and period bar that per-site must hide is rendered there (L182-188).
- **No API reference to update:** the repo documents no routes outside plans (the template routes appear only in `docs/superpowers/plans/release-1c-attendance.md`'s table); the spec's «Endpoints and permissions» is the documentation.
- **Arabic file text** (headers, weekdays, title, prompt, type labels) lives in `backend/lang/ar/attendance.php` under `workbook` only: W1 makes the file Arabic always, so nothing reads it in English.

Pinned here, not in the spec (controller to confirm before B4):
- **Hire date.** The spec's "the earliest date they have in the file" would date the type change and crew row into a locked period, which the M1 rule refuses for every other dated change (`EmployeeService::assertUnlocked`, L595-609, through `PeriodGuard::lockedFrom`), or into the future. So, inside the apply transaction (`lockedFrom` takes `LOCK TABLE`, which needs one), a new laborer's row dated after today is `future_date` and a row where `PeriodGuard::lockedFrom($date, EmployeeType::DayLaborer)` is true is `period_locked`; the hire date is the earliest remaining date.
- **Values of a new laborer** (settled rule 2) are taken from their rows not refused, by sheet date then row number. On a row matched by national ID, columns H–J are ignored like an entry row's (the person exists).

## The side-effect proof (spec «Preview, confirm and the token»; done at planning on `98f6b2e`, re-checked in Task A1)
Nothing in `writeCell` or `DayCrew::propose` acts outside the database transaction, so the preview may run the confirm's writes and roll back:
- `AttendanceDays::write` (L293-398): `PeriodGuard::assertOpen` takes `pg_advisory_xact_lock_shared`, released at rollback; the row lock is `lockForUpdate`; `BusinessRules::all()` → `VersionedCache::remember` only caches committed settings (the import writes none, so `invalidate`'s `DB::afterCommit` never runs); the save goes through `Audited` → `AuditLog::record`, a `DB::table('audit_log')->insert` in the same transaction (`Audit/Infrastructure/AuditLog.php:20-44`); `HasStandardColumns` only sets `created_by`/`updated_by`.
- `DayCrew::propose` (L139-150): `EmployeeCodes::issueNext` locks `employees` and updates the `employee_code_counter` table, a table rather than a sequence so a rollback gives the number back (`2026_11_03_000003…:11`); `Employee`, `EmployeeTypeChange`, `DayCrewChange`, `EmployeeWageProposal` are all `Audited`; ids are UUIDv7 made in PHP. The notification is in `SiteDayCrew::propose` (L97-111), which the import does not call.
- The app's only listeners (`app/Providers/AppServiceProvider.php:38-50`): `MigrationsEnded`, `Employee::deleting` (no delete here) and `EmployeeOpenItems` (dispatched only by `EmployeeService`, L562). No `dispatch(`, `event(`, `Mail::`, `Notification::` on either path.
- B2 pins it: the preview test fakes `Queue`, `Mail` and `Notification` and asserts nothing was pushed or sent. (Not `Event::fake()`: it would silence the `Audited` model events.)

---

## Slice A: backend download

### Task 0: test harness for this worktree
`docker compose exec app` runs the main checkout's code and the compose `hrms_test` is shared by every worktree, so this branch uses its own PostgreSQL and one-off PHP containers.

**Files:** create `$NOTES/site-workbook-commands.md` (outside the repo). No repo change, no commit.

- [ ] **Step 1: Start the throwaway database** (same image and locale as `docker-compose.yml`; the `C.UTF-8` collation decides name order):
```sh
docker network create hrms-sw
docker run -d --name hrms-sw-pg --network hrms-sw -e POSTGRES_DB=hrms_test -e POSTGRES_USER=hrms \
  -e POSTGRES_PASSWORD=sw-throwaway -e POSTGRES_INITDB_ARGS="--encoding=UTF8 --locale=C.UTF-8" -e TZ=UTC -e PGTZ=UTC postgres:17.5-bookworm
docker exec hrms-sw-pg pg_isready -U hrms -d hrms_test   # repeat until "accepting connections"
```
- [ ] **Step 2: Define the PHP runner** (check first: `docker image inspect hrms-php` exists and runs a command passed after the image):
```sh
sw() { docker run --rm --network hrms-sw -v "$WT/backend:/var/www/html" -v "$WT/docs:/var/www/docs:ro" -w /var/www/html \
  -e DB_HOST=hrms-sw-pg -e DB_PORT=5432 -e DB_USERNAME=hrms -e DB_PASSWORD=sw-throwaway hrms-php "$@"; }
```
Safety: on `hrms-sw` the `.env` host `postgres` does not resolve, so a lost override fails to connect instead of reaching real data; `phpunit.xml` forces `hrms_test` and `TestCase::setUpTraits` refuses any other name. `phpunit.xml` sets `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, so no Redis is needed (check first: the baseline passes without one).
- [ ] **Step 3: Prove the override:** `sw php artisan tinker --execute='echo config("database.connections.pgsql.host");'` prints `hrms-sw-pg`.
- [ ] **Step 4: Baseline**, all on the unchanged branch: `sw php artisan test`; `sw php artisan test tests/Feature/AttendanceImportTest.php`; `sw vendor/bin/pint --test`; `sw vendor/bin/phpstan analyse --memory-limit=1G`; `sw vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`; `npm --prefix "$WT/frontend" run test`, `run lint`, `run build`. Expected: all green, or the pre-existing failures listed by name.
- [ ] **Step 5: Record** in `$NOTES/site-workbook-commands.md`: Steps 1–2 verbatim, the five backend commands and a single-file form (`sw php artisan test --filter=SiteWorkbookTemplateTest`), the frontend commands, the baseline result, and the teardown (`docker rm -f hrms-sw-pg hrms-sw-api; docker network rm hrms-sw`, run only after the PR's last gate). Never run two suites at once on `hrms-sw-pg` (`RefreshDatabase`).

### Task A1: re-check the side-effect proof
- [ ] **Step 1:** `git -C "$WT" diff 98f6b2e -- backend/app/Modules/Attendance/Application/AttendanceDays.php backend/app/Modules/Employees/Application/DayCrew.php backend/app/Modules/Employees/Application/EmployeeCodes.php backend/app/Modules/Audit backend/app/Providers backend/app/Support` is empty (else re-read those files against the proof above).
- [ ] **Step 2:** `grep -rnE "dispatch\(|event\(|Mail::|Notification::|afterCommit" backend/app/Modules/Attendance/Application/AttendanceDays.php backend/app/Modules/Attendance/Infrastructure backend/app/Modules/Employees/Application/DayCrew.php backend/app/Modules/Employees/Application/EmployeeCodes.php backend/app/Modules/Audit/Infrastructure` prints nothing.
- [ ] **Step 3:** Append "proof re-checked on <sha>: unchanged" to the commands file. No commit.

### Task A2: the name list
**Files:** modify `Employees/Application/EmployeeRoster.php`; create `Attendance/Application/SiteWorkbookTemplate.php`; modify `backend/lang/ar/attendance.php`; create `backend/tests/Feature/SiteWorkbookTemplateTest.php`.

**Interfaces (produces):**
- `EmployeeRoster::onSite(string $siteId, string $date, ?string $managedBy)`: `EmployeeReach::apply` only when `$managedBy` is not null (callers unchanged).
- `EmployeeRoster::siteWorkbookPeople(string $siteId, list<string> $managerIds, string $from, string $to): array{own: list<array<string, mixed>>, others: list<array<string, mixed>>}`: roster rows, each group sorted by `name_ar` with `strcmp` (code-point order, as the `C.UTF-8` database sorts), then `code`.
- `SiteWorkbookTemplate::names(string $siteId, string $from, string $to): list<array{entry: string, employee: array<string, mixed>, own: bool}>`, own first.

- [ ] **Step 1: Failing tests** in `SiteWorkbookTemplateTest` (`use RefreshDatabase, AttendanceFixtures`; setUp `cairo('2026-11-12 12:00')`, `signIn('hr')`, `leaveTypes()`, site S with `managerOf(S)`), calling `app(SiteWorkbookTemplate::class)->names(S, '2026-11-07', '2026-11-13')`:
  - `test_the_sites_own_people_come_first_each_group_by_name` (W4, spec «The name list»): own = a permanent worker and a day laborer homed at S (`homeSite`), an office employee homed at S, an engineer assigned to S's manager (as `AttendanceImportTest::assign()` L656 does); others = a laborer homed at another site, an engineer of another manager, a permanent worker with no site. Names chosen so creation order ≠ name order. Assert the exact `entry` list, e.g. `"Laborer-1 (يومية) · {code}"`, own group first.
  - `test_archived_people_and_other_office_staff_are_left_out_and_a_site_manager_is_listed`: an archived laborer (termination before today), an office employee homed at another site and one with no site are absent; an engineer whose `employees.user_id` is S's manager is present.
  - `test_the_type_label_is_the_type_on_the_first_employed_day`: a laborer hired on 2026-11-10 is `(يومية)`; labels مهندس, عامل مثبت, يومية, موظف مكتب all appear.
- [ ] **Step 2:** `sw php artisan test --filter=SiteWorkbookTemplateTest`: FAIL (class missing).
- [ ] **Step 3: Implement.** `siteWorkbookPeople`: own = `onSite($siteId, $from, null)` + the rows of `officeIdsAt($siteId, $from)` (one `rows()` query on those ids through `employees()`, so archived stay out) + `engineersOf($m, $from)` for each manager; others = `allOfType()` for day laborer, permanent worker and engineer over `[$from, $to]`, minus own; unique by id. `names()`: managers from `SiteService::show($siteId)['managers']`; label = `__('attendance.workbook.type.'.$type->value, [], 'ar')` for the first range date where `Employment::typeOn($row, $d)` is not null; entry `"{name_ar} ({label}) · {code}"`. Lang `workbook.type`: `engineer` مهندس, `permanent_worker` عامل مثبت, `day_laborer` يومية, `office` موظف مكتب.
- [ ] **Step 4:** the filter run passes; `sw php artisan test --filter='SiteDay|ManagerReach|ArchivedLists'` stays green (`onSite` callers).
- [ ] **Step 5: Commit** `Site workbook: the name list (W4)`.

### Task A3: the download
**Files:** modify `Attendance/Infrastructure/XlsxTemplateWriter.php`, `Attendance/Application/SiteWorkbookTemplate.php`, `Attendance/Presentation/AttendanceController.php`, `backend/routes/api.php` (after L116), `backend/lang/ar/attendance.php`, `backend/lang/en/attendance.php`; test `SiteWorkbookTemplateTest.php`.

**Interfaces (produces):**
- `XlsxTemplateWriter::writeWorkbook(array $book): string` (temp path), `$book` = `array{sheets: non-empty-list<array{name: string, title: string}>, columns: list<string>, rows: int, statuses: list<string>, names: list<string>, residences: list<string>, meta: list<string>, prompt: array{title: string, text: string}}`.
- `SiteWorkbookTemplate::MAX_DAYS = 31`; `file(string $siteId, string $from, string $to): string`; `static columns(): list<string>` (the ten headers); `static sheetName(string $date): string` (`<weekday> dd-mm`); `static dates(string $from, string $to): list<string>`.
- `AttendanceController::siteWorkbook(Request): BinaryFileResponse`; private `assertAllScope(Request): void`.

- [ ] **Step 1: Failing tests** (download through `GET /api/v1/attendance/site-workbook?site_id&from&to`, read back with OpenSpout `Reader` and the sheet XML; copy `download()`/`sent()`/`zipEntry()` from `AttendanceTemplateTest` L301-359 into this file, which must stay untouched there):
  - `test_one_right_to_left_sheet_per_date_then_the_hidden_lists`: 2026-11-07..13 gives السبت 07-11 … الجمعة 13-11 visible, then `lists` hidden, no `versions`; each sheet `rightToLeft="true"`, pane frozen at `B3`, `<mergeCell ref="A1:J1"/>`, A1 `موقع Site-1 — السبت 07-11-2026`, row 2 the ten headers; only sheet 1 `tabSelected`. A range 2026-11-26..2026-12-02 names its sheets `… 26-11` to `… 02-12`.
  - `test_every_day_sheet_has_the_three_dropdowns`: per sheet, A3:A{last} `lists!$B$1:$B${names}` with `showErrorMessage="0"`, `promptTitle="إضافة"` and the prompt; B3:B{last} `lists!$A$1:$A${statuses}` and I3:I{last} `lists!$C$1:$C${residences}`, both blocking (as `verify.py` asserts).
  - `test_rows_are_empty_text_cells_with_the_fills`: 30 data rows when the site has ≤ 15 own people, `2 × own` above (make 16); every cell style `@`; A3:A{last} `FFF2CC`, G–J `FFF9E5`, B–F no fill; column A width = max(8, longest entry) + 3.
  - `test_lists_holds_statuses_names_residences_and_the_site_and_range`: A1 `حاضر` then the active leave names; B = `names()` entries (an employee named `=SUM(1)` stays an inline string); C the residences; D1 site id, D2 `2026-11-07`, D3 `2026-11-13`.
  - `test_the_file_is_arabic_with_an_english_request` (`Accept-Language: en`).
  - `test_an_empty_name_or_residence_list_adds_no_dropdown_for_it` (Review Focus 5).
  - `test_the_download_refuses_a_bad_site_or_range` (data provider, 422 `validation_failed`): an inactive site, a leave day-type, a deleted site, `from` after `to`, 32 days; 31 days is 200 (W1).
  - `test_an_own_scope_caller_gets_403`: `userWith('own', 'own')` and `userWith('all', 'own')` get 403 `permission_denied` (spec «Security notes»).
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Writer.** Move L38-58 of `write()` into `private function save(Options $options, string $prefix, \Closure $fill): string`; `write()` calls it with its own fill (output byte-for-byte as before). `writeWorkbook()` follows `build.php`: per sheet `$i`, `addValidation(…, sheetIndex: $i)` for A (`ValidationDisplay(showErrorMessage: false, promptTitle:, prompt:)`), B and I, each only when its list is non-empty, and `mergeCells(0, 1, 9, 1, $i)`, all before `openToFile`; `SheetView(rightToLeft: true, tabSelected: $i === 0, freezeRow: 3, freezeColumn: 'B')`; row 1 title cells (bold, 14, `DDEBF7`, `@`), row 2 bold headers, then `$rows` rows with A an empty `StringCell('')` (OpenSpout drops an all-empty row), styles per the spec's fills; widths per the spec; then `lists` hidden with columns A–D. Every non-empty value is a `StringCell`.
- [ ] **Step 4: Template.** `file()`: `$site = SiteService::show($siteId)`; statuses = `__('attendance.template.present', [], 'ar')` then `Site::where(['kind' => 'leave', 'active' => true])->orderBy('name_ar')->pluck('name_ar')`; residences = `Residence::orderBy('name_ar')->pluck('name_ar')`; rows = `max(30, 2 × count(own))`; meta `[$siteId, $from, $to]`. Lang `workbook`: `columns` (the ten, in rule-4 order), `weekday` (0 = الأحد … 6 = السبت, keyed by `date('w')`), `title` `'موقع :site — :day'`, `prompt_title`, `prompt`. Both langs: `attendance.workbook_max_days` («المدة :max يومًا على الأكثر.» / "The range is at most :max days.").
- [ ] **Step 5: Route and controller.** `Route::get('site-workbook', 'siteWorkbook')->middleware(['permission:attendance.view', 'permission:attendance.edit'])`. `siteWorkbook()`: `assertAllScope()` first (throws `AuthorizationRefused::of('attendance.edit')` when `RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit') !== null`, as `EmployeeController::changeCode` L64-66 does); validate `site_id` (`bail, required, uuid, Rule::exists('sites', 'id')->where('kind', 'work')->where('active', true)->whereNull('deleted_at')`), `from`, `to` (`date_format:Y-m-d`, `after_or_equal:from`, a closure refusing more than `MAX_DAYS` days); `response()->download($path, "site-workbook-{$from}-{$to}.xlsx", [xlsx content type])->deleteFileAfterSend()` as `template()` L81-82.
- [ ] **Step 6:** `sw php artisan test --filter='SiteWorkbookTemplateTest|AttendanceTemplateTest'` passes; pint, phpstan, deptrac green.
- [ ] **Step 7: Commit** `Site workbook: download (W1)`.

### Gate A: a real file from a throwaway copy of the data
- [ ] **Data (check first: the controller approves this one read of `hrms`).** `docker exec hrms-sw-pg createdb -U hrms hrms_gate`. Dump the local real-data database read-only from a one-off client, never through the `hrms-*` containers: `docker run --rm --network hrms_default -e PGPASSWORD="$(grep '^DB_PASSWORD=' "$WT/backend/.env" | cut -d= -f2-)" postgres:17.5-bookworm pg_dump -h postgres -U hrms -Fc hrms > "$SCRATCH/hrms.dump"`, then `docker exec -i hrms-sw-pg pg_restore -U hrms -d hrms_gate --no-owner < "$SCRATCH/hrms.dump"`. `sw` with `-e DB_DATABASE=hrms_gate` `php artisan migrate:status` shows nothing pending. Delete the dump after the last gate.
- [ ] **Throwaway API**, reused by Gates B and C: `docker run -d --name hrms-sw-api --network hrms-sw -p 127.0.0.1:8010:8000 -v "$WT/backend:/var/www/html" -v "$WT/docs:/var/www/docs:ro" -w /var/www/html -e DB_HOST=hrms-sw-pg -e DB_DATABASE=hrms_gate -e DB_USERNAME=hrms -e DB_PASSWORD=sw-throwaway -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e MAIL_MAILER=array hrms-php php -S 0.0.0.0:8000 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. Check first: `curl -s 127.0.0.1:8010/api/v1/health` is ok and the API reads `hrms_gate` (a site count matches `psql`). If the `-e` values do not reach the app (memory: `artisan serve` re-reads `.env`), back up `backend/.env` to the scratchpad, point it at `hrms-sw-pg`/`hrms_gate` and restore it after the last gate.
- [ ] Sign in as HR through `POST /api/v1/auth/login` (password from `$WT/.claude/dev-accounts.md`, never printed), download one real work site for the current half-month, check it with a stdlib script modelled on `verify.py` (sheets, dropdowns, `lists!D1:D3`), copy it to `$NOTES/site-workbook-gate-a.xlsx`.
- [ ] Report to the owner in Arabic: the site and range, sheets, own people / name-list size, rows per sheet, the file's path; the open gender question. Stop for approval.

---

## Slice B: backend preview and confirm

### Task B1: reader and shared import helpers
**Files:** modify `Attendance/Infrastructure/XlsxAttendanceReader.php`, `Attendance/Application/AttendanceImport.php`; create `backend/tests/Unit/XlsxAttendanceReaderTest.php` (`PHPUnit\Framework\TestCase`, no database, as `tests/Unit/DayRulesTest.php`).

**Interfaces (produces):**
- `XlsxAttendanceReader::sheets(string $path, int $maxRows, list<int> $timeColumns): list<array{name: string, visible: bool, rows: array<int, list<?string>>}>`: the visible sheets (rows keyed by Excel row number, at most `$maxRows` rows below row 2, else `too_many_rows`; `$timeColumns` read in time mode from row 3) and the hidden `lists` (rows 1–3 only); other hidden sheets skipped.
- Time mode in `value()`: a `DateTimeInterface` → `H:i`; a number in [0, 1) → `sprintf('%02d:%02d')` of `round($v * 1440)` minutes; a `DateInterval` with no days → `%H:%I`; anything else as today.
- `AttendanceImport::choices(EmployeeType $g)` and `AttendanceImport::key(string $name)` public (unchanged bodies); `AttendanceImport::reason(AttendanceRefused|ConcurrencyConflict|DayRulesViolated $e): array{error: string, message: string}`.

- [ ] **Step 1: Failing tests** (write a small xlsx with OpenSpout `Writer` in the test):
  - `test_sheets_returns_the_visible_sheets_and_lists_with_their_visibility`: visible `A`, hidden `lists`, hidden `other` → `[A visible, lists hidden]`, `lists` cut after row 3.
  - `test_time_columns_read_what_excel_makes_of_a_typed_time`: column C `NumericCell(0.375)` → `09:00`, a `DateTimeCell` at 09:30 → `09:30`, a `DateIntervalCell(PT8H5M)` → `08:05`, text `٩:٠٥` → `9:05`; column E `NumericCell(500)` stays `500` (W8).
  - `test_the_row_cap_counts_rows_below_the_two_head_rows`: `$maxRows = 2`, two data rows pass, three throw `too_many_rows`; a 5-row `lists` passes.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Implement.** One private loop serves `read()` (filter `attendance`/`versions`, head rows 1, no time columns: output unchanged) and `sheets()`; `rows()` gains `int $headRows = 1, array $timeColumns = [], ?int $lastRow = null`. In `AttendanceImport::write`, the three catches return `self::error(...$at, ...self::reason($e))`.
- [ ] **Step 4:** the unit test and `sw php artisan test --filter=AttendanceImportTest` (unchanged file) pass; phpstan green.
- [ ] **Step 5: Commit** `Site workbook: reader sheets and time cells; shared import helpers (W2, W8)`.

### Task B2: preview for listed employees
**Files:** create `Attendance/Application/SiteWorkbookImport.php`; modify `AttendanceController.php`, `backend/routes/api.php`, `backend/lang/{ar,en}/attendance.php` (`import.name_required`, `status_required`, `unknown_status`, `bad_time`, `bad_amount`, `note_too_long`); create `backend/tests/Feature/SiteWorkbookImportTest.php`.

**Interfaces (produces):**
- `SiteWorkbookImport::MAX_ROWS = 2000`; constructor `(SiteWorkbookTemplate $template, SiteService $sites, AttendanceImport $import, AttendanceDays $writes, EmployeeRoster $roster, BusinessRules $rules, XlsxAttendanceReader $reader, int $maxRows = self::MAX_ROWS)`.
- `preview(string $path, bool $mayCreate): array`: the spec's «Preview» JSON exactly (`site`, `from`, `to`, `token`, `summary`, `changes`, `new_laborers`, `errors`).
- private `apply(array $plan, ?array $choices): array` (null choices = preview: every conflict replaced), shared with B3.
- `AttendanceController::siteWorkbookPreview(Request): JsonResponse`; private `const FILE` = the upload rule of `upload()` L150, used by both.

- [ ] **Step 1: Failing tests.** setUp: `cairo('2026-11-12 12:00')`, HR, `leaveTypes()`, site S (with a manager) and site T. Helpers: `download($from, $to)` via the route; `fill(string $path, array $bySheet, array $order = []): UploadedFile` rewriting every sheet as `AttendanceImportTest::rewrite()` L581-618 does (copy it, plus `file()`, `temp()` and the tearDown), rows placed from Excel row 3, `$order` reorders sheets; `entry($employeeId)` from `SiteWorkbookTemplate::names()`; `preview($file, $locale = 'ar')`. Tests (each cites its rule):
  - `test_a_picked_entry_is_previewed_at_this_site` (W6, W14): a worker homed at T and an engineer of another manager, «حاضر» 08:30–18:00 on 07-11 → `changes[].to` `{site: 'Site-S', in_time: '08:30', out_time: '18:00', late_minutes: 30, ot_minutes: 30}` for the engineer (field shift 08:00–17:00); `from` null.
  - `test_unknown_employee` (provider, W4): an entry with another code, the entry an office employee of T would have, an archived employee's entry, an entry of someone renamed after the download → `unknown_employee`.
  - `test_row_errors` (provider, W3, W6, W8, W9, W12): status, no name → `name_required`; name, no status → `status_required`; status T's name → `unknown_status`; `25:00` / `9.30` → `bad_time`; arrival only → `times_need_both`; advance `0` / `-5` / `abc` → `bad_amount`; a 501-character note → `note_too_long`; one entry twice on a sheet → second `duplicate_row`; a leave status with times → `field_not_allowed`; a row on 13-11 → `future_date`. Each error has `sheet`, `row`, `date`, `name`, and the other rows still preview.
  - `test_office_rows_take_status_and_times_only` (W6, W10): office «حاضر» → `to.site` `حاضر` (`Present` with `Accept-Language: en`); with an advance or a note → `field_not_allowed`.
  - `test_a_date_locked_for_engineers_is_open_for_day_laborers` (W12): lock the engineers' period holding 11-07 (`POST /api/v1/attendance/locks`, as `AttendanceLockTest` L43-45) → engineer `period_locked`, laborer previewed.
  - `test_times_excel_made_are_read` (W8): C `NumericCell(0.375)`, D `١٦:٠٠` → 09:00–16:00.
  - `test_a_conflict_is_shown_replaced` (W13): a laborer stored at T on 07-11 → `conflict_site` T, `from.site` T, `to.site` S, `key` `<id>/2026-11-07`, `summary.conflicts` 1.
  - `test_the_advances_total_is_the_backends` (W9): advances 500 and 200 and a refused row's 900 → `"700.000000"`.
  - `test_columns_g_to_j_on_an_entry_row_are_ignored` (W4): an entry row with a bad mobile in H previews as a change with no error.
  - `test_a_month_crossing_file_maps_each_sheet_to_its_date` (Review Focus 3): range 2026-10-26..2026-11-05, a row on the sheet ending `01-11` previews `date` `2026-11-01`.
  - `test_whole_file_refusals` (provider, W2, W16, 422 `template_mismatch`): a day sheet renamed to no date; two sheets for one date; `lists` dropped; one header changed; `lists!D1` a deleted site, a leave day-type or not a UUID; `D2` not ISO; a 32-day range. `too_many_rows` with `$this->app->when(SiteWorkbookImport::class)->needs('$maxRows')->give(2)`: three rows on one sheet refused, two rows on each of two sheets accepted.
  - `test_the_preview_saves_and_sends_nothing` (W15, the proof): `Queue::fake(); Mail::fake(); Notification::fake();`, counts of `attendance_days`, `audit_log`, `employees`, `day_crew_changes`, `employee_wage_proposals` unchanged; nothing pushed or sent.
  - `test_an_own_scope_caller_gets_403_on_the_preview`.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Read the file.** `sheets($path, $maxRows, [2, 3])`; no `lists` → `template_mismatch`. `lists` D1 must pass `Str::isUuid()` first (Postgres answers a malformed uuid with a query error, a 500), then `SiteService::show()` (catch `ModelNotFoundException`) with `kind === 'work'`; D2/D3 ISO (`DateTimeImmutable::createFromFormat('!Y-m-d')` round-trips), `from ≤ to`, ≤ 31 days. Each visible sheet: the one date of `SiteWorkbookTemplate::dates($from, $to)` whose `dd-mm` ends its name; none or a repeated date → `template_mismatch`; row 2 must equal `columns()` after `XlsxAttendanceReader::text()`. Rows ≥ 3, sorted by date then row.
- [ ] **Step 4: Plan each row** in W17 order. Name: `text()` of A looked up in the `names()` entries (keyed by `text($entry)`); no hit and `/ · \d+$/u` → `unknown_employee`; no hit otherwise → `unknown_employee` too for now (B4 replaces this branch). The same employee twice on a sheet → the later row `duplicate_row`. Status: `AttendanceImport::key($b)` in `choices(EmployeeType::Office)[0]`, else `unknown_status`; present → `site_id` = S for field types, null for office; leave → its id. Times: `/^(\d{1,2}):(\d{2})$/`, h ≤ 23, m ≤ 59, padded to `HH:MM`, else `bad_time`; one without the other → `times_need_both`. Advance: `Validator::make` with `AttendanceDays::FORMAT['advance_amount']` + `EmployeeRules::POSITIVE`, else `bad_amount`. Note: `mb_strlen > 500` → `note_too_long`.
- [ ] **Step 5: Live days and token.** One query `AttendanceDay::whereIn('employee_id', …)->whereIn('date', …)` (live rows only). `$input` = `site_id`, `version` (live or null), `in_time`/`out_time` only when both, `ot_minutes` for an engineer or day laborer present at S with both times and `out ≥ in` = `DayRules::workerMinutes($shift['start'], $shift['end'], $in, $out)['ot']` with `$shift = EffectiveDays::fieldShift($employee, $date, $this->rules->all())`, `advance_amount`/`note` only when given. A live day at a work site other than S is a conflict, key `<employee_id>/<date>`. Token = `hash('sha256', …)` over `employee_id|date|version|site_id` lines in `employee_id, date` order, then each new national ID and its resolution (B4 adds them).
- [ ] **Step 6: Apply.** `apply()`: rows in `employee_id, date` order; a conflict follows `$choices` (preview: replace); `writeCell($employee, $date, $input, null, 'imported')`; a refusal → `AttendanceImport::reason($e)` into `errors`; then read the row back: version unchanged → `unchanged`, else a `changes` entry with `from` (live) and `to` (read back), `site` named from `choices(Office)[1]` or the present word for office present, times `HH:MM`. `advances_total` = `bcadd` over the advances of rows in `changes` and `unchanged`. `preview()` wraps it: `DB::beginTransaction(); try { … } finally { DB::rollBack(); }`.
- [ ] **Step 7: Route and controller.** `Route::post('site-workbook/preview', 'siteWorkbookPreview')` with both permissions; `assertAllScope()`, validate `['file' => self::FILE]`, `$mayCreate = app(AuthorizeAction::class)->decide($request->user(), 'employee.create')->allows(Scope::All)`, `ApiEnvelope::single($request, $this->workbookImport->preview($path, $mayCreate))`. Lang messages for the six codes in both languages; error messages never hold the national ID.
- [ ] **Step 8:** `sw php artisan test --filter='SiteWorkbook|AttendanceImportTest|AttendanceTemplateTest'` passes; pint, phpstan, deptrac green.
- [ ] **Step 9: Commit** `Site workbook: preview (W2–W13, W15)`.

### Task B3: confirm
**Files:** modify `SiteWorkbookImport.php`, `AttendanceController.php`, `backend/routes/api.php`, `Attendance/Domain/AttendanceRefusal.php` (`PreviewStale = 'preview_stale'`, status 409), `backend/lang/{ar,en}/attendance.php` (`refusal.preview_stale`, `import.choice_required`); test `SiteWorkbookImportTest.php`.

**Interfaces (produces):** `SiteWorkbookImport::confirm(string $path, string $token, array<string, string> $choices, bool $mayCreate): array` (the spec's «Confirm» JSON); `AttendanceController::siteWorkbookConfirm(Request): JsonResponse`.

- [ ] **Step 1: Failing tests:**
  - `test_confirm_stores_the_day_here_and_moves_nobody` (W14): the B2 worker and engineer → `attendance_days` at S, source `imported`; their `employee_site_assignments`, `day_crew_changes` and `employee_manager_assignments` rows unchanged.
  - `test_keep_replace_and_no_choice` (W13): three conflicts; `keep` → day untouched, `kept` 1; `replace` → same row id, site S, version + 1; none → `choice_required`. A `choices` key that is no conflict of the file is ignored.
  - `test_confirm_approves_overtime_over_a_site_managers_day` (W11): the manager's day 08:00–18:00 stored with `ot_minutes` 0 (`AttendanceDays::bulk(…, $managerId, 'manager')`), the file repeats the times → 60 stored.
  - `test_the_advance_is_stored` (W9): `advance_amount` `500.000000`, `advances_total` the saved rows' sum.
  - `test_a_day_changed_after_the_preview_is_409_and_saves_nothing` (W15): preview, `putDay` one row, confirm with the token → 409 `preview_stale`, `attendance_days` unchanged.
  - `test_uploading_the_same_file_again_saves_nothing` (W15): second confirm `applied` 0, audit rows unchanged.
  - `test_a_refused_row_does_not_stop_the_others` (W12).
  - `test_confirm_validates_token_and_choices`: no `token` → 422; `choices[x] = maybe` → 422 `validation_failed`.
  - `test_an_own_scope_caller_gets_403_on_the_confirm`, nothing written.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Implement.** `confirm()`: read and plan as the preview; recompute the token and compare with `hash_equals` → `AttendanceRefused::because(AttendanceRefusal::PreviewStale)`; then `DB::transaction(fn () => $this->apply($plan, $choices))`: `keep` writes nothing and counts `kept`, `replace` writes, none → `choice_required`. Result `applied`, `unchanged`, `kept`, `advances_total` (rows applied), `new_laborers` (empty until B4), `errors`. Route `site-workbook/confirm`; validate `file`, `token` (`required, string`), `choices` (`sometimes, array`), `choices.*` (`in:keep,replace`).
- [ ] **Step 4:** the filter run, pint, phpstan, deptrac green.
- [ ] **Step 5: Commit** `Site workbook: confirm with token and choices (W13, W15)`.

### Task B4: new day laborers
**Files:** modify `Employees/Application/DayCrew.php`, `Employees/Application/EmployeeRoster.php`, `Attendance/Application/SiteDayCrew.php` (docblock only if phpstan asks), `SiteWorkbookImport.php` (inject `DayCrew`), `backend/lang/{ar,en}/attendance.php` (`import.national_id_required`, `national_id_taken`, `national_id_name_mismatch`, `new_laborer_not_allowed`, `bad_mobile`, `unknown_residence`); test `SiteWorkbookImportTest.php`.

**Interfaces (produces):**
- `DayCrew::propose(array{name_ar: string, gender: string, phone?: ?string, national_id?: ?string, residence_id?: ?string} $identity, string $siteId, string $date, ?string $wage): array{id: string, code: int, name_ar: string, proposal_id: ?string}`: no proposal when `$wage` is null; `SiteDayCrew::propose` keeps passing its wage.
- `EmployeeRoster::idWithNationalId(string $nationalId): ?string`, soft-deleted included (spec: `national_id` is unique over every row).

- [ ] **Step 1: Failing tests** (W5 unless named):
  - `test_a_new_laborer_is_one_employee_hired_on_the_earliest_date`: a typed name with one national ID on 08-11 and 07-11 → preview `new_laborers` `[{name_ar, national_id, phone, residence, proposed_wage: '350.000000', gender: 'male', first_date: '2026-11-07', days: 2}]`, `changes[].new_laborer` true with `employee_id`/`code`/`key` null; confirm → one employee, gender `male`, day laborer from 07-11, no `employee_site_assignments`, one crew row (07-11 at S), a pending proposal of 350, both days at S; `new_laborers[0].proposal` true.
  - `test_a_blank_wage_makes_no_proposal`: `proposed_wage` null; `proposal` false; no `employee_wage_proposals` row.
  - `test_the_first_non_blank_value_in_date_order_wins` (Review Focus 4): sheets reordered so 08-11 comes first; mobile `01011111111` on 08-11 and `01022222222` on 07-11 → `01022222222`; residence blank on 07-11 and R on 08-11 → R.
  - `test_national_id_rules` (provider): missing or 13 digits → `national_id_required`; a listed employee's ID → resolved to them, `matched_by_national_id` true, H–J ignored, nobody created; an archived, a soft-deleted, an office-of-T employee's ID → `national_id_taken` (the message does not contain the ID); the same new ID under another name on a later row → `national_id_name_mismatch`; twice on one sheet → `duplicate_row`; an ID typed in Arabic-Indic digits matches its ASCII form (Review Focus 2).
  - `test_new_laborer_cells`: mobile `0101234567` → `bad_mobile`; an unknown residence → `unknown_residence`; wage `0` / `x` → `bad_amount`.
  - `test_new_laborer_not_allowed_without_employee_create_at_all_scope`: a role with `attendance.view.all` + `attendance.edit.all` only → `new_laborer_not_allowed`; the file's other rows are saved.
  - `test_rows_after_today_or_in_a_locked_period_do_not_hire` (Review Focus 1): lock the day laborers' period 2026-10-16..31, range 2026-10-30..2026-11-13; rows 10-30 → `period_locked`, 11-13 → `future_date`, 11-01 → the hire date; a laborer with only refused rows is not created.
  - `test_the_preview_with_a_new_laborer_saves_nothing`: `employees`, `employee_type_changes`, `day_crew_changes`, `employee_wage_proposals`, `employee_code_counter` unchanged.
  - `test_uploading_again_after_a_confirm_creates_nobody` (W15): the ID now resolves (`matched_by_national_id`), `changes` empty.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: `DayCrew::propose`.** Add `national_id` and `residence_id` to `Employee::create`; create the proposal only when `$wage !== null`; `proposal_id` null otherwise.
- [ ] **Step 4: Resolve.** Replace B2's placeholder: national ID (`text()` of G) must be 14 digits; `idWithNationalId()` → in the name list: that employee, flagged; anyone else: `national_id_taken`; nobody: new, grouped by ID; a later row (date order) with another `text()` name → `national_id_name_mismatch`; `!$mayCreate` → `new_laborer_not_allowed`. Cells of a new laborer's row: mobile with `EmployeeRules::MOBILE`, residence by `AttendanceImport::key()` over `Residence::pluck('name_ar', 'id')` (a name two residences share is unknown), wage as the advance. Add each new ID and its resolution (`new`, the employee id, `taken`) to the token.
- [ ] **Step 5: Create in `apply()`,** before any row: per new laborer, refuse rows after today (`future_date`) and rows where `PeriodGuard::lockedFrom($date, EmployeeType::DayLaborer)` (`period_locked`); hire date = earliest remaining; values = first non-blank per field over the remaining rows; `DayCrew::propose(['name_ar', 'gender' => 'male', 'phone', 'national_id', 'residence_id'], $siteId, $hireDate, $wage)`; a `UniqueConstraintViolationException` (a racing ID) → `ConcurrencyConflict` for the whole request; the employee row for `writeCell` from `EmployeeRoster::find($id)`. Preview `new_laborers` per the spec; confirm `new_laborers` `[{employee_id, code, name_ar, proposal}]`.
- [ ] **Step 6:** `sw php artisan test --filter='SiteWorkbook|SiteDayCrew|AttendanceImportTest'` passes; pint, phpstan, deptrac green.
- [ ] **Step 7: Commit** `Site workbook: new day laborers (W5)`.

### Gate B: a full round trip on the throwaway database
- [ ] On `hrms_gate` through `hrms-sw-api`: download a real site's file; fill it with a scratchpad PHP script run by `sw` (OpenSpout, as the test's `fill()`): an engineer present with times, a worker of another site, a row conflicting with a day at another site, an office row with an advance, a leave row, and a new laborer with a national ID and a wage; `curl` the preview (save the JSON), confirm with the token and a `replace` choice (save the JSON).
- [ ] Show with `docker exec hrms-sw-pg psql -U hrms -d hrms_gate`: the `attendance_days` rows written (site, times, late, overtime, advance, source, version) and the matching `audit_log` rows (`event`, `entity_type`, old/new values), plus the new employee and proposal.
- [ ] Report to the owner in Arabic: what each row became, the errors, the conflict choice, the new laborer. Stop for approval.

---

## Slice C: frontend

### Task C1: the switch and the download form
**Files:** modify `frontend/src/pages/attendance.tsx` (import `TabsContent`, L184-189); create `frontend/src/pages/attendance-site-workbook.tsx`, `frontend/src/pages/attendance-site-workbook.test.tsx`; modify `frontend/src/locales/ar.json`, `en.json` (`attendance.import.byType`, `bySite`, `site.*`); export `STEP` and `errorKey` from `frontend/src/pages/attendance-import.tsx`.

**Interfaces (produces):** `export function SiteWorkbookTab()`; URL `?tab=import&by=site` (default: per type, today's tab unchanged).

- [ ] **Step 1: Failing tests** (`mockFetch`, `renderWithAuth`, as `attendance-import.test.tsx`):
  - `the import tab switches between per type and per site`: two options; per site hides the group select and shows the site picker; `by=site` in the URL.
  - `the download asks for the chosen site and the current half-month`: system time 2026-11-12 → from 2026-11-01, to 2026-11-15; after picking Site-1 the GET is `/api/v1/attendance/site-workbook?site_id=…&from=2026-11-01&to=2026-11-15`, saved as `site-workbook-2026-11-01-2026-11-15.xlsx`.
  - `a reversed range or one over 31 days disables the download and says why`.
- [ ] **Step 2:** `npm --prefix frontend run test -- attendance-site-workbook`: FAIL.
- [ ] **Step 3: Implement.** In `attendance.tsx` a two-option `Tabs` switch at the top of the import tab; per site renders `<SiteWorkbookTab />` without `bar`. `SiteWorkbookTab`: `FilterSelect` over `useApi(WORK_SITES)`, two `TextField type="date"`, defaults from `cairoToday()` (day ≤ 15 → 1..15, else 16..month end), download through `apiBlob` as `ImportTab.download` does.
- [ ] **Step 4:** test, lint, build green.
- [ ] **Step 5: Commit** `Site workbook: per-site switch and download`.

### Task C2: preview and confirm
**Files:** `attendance-site-workbook.tsx`, its test, `ar.json`, `en.json` (`attendance.import.site.*`, `attendance.import.error.<code>` for every new code: `name_required`, `status_required`, `unknown_status`, `bad_time`, `bad_amount`, `note_too_long`, `national_id_required`, `national_id_taken`, `national_id_name_mismatch`, `new_laborer_not_allowed`, `bad_mobile`, `unknown_residence`, `choice_required`, `preview_stale`, `permission_denied`).

- [ ] **Step 1: Failing tests:**
  - `the preview shows the file's site and range, the changes with late and overtime, the advances total, the new laborers and the errors`: the spec's preview JSON as the mock; «بدون أجر مقترح» for a null wage; the backend's `advances_total` shown as sent, never summed.
  - `confirm stays disabled until every conflict has a choice, and the set-all control sets them all`: confirm's body holds `file`, `token` and `choices[<key>]`.
  - `a preview_stale answer clears the preview and asks for a new one`.
  - `an error code follows the UI language`.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Implement** with the `ImportTab` pattern (`run`, `pick` with `MAX_BYTES`, `DataList`, `ErrorBanner`, `Alert`, `ltrDates`, `localName`): file → `POST /attendance/site-workbook/preview`; tables for changes (sheet, row, employee, from → to, late, overtime, advance, note), new laborers (gender, wage or «بدون أجر مقترح»), errors; per conflict a keep/replace `select` and one set-all control; confirm through `useSave()` with the same `File`, `token` and choices; on `preview_stale` drop the preview and show the message.
- [ ] **Step 4:** test (locale parity included), lint, build green.
- [ ] **Step 5: Commit** `Site workbook: preview and confirm screen`.

### Gate C: captures
- [ ] Vite for this worktree on 5174 against the throwaway API: `(cd "$WT/frontend" && API_PROXY_TARGET=http://127.0.0.1:8010 npm run dev -- --port 5174 --strictPort)` in the background.
- [ ] With `verify-hrms` (`/Users/yousefhasabo/HRMS-Smart TECH/.claude/skills/verify-hrms/`, read its `SKILL.md` and `features/attendance-import.md` first): `HRMS_PORT=5174 HRMS_FRONTEND="$WT/frontend" node "<skill>/scripts/capture.mjs" capture --out "$WT/.claude/gate-site-workbook" --widths 375,1024,1440 --langs ar,en …` for the empty per-site tab (`/attendance?tab=import&by=site`) and the preview of the Gate B file before its confirm (`--upload` the file, `--click` Preview, which stores nothing). Check first: Doctor's compose and port-8000 lines describe the main stack and will not match; the capture signs in through the 5174 proxy with the accounts of `$WT/.claude/dev-accounts.md`.
- [ ] Report to the owner in Arabic with the screenshots. Stop for approval.

---

## After the slices

### Task D: checks, review, PR
- [ ] `sw php artisan test` (full), `sw vendor/bin/pint --test`, `sw vendor/bin/phpstan analyse --memory-limit=1G`, `sw vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`; `npm --prefix "$WT/frontend" run test`, `lint`, `build`. All green against the Task 0 baseline.
- [ ] `git diff main --stat` lists none of `backend/tests/Feature/AttendanceTemplateTest.php`, `AttendanceImportTest.php`, `PayrollOctoberTest.php`, `backend/tests/Fixtures/payroll/`, and no migration.
- [ ] Run `code-review` (standards and this spec) and `security-review` (upload, permissions, import); fix what they find, each fix with its test.
- [ ] Update `docs/calculations.md` §14 and the spec where the build differs from them (the hire-date rule above once the controller confirms it).
- [ ] Push the branch and open the PR into `main`: what it implements (W1–W17), roles and the own-scope 403, tables written (`attendance_days`, `employees`, `employee_type_changes`, `day_crew_changes`, `employee_wage_proposals`, `audit_log`), the tests per Definition of Done §14, the three gate reports; end the body with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- [ ] After the PR is open and the controller agrees: `docker rm -f hrms-sw-api hrms-sw-pg; docker network rm hrms-sw`, delete the scratchpad dump, restore `backend/.env` if Gate A changed it.
