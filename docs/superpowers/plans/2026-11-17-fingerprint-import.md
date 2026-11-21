# Fingerprint import plan

HR uploads the fingerprint device's Excel export and gets office attendance days from it, with a preview before anything is saved.
The rule the program enforces is the contract merged as FP-0, `docs/superpowers/specs/2026-11-17-fingerprint-import-design.md`.
Every PR builds against that spec and its types and changes neither without the owner.
The PRs, in order, are FP-1 (device file reader), FP-2 (lookup by fingerprint name), FP-3 (punches to an office day), FP-4 (the import), FP-5 (the import screen) and FP-6 (connect and verify).
FP-2, FP-3 and FP-5 are Mohanad's, one GitHub issue each. FP-1, FP-4 and FP-6 are built by our subagents.

## How to read this

One box is one unit of work. Every box names the evidence that checks it. A nested box is a sub-step of the box above it. Check a box only when its evidence exists, a file, a log line, a screenshot, a test run, or a SHA. The body is a how-to. The appendices explain and record.

Our PRs run `pstack/skills/poteto-mode/playbooks/feature.md`, one fresh subagent per PR. The program as a whole follows `pstack/skills/poteto-mode/playbooks/autopilot-full.md` in shape, one owner per PR and each PR merged on its own. The owner merges every PR. Mohanad's PRs (FP-2, FP-3, FP-5) stop at merge-ready for the owner's review.

Owner-approved adaptation (2026-11-17). Verification uses this repo's gates instead of the ten-lane swarm, the perf swarm, Bugbot and cloud VMs. The gates are PHPUnit, pint, phpstan and deptrac on the backend, `npm run test`, `npm run lint` and `npm run build` on the frontend, and the `verify-hrms` skill for screens. A PR without a screen has no live lanes; its live block says what stands in for them.

Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

Each section for Mohanad (FP-2, FP-3, FP-5) is written to be copied whole into its GitHub issue. It names everything needed, so it does not depend on the rest of this file.

## Program checklist

### Arm the program

- [ ] Merge FP-0 into `main`. No feature branch starts before it. Evidence is the merge SHA.
- [ ] Open three issues for Mohanad, one per section FP-2, FP-3 and FP-5, each body copied from its section. Evidence is the three issue numbers.
- [ ] Every owner reads these from trunk before starting, and again before opening the PR.
  - [ ] `git show origin/main:docs/superpowers/specs/2026-11-17-fingerprint-import-design.md`
  - [ ] `git show origin/main:docs/coding-standards.md`
  - [ ] `git show origin/main:CLAUDE.md`

### Spawn owners

- [ ] Spawn one fresh subagent per PR of ours (FP-1, FP-4, FP-6), each with this plan's section, the spec path and the gate commands.
- [ ] Follow this dependency graph.
  - [ ] FP-1, FP-2, FP-3 and FP-5 are independent and first. All branch from `main` after FP-0.
  - [ ] FP-4 after FP-1, FP-2 and FP-3 merge.
  - [ ] FP-6 after FP-4 and FP-5 merge.
- [ ] Hold the file boundaries. Each PR touches only the files in its own Files block. Shared files (`backend/routes/api.php`, `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/locales/*`, `frontend/src/lib/api.ts`, `frontend/src/lib/query.ts`, `frontend/src/components/ui/*`) are edited only in FP-4 and FP-6.
- [ ] Hold the review gate. FP-6 changes an interaction and waits for the owner's review with screenshots and a video before merge.

### PR mechanics, for every PR

