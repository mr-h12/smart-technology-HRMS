# Plan: performance architecture + coding standards (before Slice 4)

## Context
The owner paused Release 1b Slice 4 because moving between pages feels heavy and not smooth.
The system will be read/write heavy and will produce a lot of reports, so the architecture gets
fixed now, before more pages are built on it. The owner also wants the CRM coding standards
adopted for all code from here on.

**Handoff verified (2026-10-18):**
- HEAD is `2ecb5b8`, 33 commits ahead of `origin/main`.
- 22 commits are unpushed (`origin/feature/release-1b-accounts` is at `e9a921f`).
- No PR exists.
- `app`, `postgres` and `redis` are running.
- The gates were not re-run this session (plan mode); they run at gate A.

**Owner decisions (this session):**
- Use TanStack Query for the data layer.
- Work on a stacked branch, `feature/perf-architecture`, cut from `2ecb5b8`. The 1b PR goes first; this PR follows it.

**Measured causes (two read-only audits).** The bundle is fine (~168 KB gz initial, lazy pages 2–6 KB). Real DB work is 1–3 ms per call at today's data size. The heaviness comes from:
1. **No data cache.** Every page visit unmounts, shows a skeleton and refetches everything.
   - `useList` (`frontend/src/components/form.tsx:103-120`) has no cache, no abort and no race guard.
   - Other pages fetch inside effects: `employees.tsx`, `sites.tsx`, `settings.tsx`, `console.tsx`, `audit.tsx`.
2. **`key={pathname}` and `animate-fade-in` wrap the only Suspense** (`AppLayout.tsx:325-329`).
   - Every navigation remounts the page area and plays a 250 ms fade.
   - It also cancels the router's built-in transition.
   - Settings tabs remount, refetch and fade on every switch.
3. **The API answers one request at a time.**
   - It runs on `php artisan serve` (`docker-compose.yml:49`), not php-fpm, which is already in the image.
   - A slow report would block every other user.
   - 20 parallel requests: median 51 ms, slowest 101 ms, against 7 ms one at a time.
4. **Too many requests per page.** The employees page fires 5 extra count requests (`employees.tsx:91-99`). StrictMode doubles every request because nothing aborts it. Search has no debounce. `/users` and `/residences` are each fetched by 3–4 components.
5. **Per-request DB cost.** Each authenticated request runs 5 queries and 1 write before the controller:
   - a `last_activity_at` update on every request (0 HOT updates, `BearerSessionResolver.php:50`);
   - the user is loaded twice;
   - permissions are read without a cache (`AuthorizeAction.php:37`);
   - settings are read without a cache (`BusinessRules.php:17`).
6. **Missing indexes.**
   - 6 foreign keys have no index: `user_sessions.user_id`, `users.role_id`, `fares.site_id`, `manager_sites.site_id`, `employee_manager_assignments.manager_id`, `role_permissions.permission_id`.
   - Employee search runs `ILIKE '%…%'` with no trigram index.

## How the work runs
- This session is the controller: it plans, dispatches, reviews diffs, runs the gates and does the browser spot checks. Subagents write the code.
- Every brief tells the subagent to:
  - load `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and its superpowers skill: TDD for implementers; `requesting-code-review` and the SDD reviewer prompts for reviewers; `verification-before-completion` plus `built-in-browser` for browser checkers;
  - read `docs/coding-standards.md` (from Task 0 on).
- Models: **opus** for permission/session/cache logic, the data-layer design and every reviewer; **sonnet** for docs, the docker copy-over, page migrations and browser checks.
- Per-task loop (from the handoff): brief + BASE → implementer → Opus review ∥ Sonnet browser check (375/1024/1440, dark, English) → one fix round → re-review → ledger line in `.superpowers/sdd/perf-architecture/progress.md`.
- The commit trailer is `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Task 0 — Coding standards (sonnet, docs only)
- Create `docs/coding-standards.md` from `~/Projects/crm-system/docs/Coding_Standards_EN.md`, adapted to HRMS:
  - keep every generic rule;
  - swap each CRM-only item for its HRMS equivalent, or drop it:
    - quotations, FX and supplier cost → payroll periods, advances, overrides;
    - CRM optimistic-locking examples → HR edits of closed periods;
    - J-xx jobs → none yet;
    - the CRM module list → the HRMS modules;
    - `OpenAPI_Contract_EN.md` / `Design_System_EN.md` → `CONTEXT.md` and the frontend tokens.
- Precedence line: `CLAUDE.md` → `docs/hrms-module-plan.md` → `docs/calculations.md` → this standard.
- Add one bullet to the root `CLAUDE.md` docs list saying the standard is mandatory for all code and is read before writing code.
- Add one line to the owner memory rule so every future subagent brief includes it.
- Verify: the doc has no leftover CRM-only terms (grep for quotation, FX, supplier, Outdoor).

## Task 1 — Backend runtime like the CRM (sonnet implementer, opus reviewer)
Replace `php artisan serve` with the CRM's stack (`~/Projects/crm-system/docker-compose.yml`, `docker/nginx/default.conf`, `docker/php/zzz-production.ini`):
- An `nginx` service in front of the `app` service, which now runs php-fpm with `pm.max_children` sized for dev. nginx keeps port 8000, so the Vite proxy is unchanged.
- Queue workers in the CRM's priority split: `critical`, `pdf`, `reports`, `maintenance` (`queue:work redis --queue=<name> --tries=3`), plus a `scheduler` service (`schedule:work`).
  - The workers have nothing to run yet. They are the report path from day one and cost ~15 lines each.
