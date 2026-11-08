# Smart Tech HRMS

HR + payroll system for Smart Technology (سمارت تكنولوجي للأنظمة المتكاملة), Alexandria, Egypt.
It replaces 6 Excel workbooks. Standalone now; a REST API for the company's Laravel CRM comes later.

- `backend/`: Laravel API + PostgreSQL. `frontend/`: React 19 + Vite + TS, forked from HRMS-DEMO.
- Each folder has its own CLAUDE.md with its commands.
- `CONTEXT.md`: the domain glossary. Use its terms in code, UI keys and docs.
- `docs/hrms-module-plan.md`: what ships in each of the 4 monthly releases, plus every settled decision. Read it before starting a module. Its last two sections are the client's revision 1 (gap analysis, draft) and the module ownership plan (proposal): check who owns a module and its shared files before editing them.
- `docs/excel-system-analysis.md`: the current payroll formulas, cell by cell, and the known Excel errors. Read it before touching attendance, payroll, advances or site costing.
- `docs/calculations.md`: every formula the system computes, with the open questions marked ⚠️. It is the spec for payroll, leave, insurance and site costing code; update it in the same PR as any formula change.
- `docs/client-questions.md`: questions still open with the client, by release. Check it before building a module; the client may change settled defaults during the build.
- `docs/adr/`: decisions that are hard to reverse (stack, wage model).
- `docs/templates/`: the client's 11 official forms. PDFs we generate must match these layouts.
- `docs/coding-standards.md`: the mandatory engineering standard for all code (adapted from the CRM). Read it before writing code. Where docs differ, the first in this order wins: CLAUDE.md, `docs/hrms-module-plan.md`, `docs/calculations.md`, this standard, then everything else (CONTEXT.md is the glossary for terms, the ADRs record decisions, the templates are the PDF layouts); see its §1.

## Stack
Mirror the CRM (github.com/mr-h12/smart-technology-CRM, see `docs/adr/0001-mirror-crm-stack.md`): Laravel 13 / PHP 8.4, PostgreSQL 17, Redis queues, Docker; `/api/v1`, UUIDv7 ids, the CRM response envelope; modules `Domain/Application/Infrastructure/Presentation` enforced by deptrac; PHPStan; PHPUnit; the CRM's custom RBAC, audit log and browsershot PDF queue. Frontend keeps the demo's look (Tailwind 4, shadcn/Radix, tokens, fonts) and uses react-i18next; the demo's mock data and DOM-rewrite translator are not carried over.

## Users and permissions
- Accounts: Developer, CEO, HR, Site manager (مدير الموقع; role slug `manager`, 5 today). **Most employees have no account.** A site manager can also be an employee: HR records and reviews their attendance like anyone else's, and their account permissions stay separate from their pay (the account-to-employee link is not built yet).
- Site manager (مدير الموقع) is the domain and UI term (owner, 2026-11-04, replacing Team lead / مسؤول التيم from 2026-11-03); a site may have more than one; code, permissions and existing assignments keep `manager`. A site manager never sees or sets wages; HR owns wages and rates. A manager acts on behalf of their own employees: field attendance, leave, requests, the "signed" mark. A manager covers one or more sites and reaches, on each date, the workers whose home site is one of their sites and the engineers assigned to them. A worker has one dated home site; only HR moves it. Engineers have one dated manager and work at any of that manager's sites. Office staff keep a dated department manager for reference; HR enters their attendance, leave and requests. Since 2026-11-08 office staff may also hold an optional dated site that HR sets: it only lists them at that site in HR's attendance filter and gives no manager reach, pay, fare or site costing.
- Office staff attendance comes from the fingerprint import and HR, not from managers.
- HR can view and edit everything managers enter. Every manager action notifies HR: instantly for requests, warnings and edits to past days; in a daily digest for routine attendance.
- Approval flow: manager → HR → (HR chooses) CEO. Escalation is HR's per-request decision, not a rule.
- Only the Developer role creates accounts and sets or changes passwords. There is no self-service password reset or change. ⚠️ The owner said on 2026-11-03 that HR handles accounts; HR holds `user.view` but not `user.manage` today. Unchanged until R22 in `docs/client-questions.md` is answered.
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
- Lateness (decided 2026-11-03, `docs/calculations.md` §0a): a 15-minute grace a day for every type, then chargeable minutes × wage ÷ divisor × **the multiplier of the employee's type** (office 1, engineers 1.5, permanent workers 2, day laborers 2; per type since 2026-11-06, R27). Divisor 14,400 for monthly staff, 480 for day laborers.
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

## Claude workflow (agreed 2026-10-31)
Both developers use the same skills, committed to `.claude/skills/` so that every account and machine loads the same set. (They are added in a follow-up PR, after PR #4 merges, because PR #4 changes `.gitignore` to allow that folder.)
- **Settle open questions:** `grill-with-docs`, against `docs/client-questions.md` and this file. Record answers in the spec docs, and record terms with `domain-modeling` in `CONTEXT.md` / `docs/adr/`.
- **Design and plan:** `superpowers:brainstorming` → a spec in `docs/superpowers/specs/`, then `superpowers:writing-plans` → a plan in `docs/superpowers/plans/`.
- **Build:** `superpowers:subagent-driven-development` or `superpowers:test-driven-development`, always following `ponytail` and `andrej-karpathy-skills:karpathy-guidelines` and `docs/coding-standards.md`.
- **Review before the PR:** `code-review` (standards and spec), plus `security-review` for uploads, permissions and imports. The other developer still reviews the PR.
- **End of session and account switch:** run `handoff`, which updates `CLAUDE_HANDOFF.md`, then commit and push the branch. Nothing a session needs may live only in a Claude account's memory or conversations: a new session (any account) starts by reading this file and `CLAUDE_HANDOFF.md`.
- **Git identity:** set `user.name` and `user.email` in each clone to your GitHub account before the first commit.
