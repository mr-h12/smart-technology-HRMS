# Real Data on the Server (Migration Step 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the company's master data from the 6 Excel workbooks into the HRMS on `testserver` as a test copy: employees with their type and pay, sites, allowance rules, residences and fares. HR then checks it in the app.

**Architecture:** There are three stages. First, a throw-away script on the Mac turns the 6 workbooks into one clean staging workbook (`master-data.xlsx`), kept outside the repo, which the owner or HR reviews and completes. Second, a tested artisan command, `hrms:import-master`, loads that workbook through the existing services and the controllers' validation rules, in a single transaction. It is a dry run unless `--commit` is passed, and every row is audited under the HR actor. Third, the server (which already runs the app behind Tailscale HTTPS via `~/apps/deploy-hr.sh`) is moved to `main`, gets the deploy fixes, and runs the import, with a manual `pg_dump` before and after.

**Tech Stack:** Laravel 13 / PHP 8.4, OpenSpout (already installed, Infrastructure only), PostgreSQL 17, docker compose project `hrms` on the server, Python 3 + openpyxl in a venv outside the repo (extraction only).

**Spec:** `docs/hrms-module-plan.md:78` (Migration step 1), `docs/excel-system-analysis.md` (fields and known errors), `docs/client-questions.md` Q5–Q7, plus the owner's decisions on 2026-10-22:
- staging sheet + importer;
- **new app codes** (Excel codes are not kept in the DB; the import report maps each staging `ref` to its new code);
- the owner/HR fill gender, hire date, phone and manager in the staging sheet;
- **master data only**: opening leave balances and open advance balances wait for their modules (no tables exist yet);
- **test copy first**: the server data may be wiped and reloaded until HR approves it.

## Global Constraints

