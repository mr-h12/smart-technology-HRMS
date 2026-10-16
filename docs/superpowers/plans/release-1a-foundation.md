# Release 1a — scaffold, employees, sites & rates

Spec: `CLAUDE.md`, `CONTEXT.md`, `docs/calculations.md`, `docs/hrms-module-plan.md` (Release 1), `docs/adr/0001-mirror-crm-stack.md`, `docs/adr/0002-daily-wage-on-worker.md`.
CRM reference clone (read-only, never run): `/private/tmp/claude-501/-Users-yousefhasabo-HRMS-Smart-TECH/028b0409-a8b3-41fd-b374-7738bf360d1b/scratchpad/ref/crm/crm`.
Demo reference clone (read-only): `/private/tmp/claude-501/-Users-yousefhasabo-HRMS-Smart-TECH/028b0409-a8b3-41fd-b374-7738bf360d1b/scratchpad/ref/demo`.

Out of this plan (next plan, Release 1b): accounts/login, RBAC, audit log, manager ↔ employee and manager ↔ site assignment, attendance, leave, notifications, migration import.

## Global Constraints
- No PHP on the host. Every PHP / composer / artisan / test command runs in Docker (`docker compose run --rm app ...`).
- Laravel 13, PHP 8.4, PostgreSQL 17.5, Redis 7.4. Tests run on PostgreSQL, never SQLite.
- PHPUnit 12 (not Pest). Pint. PHPStan (larastan) level 8. deptrac layer rules.
- Module layout `backend/app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`. Domain imports nothing from `Illuminate\*`. Eloquent models live in `Infrastructure/Eloquent/`. Application may use Infrastructure models directly (no repository interfaces with one implementation).
- Every business table uses the CRM `standardColumns()` macro (UUIDv7 id, created_by/updated_by, timestampsTz, softDeletesTz). Money columns use the `money()` macro (`NUMERIC(18,6)`); percentages `percentage()` (`NUMERIC(6,3)`). Never floats; arithmetic with bcmath on decimal strings. No intermediate rounding.
- API under `/api/v1`, responses via `ApiEnvelope` (`{data, meta.request_id}`; errors `{error:{code,message,details}, meta}`); lists paginated with the CRM pagination block. Validation errors → 422 in the error envelope.
- Effective-dated values: a row with `effective_from` (date); the value in force on date D is the row with the greatest `effective_from <= D`.
- Every UI string through i18n keys in `ar.json` and `en.json`; Arabic default, RTL.
- Ponytail: no speculative abstraction, fewest files. Comments only where the why is non-obvious (do not copy the CRM's long docblocks).

### Task 1: Backend scaffold in Docker

Create `backend/` as a fresh Laravel 13 app and the Docker stack at the repo root.

- `docker-compose.yml` (repo root): `postgres` (postgres:17.5-bookworm, UTF8 + `C.UTF-8` locale, TZ/PGTZ UTC, healthcheck, port bound to 127.0.0.1), `redis` (redis:7.4-alpine, requirepass), `app` (built from `docker/php/Dockerfile`, mounts `./backend` to `/var/www/html`, depends on healthy postgres + redis). Secrets come from a root `.env` (git-ignored) with a committed `.env.example`; required vars use `${VAR:?}`.
- `docker/php/Dockerfile`: `php:8.4-fpm-bookworm` + extensions `pdo_pgsql`, `bcmath`, `intl`, `zip`, `pcntl`, phpredis; composer copied from `composer:2`.
- Create the Laravel project with composer inside a container (`laravel/laravel` `^13`). Remove anything SQLite-related; `backend/.env.example` uses `pgsql`, `APP_LOCALE=ar`, `APP_FALLBACK_LOCALE=en`, `APP_TIMEZONE=UTC`, Redis for cache/queue/session.
- Dev deps: `larastan/larastan`, `laravel/pint`, `qossmic/deptrac`, `phpunit/phpunit ^12`. Remove Pest if the skeleton brings it.
- Copy from the CRM, trimming docblocks to one or two lines each:
  - `app/Support/Database/Precision.php` (MONEY 18,6; PERCENT 6,3; QUANTITY 14,4 and their CAST_ constants; drop FX).
  - `app/Support/Database/StandardColumns.php` with macros `standardId`, `standardAudit`, `standardColumns`, `money`, `percentage`, `quantity` only; registered in `AppServiceProvider::boot`.
  - `app/Support/Database/HasStandardColumns.php` (HasUuids + SoftDeletes).
  - `app/Support/Money/Decimal.php` (the CRM `Decimal::of` / `positive` validator).
  - `app/Support/Http/ApiEnvelope.php` (`single`, `collection`, `error`).
  - A request-id middleware (`app/Http/Middleware/AddRequestId.php`, header `X-Request-Id`, stored in request attribute `request_id`).
- `bootstrap/app.php`: API routes at prefix `api/v1`; AddRequestId on the api group; exceptions rendered with ApiEnvelope for `ValidationException` (422, `validation_failed`, details per field), `ModelNotFoundException`/`NotFoundHttpException` (404, `not_found`), and anything else on `/api/*` (500, `server_error`, no stack trace unless `APP_DEBUG`).
- `GET /api/v1/health` → `{data:{status:"ok"}}`.
- `phpunit.xml`: Unit + Feature suites, `DB_CONNECTION=pgsql`, database `hrms_test` (create it via a `docker/postgres/init` script that runs on first boot).
- `phpstan.neon` (larastan, level 8, paths `app`), `pint.json` (laravel preset), `deptrac.layers.yaml` with layers Presentation, Application, Domain, Infrastructure, SharedContracts (`App\Support\*`), Framework; ruleset: Presentation → Application, Domain, Framework, SharedContracts; Application → Domain, Infrastructure, Framework, SharedContracts; Infrastructure → Domain, Framework, SharedContracts; Domain → nothing.
- `backend/CLAUDE.md`: the exact commands to boot, migrate, test, lint, analyse.
- Tests: Feature test for `/api/v1/health` (envelope + request id header), Feature test that an unknown `/api/v1/x` returns the 404 error envelope, Unit test for `Decimal::of` (accepts `12.5`, `-3`; rejects `1e5`, ` 1`, `1.`), a migration test proving `money()` creates `numeric(18,6)` (query `information_schema.columns`).
- Done when: `docker compose up -d`, migrations, `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml` all pass inside the container.

### Task 2: Frontend scaffold from the demo

Create `frontend/` (React 19 + Vite + TS) from the demo's look, without its data.

- Copy from the demo: `package.json` deps that the kept code needs, `vite.config.ts`, `tsconfig*.json`, `index.html`, `src/index.css` (tokens, light + dark), self-hosted fonts in `public/`, `src/components/ui/*` (shadcn/Radix), and the layout shell (sidebar + navbar) rewritten to static nav.
- Do NOT copy: `src/data/*`, `src/store/*`, `src/i18n/*` (DOM-rewrite translator), role switcher, any page.
- i18n: `react-i18next` + `i18next`; `src/locales/ar.json` and `src/locales/en.json`; default `ar`; on language change set `<html dir>` and `lang` and Radix `DirectionProvider`; choice kept in localStorage `hrms-lang` (wrapped in try/catch).
- `src/lib/api.ts`: `fetch` wrapper for `/api/v1` that unwraps `{data}` and throws an `ApiError` carrying `error.code`, `message`, `details`. Vite dev server proxies `/api` to `http://localhost:8000`.
- Routes (react-router 7): `/` redirects to `/employees`; `/employees` and `/sites` render an empty page with a translated title (filled by Tasks 5 and 6); 404 page.
- Vitest: one test that `ar.json` and `en.json` have exactly the same key set; one test for `api.ts` unwrapping data and throwing `ApiError` on an error envelope (mock `fetch`).
- `oxlint` from the demo kept; `npm run build`, `npm run test`, `npm run lint` pass.
- `frontend/CLAUDE.md`: commands.
- Add `.superpowers/`-style ignores for `node_modules`, `dist` in a `frontend/.gitignore`.

### Task 3: Employees module (backend)

Module `app/Modules/Employees`. Terms from `CONTEXT.md`.

Tables (all `standardColumns()`):
- `employees`: `code` (unsigned integer, unique, allocated as max+1 inside the insert transaction with a lock on the table — `ponytail:` comment naming the ceiling), `name_ar` (required), `name_en` (nullable), `fingerprint_name` (nullable, unique where not null), `gender` (`male|female`), `national_id` (nullable, unique where not null, 14 digits), `insurance_no` (nullable), `phone` (nullable), `address` (nullable), `department` (nullable string), `residence_id` (nullable uuid; FK added in Task 4), `pay_method` (`bank|cash`, default `cash`), `bank_account` (nullable; required when `pay_method=bank`), `hire_date` (nullable date), `contract_end_date` (nullable date), `termination_date` (nullable date), `termination_reason` (nullable text), `hr_notes` (nullable text), `crm_user_id` (nullable uuid).
- `employee_type_changes`: `employee_id` FK, `type` (`office|engineer|permanent_worker|day_laborer`), `effective_from` date; unique (`employee_id`, `effective_from`).
- `employee_pay_changes`: `employee_id` FK, `effective_from` date, `salary` money nullable, `raise` money default 0, `insured_basic` money nullable, `daily_wage` money nullable, `shift_start` time nullable, `shift_end` time nullable; unique (`employee_id`, `effective_from`). Monthly types require `salary`; `day_laborer` requires `daily_wage` (validated against the type in force on `effective_from`).
- `employee_documents`: `employee_id` FK, `kind` (`national_id_copy|work_stub|criminal_record|qualification|birth_certificate|insurance_form_6|photos|military_status|other`), `received_at` nullable date, `expires_at` nullable date, `note` nullable.

Domain (pure PHP):
- `EmployeeType` and `Gender`, `PayMethod`, `DocumentKind` backed enums.
- `EffectiveDated::at(list<array{effective_from: string}> $rows, string $date): ?array` — the row in force on a date (greatest `effective_from <= date`). Unit-tested: before first row → null; exact date; between rows; unordered input.

API (`/api/v1/employees`):
- `GET /employees?type=&search=&page=&per_page=` — paginated; `search` matches `name_ar`, `name_en`, `code`, `national_id`; `type` filters by the type in force today (Africa/Cairo date).
- `POST /employees` — creates the employee plus its first type change and first pay change in one transaction (`type`, `effective_from`, and the pay fields in the body).
- `GET /employees/{id}` — includes current type, current pay, full type and pay history, documents.
- `PATCH /employees/{id}` — identity/placement fields only (not type/pay).
- `DELETE /employees/{id}` — soft delete.
- `POST /employees/{id}/type-changes`, `POST /employees/{id}/pay-changes` — append a dated row; 422 on duplicate date.
- `PUT /employees/{id}/documents/{kind}` — upsert one checklist row.
- Route ids constrained to UUID.

Tests (Feature, pgsql): create → code 1 then 2; validation (national_id 14 digits, bank_account required for bank, day_laborer needs daily_wage, office needs salary); list filter by current type after a dated type change; search by Arabic name; show returns pay in force today with a future-dated change ignored; duplicate effective_from → 422; soft-deleted employee → 404; money returned as decimal strings with no float drift (`"9000.000000"` round-trips exactly).

### Task 4: Sites, residences, fares and allowance rules (backend)

Module `app/Modules/Sites`.

Tables (all `standardColumns()`):
- `sites`: `name_ar` (unique, trimmed, internal double spaces collapsed before save), `name_en` nullable, `kind` (`work|leave`), `leave_code` nullable (`friday|official_holiday|sick|rest|absent|other`; required when `kind=leave`, null otherwise), `outside_alexandria` bool default false, `active` bool default true.
- `site_allowance_rules`: `site_id` FK, `effective_from` date, `mode` (`none|share_of_wage|fixed`), `value` (`NUMERIC(18,6)` via `money()`, used as a multiplier for share_of_wage, as an amount for fixed); unique (`site_id`, `effective_from`).
- `residences`: `name_ar` unique (same normalisation).
- `fares`: `residence_id` FK, `site_id` FK, `effective_from`, `amount` money; unique (`residence_id`, `site_id`, `effective_from`).
- `employee_fare_overrides`: `employee_id` FK, `site_id` FK, `effective_from`, `amount` money; unique (`employee_id`, `site_id`, `effective_from`).
- Add the FK `employees.residence_id → residences.id` (nullOnDelete) in a new migration.

Domain (pure PHP, bcmath, unit-tested):
- `Allowance::for(string $mode, string $value, string $dailyWage): string` — none → `0`, share_of_wage → wage × value, fixed → value. Tests: Alex Apparel 330 × 0.25 = 82.5; Nestlé Sharm 330 × 2 = 660; fixed 50; none 0. Scale 6, no rounding.
- `Fare::resolve(?string $override, ?string $default): string` — override wins, else default, else `0`. (Selecting the rows in force uses `EffectiveDated::at` from Task 3.)
- `Fare::forDay(string $fare, string $portion): string` — `full` → fare, `half` → fare ÷ 2, `none` → 0.

Application: `FareLookup::for(employeeId, siteId, date)` returning the resolved fare string (query override in force, else residence × site fare in force).

API (`/api/v1`): CRUD (list/create/show/update/soft delete) for `sites` (with `kind` filter and `active` filter) and `residences`; `POST /sites/{id}/allowance-rules`; `GET|POST /fares` (list filter by residence/site; create dated row); `POST /employees/{id}/fare-overrides`; `GET /employees/{id}/fare?site_id=&date=` → `{data:{amount, source: "override"|"default"|"none"}}`.

Seeder (`SitesSeeder`, idempotent with `firstOrCreate`): leave sites اجازة الجمعة (friday), أجازة رسمية (official_holiday), اجازة مرضي (sick), راحة (rest), غ (absent); residences كفر الدوار، الاسكندرية، العامرية، دمنهور.

Tests: unit tests above; feature: name normalisation makes `"سموحة  الدولي "` collide with `"سموحة الدولي"` (422); leave site requires leave_code; fare lookup override vs default vs none, and a future-dated fare ignored; seeder run twice creates no duplicates.

### Task 5: Employees screens (frontend)

- `/employees`: table (code, name, current type, department, pay method) with search box and type filter; pagination; "add employee" button.
- `/employees/new` and `/employees/:id`: form with identity, placement, pay sections; type + effective date + pay fields on create; on the detail page, history tables for type changes and pay changes with an "add change" dialog, and the documents checklist (received/expiry per kind).
- Server validation errors (`error.details[].field`) shown under the matching field.
- Money shown with 2 decimals (`Intl.NumberFormat`, locale from i18n), sent as strings exactly as typed.
- All labels in `ar.json`/`en.json`; enums translated.
- Vitest + Testing Library: list renders rows from a mocked API; form shows a server field error under its field.

### Task 6: Sites & fares screens (frontend)

- `/sites`: list with kind/active filters, create/edit dialog (leave_code shown only for leave kind), allowance-rule history with add dialog.
- `/residences`: simple list + create/edit.
- `/fares`: grid residences × work sites showing the fare in force today; clicking a cell adds a dated fare.
- Nav entries for sites, residences, fares.
- All labels in both locales. Vitest: fares grid places amounts in the right cell from a mocked API.