- [ ] Branch `feature/fingerprint-<topic>` from the latest `main`. One feature is one issue, one branch, one PR.
- [ ] Open the PR ready, never draft, into `main` with `gh pr create --base main`. A PR for an issue says `Closes #<issue>` in its body.
- [ ] Follow `ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `docs/coding-standards.md`.
- [ ] Run every gate in the PR's verify blocks before the push, and paste the tail of each in the PR body.
- [ ] Run `code-review` on the branch, plus `security-review` for FP-1, FP-2 and FP-4 (upload, uniqueness, import).
- [ ] Rebase onto current `main` before asking for review. Re-date an unmerged migration if `main` gained a later one.

### Verdict and merge, for every PR

- [ ] The reviewer reruns the PR's gates at the head SHA and reads the diff against the spec. Clean only when every gate passes and every spec rule the PR owns is met.
- [ ] Findings go back to the PR's owner. A new head is verified again.
- [ ] Mohanad reviews FP-1, FP-4 and FP-6; the owner reviews FP-2, FP-3 and FP-5.
- [ ] The owner's step for FP-2. Before merging FP-2, and again before deploying it, the owner runs FP-2's collision query (FP-2, Build) on the test server's database and on production. Pass when it returns no rows. When it returns rows, the owner fixes those employees' fingerprint names first.
- [ ] The owner merges. Nobody else merges into `main`.

### Boot recipe, for every live check

- [ ] `git fetch origin <head-branch> && git checkout <head SHA>` in a worktree of its own.
- [ ] `docker compose up -d`, `docker compose exec app php artisan migrate --seed`, then `npm run dev` in `frontend/`.
- [ ] Drive the app through the `verify-hrms` skill as HR, in Arabic and English, at the widths it names.
- [ ] Save screenshots under `/tmp/verify-fp-<n>/` and list their paths in the PR body.

## Read the device file into punches (FP-1)

**Depends on.** FP-0.

**Files.**

- [ ] Create `backend/app/Modules/Attendance/Infrastructure/XlsxPunchFileReader.php` implementing `Domain/Contracts/PunchFileReaderInterface.php`.
- [ ] Create `backend/tests/Unit/XlsxPunchFileReaderTest.php`.
- [ ] Edit `backend/app/Modules/Attendance/Domain/AttendanceRefusal.php` to add `NotFingerprintFile = 'not_fingerprint_file'` (status 422).
- [ ] Edit `backend/lang/ar/attendance.php` and `backend/lang/en/attendance.php` for that refusal's message.
- [ ] Edit `backend/app/Modules/Attendance/Infrastructure/XlsxAttendanceReader.php` only to reuse its file opening and error handling (`each()`) and its `EXCEL_ROWS` and `SERIALS` constants, with no change to what it reads.
- [ ] Edit `backend/tests/XlsxFiles.php` so `fingerprintFile()` can also write a sheet with another header row and a hidden sheet, for the tests below. The default workbook (`FINGERPRINT_SHEETS`) and `FingerprintFileTest` stay as they are. No other PR's Files block lists this file.

**Build.**

- [ ] Read every sheet whose row 1 starts with `Date, ID Number, Name, IN, OUT, IN, OUT, IN, OUT`, compared after trimming and ignoring case. Ignore column J and its hyperlink. Ignore other sheets. No matching sheet throws `not_fingerprint_file`.
- [ ] Per data row, A is a date cell or a date serial read as `Y-m-d`, B a positive whole number, C the name as the file spells it, and each filled cell of D to I a time read as `H:i:s` (a time cell, or a fraction of a day under 1 rounded to the second). For C, a control character reads as a space (PostgreSQL refuses a NUL), `XlsxAttendanceReader::text()` only decides whether it is blank (`missing_name`), and the name handed on has only leading and trailing ASCII whitespace trimmed (`trim($name, " \t\n\r\f\x0B")`). Inner spaces, a no-break space and Arabic digits are kept; ignoring ASCII whitespace and case is `EmployeeRules::fingerprintKey()`'s job in FP-2 (spec decision 1). A blank row is skipped. A row with no time gives no punch and no error, except a row dated after `$today`, which is `future_date`. The spec's "The FP-1 reader" lists every cell rule.
- [ ] A row with a bad cell gives no punches and one error with its sheet name, Excel row number and the first code, checked in the order `bad_date`, `bad_device_user_id`, `missing_name`, `bad_time`, `future_date`. `future_date` is a valid date after the `$today` (`Y-m-d`) that `read()` is given. The reader raises it, not the import, because a `Punch` has no sheet or row.
- [ ] De-duplicate punches on (device user ID, date, time) across sheets, keeping the first. A `Punch` has no sheet or row; only `errors` carries a file position. Count data rows across sheets against `$maxRows` and throw `too_many_rows` past it. A file OpenSpout cannot open is `unreadable_file`.

**You see.**

- [ ] `XlsxPunchFileReaderTest` reads the builder's workbook (`Tests\XlsxFiles::fingerprintFile()`) into the 13 punches the spec's "Test workbook" table lists.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `XlsxPunchFileReaderTest` gains the 13 punches of the default workbook, a hidden sheet read like a visible one, a sheet with another header ignored, a file with none refused with `not_fingerprint_file`, each row error code once, `too_many_rows`, and a text time `8:05` read as `08:05:00`. Run `docker compose exec app php artisan test --filter=XlsxPunchFileReaderTest`.
- [ ] `XlsxPunchFileReaderTest` proves `future_date`. With `$today` `2026-09-28`, the default workbook's rows dated 2026-09-29 and 2026-09-30 each give one `future_date` error with their sheet and row and no punches, and the rows dated 2026-09-27 and 2026-09-28 read as in the spec's table.
- [ ] Run `docker compose exec app vendor/bin/pint --test`, `docker compose exec app vendor/bin/phpstan analyse` and `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. No screen, so no lanes (owner-approved adaptation).

- [ ] Read the client's real file once in a scratch test outside git and record the counts in the PR body. Pass when it gives 857 punches from 548 rows across 6 sheets and no error.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Wall time of `read()` on the client's real file.
- [ ] Probe. Time three runs in the scratch test of the live box.
- [ ] Baseline. None on `main`, which has no reader. Record the three times.
- [ ] Rule. Fails above 2 seconds for the real file.

**Review gate.** None. FP-1 is not review-gated.

**Merge.**

- [ ] Gates green at the head SHA, pasted in the PR body.
- [ ] `code-review` and `security-review` findings resolved.
- [ ] The owner merges.

## Look employees up by fingerprint name (FP-2)

Issue for Mohanad. Goal. HR's fingerprint import matches each name in the device's file to an employee's fingerprint name, ignoring letter case and ASCII whitespace. Build that lookup, and make the fingerprint name unique in the same normalised form, ignoring deleted employees. This closes review items 11-F6 and the fingerprint half of 13-E7 in `docs/reviews/2026-11-08-release-1-review.md`. Read `docs/superpowers/specs/2026-11-17-fingerprint-import-design.md` (section "The FP-2 seam") and `docs/coding-standards.md` first.

**Depends on.** FP-0, merged in `main`. Nothing else.

**Files.**

- [ ] Edit `backend/app/Modules/Employees/Application/EmployeeRoster.php` to add `byFingerprintNames()`.
- [ ] Edit `backend/app/Modules/Employees/Application/EmployeeRules.php` (the `fingerprint_name` rule in `identity()`, and one public static `fingerprintKey()`).
- [ ] Edit `backend/app/Modules/Employees/Application/MasterDataImport.php` (its in-file duplicate check for `fingerprint_name`).
- [ ] Create one migration `backend/database/migrations/<Y_m_d_His>_normalise_fingerprint_name_unique.php`, named with the full time you create it.
- [ ] Create `backend/tests/Feature/FingerprintNameTest.php`.
- [ ] Edit `backend/tests/Feature/ImportMasterDataTest.php` to add the in-file duplicate case; it holds the workbook helpers (the private `workbook()` and `employee()`).
- [ ] Touch nothing else. In particular not `backend/app/Modules/Attendance/*`, `backend/app/Modules/Leave/*`, `backend/routes/api.php`, `backend/lang/*`, `frontend/*`, other migrations, or `national_id`'s uniqueness.

**Build.**