- **Real data is customer PII.** No real value (name, national ID, wage, phone) goes into the repo, a commit, a PR, a test fixture, an artifact, chat output or logs. Real files live only in `~/HRMS-real-data/` on the Mac (outside the repo) and in transit on the server. Agents print counts and structure, never row values.
- The repo `mr-h12/smart-technology-HRMS` is PUBLIC. Before any `git add`, run `git status --short` and `git check-ignore` on anything that looks like data.
- Money: decimal strings, matching `^\d{1,12}(\.\d{1,6})?$`. Never floats.
- Every write goes through the existing services, so the audit log records it. The actor is the HR account given in `--as`.
- Names are normalised with `Name::normalise` (as `SiteController` does) before matching sites and residences.
- Effective dates: one `--from=YYYY-MM-DD` for every type, pay, rule and fare row. Recommended: `2026-09-26`, the earliest period start among the October workbooks.
- Backups stay manual (`pg_dump`). Add no automation.
- **Owner gates.** Every write to the server or its database needs the owner's explicit go for that step. Never push, merge or open a PR without the owner's go. Never commit `CLAUDE_HANDOFF.md`.
- All subagents run on Opus and load `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `superpowers:test-driven-development`, and follow `docs/coding-standards.md`.

## Staging workbook contract (`master-data.xlsx`)

Task 4 produces this workbook and Tasks 2–3 consume it. Row 1 of each sheet holds the headers. Columns marked *info* are ignored by the importer.

| Sheet | Columns |
|---|---|
| `residences` | `name_ar` |
| `sites` | `name_ar`, `name_en`, `outside_alexandria` (`1`/`0`), `allowance_mode` (`none`\|`share_of_wage`\|`fixed`), `allowance_value`, `excel_spellings` *info* |
| `fares` | `residence`, `site`, `amount` (residence and site by `name_ar`) |
| `employees` | `ref` (unique, e.g. `ENG-12`, `DAY-r57`), `type` (`office`\|`engineer`\|`permanent_worker`\|`day_laborer`), `name_ar`, `name_en`, `fingerprint_name`, `gender` (`male`\|`female`), `national_id`, `insurance_no`, `phone`, `address`, `department`, `residence`, `pay_method` (`cash`\|`bank`, blank = cash), `bank_account`, `hire_date` (Y-m-d), `salary`, `raise`, `insured_basic`, `daily_wage`, `manager_email`, `hr_notes`, `source` *info* |
| `fare_overrides` | `ref`, `site`, `amount` |

Blank cells mean null. Work sites only: the leave day-types are already seeded.

## Review Focus

1. **A second run after a successful commit.** The command must refuse (any employee row exists, trashed included) and must not create duplicates. → Task 3 test `refuses_when_employees_exist`.
2. **One bad row among 145.** Nothing is written. Every error is listed with sheet, row number and field, and a single run reports all errors, not just the first. → Task 3 test `bad_rows_roll_back_everything_and_list_all_errors`.
3. **The same national ID or fingerprint name twice in the file.** It is reported as an error on the second row, not as a raw SQL exception. → covered in the same test.
4. **A site or residence spelled with extra or double spaces.** It matches the existing or staged name after normalisation and does not create a duplicate. → Task 3 test `names_match_after_normalising`.
5. **An unknown `manager_email`, or one for an inactive user.** It is a row error, not a crash. → Task 3 test `unknown_manager_is_a_row_error`.

---

## Part A — Code (branch `feature/migration-step-1` from `main` = `7be93b9`)

### Task 1: Deploy fixes in the repo (upload limits, ignore local data)

**Files:**
- Modify: `docker/php/Dockerfile:15` (the `zz-app.ini` printf)
- Modify: `.gitignore`

- [ ] **Step 1:** Extend the printf at `docker/php/Dockerfile:15` to also write `upload_max_filesize=6M` and `post_max_size=8M`.
- [ ] **Step 2:** Rebuild and check.
  Run: `docker compose build app && docker compose up -d && docker compose exec app php -r 'echo ini_get("upload_max_filesize")," ",ini_get("post_max_size");'`
  Expected: `6M 8M`.
- [ ] **Step 3:** Add `.claude/dev-accounts.md`, `.claude/excel-preview/`, `.claude/gate-*/`, `.claude/verify-runs/` and `/HRMS-real-data/` to `.gitignore`.
  Verify with `git check-ignore -v .claude/dev-accounts.md .claude/excel-preview/x.xlsx`: both lines match.
- [ ] **Step 4:** Commit: `Raise PHP upload limits to 6M/8M and ignore local accounts and Excel previews`.

### Task 2: Shared validation rules and the workbook reader

The importer must validate exactly as the API does. Move the rule arrays the controllers build privately into Application classes, so that both the controllers and the importer use them.

**Files:**
- Create: `backend/app/Modules/Employees/Application/EmployeeRules.php`
- Create: `backend/app/Modules/Sites/Application/SiteRules.php`
- Create: `backend/app/Modules/Employees/Infrastructure/XlsxSheetRows.php`
- Modify: `backend/app/Modules/Employees/Presentation/EmployeeController.php:42-50,108-150`
- Modify: `backend/app/Modules/Sites/Presentation/SiteController.php:57-95`, `ResidenceController.php:49-56`, `FareController.php:34-50`
- Test: `backend/tests/Unit/XlsxSheetRowsTest.php`

**Interfaces:**
- Produces:
  - `EmployeeRules::store(): array<string, array<mixed>>`: what `EmployeeController::store` validates today (identity + `type` + pay + `effective_from`).
  - `EmployeeRules::identity(bool $patch, ?string $id = null)`.
  - `EmployeeRules::pay()`.
  - `SiteRules::site(bool $patch, ?string $id = null)`, `SiteRules::allowanceRule()`, `SiteRules::residence(bool $patch, ?string $id = null)`, `SiteRules::fare()`, `SiteRules::fareOverride()`.
  - `XlsxSheetRows::read(string $path): array<string, list<array{row: int, cells: array<string, ?string>}>>`: sheet name → rows. Headers come from row 1. `row` is the 1-based Excel row number. Cells are trimmed strings, and blank cells become null. Fully blank rows are skipped. A formula cell yields its cached value.

- [ ] **Step 1: Write the failing test** `XlsxSheetRowsTest::reads_sheets_by_header`. Build an xlsx in the test with the OpenSpout writer: sheet `sites` with header `name_ar,allowance_value`, then rows `[' موقع أ ', '12.5']`, `['', '']` and `['موقع ب', '']`. Assert:
  - `read()['sites']` has 2 rows;
  - row numbers are `2` and `4`;
  - `cells['name_ar'] === 'موقع أ'` (trimmed);
  - `cells['allowance_value']` of the second row is `null`.
  Use fake placeholder names only.
- [ ] **Step 2:** Run `docker compose exec app php artisan test --filter=XlsxSheetRowsTest`. Expected: FAIL, class not found.
- [ ] **Step 3:** Implement `XlsxSheetRows`, reusing the reader options pattern in `XlsxAttendanceReader`.
- [ ] **Step 4:** Move the rule arrays into `EmployeeRules` and `SiteRules`. Keep the name normalising merge (`Name::normalise`) in the controllers, where it sits today. The controllers call the new statics, and no behaviour changes.
- [ ] **Step 5:** Run all backend gates: `php artisan test`, pint, phpstan, and deptrac with `--config-file=deptrac.layers.yaml`. Expected: all green, and the 241 existing tests still pass.
- [ ] **Step 6:** Commit: `Share employee and site validation rules; add a header-keyed xlsx sheet reader`.

### Task 3: `MasterDataImport` service and `hrms:import-master` command

**Files:**
- Create: `backend/app/Modules/Employees/Application/MasterDataImport.php`
- Create: `backend/app/Modules/Employees/Presentation/ImportMasterDataCommand.php`
- Test: `backend/tests/Feature/ImportMasterDataTest.php`
- Modify: `docs/hrms-module-plan.md:78` (mark step 1 as master data only, with balances deferred)

**Interfaces:**
- Consumes: `EmployeeRules`, `SiteRules`, `XlsxSheetRows` (Task 2). Services:
  - `SiteService::create/addRule`;
  - `FareService::createResidence/addFare/addOverride`;
  - `EmployeeService::create/assignManager`.
- Produces:
  - `MasterDataImport::run(string $path, string $from, bool $commit): array{counts: array<string,int>, errors: list<array{sheet: string, row: int, field: string, message: string}>, codes: list<array{ref: string, code: int}>}`
  - Artisan: `hrms:import-master {file} {--as= : email of the acting HR account} {--from= : Y-m-d} {--commit} {--report= : CSV path for ref→code}`

**Behaviour (decisions):**
- **Refuse before reading** if any `employees` row exists, trashed included. Message: "Employees already exist; this import is for an empty system."
- **One `DB::transaction` around everything.** Roll back if `!$commit` or if `errors` is non-empty. Otherwise commit. The counts and codes are reported either way, so a dry run shows what would be created.
- **Order:**
  1. residences: reuse an existing live residence with the same normalised name, else create;
  2. sites: reuse by normalised `name_ar`, else create with `kind=work`;
  3. allowance rules: only when `allowance_mode` is not blank, with `effective_from=$from`;
  4. fares;
  5. employees;
  6. fare overrides;
  7. manager assignments, only when `manager_email` is filled.
- **Per row:**
  - map names and emails to ids;
  - validate with `Validator::make($data, EmployeeRules::store())` (or the matching `SiteRules` method);
  - call the service inside a savepoint (`DB::transaction` nested);
  - catch `ValidationException` and add one error per message key;
  - check `ref` uniqueness within the file.
  - Unknown residence, site, ref or manager → an error on that row's field. No row stops the others.
- **The command:**
  - resolves `--as` to an active user;
  - checks `employee.create`, `employee.assign_manager` and `site.manage` with `AuthorizeAction` (the class `AuthorizePermission` uses), and refuses otherwise;
  - calls `Auth::setUser($actor)`, so `AuditLog` stamps `user_id`;
  - prints the counts table, then the errors (sheet, row, field, message; never cell values);
  - writes `--report` as CSV `ref,code` with mode 0600;
  - prints `DRY RUN: nothing was saved` or `COMMITTED`.

- [ ] **Step 1: Write the failing tests** in `ImportMasterDataTest`. Build fixture workbooks in-test with the OpenSpout writer, using fake data only. Log in an HR user from the seeded roles.
  - `dry_run_writes_nothing`: 2 residences, 2 sites (one `share_of_wage` 0.5), 1 fare, 3 employees (office, engineer, day_laborer), 1 override. Exit 0. `employees`, `sites` (work) and `fares` counts stay unchanged. The output contains `DRY RUN`.
  - `commit_creates_everything_audited`: same file with `--commit`. Then:
    - 3 employees with codes 1..3;
    - each has a type change and a pay change at `--from`;
    - the residence is linked;
    - 1 allowance rule, 1 fare, 1 override;
    - `audit_log` rows for the employees have `user_id` = the HR user;
    - the report CSV has 3 `ref,code` lines and mode `0600`.
  - `bad_rows_roll_back_everything_and_list_all_errors`: one 13-digit `national_id`, one unknown `residence`, one `national_id` repeated on a second row, one engineer without a salary. With `--commit`: exit non-zero, nothing written, and all 4 errors listed with the right sheet and row numbers.
  - `names_match_after_normalising`: a site cell `"موقع  أ "` and a fare referencing `"موقع أ"` give one site and the fare links to it. A residence equal to a seeded one is reused, not duplicated.
  - `unknown_manager_is_a_row_error`: a `manager_email` that is unknown, or belongs to an inactive user, gives a row error on field `manager_email`.
  - `refuses_when_employees_exist`: with one existing (soft-deleted) employee, the command fails with the refusal message and writes nothing.
  - `refuses_actor_without_permission`: `--as` a manager account gives a refusal.
- [ ] **Step 2:** Run `php artisan test --filter=ImportMasterDataTest`. Expected: FAIL, command not found.
- [ ] **Step 3:** Implement `MasterDataImport::run` and `ImportMasterDataCommand` per the decisions above.
- [ ] **Step 4:** Run the test filter, then all backend gates. Expected: all green.
- [ ] **Step 5:** Update `docs/hrms-module-plan.md:78` to say "step 1 (master data) is done by `hrms:import-master`; opening leave and advance balances follow their modules".
- [ ] **Step 6:** Commit: `Add hrms:import-master: dry-run-first, all-or-nothing, audited master data import`.
- [ ] **Step 7:** Get a whole-branch review (`superpowers:requesting-code-review`), then **stop for the owner**: push, PR, Mohanad's review, merge.

## Part B — Local extraction (outside the repo, never committed)

### Task 4: Build `~/HRMS-real-data/master-data.xlsx` from the 6 workbooks

**Files (all outside the repo):**
- `~/HRMS-real-data/.venv/` (python3 -m venv, then `pip install openpyxl`)
- `~/HRMS-real-data/extract.py` (reads the 6 workbooks in the repo root read-only, passing their paths as arguments)
- Output: `~/HRMS-real-data/master-data.xlsx` (the staging contract above), plus `~/HRMS-real-data/review-notes.md`

- [ ] **Step 1:** Follow `docs/excel-system-analysis.md` §§2–4 to extract:
  - **Office staff** from the October salary sheet, enriched with the payslip fields (national ID, insurance no., bank account) from `مفردات مرتب شهر 9`.
  - **Engineers and permanent workers** from their workbook (salary, residence).
  - **Day laborers** with a wage on the consolidated sheet. This includes the people with no code, whose `ref` is `DAY-r<row>`.
  - **Sites**: one row per merged spelling, recording every source spelling in `excel_spellings` (error #15).
  - **Fares**: residence × site, with per-worker fares that differ from the default going to `fare_overrides`. Where two different amounts exist (#16), keep the first and note the conflict.
- [ ] **Step 2:** Leave `gender`, `hire_date`, `phone`, `manager_email` and `department` blank unless a workbook has them. `review-notes.md` lists:
  - every site merge;
  - fare conflicts;
  - people who appear in two workbooks (error list `:226`), with `source` naming both;
  - the two uncertain fingerprint names (Q5);
  - the count of blank required cells (gender);
  - the open advance balances per `ref` from the hidden advances ledger, kept for the future advances module and not imported now.
- [ ] **Step 3:** Check by printing **only** counts per sheet, and per type for employees. Expected: about 27 office, about 26 engineers and permanent workers, about 91 day laborers, and a site count matching the merged list. No row values go to the terminal.
- [ ] **Step 4:** **Stop for the owner.** The owner (with HR) opens `master-data.xlsx`, fills gender (required) and whatever hire dates, phones and manager emails are known, and approves the site list (client Q7).

## Part C — Server (each step needs the owner's go)

Server facts, read-only on 2026-10-22:
- `testserver` runs Ubuntu 24.04, Docker 29.9 and compose v5.6.
- The checkout is `~/apps/smart-technology-HRMS` at `c734a31`. That is PR #3's head; its code is identical to `main` = `7be93b9`.
- The compose project is `hrms`, with `-f docker-compose.yml -f ~/apps/hr-deploy/compose.hr.yml`.
- The app is published at `https://hr.<tailnet>.ts.net` through the `ts-hr` sidecar. `APP_ENV=production` and `APP_DEBUG=false` are set.
- The database has no employees, 2 users, and the seeded leave sites and 4 residences.
- PHP limits are still 2M/8M, and `web.conf` sends no MIME type for `.webmanifest`.
- `~/apps/deploy-hr.sh` deploys a branch (default `feature/release-1c-attendance`).

