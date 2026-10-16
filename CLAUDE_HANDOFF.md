# Handoff — Release 1a merged (scaffold, employees, sites & rates); next: Release 1b

Written 2026-10-17. Every fact below was re-checked at the end of the session unless marked *unverified*.

## Current Goal
Start **Release 1b**: accounts and login, roles/permissions (RBAC), the audit log, and manager ↔ employee / manager ↔ site assignment. First action: write the 1b implementation plan at `docs/superpowers/plans/release-1b-accounts.md`, mirroring the CRM's Identity + Audit modules (see Key Findings), then execute it with subagent-driven development.

## Key Findings
- **Repo state:** branch `main`, HEAD `67d8421`. `feature/release-1-foundation` was merged (`1d9a715`) and is kept. **No git remote** — nothing pushed, no PRs exist. Untracked: `.claude/launch.json` (preview config, deliberately uncommitted).
- **Working rule (user):** always use the skills `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and superpowers (e.g. `superpowers:subagent-driven-development`, `superpowers:test-driven-development`); delegate implementation to subagents and tell every subagent to load the same three. Saved in project memory.
- **Specs live in:** `CLAUDE.md` (rules), `CONTEXT.md` (glossary), `docs/calculations.md` (formulas, ⚠️ = client-pending), `docs/hrms-module-plan.md` (release contents + settled decisions), `docs/client-questions.md` (17 open client questions), `docs/adr/0001-mirror-crm-stack.md`, `docs/adr/0002-daily-wage-on-worker.md`, `docs/superpowers/plans/release-1a-foundation.md` (the executed 1a plan).
- **Stack:** mirrors the CRM at github.com/mr-h12/smart-technology-CRM (Laravel 13 / PHP 8.4 / Postgres 17.5 / Redis 7.4; modules `app/Modules/<M>/{Domain,Application,Infrastructure,Presentation}`; deptrac layers; PHPUnit 12; PHPStan level 8; `/api/v1`; UUIDv7; `ApiEnvelope`). For 1b copy the CRM's custom bearer-session auth (`app/Modules/Identity`, `BearerSessionResolver`, `user_sessions`), RBAC tables (`roles`, `permissions` resource.action.scope, `role_permissions`), and the append-only audit log (`app/Modules/Audit`). Drop self-service password change: only the Developer role sets passwords.
- **Backend modules built:** `Employees` (employees, employee_type_changes, employee_pay_changes, employee_documents; auto code via table lock) and `Sites` (sites incl. leave day-types, site_allowance_rules, residences, fares, employee_fare_overrides; `GET /fares?date=` returns the in-force row per residence×site; `GET /employees/{id}/fare`). Money is `NUMERIC(18,6)` strings with bcmath, no rounding; regex `/^\d{1,12}(\.\d{1,6})?$/D`. Effective-dated rule everywhere = greatest `effective_from <= D`, Cairo date for "today".
- **Frontend:** React 19 + Vite + TS, react-i18next (ar default, RTL), shadcn/Radix; pages employees (list/new/detail), sites, residences, fares grid. Shared form helpers in `frontend/src/components/form.tsx` (ErrorBanner, Field, TextField with Arabic-digit conversion, useMoney).
- **Facts that cost a failure to learn:**
  - Container env sets `DB_DATABASE=hrms`; Laravel reads `$_SERVER` before `$_ENV`, so phpunit needs `<server … force="true">` too. `tests/TestCase.php` now refuses to run on any DB but `hrms_test` (`67d8421`). Before that fix every test run wiped the dev DB.
  - Validated query params are strings: shared `Sites\Application\Paginates::page()` takes `int|string` (`2cd4cc4`).
  - After `git switch`/merge, the `artisan serve` process can lose its cwd (`require_once(/index.php)` error) → `docker compose restart app`.
  - Host ports 5432/6379 belong to the CRM stack; this project's root `.env` maps 5433/6380 (API stays on 8000).
  - A trait method overrides a parent-class method, so RefreshDatabase hooks can't be overridden from `TestCase`; use `setUpTraits()`.
- **Open owner/client decisions and what they block:**
  - Leave pay rules (paid %, sick 75%?, beyond-balance → absence) — blocks the leave module (deferred by the user to "when we build it").
  - End-of-service rule (fractions of a year, wage base) — blocks the EOS form in Release 3.
  - Is a worker's daily wage ever different by site — would add a per-worker × site wage override (ADR 0002).
  - Do day laborers get transport (Oct Excel pays 0) — affects payroll parity in Release 2.
  - The full list with defaults is in `docs/client-questions.md`.

## Completed Actions
- Grilling session (35 questions answered or accepted) → docs updated: `CLAUDE.md`, `calculations.md`, `hrms-module-plan.md`, `excel-system-analysis.md` (16 known Excel errors now), new `CONTEXT.md`, 2 ADRs, `client-questions.md`.
- Release 1a: 6 tasks implemented by subagents, each spec + quality reviewed with fix rounds, then a final whole-branch review (opus) and one fix wave, re-reviewed clean. Merged to `main` as `1d9a715`.
- Post-merge fixes on `main`: `2cd4cc4` (per_page crash), `67d8421` (tests off the dev DB).
- Gates re-run at session end on `67d8421`: backend `php artisan test` **48 passed (251 assertions)**, pint clean, phpstan `[OK] No errors`, deptrac 0 violations / 0 errors; frontend vitest **31 passed**, build ok, lint ok; `GET /api/v1/health` ok; Vite on 5173 returns 200.
- Dev DB `hrms` now holds the seed only: 5 leave sites, 4 residences, 0 employees.

## Plan
**Next (Release 1b, start cold):**
1. Read `CLAUDE.md` "Users and permissions", `docs/hrms-module-plan.md` Release 1, and the CRM's `crm/app/Modules/{Identity,Audit}` + RBAC migration (`2026_08_23_000000_create_rbac_tables.php`) and `audit_log` migrations.
2. Write `docs/superpowers/plans/release-1b-accounts.md` with tasks, roughly:
   1. users + bearer sessions + login/logout + lockout (Developer creates accounts and sets passwords; no self-service).
   2. RBAC data (roles Developer/CEO/HR/Manager, permissions by name, scope Own/All) + middleware; check permissions by name, never by role name.
   3. Audit log (append-only, before/after on every create/update/delete, login, override) wired into the existing Employees/Sites services; fill `created_by`/`updated_by`.
   4. Manager ↔ employee assignment (dated, exactly one at a time) and manager ↔ sites; scope employee lists to the manager's own employees.
   5. Developer console screens (accounts, roles/permissions, audit log viewer) + login screen; `api.ts` sends the token and `Accept-Language`.
   6. Arabic backend validation messages (deferred finding I4).
3. Execute with `superpowers:subagent-driven-development` (ledger in `.superpowers/sdd/<plan>/`).

**Remaining Release 1 items after 1b:** manager attendance (mobile), leave (balances; needs the leave-pay answers), notifications (instant + daily digest), migration step 1 from Excel (employees, sites with name dedupe, fares, opening leave balances, open advances).

**Deferred minors carried forward** (from the 1a reviews):
- A soft-deleted residence still resolves fares, and PATCHing it back returns 422 → block deleting a residence that is in use.
- `national_id` / `fingerprint_name` are unique even across soft-deleted rows, which blocks re-hiring.
- The duplicate-date check races the unique index and can return 500.
- No DB CHECK constraints on enum columns.
- `config/auth.php` references a missing `App\Models\User`, to fix in 1b.
- `Allowance::for` truncates at scale 6; decide before payroll sums it.
- Arabic search doesn't fold أ/ا or ة/ه, to handle at import.
- Pay dialog with an empty date shows the first type's fields.
- Frontend:
  - No debounce or cancel on the search box.
  - `Row = any` typing.
  - Focus isn't restored when a dialog closes.
  - Rows have no aria-labels.
  - `TitlePage` is unused.

## Environment
Run from the repo root `/Users/yousefhasabo/HRMS-Smart TECH` (no PHP on the host; everything PHP runs in Docker):
```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml
docker compose restart app
curl http://127.0.0.1:8000/api/v1/health
```
Frontend (host, Node 22):
```bash
cd frontend && npm install && npm run dev
cd frontend && npm test && npm run build && npm run lint
```
App: http://localhost:5173 (Vite proxies `/api` to `API_PROXY_TARGET`, default http://localhost:8000). In the desktop app, the preview config is `.claude/launch.json` → `frontend`.
First-time setup: `cp .env.example .env` (set ports 5433/6380 if the CRM stack is running), `cp backend/.env.example backend/.env`, `docker compose exec app php artisan key:generate`.

## Non-code items
- ⚠️ **Backups are not configured; they stay manual until the owner confirms.** Do not automate them unprompted.
- **No git remote.** Create a GitHub repo before PRs and the "other developer reviews" flow can start.
- **Client inputs still needed:**
  - The 17 questions in `docs/client-questions.md`.
  - Remote access (VPN/SSH) to the on-prem server.
  - An SMTP mailbox on the company domain.
  - HR and CEO signature images (Release 3).
  - A full month of fingerprint export (Release 2).
  - The current company address, phone and email (the forms show two versions).
- **claude-mem memory capture was paused** at session start (5-hour usage at 95%). Native project memory files work regardless.
