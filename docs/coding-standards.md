# Smart Tech HRMS — Coding Standards

> **Status:** Mandatory engineering standard. Read it before writing code.
> **Scope:** Applies to all backend (Laravel), frontend (React), database, queue-worker, and infrastructure code.
> **Authority:** `CLAUDE.md` is the project source of truth. Where this file conflicts with it or with the documents ranked above it in §1, those win; this file wins over the rest.
> **Origin:** Adapted from the CRM's coding standard; the CRM-only parts (sales documents, currencies, procurement, field-sales flows) are replaced by HRMS equivalents or dropped.

## 1. Purpose and Rule Precedence

These standards make implementation safe, reviewable, testable, and consistent for a system that handles payroll money, permissions, audit history, and personal employee data.

Use this precedence order:

1. `CLAUDE.md` (business rules, invariants, working agreement).
2. `docs/hrms-module-plan.md` (what ships in each release, settled decisions).
3. `docs/calculations.md` (every formula, with open questions marked ⚠️).
4. This coding standard.
5. Everything else: `CONTEXT.md` (the glossary for terms), `docs/adr/` (recorded decisions), `docs/templates/` (the PDF layouts), `docs/excel-system-analysis.md`, `docs/client-questions.md`, and module-specific notes.
6. Tool, plugin, framework, or individual preference.

If a requirement is ambiguous, incomplete, or contradictory, stop and ask for a documented decision. Do not resolve it in code silently. A formula marked ⚠️ in `docs/calculations.md` is an open question, not permission to guess.

## 2. Working Discipline

- Restate each task as observable success criteria before changing code.
- For any change that spans more than one file, present `step → verification` and obtain approval before editing.
- Make the smallest coherent change. Do not mix unrelated refactoring, formatting, dependency upgrades, or dead-code cleanup into feature work.
- Read only the source sections relevant to the task, but re-read them before changing payroll, leave balances, advances, permissions, period locking, site costing, or generated PDFs.
- State assumptions explicitly. An unapproved assumption must be visible in the task notes and must not become a hidden business rule.
- One branch per feature, a PR into `main`, reviewed by the other developer.
- Do not mark work complete until the applicable checks have run and their actual results are reported.

## 3. Architecture and Module Boundaries

### 3.1 Layering

Dependencies are enforced by deptrac (`backend/deptrac.layers.yaml`). The allowed directions are:

```text
Presentation → Application → Domain
Application  → Infrastructure → Domain
Presentation, Application, Infrastructure → SharedContracts (App\Support)
Domain → nothing
```

| Layer | Responsibilities | Must not contain |
|---|---|---|
| Presentation | HTTP controllers, request parsing, response serialization, UI components, user interaction. | Business calculations, permission decisions, direct database queries. |
| Application | Use cases, transaction coordination, authorization invocation, orchestration of interfaces and events. | Framework-specific UI concerns, raw SQL scattered through use cases. |
| Domain | Entities, value objects, policies, state transitions, calculation rules, domain events. | HTTP, UI, framework (not even Illuminate), storage, queue, or vendor SDK calls. |
| Infrastructure | Database repositories, file storage, queue, PDF and other external-service adapters. | Unbounded business policy or permission logic. |
| SharedContracts (`App\Support`) | Small technical contracts used across modules: the response envelope, `Precision`/`Decimal` money helpers, database helpers. May depend only on the framework. | Business rules, module-specific logic. |

Domain depends on nothing, not even the framework or `App\Support`. That is the load-bearing rule, and it is why Domain code does money math with plain bcmath on decimal strings (§6). Presentation, Application, and Infrastructure may use SharedContracts. Changing the ruleset needs a deliberate, documented decision, never a quiet edit to make code compile.

### 3.2 Modules

Code lives under `backend/app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`. Today the modules are Audit, Employees, Identity, Settings, and Sites. Planned modules, in release order (`docs/hrms-module-plan.md`): attendance, leave, notifications, payroll (engines, period close, payout, payslip), advances, requests, documents (PDF templates), communication, date reminders, custody, onboarding/offboarding, talent (recruitment, performance, training), and reports (including site costing). Add a new module only when the plan names it.