Shorthand used below: `DC='docker compose -p hrms -f docker-compose.yml -f ~/apps/hr-deploy/compose.hr.yml'`, run from the checkout.

### Task 5: Manual backup, then move the server to `main`

- [ ] **Step 1 (go needed):** Take a manual dump:
  `mkdir -p -m 700 ~/backups/hr-manual && (umask 077; $DC exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > ~/backups/hr-manual/$(date +%Y%m%d-%H%M)-pre-main.sql.gz)`
  Then verify: `gunzip -c <file> | grep -c 'CREATE TABLE public.migrations'` returns `1`.
- [ ] **Step 2 (go needed):** Change the default branch in `~/apps/deploy-hr.sh` to `main` (and drop the out-of-date "main has no login yet" comment).
- [ ] **Step 3 (go needed):** Add the webmanifest type to `~/apps/hr-deploy/web.conf` with `location = /manifest.webmanifest { types { } default_type application/manifest+json; }`. A `types` block at server level would replace the whole MIME map.
- [ ] **Step 4 (go needed, after Task 3 is merged):** Run `bash ~/apps/deploy-hr.sh main`. Expected: the final line is `OK: HR published at https://hr.…`.
- [ ] **Step 5:** Verify, read-only:
  - `git -C ~/apps/smart-technology-HRMS log -1 --oneline` shows the merge of Task 3;
  - `$DC exec app php -r 'echo ini_get("upload_max_filesize");'` prints `6M`;
  - `curl -sI` of `/manifest.webmanifest` through the `web` container shows `application/manifest+json`;
  - `$DC exec app php artisan list | grep hrms:import-master` lists the command.

