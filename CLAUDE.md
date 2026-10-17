# Smart Tech HRMS

HR + payroll system for Smart Technology (سمارت تكنولوجي للأنظمة المتكاملة), Alexandria, Egypt.
It replaces 6 Excel workbooks. Standalone now; a REST API for the company's Laravel CRM comes later.

- `backend/`: Laravel API + PostgreSQL. `frontend/`: React 19 + Vite + TS, forked from HRMS-DEMO.
- Each folder has its own CLAUDE.md with its commands.
- `CONTEXT.md`: the domain glossary. Use its terms in code, UI keys and docs.
- `docs/hrms-module-plan.md`: what ships in each of the 4 monthly releases, plus every settled decision. Read it before starting a module.
- `docs/excel-system-analysis.md`: the current payroll formulas, cell by cell, and the known Excel errors. Read it before touching attendance, payroll, advances or site costing.
- `docs/calculations.md`: every formula the system computes, with the open questions marked ⚠️. It is the spec for payroll, leave, insurance and site costing code; update it in the same PR as any formula change.
- `docs/client-questions.md`: questions still open with the client, by release. Check it before building a module; the client may change settled defaults during the build.
- `docs/adr/`: decisions that are hard to reverse (stack, wage model).
- `docs/templates/`: the client's 11 official forms. PDFs we generate must match these layouts.
- `docs/coding-standards.md`: the engineering standard for all code (adapted from the CRM). Read it before writing code; CLAUDE.md and the docs above win where they differ.

## Stack
Mirror the CRM (github.com/mr-h12/smart-technology-CRM, see `docs/adr/0001-mirror-crm-stack.md`): Laravel 13 / PHP 8.4, PostgreSQL 17, Redis queues, Docker; `/api/v1`, UUIDv7 ids, the CRM response envelope; modules `Domain/Application/Infrastructure/Presentation` enforced by deptrac; PHPStan; PHPUnit; the CRM's custom RBAC, audit log and browsershot PDF queue. Frontend keeps the demo's look (Tailwind 4, shadcn/Radix, tokens, fonts) and uses react-i18next; the demo's mock data and DOM-rewrite translator are not carried over.

## Users and permissions
- Accounts: Developer, CEO, HR, Manager (5 managers today). **Employees and workers have no accounts.**
- A manager acts on behalf of their own employees: field attendance, leave, requests, the "signed" mark. A manager sees only employees assigned to them. Each employee has exactly one manager at a time (office staff: their department manager); reassignment is dated.
- Office staff attendance comes from the fingerprint import and HR, not from managers.
- HR can view and edit everything managers enter. Every manager action notifies HR: instantly for requests, warnings and edits to past days; in a daily digest for routine attendance.
- Approval flow: manager → HR → (HR chooses) CEO. Escalation is HR's per-request decision, not a rule.
- Only the Developer role creates accounts and sets or changes passwords. There is no self-service password reset or change.
- Roles and permissions are data (CRM-style `resource.action.scope`), editable from the developer console. Check permissions by name, never by role name.

## Domain
Employee types, each with its own payroll cycle:

| Type | Arabic | Pay basis | Period |
|---|---|---|---|
| office | موظفين مثبتين شركة | monthly salary + fingerprint lateness | calendar month |
| engineer | مهندسين | monthly salary, daily site timesheet | 26th → 25th |
| permanent_worker | عمال مثبتين | monthly salary, daily site timesheet | 26th → 25th |
| day_laborer | عمال باليومية | the worker's daily wage + site allowance | 1–15, 16–end |

- **Site** (موقع): where a day is worked. Attendance records a site or a leave day-type per day. Leave day-types (Friday, official holiday, sick, absent غ) appear as their own rows in site costing.
- **Daily wage** lives on the day laborer, effective-dated (`docs/adr/0002-daily-wage-on-worker.md`). **Site allowance** is a rule on the site (a share of the wage or a fixed amount). **Fare** = residence × site default, with an optional per-worker override.
- An employee's type can change (monthly ↔ daily); it is the same employee with a dated type history starting at a period boundary.
- **Insured basic** (الأساسي التأميني): splits the payslip into insured basic + incentives (حوافز).
- **Advance** (سلفة): a ledger of amount, installments, repayments and balance. HR chooses one payment or installments for every advance, including those logged on a timesheet day.
- **Custody** (عهدة): items held by an employee (mobile line, devices). Clearance (إخلاء طرف) is blocked until every item is returned.

## Business rules
These numbers are **settings** seeded with the values below. Read them from settings and keep them out of code literals:
- Month = 30 days, day = 8 hours, overtime ×1.5.
- Lateness multiplier per type: office 1×, engineer 1.5×, workers 2×. Office grace period is 15 minutes.
- Office shift 09:00–17:00 (per-employee override). Weekly off per type: office Friday + Saturday, field Friday. Weekly off is paid for monthly staff.
- Leave: under 1 year of service on Jan 1 → 10 days, 1 year or more → 21 days, 6 of them casual (عارضة) in both cases. The leave year starts Jan 1; nothing carries over. Passing 1 year mid-year alerts HR, who decides. Day laborers have no leave balance.
- Social insurance (employee and company share) and income tax are **manual fields** HR types per employee per period. Blank means nothing is deducted.
- End-of-service bonus: the system suggests half a month per year of service on the last total wage; HR edits it.
- The system computes every amount; **HR can override any computed value** with a required reason. The override is highlighted, survives recomputation (with an "inputs changed" warning) and can be reverted.
- Payout: HR marks each employee "paid" and writes how the money was received. There are no bank files.

Payroll must reproduce the Excel results. The October 2026 totals (engineers net 315,451; day laborers net 391,948) are regression fixtures. Every difference from them must trace to a documented Excel error and appear in the Excel-difference report the client signs off in Release 2.

## Invariants
- Money: PostgreSQL `NUMERIC(18,6)` with bcmath decimal strings (as the CRM's `Precision`/`Decimal`). Never use floats. Do not round intermediate values (the Excel doesn't); display 2 decimals.
- Time zone `Africa/Cairo`; store UTC.
- Every create/update/delete, approval, override, login and period lock/reopen writes an audit-log entry with before/after values.
- A closed payroll period is read-only. Only HR can reopen it, and must give a reason.
- Historical data imported from Excel is kept as-is. Rows matching a known Excel error carry a visible warning flag; do not correct them.
- Every UI string goes through i18n keys in both Arabic (RTL, default) and English.
- Generated PDFs are Arabic, use embedded fonts, are auto-filled from the employee record (with masculine/feminine wording from gender), carry a per-form-type serial number, and are stamped with the stored HR/CEO signature image on approval.

## Working agreement
- Payroll, leave-balance, insurance and permission logic ships with tests (PHPUnit) before merge. A change to a payroll formula updates the October fixtures in the same PR, and the PR explains the difference.
- One branch per feature, a PR into `main`, and the other developer reviews it.
- Backups are not configured; they are manual until the owner confirms. Do not add backup automation unprompted.
- Out of scope for the 4 months: employee self-service, a WhatsApp API, a live fingerprint-device connection, CRM integration (an optional `crm_user_id` on the employee is allowed).