- [ ] `EmployeeRules::fingerprintKey(string $name): string` returns `mb_strtolower((string) preg_replace('/[ \t\n\r\f\x0B]+/', '', $name))`. That removes the six ASCII whitespace characters (space, tab, line feed, carriage return, form feed, vertical tab), inner ones included, then lower-cases. It is the one normalisation the PHP code below uses. The database's form is `lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g'))`, and that SQL expression is the source of truth for uniqueness. Every other character is part of the name, a no-break space (U+00A0) included.
  - [ ] Copy both strings exactly. The PHP pattern sits in single quotes. Write the SQL in a single-quoted PHP string (each `'` escaped as `\'`) or a nowdoc, never a double-quoted one, so the index, the lookup and the rule hold the same text and each backslash reaches PostgreSQL. PHP writes the vertical tab as `\x0B`, not `\v`, because PCRE's `\v` also matches the byte 0x85 inside UTF-8 letters such as Arabic `م`; PostgreSQL's `\v` is the vertical tab alone. The two classes are the same six bytes.
- [ ] `EmployeeRoster::byFingerprintNames(array $names): array` takes `list<string>` and returns `array<string, array<string, mixed>>` keyed by each name exactly as given. Each value is the row `EmployeeRoster::row()` builds, the same shape `find()` returns. A name with no employee is left out. Archived employees match. Soft-deleted employees never match. The lookup takes one query for the employees plus one per eager-loaded relation (`EmployeeRoster::RELATIONS`, four today), whatever the number of names, with the same eager-loaded relations as `find()`.
  - [ ] Query `Employee::with(self::RELATIONS)` directly, as `find()` (`EmployeeRoster.php:176-181`) and `findMany()` do. Do not start from the private `employees()` builder (line 252), which leaves archived employees out unless `withArchived()` was called. Eloquent's soft-delete scope already leaves soft-deleted employees out; do not add `withTrashed()`. Match in SQL with `whereIn(DB::raw(<the SQL expression above>), <the fingerprintKey() of each name>)`, then map each given name to its row by `fingerprintKey()`.
  - [ ] Add `byFingerprintNames` to the `EmployeeRoster` class docblock's list of reads that include archived employees (`EmployeeRoster.php:24-25`, "the by-id reads (find, findMany, names) and hiredBetween are unchanged").
  - [ ] Example. An employee holds `NadimFares`. `byFingerprintNames(['nadimfares', ' NADIMFARES ', 'Nadim Fares', 'Nobody'])` returns three keys, `nadimfares`, ` NADIMFARES ` and `Nadim Fares`, each that employee's row. `Nobody` is absent.
  - [ ] Example. An employee holds `Sara  Nabil` (two spaces). `saranabil` and `sara nabil` both match.
  - [ ] Example. An employee holds `Sara Nabil` (an ordinary space). `Sara` U+00A0 `Nabil` (a no-break space, `"Sara\u{00A0}Nabil"` in PHP) does not match it.
- [ ] The migration replaces the unique constraint `employees_fingerprint_name_unique` with a unique expression index on `lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g'))` for rows where `deleted_at is null and fingerprint_name is not null`. The old one comes from `->unique()` in `2026_10_16_000001_create_employees_tables.php` (line 16), so PostgreSQL holds it as a constraint, not an index. Drop it with `$table->dropUnique('employees_fingerprint_name_unique')` or `alter table employees drop constraint employees_fingerprint_name_unique`, never `drop index`, as `2026_11_03_000002_make_employee_code_unique_deferrable.php` drops `employees_code_unique`. Before it drops the old constraint, it runs the collision query below and, when any row comes back, throws a `RuntimeException` whose message names each colliding normalised name and its count and says to fix those employees first. It never changes, merges or drops an employee row. Its `down()` drops the new index and restores `unique(fingerprint_name)`.
  - [ ] Before restoring `unique(fingerprint_name)`, `down()` looks for a fingerprint name (exact text) held by more than one row, soft-deleted rows included, because the new rule lets a new employee take a soft-deleted employee's name and the old constraint refuses that. When any exist, it throws a `RuntimeException` naming each and its count, and changes no row, as `up()` does.
- [ ] `EmployeeRules::identity()` refuses a `fingerprint_name` whose `fingerprintKey()` equals another employee's, among employees not soft-deleted (archived ones included), ignoring the employee being edited. Check it in SQL with the same `lower(regexp_replace(...))` expression the index uses. The error is the existing `validation.unique` message on `fingerprint_name`.
  - [ ] Example. `NadimFares` exists. Creating another employee with `nadimfares` or `Nadim Fares` is a 422 on `fingerprint_name`. Editing the first employee to `NADIMFARES` is accepted. After the first employee is soft-deleted, a new employee may take `NadimFares`. An archived (terminated) employee still blocks it.
  - [ ] Example. `Sara Nabil` (an ordinary space) exists. Creating another employee with `Sara` U+00A0 `Nabil` is accepted, because the no-break space is part of the name.
- [ ] `MasterDataImport` compares `fingerprint_name` within one file by `fingerprintKey()`, so rows `NadimFares` and `nadim fares` in the same workbook give the existing `validation.distinct` error on the second.
- [ ] The collision query, used by the migration (the owner also runs it on the servers before merge and deploy), is `select lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g')) k, count(*) from employees where deleted_at is null and fingerprint_name is not null group by 1 having count(*) > 1`.

**You see.**