### Task 6: Real accounts (the owner types every password)

Claude never enters a password. The owner runs this in their own terminal:
- [ ] **Step 1:** For HR, the CEO and each manager:
  `ssh testserver`, `cd ~/apps/smart-technology-HRMS`, then `$DC exec app php artisan identity:create-user <email> --name="<name>" --role=<hr|ceo|manager>`. Each one asks for the password at a hidden prompt.
  The Developer account already exists (its password is `HR_ADMIN_PASSWORD` in the server's root `.env`).
- [ ] **Step 2:** Verify, read-only: `select email, is_active from users` shows the new accounts (emails only, no hashes).

### Task 7: Load the test copy

- [ ] **Step 1 (go needed):** Take a fresh dump as in Task 5 Step 1, named `…-pre-import.sql.gz`.
- [ ] **Step 2 (go needed):** Copy the staging workbook into the container:
  `scp ~/HRMS-real-data/master-data.xlsx testserver:/tmp/` → `docker cp /tmp/master-data.xlsx hrms-app-1:/tmp/` → `rm /tmp/master-data.xlsx` on the host.
- [ ] **Step 3:** Dry run: `$DC exec app php artisan hrms:import-master /tmp/master-data.xlsx --as=<hr email> --from=2026-09-26`. Expected: `DRY RUN`, the counts match Task 4 Step 3, and there are 0 errors. If there are errors, the owner fixes the sheet on the Mac and repeats Step 2.
- [ ] **Step 4 (go needed):** Repeat with `--commit --report=/tmp/codes.csv`. Expected: `COMMITTED`.
- [ ] **Step 5:**
  1. `docker cp hrms-app-1:/tmp/codes.csv` → scp to `~/HRMS-real-data/codes.csv` (HR's old-ref → new-code list).
  2. Delete `/tmp/master-data.xlsx` and `/tmp/codes.csv` in the container.
  3. Take the post-import dump (`…-post-import.sql.gz`).
  4. Optionally scp the dumps to `~/HRMS-real-data/backups/` with mode 600.
- [ ] **Step 6:** Owner verification. Using `verify-hrms` against the server URL (owner logged in), take screenshots of:
  - the employee list with counts per type;
  - one employee of each type (pay and residence);
  - the sites tab with allowance rules;
  - the fares tab;
  - the audit log showing the import actor.
  Do not post screenshots outside this chat. **Stop for the owner's approval.**
- [ ] **Reset path (test copy, go needed each time):** dump → `$DC exec app php artisan migrate:fresh --seed --force` → redo Task 6 for the accounts (or restore the pre-import dump) → repeat Task 7.

## Owner reminders (not tasks)

- Make the GitHub repo private, and send the GitHub Support request in `.superpowers/purge-cleanup/` (purged PII commits are still reachable).
- HR staff reach the app only over Tailscale: they need the Tailscale app and an entry in `group:hr` in `~/apps/hr-deploy/tailscale-acl-draft.hujson`, which is not applied yet.
- Opening leave balances (blocked on Q8–10) and open advance balances (no ledger yet) come with their modules. `review-notes.md` keeps the advance balances found in the workbooks.
- The site → employee redesign (model B) is still pending. Manager assignments loaded now use today's model.