- A module owns its domain rules, application use cases, persistence interface, and API surface.
- A module must not import another module's private tables or repositories to change its data.
- Cross-module work uses declared interfaces or domain events and has an explicit owner for the resulting transaction and audit behavior.
- Do not create a shared "utils" area for business logic. Place a rule in its owning module, or in `App\Support` when it is a clearly named technical contract (e.g. `Precision`/`Decimal`, the response envelope); it is not reachable from Domain.

## 4. Naming and Code Clarity

- Use the terms in `CONTEXT.md` in code, UI keys, and docs. Use `employee`, not `user` or `worker` as the general term; `period`, not `payroll run`; `override`, not `manual edit`; `leave day-type`, not `leave site`. Never introduce a synonym for a glossary term.
- Use the documented employee types and status values exactly (`office`, `engineer`, `permanent_worker`, `day_laborer`). Do not create framework enums that drift from managed settings or database lists.
- Name boolean fields as a question or state: `is_active`, `is_overridden`, `has_open_custody`.
- Name time fields precisely: `created_at`, `updated_at`, `effective_from`, `period_start`, `closed_at`. Store timestamps in UTC; the business time zone is `Africa/Cairo`.
- Name money fields by meaning: `daily_wage`, `insured_basic`, `incentives`, `site_allowance`, `fare`, `day_cost`, `net_pay`, `advance_balance`. Keep company share and employee share of insurance separate.
- Keep functions focused on one use case or policy. Prefer explicit names over comments explaining vague names.
- Comments explain non-obvious constraints, decisions, security boundaries, or references to ADRs, `docs/calculations.md` sections, and the Excel error being reproduced or fixed. They do not narrate obvious syntax.

## 5. Types, Validation, and Data Integrity

- Use strict typing (`declare(strict_types=1)` in PHP, `strict` in TypeScript). Do not introduce untyped escape hatches (`any`, unchecked casts), silent coercion, or implicit nullable values.
- Validate every untrusted value at the backend boundary: request body, query, header, path parameter, file metadata, queue payload, environment configuration, external-adapter responses, and imported file rows (fingerprint export, Excel migration).
- Validate format and business rules separately: format validation produces field errors; domain validation produces a stable business-rule error code.
- Enforce invariants in the database as well as in application code: foreign keys, check constraints, unique constraints, required fields, and indexes. Dated histories (employee type, daily wage, manager assignment, shift) must not overlap; enforce it in the database.
- Treat database constraints as the final authority under concurrent requests. Convert known constraint failures to safe contract errors; never expose raw database messages.
- Business numbers (month = 30 days, day = 8 hours, overtime ×1.5, lateness multipliers, grace period, shifts, leave entitlements) are **settings**. Read them from settings; never use literals in domain code or UI.
- Use reference tables (managed lists) for extendable values such as leave day-types, sites, departments, and residences.
- Never hard-code permissions, employee types' pay rules, sites, leave day-types, or any managed list in UI or domain conditionals. Check permissions by name (`resource.action.scope`), never by role name.

## 6. Money, Time, and Calculations