- Out of scope: copying the CRM's TLS, LAN and Meilisearch parts.
- Verify:
  - `curl /api/v1/health` returns 200 through nginx;
  - 20 parallel calls give a median under 20 ms, against today's 51 ms;
  - every backend gate passes;
  - `docker compose ps` lists every worker as running.

## Task 2 — Backend hot path (opus implementer + opus reviewer, TDD)
- **Session touch:** `BearerSessionResolver` writes `last_activity_at` only when it is more than 60 s old.
  - Read the 60 s from a constant beside `IdleTimeout`. It is not a business rule.
  - Test: two requests within 60 s write once; idle expiry still works.
- **Permission cache:** `AuthorizeAction` reuses the user the guard already loaded.
  - Each role's permission list is held in `Cache::rememberForever("role-perms:{id}")`.
  - It is cleared in `RoleAdministration::syncPermissions`, on role delete and on a user role change.
  - Negative test: revoking a permission denies the very next request.
- **Settings cache:** `BusinessRules::all()` is cached and cleared in `update()`. Test: an update is visible on the next read.
- **Employee summary:** one `GET /api/v1/employees/summary` returns the counts by type in one grouped query, with the same scope as the list.
  - It replaces the 5 `per_page=1` calls.
  - Tests cover a manager's scope and a caller without permission.
- **One index migration:** the 6 foreign-key indexes, plus `pg_trgm` GIN indexes on `name_ar`, `name_en` and `national_id`.
  - Tested up and down.
  - Check with `EXPLAIN` that the scoped list and search use them.
- Verify: query log on `/auth/me` and the employees list drops from 5 queries + 1 write to ≤2 queries + 0 writes. All 4 backend gates pass.

## Task 3 — Frontend data layer (opus implementer + opus reviewer, TDD)
- Add `@tanstack/react-query` v5.
  - One `QueryClient` in `main.tsx`: `staleTime` 30 s, `gcTime` 5 min, no retry on 4xx.
- `api.ts`: `call()` accepts `signal`, which `queryFn` passes through.
- **`useList(path)`:** keep its return shape `{rows, total, err, loading, reload}` so the pages barely change, but build it on `useQuery`.
  - Query key: the path segments plus the query string.
  - `placeholderData: keepPreviousData`, so paging and filters don't flash.
  - `reload` = invalidate.
  - On error, clear the stale rows (fixes the audit deferred minor).
- **New hooks:** `useApi(path)` for single records, and `useSave()`, a mutation that invalidates by resource prefix (`['employees']`).
- **Security:** logout and any 401 call `queryClient.clear()`, so nothing cached survives into another account. Changing language invalidates everything.
- **`AppLayout.tsx`:** drop `key={pathname}` and the page-level `animate-fade-in`; keep Suspense.
- **Tabs:** drop the per-switch fade on tab content (`ui/tabs.tsx:29`).
- **`index.css`:** add a `prefers-reduced-motion` rule.
- Tests (vitest):
  - a second mount with the same path makes no new request within `staleTime`;
  - a save refetches the lists that show that resource;
  - logout clears the cache;
  - an out-of-order response never overwrites a newer one.

## Task 4 — Migrate pages to the data layer (sonnet implementer, opus reviewer)
- Replace each fetch inside an effect with `useList`/`useApi`/`useSave`:
  - `employees.tsx`, which now uses `/employees/summary`;
  - `sites.tsx`, `settings.tsx`, `console.tsx` and `audit.tsx`;
  - the `ManagerSelect` and residences lookups, which now share one cached query each.
- Debounce employee search by 250 ms with a few-line `useDebounced` (no library).
- Verify:
  - all frontend tests, the build and lint pass;
  - grep finds no `useEffect(` + `api(` fetch left in `src/pages`.

## Task 5 — Reports architecture ADR (opus, docs only)
`docs/adr/0003-reports-and-heavy-reads.md`:
- Reports and exports (PDF, Excel) run as queued jobs on the `reports` and `pdf` queues. They write a file to storage, and the UI polls a job status that the API contract defines.
- A **closed payroll period is read-only**, so its report results are snapshotted once at close and served as stored data. Only HR's reopen invalidates them.
- Summaries for open periods come from plain indexed queries first. A materialized view or summary table refreshed by the scheduler is added only when a measured query passes its budget.
- List endpoints stay paginated. The audit log moves to cursor pagination when its row count makes `COUNT(*)` measurably slow.
- No code changes in this task.
- Also note the deferred items in it: `APP_DEBUG=false`, `php artisan optimize`, `opcache.validate_timestamps=0` and `vendor` baked into the image for the production deploy.

## Gates (owner approval each)
- **Gate A (backend, after Tasks 0–2):** gate output, the before/after concurrency numbers, the per-request query counts.
- **Gate B (frontend, after Tasks 3–4):**
  - a browser walkthrough of accounts → roles → audit → employees → settings tabs → back;
  - proof that revisits render instantly with no skeleton;
  - network counts per page, before and after;
  - screenshots at 375/1024/1440, dark and English.
- **Then:** the ADR (Task 5); a final whole-branch Opus review over `2ecb5b8..HEAD` with one fix wave; an updated handoff.
- **Push and PR only on the owner's go:** the 1b PR first, then this one stacked on it. After that, back to Slice 4, Task 12.

## Verification summary (success criteria)
- Revisiting a page within 30 s shows its data with no skeleton and no new request.
- First visits show the old page until the new one is ready (no flash).
- Employees page: from ~12 requests per visit (dev) to ≤3.
- Backend: 20 parallel requests median < 20 ms; authenticated reads write nothing; permission checks hit the cache.
- All backend gates (test, pint, phpstan, deptrac) and frontend gates (vitest, build, lint) pass on the final commit.