- [ ] `FingerprintNameTest` and `ImportMasterDataTest` pass, with each case of every example above as its own test (the rule's first example holds four cases).

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `FingerprintNameTest` covers the case-and-space match, the inner space that matches, an unknown name absent, keys exactly as given, an archived match, a soft-deleted non-match, the row having `id`, `code`, `types` and `shifts`, a no-break space (U+00A0) that does not match an ordinary space, the same number of queries for 1 name and for 5 names, every name matching an employee (`DB::enableQueryLog()`), the create, edit, soft-delete and archived cases of the rule, the no-break-space name the rule accepts beside the ordinary-space one, and a raw insert of a duplicate normalised name throwing `UniqueConstraintViolationException`. Run `docker compose exec app php artisan test --filter=FingerprintNameTest`.
- [ ] `ImportMasterDataTest` gains the in-file duplicate case. Run `docker compose exec app php artisan test --filter=ImportMasterDataTest`.
- [ ] Run the whole suite and the static gates. `docker compose exec app php artisan test`, `docker compose exec app vendor/bin/pint --test`, `docker compose exec app vendor/bin/phpstan analyse`, `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`.
- [ ] Run `docker compose exec app php artisan migrate:rollback --step=1` then `docker compose exec app php artisan migrate`, and paste both outputs.
- [ ] Prove the migration refuses collisions. A fresh dev database has no employees (`DatabaseSeeder` calls only `SitesSeeder`), so if there are none, create two first, in the employee form or through the API. After `migrate:rollback --step=1`, give two existing employees the fingerprint names `Nadim Fares` and `nadimfares` directly, in `docker compose exec app php artisan tinker` or in SQL (`update employees set fingerprint_name = 'Nadim Fares' where id = '<id>'`), because your rule in `EmployeeRules::identity()` refuses the second through the API; the old exact-text index allows both. Run `docker compose exec app php artisan migrate`, and paste its failure naming `nadimfares`. Clear one of the two names the same way, migrate again, and paste the success. Both employees still exist afterwards.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. No new screen, so no lanes (owner-approved adaptation).

- [ ] On the dev stack, in the employee form, save a fingerprint name, then try the same name in another case on a second employee. Pass when the second save shows the uniqueness error under the field. Paste a screenshot in the PR.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Queries per `byFingerprintNames()` call.
- [ ] Probe. The query-count test above.
- [ ] Baseline. None on `main`, which has no lookup.
- [ ] Rule. Fails when 5 names take more queries than 1 name.

**Review gate.** None. FP-2 is not review-gated.

**Merge.**

- [ ] Work on branch `feature/fingerprint-name-lookup` in your fork, from the latest `main`.
- [ ] Run the `code-review` and `security-review` skills on the branch if your Claude setup has them, and resolve their findings. They are Claude Code skills, not in the repo; if your setup lacks them, say so in the PR body and the owner runs them. Rebase onto current `main` before asking for review. Re-date your migration if `main` gained a later one.
- [ ] Open a PR into `main` from your fork. Its body says `Closes #<issue>` and pastes the tail of every gate above.
- [ ] Done when every box above is checked, the tail of every gate is pasted in the PR body and passes, and the owner has reviewed it. The owner merges.

## Reduce one day's punches to an office day (FP-3)

Issue for Mohanad. Goal. Turn one office employee's punches on one date into that day's in time, out time and flags, as pure PHP with no database and no Laravel. The import (built separately) calls it for every person-day of the device's file. Read `docs/superpowers/specs/2026-11-17-fingerprint-import-design.md` (section "The FP-3 seam") and `docs/coding-standards.md` first.

**Depends on.** FP-0, merged in `main`. Nothing else.

**Files.**

- [ ] Create `backend/app/Modules/Attendance/Domain/OfficePunches.php`.
- [ ] Create `backend/tests/Unit/OfficePunchesTest.php`.
- [ ] Touch nothing else. Build against `backend/app/Modules/Attendance/Domain/PunchedDay.php` and `PunchFlag.php` and do not edit them. `DayRules.php` needs no change, because it already accepts an office day with only one of the two times.

**Build.**

- [ ] `final class OfficePunches` with `public const DOUBLE_TAP_SECONDS = 120;` and `public static function reduce(array $times, string $shiftStart, string $shiftEnd): PunchedDay`, whose docblock is `/** @param non-empty-list<string> $times */` (phpstan runs at level 8 and needs the array's value type). `$times` is a non-empty `list<string>` of distinct `H:i:s` in any order. The shifts are `H:i`. The file depends on nothing outside plain PHP and `Attendance/Domain` (`backend/deptrac.layers.yaml` gives the Domain layer no allowed dependency, so deptrac fails on `Illuminate`, `App\Support` or another layer).
- [ ] `DOUBLE_TAP_SECONDS` is a technical constant, not a setting (spec, "FP-0's calls", Double tap). The rule in `docs/coding-standards.md` §5 that business numbers are settings does not apply; keep it as the class constant.
- [ ] `punchCount` is the number of times given. Sort the times. The result's times are `H:i`, the seconds cut off, never rounded. Every comparison below (distance to the shift, earlier than the shift end) uses that truncated `H:i`, never the time with seconds. Only the double-tap test uses seconds.
- [ ] One time, or the latest less than `DOUBLE_TAP_SECONDS` after the earliest (in seconds), is a one-punch day. Classify it from the earliest time's `H:i`. It is a check-in when its absolute distance in whole minutes to `$shiftStart` is smaller than to `$shiftEnd`, otherwise check-out. A tie is check-out. A check-in gives `inTime` = the earliest time; a check-out gives `outTime` = the latest time. The flag `missing_punch` is set.
  - [ ] Why the latest for a check-out. A departure double tap at `16:59:58, 17:00:01` with shift end `17:00` must give out `17:00` and no `early_leave`; the earliest would give `16:59` and a false `early_leave`.
  - [ ] Why the truncated minute for the distance. With shift `09:00` to `17:01`, `13:00:45` is 240.75 minutes from the start and 240.25 from the end with seconds, which would make it a check-out. Its `13:00` is 240 and 241 minutes away, so it is a check-in, `inTime` `13:00`.
- [ ] Otherwise `inTime` is the earliest and `outTime` the latest. Times in between are ignored.
- [ ] `early_leave` is set when `outTime` is set and earlier than `$shiftEnd`. Flags are listed in enum order, `missing_punch` first.
- [ ] These cases, with shift `09:00` to `17:00` unless a case names another, are the specification.
  - [ ] `['08:53:25']` gives in `08:53`, out null, `[missing_punch]`, count 1.
  - [ ] `['17:05:00']` gives in null, out `17:05`, `[missing_punch]`, count 1.
  - [ ] `['16:30:10']` gives in null, out `16:30`, `[missing_punch, early_leave]`, count 1.
  - [ ] `['13:00:00']` is a tie and gives in null, out `13:00`, `[missing_punch, early_leave]`, count 1.
  - [ ] `['08:53:25', '08:53:26']` is a double tap classified as check-in and gives in `08:53`, out null, `[missing_punch]`, count 2.
  - [ ] `['16:59:58', '17:00:01']` is a double tap classified as check-out from `16:59` and gives in null, out `17:00`, `[missing_punch]`, count 2.
  - [ ] `['08:59:59', '09:00:01']` is a double tap classified as check-in from `08:59` and gives in `08:59`, out null, `[missing_punch]`, count 2.
  - [ ] `['08:50:00', '08:51:59']` is 119 seconds apart and gives in `08:50`, out null, `[missing_punch]`, count 2.
  - [ ] `['08:50:00', '08:52:00']` is 120 seconds apart and gives in `08:50`, out `08:52`, `[early_leave]`, count 2.
  - [ ] `['08:50:59', '08:52:00']` is 61 seconds apart and gives in `08:50`, out null, `[missing_punch]`, count 2. Its truncated minutes are two apart, so it shows the double-tap window is measured in seconds, not in truncated minutes.
  - [ ] `['08:51:58', '08:51:59', '17:27:01', '17:27:02']` gives in `08:51`, out `17:27`, `[]`, count 4.
  - [ ] `['08:52:04', '15:59:04', '16:00:08']` gives in `08:52`, out `16:00`, `[early_leave]`, count 3.
  - [ ] `['17:30:00', '08:59:59']` (unsorted) gives in `08:59`, out `17:30`, `[]`, count 2.
  - [ ] `['17:00:00', '09:00:00']` gives in `09:00`, out `17:00`, `[]`, count 2.
  - [ ] Shift `10:00` to `18:00`, `['09:00:00']` gives in `09:00`, out null, `[missing_punch]`, count 1.
  - [ ] Shift `09:00` to `17:01`, `['13:00:45']` gives in `13:00`, out null, `[missing_punch]`, count 1.

**You see.**

- [ ] `OfficePunchesTest` passes, one test method per case above.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `OfficePunchesTest` extends `PHPUnit\Framework\TestCase` (no database) and asserts the whole `PunchedDay` of each case. Run `docker compose exec app php artisan test --filter=OfficePunchesTest`.
- [ ] Run `docker compose exec app php artisan test`, `docker compose exec app vendor/bin/pint --test`, `docker compose exec app vendor/bin/phpstan analyse`, `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Pure logic with no screen, so no lanes (owner-approved adaptation).

- [ ] Paste the PHPUnit output listing every case name in the PR body. Pass when every case passes.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. None. One call sorts at most a few times; nothing to measure.
- [ ] Probe. None.
- [ ] Baseline. None.
- [ ] Rule. None.

**Review gate.** None. FP-3 is not review-gated.

**Merge.**

- [ ] Work on branch `feature/fingerprint-office-punches` in your fork, from the latest `main`.
- [ ] Run the `code-review` skill on the branch if your Claude setup has it, and resolve its findings. It is a Claude Code skill, not in the repo; if your setup lacks it, say so in the PR body and the owner runs it. Rebase onto current `main` before asking for review.
- [ ] Open a PR into `main` from your fork. Its body says `Closes #<issue>` and pastes the tail of every gate above.
- [ ] Done when every box above is checked, the tail of every gate is pasted in the PR body and passes, and the owner has reviewed it. The owner merges.

## Import the file with preview and confirm (FP-4)

**Depends on.** FP-1, FP-2 and FP-3, all merged.

**Files.**

- [ ] Create `backend/app/Modules/Attendance/Application/FingerprintImport.php`.
- [ ] Edit `backend/app/Modules/Attendance/Presentation/AttendanceController.php` (two actions, `fingerprintPreview` and `fingerprintConfirm`, and `FingerprintImport` in the constructor). No new controller.
- [ ] Edit `backend/app/Modules/Attendance/Application/AttendanceDays.php` only to widen `writeCell()`'s `$input` phpdoc to `in_time?: ?string, out_time?: ?string`. The import sends null to clear a time, and an absent key would keep the stored one (the bulk semantics, `$keepUnsent`).
- [ ] Create `backend/database/migrations/<Y_m_d_His>_add_fingerprint_attendance_source.php`.
- [ ] Create `backend/tests/Feature/FingerprintImportTest.php`.
- [ ] Edit `backend/routes/api.php` (two routes in the `attendance` block).
- [ ] Edit `backend/app/Providers/AppServiceProvider.php` (bind `PunchFileReaderInterface` to `XlsxPunchFileReader`).
- [ ] Edit `backend/lang/ar/attendance.php` and `backend/lang/en/attendance.php` (row-error messages).
- [ ] No file in `backend/app/Modules/Leave/` changes.

**Build.**

- [ ] The migration adds `fingerprint` to `attendance_days_source_check`, following `2026_11_04_200000_add_manager_attendance_rules.php`. Its `down()` turns `fingerprint` rows into `entered` first.
- [ ] `FingerprintImport::preview(string $path)` and `confirm(string $path, list<array{employee_id, date, version}> $replace)` return the spec's shapes. `MAX_ROWS = 5000`. It passes `EffectiveDays::today()` to the reader as `$today` and returns the reader's errors, `future_date` included; it raises no row error itself.
- [ ] Match device names through `EmployeeRoster::byFingerprintNames()`, then group punches by employee and date and pass `array_values(array_unique($times))` to `OfficePunches::reduce()`, because two device ids can resolve to one employee and both may hold the same time. Unmatched names go to `unmatched`, once per normalised name. A device name made only of digits comes back as an int key (PHP array keys), so look each name up with `$rows[$name]` and never pass an iterated key to a `string` parameter.
- [ ] Per person-day, take the status from the spec's table in its order. Read the stored day's site with `Site::withTrashed()`, as `AttendanceDays::write()` reads its kind (`AttendanceDays.php:328`), so a leave type whose site was deleted still gives `leave`. Office checks use `Employment::typeOn()`, the shift `Employment::shiftOn()` or `office_shift_start` and `office_shift_end`, `OfficePunches::reduce()` and `DayRules::officeLateMinutes()` with `office_grace_minutes`.
- [ ] Write every day through `AttendanceDays::writeCell()` with source `fingerprint`, `$ownerId` null, `site_id` null and both `in_time` and `out_time` keys always present. Preview is the same call with `$dryRun` true. Confirm writes in one transaction in (employee id, date) order and reports refused writes in `errors`.
- [ ] On a `refresh` day send the file's `in_time` when it has one, else the stored `in_time`; `out_time` likewise (spec, planner's call "A refresh never clears a stored time"). The preview row shows that merged day, with flags and late minutes computed from the merged times.
- [ ] Every write sends `version` = the stored day's version, or null when there is no row or it is soft-deleted (`assertVersion()`, `AttendanceDays.php:497-503`). A `derived` day that becomes `new` sends its row's version.
- [ ] Replace a ticked `conflict` or `leave` day only when the tick's version equals the stored one, else report `concurrency_conflict` and keep the day.
- [ ] Each controller action first calls the private `assertAllScope()` (lines 208-213) as `siteWorkbookPreview` does, then validates `file` with the private `FILE` rule (line 32) and `replace.*` (uuid, `Y-m-d`, integer of at least 1), and answers through `ApiEnvelope::single()`. Routes are `POST attendance/fingerprint-import/preview` and `confirm` with `['permission:attendance.view', 'permission:attendance.edit']`.

**You see.**

- [ ] A preview of the builder's workbook with SaraNabil and HadiSalem as office employees lists MonaAdel unmatched, SaraNabil's 2026-09-28 as `out_time` 16:45 with `missing_punch` and `early_leave`, and HadiSalem's 2026-09-29 merged from both sheets as 08:50 to 17:05.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `FingerprintImportTest` has one test per status, each set up through the API or the services, never by raw table writes.
- [ ] `FingerprintImportTest` proves a ticked leave day. Approve a 3-day annual leave request, confirm with one of its dates ticked, then assert the annual used count from `LeaveBalances::forEmployee()` dropped by 1, an `attendance_days` audit row records the change from the leave type to office present with source `fingerprint`, and cancelling the request afterwards succeeds and clears only its other two days.
- [ ] `FingerprintImportTest` proves an unticked leave day and an unticked conflict are kept, a stale tick gives `concurrency_conflict`, a repeated confirm is all `unchanged`, a refresh from a later file that holds only a day's `17:05:00` keeps the stored `in_time` (stored 08:55 to 17:02 becomes 08:55 to 17:05), a site manager gets 403, a `.xls` upload gets 422, and nothing is written by preview.
- [ ] Run `docker compose exec app php artisan test`, `docker compose exec app vendor/bin/pint --test`, `docker compose exec app vendor/bin/phpstan analyse`, `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`, and the migration rollback and re-run.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. No screen yet, so no lanes (owner-approved adaptation).

- [ ] On the dev stack, give three seed office employees the fingerprint names of the builder's workbook, `curl` the preview and the confirm with an HR token, and paste both responses in the PR body. Pass when they match the spec's shapes and the period grid then shows those days.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Server time of preview and of confirm for the client's real file with its 28 names given to dev employees, run outside git.
- [ ] Probe. `curl -w '%{time_total}'` three times each.
- [ ] Baseline. None on `main`. Record the grid import's preview time for a full office month as the comparison.
- [ ] Rule. Fails above 10 seconds for either call.

**Review gate.** None. FP-4 is not review-gated.

**Merge.**

- [ ] Gates green at the head SHA, pasted in the PR body.
- [ ] `code-review` and `security-review` findings resolved.
- [ ] The owner merges.

## Build the import screen against the contract (FP-5)

Issue for Mohanad. Goal. HR uploads the device's Excel file, sees a preview of every office day it would write, ticks the days that should replace what is stored, and confirms. Build that screen as a component with its test, against the API types and a fixture already in `main`, with the API mocked. Another PR mounts it in the app and connects it to the real API. Read `docs/superpowers/specs/2026-11-17-fingerprint-import-design.md` (sections "API", "Frontend contract") and `docs/coding-standards.md` §11 first.

**Depends on.** FP-0, merged in `main`. Nothing else.

**Files.**

- [ ] Create `frontend/src/pages/fingerprint-import.tsx` exporting `FingerprintImportTab` (no props).
- [ ] Create `frontend/src/pages/fingerprint-import.test.tsx`.
- [ ] Create `frontend/src/pages/fingerprint-import.locales.json` holding the page's strings as `{"ar": {"attendance": {"fingerprint": {...}, "source": {"fingerprint": "..."}}}, "en": {...}}`.
- [ ] Touch nothing else. Do not edit `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/pages/attendance.tsx`, `frontend/src/locales/*`, `frontend/src/lib/api.ts`, `frontend/src/lib/query.ts`, `frontend/src/components/ui/*`, `frontend/src/lib/fingerprint-import.ts` or `frontend/src/lib/fingerprint-import.fixture.ts`. If a type looks wrong, say so in the issue instead of changing it.

**Build.**

- [ ] Use the types in `frontend/src/lib/fingerprint-import.ts` and the fixtures `fingerprintPreview`, `fingerprintEmptyPreview` and `fingerprintConfirmResult` in `frontend/src/lib/fingerprint-import.fixture.ts`. Follow `frontend/src/pages/attendance-import.tsx` for the upload, the busy state, the error banner and `api` calls through `@/lib/api`.
- [ ] Upload accepts one `.xlsx` up to 5 MB and refuses others on the client with a message, as `attendance-import.tsx` does.
- [ ] Preview posts `file` to `/attendance/fingerprint-import/preview` and shows the date range, the summary counts, the unmatched names (name, device user IDs, day count), the file errors (sheet, row, the code's string per the error box below), and the days table.
- [ ] Empty preview (`fingerprintEmptyPreview`, with `days` empty, `from` and `to` null, every count 0). When `days` is empty, show the summary counts, hide the days table and the confirm button, and show one line saying the file holds no office day to import. Hide the date range when `from` is null. Unmatched names and file errors still show when their lists are not empty (a file whose every name is unmatched has `days` empty but `from` set). The confirm button shows whenever `days` is not empty.
- [ ] The days table shows, per row, the employee (code and name in the UI language), the date, in and out (a dash for null), punch count, late minutes (a dash for null, which `not_office` rows and rows without an in time have), a badge per flag, the status, and the stored day when `existing` is not null (times, source label, leave type name when `leave_code` is set). `existing` is set for `conflict`, `leave`, `refresh` and `unchanged`, and null for `new`, `locked` and `not_office`; for a null `existing` show a dash.
- [ ] The name is `localName(row.employee, i18n.language)` from `@/components/form` (`frontend/src/components/form.tsx:71`), as `attendance-import.tsx` does (line 161). In English it shows `name_en`, and `name_ar` when `name_en` is null (the fixture's code 103 has none).
- [ ] A row with status `conflict` or `leave` has a tick box, unticked by default. No other row has one.
- [ ] Confirm sends the same `File` again to `/attendance/fingerprint-import/confirm` with each ticked row as `replace[i][employee_id]`, `replace[i][date]` and `replace[i][version]` (the row's `existing.version`). The result shows written, refreshed, replaced, unchanged and skipped, and lists its `errors`. The result replaces the preview on screen, as in `attendance-import.tsx`; each error row shows the employee's code and name taken from the previewed day with the same `employee_id` and date, the date, and the code's string per the error box below.
- [ ] Error strings. Show every error code in the UI language, never the server's text when a key exists, as `attendance-import.tsx` does (`errorKey`, line 28, and `fileErr`, line 79). For a code, use the first key that exists, in this order. First `attendance.fingerprint.error.<code>` (in the snippet), then `attendance.import.error.<code>` (already in the locale files; import `errorKey` from `@/pages/attendance-import`), else the server's `message` as is. The snippet holds `attendance.fingerprint.error.<code>` for `not_fingerprint_file` and the five file-row codes `bad_date`, `bad_device_user_id`, `missing_name`, `bad_time` and `future_date` (the existing `bad_time` and `future_date` strings speak of a typed cell, not the device). The existing keys already cover the other codes, `unreadable_file`, `too_many_rows` (pass `{ max: 5000 }`, `FingerprintImport::MAX_ROWS`), `permission_denied`, and confirm's `concurrency_conflict`, `period_locked`, `employee_not_active` and the `DayRules` codes. A whole-request refusal (`not_fingerprint_file`, `unreadable_file`, `too_many_rows`, `permission_denied`) shows in the page's `ErrorBanner`. A refused preview leaves no preview on screen; a refused confirm keeps the preview and its ticks, so HR can confirm again. `validation_failed` has no key, so pass the `ApiError` to `ErrorBanner` unchanged, which lists the server's field messages as is. The client's own `.xlsx` and 5 MB check means HR rarely sees it.
- [ ] A new file clears the preview, the ticks and the result.
- [ ] Every string is a key under `attendance.fingerprint.*` in the snippet, in Arabic and English with the same keys. Reuse existing keys where they fit (`attendance.leaveName.<code>` for a leave type, `attendance.source.<source>` for a source, adding only `attendance.source.fingerprint` to the snippet). Arabic strings hold no Latin letters except `Excel` and `.xlsx`. Use `CONTEXT.md`'s terms (fingerprint name اسم البصمة, attendance day يوم حضور, punch بصمة).
- [ ] RTL by default, logical properties (`start`, `end`), times and dates in `<bdi dir="ltr">`, usable at 375 px wide, keyboard reachable tick boxes with labels naming the employee and date.

**You see.**

- [ ] `npm run test -- fingerprint-import` passes, rendering the fixture's every status and flag in Arabic.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] The test loads the snippet in `beforeAll` with `i18n.addResourceBundle(lng, 'translation', snippet[lng], true, true)` for `ar` and `en`, and asserts the snippet's `ar` and `en` hold the same keys.
- [ ] The test mocks `fetch` with `mockFetch` from `@/test-utils` and covers the preview request body, each status shown, both flag badges, tick boxes only on `conflict` and `leave`, a confirm sending the same file and only the ticked rows with their versions, the result and its error row, a new file clearing everything, a `.xls` refused before any request, a 422 `not_fingerprint_file` shown as the snippet's string, a 422 `too_many_rows` shown with 5000, an unknown confirm error code shown with the server's message, the empty preview, and the English rendering of one row, including code 103 shown with its Arabic name.
- [ ] Run `npm run test`, `npm run lint` and `npm run build` in `frontend/`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. The screen is not mounted until FP-6, so no lanes (owner-approved adaptation).

- [ ] Paste the Vitest output for `fingerprint-import.test.tsx` in the PR body. Pass when every case passes. The live check of this screen is FP-6's.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. None in this PR. The table renders at most a few hundred rows; FP-6 checks it with the real file.
- [ ] Probe. None.
- [ ] Baseline. None.
- [ ] Rule. None.

**Review gate.** None. FP-5 is not mounted, so the owner reviews the screen at FP-6.

**Merge.**

- [ ] Work on branch `feature/fingerprint-import-screen` in your fork, from the latest `main`.
- [ ] Run the `code-review` skill on the branch if your Claude setup has it, and resolve its findings. It is a Claude Code skill, not in the repo; if your setup lacks it, say so in the PR body and the owner runs it. Rebase onto current `main` before asking for review.
- [ ] Open a PR into `main` from your fork. Its body says `Closes #<issue>` and pastes the tail of every gate above.
- [ ] Done when every box above is checked, the tail of every gate is pasted in the PR body and passes, and the owner has reviewed it. The owner merges.

## Connect the screen and verify with the real file (FP-6)

**Depends on.** FP-4 and FP-5, both merged.

**Files.**

- [ ] Edit `frontend/src/pages/attendance.tsx` (an office-only tab rendering `FingerprintImportTab`, for holders of `attendance.edit` at `.all` scope).
- [ ] Edit `frontend/src/locales/ar.json` and `frontend/src/locales/en.json` (merge the snippet, plus the tab's label).
- [ ] Delete `frontend/src/pages/fingerprint-import.locales.json` and its loading lines in `fingerprint-import.test.tsx`.
- [ ] Edit `frontend/src/locales/locales.test.ts` only if an Arabic key must hold `Excel` (its `allowed` list).

**Build.**

- [ ] Mount the tab beside the grid import, office group only.
- [ ] Merge the keys, then run the parity test.

**You see.**

- [ ] HR opens Attendance, office, the fingerprint tab, uploads the real file, previews, ticks one conflict, confirms, and the period grid shows the imported days.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `npm run test`, `npm run lint`, `npm run build` pass, the locale parity and Latin-letter tests included.
- [ ] The backend suite still passes, `docker compose exec app php artisan test`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Driven through the `verify-hrms` skill instead of ten lanes (owner-approved adaptation).

- [ ] With the client's real file and the 28 names set on dev employees (outside git), preview and confirm as HR in Arabic and English at 375, 1024 and 1440 px, light and dark. Save the screenshots under `/tmp/verify-fp-6/`. Pass when the counts match FP-4's `curl` run and no screen overflows.
- [ ] As a site manager, the tab is absent and the routes answer 403. Save `manager-no-tab.png`.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Time from clicking preview to the table shown, real file.
- [ ] Probe. The browser's network timing in `verify-hrms`, three runs.
- [ ] Baseline. FP-4's server time for the same file.
- [ ] Rule. Fails when the screen adds more than 2 seconds to the server time.

**Review gate.** The operator (the owner) reviews before merge.

- [ ] Copy the Arabic 1440 px and 375 px screenshots of the preview and the result into the PR.
- [ ] Record a 30 to 60 second video of upload, preview, tick, confirm and the grid, and attach it to the PR.
- [ ] Post the screenshots and the video for the operator (the owner). Stop at merge-ready.

**Merge.**

- [ ] Gates green at the head SHA, pasted in the PR body.
- [ ] The owner approved the screenshots and the video.
- [ ] The owner merges.

## Close the program

- [ ] Every box above is checked with its evidence.
- [ ] `docs/hrms-module-plan.md` Release 2 item 1 marked built, with the spec and plan paths.
- [ ] `CLAUDE_HANDOFF.md` updated through the `session-end` skill.

## Appendix A. Prototype evidence

No prototype branch. The open questions were settled by reading the client's real file (git-ignored) with a stdlib script, on 2026-11-17.
- One device user ID always carries one name, so matching by name loses nothing (28 people, 28 IDs).
- 446 person-days, of which 60 have one punch, 367 two, 13 three and 6 four.
- Gaps between consecutive punches of a person-day are 26 under 1 minute, 1 of 64 seconds, then 23 minutes and more. This sets `DOUBLE_TAP_SECONDS` at 120.
- Two person-days hold only a double tap, so without that rule they would store equal in and out times.
- Times are format 21 and dates format 14 on every row. Column J is missing on 3 rows.
Unproven until FP-6, whether the real 28 names match the client's employee records (dev holds one fingerprint name).

## Appendix B. Alternatives rejected

- Matching by device user ID. Rejected by the owner (decision 1). It needs a new employee field and HR would keep two keys in step.
- Storing flags on the day. Rejected (planner's call b). Flags follow from the times, and a stored flag goes stale when HR edits a time.
- Refusing leave days. Replaced by the owner's decision 4. The balance follows attendance, so a ticked replacement is safe for it.
- Releasing one day of an approved leave request from the import. Needs a Leave operation that does not exist and a call from Attendance into Leave, which already depends on Attendance. Rejected by the owner (spec decision 5). The request keeps its count and status.
- A JSON fixture. A TypeScript fixture is checked against the types by `npm run build`.

## Appendix C. Risks

- FP-2's migration fails on an existing database whose fingerprint names collide after normalising. It fails with a message naming the collisions and changes no row; the owner runs the collision query on the servers before merge and deploy ("Verdict and merge").
- A pending leave request becomes unapprovable over dates the import filled. FP-4's preview cannot see it. HR sees the refusal on approval.
- The client sends the six-paste workbook every month (question F3). Overlaps are merged, but a day cut mid-day becomes a one-punch day until a later file refreshes it.
- FP-5 is built without the live API. FP-6 is where a contract mismatch shows; the shared types make it a compile error first.

## Appendix D. Links and reading list

- `docs/superpowers/specs/2026-11-17-fingerprint-import-design.md`, the contract.
- `docs/coding-standards.md`, `CONTEXT.md`, `docs/calculations.md` §0a and §1.
- `backend/app/Modules/Attendance/Application/AttendanceImport.php` and `AttendanceDays.php`, the pattern and the write path.
- `docs/reviews/2026-11-08-release-1-review.md` items 11-F6 and 13-E7.
- FP-4 runs `pstack/skills/how/SKILL.md` over the Attendance write path before building.