- **Float is forbidden for money.** Store as PostgreSQL `NUMERIC(18,6)`; compute in PHP with bcmath on decimal strings. Domain code calls bcmath directly (deptrac forbids it from using `App\Support`) and passes scale 6 to every call (bcmath defaults to scale 0, which truncates); Application and Infrastructure use the shared `Precision`/`Decimal` helpers to cast, validate, and persist amounts.
- Serialize money as decimal strings at the API boundary. The frontend never parses them into floats for arithmetic.
- Perform all calculations in the backend domain layer. The frontend may show a server-confirmed result but never becomes the source of truth.
- **Do not round intermediate values** (the Excel does not). Display 2 decimals; round only where `docs/calculations.md` says so.
- Apply the documented formula order exactly (`docs/calculations.md`). Payroll must reproduce the Excel results; the October 2026 totals (engineers net 315,451; day laborers net 391,948) are regression fixtures. Every difference from them must trace to a documented Excel error and appear in the Excel-difference report.
- Insurance (employee and company share) and income tax are **manual HR fields** per employee per period. Blank means nothing is deducted. Do not compute them.
- **HR override.** HR can override any computed value, with a required reason. An override is stored separately from the computed value, highlighted in the UI, survives recomputation (with an "inputs changed" warning), can be reverted, and is audited with before/after values. Never overwrite the computed value in place.
- Never recompute a past period with a newer effective-dated daily wage, setting, or site allowance; use the value effective on that day (`docs/adr/0002-daily-wage-on-worker.md`).
- Historical data imported from Excel is kept as-is. Rows matching a known Excel error carry a visible warning flag; do not correct them.
- A formula change updates `docs/calculations.md` and the October fixtures in the same PR, and the PR explains the difference.
- Keep computed amount, override, advance repayment, and net pay semantically separate in code, schema, responses, and UI models. A day's cost is day rate plus that day's extras, summed per site for site costing; leave day-types are their own rows.
- Date and period math (26th → 25th, half months, calendar months, Jan 1 leave year, service length) uses `Africa/Cairo` calendar dates, not UTC timestamps, and is covered by boundary tests.
- Create focused unit tests for every payroll formula, boundary (period edges, grace period, 1-year service threshold), multiplier, advance installment rule, and override rule before changing calculation code.

## 7. Database and Migration Standards

- All schema changes use a new, versioned, managed migration with tested `up` and `down` paths.
- Never hand-edit a shared or production schema. Never alter a migration that has already been applied anywhere shared; add a correcting migration.
- Every business table has a UUIDv7 primary key, foreign keys, and audit columns as required by the audit design.
- Add indexes for each new join, permission scope (manager → assigned employees), filter, sort, and common lookup (employee × date, period × type). Verify them with realistic query plans for high-volume paths such as attendance and payroll.
- Use transactions for every operation touching more than one table: record mutation plus audit entry, period close, advance creation plus its schedule, type or manager reassignment, and file metadata attachment.
- Do not physically `DELETE` employees, attendance, payroll, advances, audit records, or files. Use documented deactivation, termination, or soft-delete behavior.
- **A closed payroll period is read-only.** Every write path (attendance edit, advance repayment, override, import) must check period state inside the same transaction. Only HR can reopen, and must give a reason; close and reopen are audited. A write against a closed period fails with a stable error code, never silently.
- Use optimistic locking (version column) on records that HR and managers can edit concurrently, such as attendance days, timesheets, and requests. A stale write returns `409 concurrency_conflict`; it never silently overwrites current data.
- Seed data is versioned and repeatable: roles, permissions, settings defaults, leave day-types, and test users must be reproducible in non-production environments.
- Backups are manual until the owner confirms. Do not add backup automation unprompted.

## 8. API Standards

- Follow the CRM conventions recorded in `docs/adr/0001-mirror-crm-stack.md`: `/api/v1`, the CRM JSON response envelope, UUIDv7 ids, stable error codes, and the shared pagination, filter, search, and sort parameters.
- Keep controllers thin: parse and validate, invoke an application use case, then serialize the approved result.
- Paginate every collection. Never implement a "return all" endpoint for business data.
- Apply authorization before data retrieval or mutation; query filters may narrow scope but never enlarge it.
- Use stable machine-readable error codes. Localize user-facing messages by request language; clients never parse error text.
- Require idempotency for creates and actions where a retry would duplicate money or history (payout marks, advance creation, period close, imports), and preserve the original response for valid replays.
- Keep API documentation, request validators, response serializers, and tests aligned. A route is incomplete if it is undocumented; the API will be consumed by the company's CRM later.

## 9. Authorization, Security, and Privacy

- Enforce dynamic RBAC at API and row level. Hiding a button is never access control.
- Employees and workers have no accounts. Accounts are Developer, CEO, HR, and Manager. Only the Developer role creates accounts and sets or changes passwords; there is no self-service reset or change.
- A manager sees and acts on only the employees assigned to them (dated assignment). Test this on every endpoint that returns or mutates employee-scoped data, including nested resources, downloads, exports, and notifications.
- Authorize each action on the target record and its current state, including nested resources, downloads, bulk actions, exports, and async job requests (for example, nothing is editable in a closed period; clearance is blocked while custody is open).
- Test a negative case for every permission-sensitive operation: a caller without access must be unable to read, infer, mutate, export, or download the data.
- Salary, national ID, insurance number, and bank account are sensitive. Expose them only to permissions that need them, and keep them out of logs, notifications, error messages, and manager-facing views unless a rule says otherwise.
- Keep secrets outside source code and logs. Never expose credentials, session tokens, stack traces, SQL, or internal paths in responses.
- Apply rate limiting to login and API endpoints, plus lockout on login; validate input; protect against injection, XSS, and CSRF; use HTTPS even on internal networks.
- For uploaded files (scanned signed forms, signature images, imports), validate true MIME type, size, parent-entity permission, and virus-scan result before making them available. Store outside the web root with UUID storage names.
- Generated PDFs must be built from an explicit data model for that form, so fields the reader may not see (for example other employees' pay, internal HR notes) cannot leak into it.

## 10. Audit, Logging, and Events

- Audit logs are append-only. Clients never write or alter them directly.
- Every create, update, delete, approval, override, login, and period lock or reopen writes an audit entry with the actor, event, entity, before and after values, timestamp, IP, device, request ID, and correlation ID where available.
- Always audit the critical events: overrides (with their reason), period close and reopen (with the reopen reason), role or permission change, account creation and password set, employee type change, manager reassignment, payout marks, advance changes, and edits to past days.
- Every manager action notifies HR: instantly for requests, warnings, and edits to past days; in a daily digest for routine attendance.
- Emit domain events after a successful transaction for cross-module effects (leave balance recalculation, notifications, date reminders).
- Events and jobs must be idempotent; consumers must tolerate delivery retries without duplicate data, notifications, or audit entries.
- Use structured logs with correlation IDs. Log failure context safely; never log secrets or personal data beyond what is needed. Retention follows the plan (audit 1 year, request and error logs 90 days).

## 11. Frontend, i18n, and Design Tokens

- Use the Tailwind 4 tokens, shadcn/Radix components, and fonts in `frontend/src/index.css` and `frontend/src/components`. Do not hard-code colors, spacing, radii, shadows, direction, or user-facing strings in feature code.
- Every UI string goes through react-i18next keys in both Arabic and English (`frontend/src/locales/ar.json`, `en.json`). Arabic (RTL) is the default. Both files must stay in sync (the locale test enforces this). Use the glossary terms in `CONTEXT.md` for the Arabic and English wording.
- RTL/LTR is controlled by locale, not by duplicated screens. Use logical layout properties (start/end) instead of left/right. Preserve numeric, date, money, and table reading order in RTL.
- Build loading, empty, validation, error, disabled, permission-denied, and connection-unavailable states for every screen where relevant.
- Do not compute money, permission, status-transition, or audit decisions in the client. Display backend results and use client state only for safe presentation.
- Manager screens (attendance, leave, requests) are mobile-first, touch-friendly, and minimal-typing. Preserve an unsent draft across a brief connectivity loss without presenting the system as offline-capable.
- Show overrides, "inputs changed" warnings, and Excel-error warning flags visibly and consistently; they are information HR must not miss.
- Maintain accessible keyboard behavior, visible focus, semantic labels, error associations, and contrast in light and dark themes.

## 12. Queues, Jobs, PDF, and External Adapters

- Run background work on Redis queues, separated by purpose (for example `critical`, `pdf`, `reports`, `maintenance`).
- Every job declares its input schema, idempotency strategy, retry limit, failure handling, audit and log behavior, and observable status. No scheduled jobs are defined yet; when the plan adds one (daily digest, date reminders), document it before building it.
- Generate PDFs asynchronously through the browsershot queue, in Arabic with embedded fonts. They must match the client's layouts in `docs/templates/`, be auto-filled from the employee record with masculine or feminine wording from gender, carry a per-form-type serial number, and be stamped with the stored HR or CEO signature image on approval. Store each generated form as an immutable snapshot with its serial number. Retry failures and expose readiness through the API.
- Place mail, storage, PDF, fingerprint-file parsing, and every deferred integration behind an interface or adapter. An external outage must fail safely without making the HRMS unavailable.
- Do not add out-of-scope functionality (employee self-service, a WhatsApp API, a live fingerprint-device connection, CRM integration beyond the optional `crm_user_id`) unless a new approved decision expands scope.

## 13. Testing and Verification

### 13.1 Required test levels

| Level | Required for |
|---|---|
| Unit | Payroll and leave formulas, period math, override rules, state policies, validation, permission policies, value objects. |
| Integration | Database constraints, migrations, repositories, API authorization, transactions, audit writes, queues, and module workflows. |
| End-to-end | Login, role and manager-scope boundaries, attendance to payroll to period close, request approval flow, payslip PDF, and restart recovery. |
| Security/negative | Every permission boundary, validation failure, file rejection, rate limit, write to a closed period, and stale concurrent update. |

### 13.2 Test rules

- Payroll, leave-balance, insurance, and permission logic ships with PHPUnit tests before merge. A money, permission, state-transition, versioning, or multi-table change has no exception from testing.
- A payroll formula change updates the October fixtures in the same PR, and the PR explains the difference.
- For bug fixes, add a regression test that fails before the fix whenever practical.
- Tests use deterministic dates (fixed `Africa/Cairo` clock), decimal strings, and test identities. They must not depend on current time, external services, production data, or test execution order.
- Test both allowed and denied role paths. A passing happy path does not prove authorization.
- Run relevant checks continuously while working; before handoff, run the full applicable check set: PHPStan, deptrac, PHPUnit on the backend; type check, lint, tests, and build on the frontend. The per-folder `CLAUDE.md` lists the commands.

## 14. Code Review and Definition of Done

Every change is ready for review only when it answers:

1. Which requirement, settled decision, formula in `docs/calculations.md`, or module-plan item does this implement?
2. Which roles can read and act on the changed resource, and how is that enforced at API and row level (including manager scope)?
3. Which tables, migrations, constraints, indexes, transactions, audit entries, and events are changed?
4. Which test proves the happy path, the negative permission path, the validation or error path, the closed-period path, and the concurrency path where applicable?
5. Which user-facing states exist in Arabic and English, RTL and LTR, and in light and dark themes where applicable?

Do not hand off work until:

- The diff is scoped to the task and contains no unrelated noise.
- Migrations run and reverse cleanly.
- Types, lint, relevant tests, build, and smoke checks pass.
- API documentation and any affected source documents (`docs/calculations.md`, `CONTEXT.md`, ADRs) are updated.
- Audit entries, permissions, pagination, validation, i18n, accessibility, and performance implications have been checked.
- The handoff reports actual commands and results, and a concise manual test checklist.

## 15. Prohibited Patterns

- Floating-point money, intermediate rounding, or client-owned financial calculations.
- Hard-coded business numbers, permissions, role-name checks, business enums, user-facing strings, theme values, or regional assumptions.
- Computing social insurance or income tax; they are manual HR fields.
- Overwriting a computed value with an override in place, or an override without a reason.
- Any write to a closed payroll period, or a reopen without a reason.
- Correcting imported historical Excel data instead of flagging it.
- UI-only authorization, missing negative authorization tests, manager access beyond assigned employees, or client-accessible service credentials.
- Physical deletion, or mutation of audit history.
- Unbounded list queries, client-side filtering used as security, or unpaginated exports that bypass permissions.
- Direct cross-module table writes, business logic hidden in generic helpers, or integrations called directly from domain code.
- Silent conflict resolution, silent loss of user input, or automatically overwriting a stale record.
- Out-of-scope features, external integrations, or framework changes introduced without an approved decision.
- Backup automation added without the owner's confirmation.
