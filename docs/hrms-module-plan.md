# Smart Tech HRMS — 4-Month Module Plan

## Context
Smart Technology runs HR and payroll today on 6 hand-built Excel workbooks (analysed in `docs/excel-system-analysis.md`) and has a front-end-only demo (https://github.com/mr-h12/HRMS-DEMO, React 19 + Vite, mock data). Goal: a production HRMS built by 2 full-stack devs in 4 months, delivered as 4 monthly production releases. It runs standalone now, with an API ready for integration with the company's Laravel CRM later. This plan splits the system into modules so the client knows what lands when.

## Settled decisions (from grilling)
| Topic | Decision |
|---|---|
| Team / delivery | 2 full-stack devs; 4 monthly releases, each goes straight to production |
| Stack | Mirror the CRM (`docs/adr/0001-mirror-crm-stack.md`): Laravel 13 / PHP 8.4, PostgreSQL 17, Redis, `/api/v1`, UUIDv7, modular + deptrac, PHPUnit, CRM-style RBAC + audit log, browsershot PDFs. Frontend: the demo's look + react-i18next |
| Hosting | Client's on-prem server, delivered as **Docker**. ⚠️ **Backups are NOT set up: manual until the owner confirms. Keep this warning visible in the release notes and the developer console.** |
| Language | Arabic + English (RTL/LTR) |
| Users | 7 accounts: 1 CEO, 1 HR, 5 managers. **No employee or worker accounts.** Developer role can add accounts |
| Roles | Developer (super-admin) > CEO > HR > Manager. HR can edit everything a manager enters |
| Data entry | Manager records attendance, absence, lateness, leave and requests for their own employees. Each employee belongs to exactly one manager; attendance is HR-only for now (see Attendance entry (1c)) |
| Approvals | Manager → HR → (HR decides) CEO, or HR approves alone |
| HR alerts | Instant for requests, warnings and edits to past days; daily digest for routine attendance |
| Fingerprint | Office staff only; HR uploads the device's Excel export; 15-min grace; shift 09:00–17:00 (per-employee override); import flags missing punches / early leave / OT, HR confirms. Office attendance is HR's, not the managers' |
| Payroll types | Office staff (monthly), engineers/permanent workers (26→25), day laborers (half-month). Lateness/OT multipliers are settings per type (1× / 1.5× / 2×, OT 1.5×) |
| Rates | Day laborer's daily wage on the worker (effective-dated, `docs/adr/0002-daily-wage-on-worker.md`); site allowance rule per site; residence × site fare + per-worker override. Permanent workers' transport is paid (full/half per day) |
| Leave | <1 year on Jan 1 → 10 days, ≥1 year → 21, of which 6 casual (both). Year from Jan 1, no carry-over; crossing 1 year mid-year alerts HR. Day laborers: none. Leave pay rules decided when building leave. All values are settings |
| Insurance / tax | Manual: HR types employee share, company share and income tax per employee per period; blank = 0. Insured basic only splits the payslip |
| End-of-service | System suggests ½ month × years × last total wage; HR edits (rule pending client) |
| HR override | System computes everything; HR can override any computed value (reason, highlighted, survives recompute, revertible) |
| Rounding | None, as in the Excel; NUMERIC(18,6), display 2 dp |
| Advances | HR chooses one payment or installments for every advance |
| Weekly off | Per type: office Fri + Sat, field Fri; paid for monthly staff |
| Payout | Mark "paid" + HR writes how it was received. No bank files |
| HR notes | On employee profile, visible to HR + CEO, optional reminder date |
| Signing | Manager marks "signed" for the employee (electronic) or uploads a scanned paper copy. HR/CEO saved signature images are stamped on approval |
| Communication | HR picks the recipient (manager or employee). Channels: in-app to manager, email, and a wa.me WhatsApp button. Every message is logged on the employee file. WhatsApp API → phase 2 |
| Migration | Everything extractable from the Excel files; history imported as-is, with a **warning flag beside each known error** (the 16 in the analysis doc) |
| Period lock | Locked after HR close + CEO approval; only HR can reopen, with a reason (audit-logged) |
| Extra demo modules | Recruitment, performance, training and assets are IN scope as lite versions (no applicant portal, no self-review) |
| Attendance entry (1c) | For now HR alone records attendance, for every employee type including office staff. The manager screen is a later item (Release 1, item 5). HR gets a day sheet per group and date with bulk marking (cohort sweeps, ID lists, ranges, paste) and a session undo; the period grid stays |
| Attendance day | One record per employee per day: one site or one leave day-type (office staff: present, with no site, or a leave day-type). A second site goes in the note. Absent (غ) is a leave day-type, never a flag. A day with no record is missing, never absence |
| Attendance dates | HR enters or edits any day of an open period up to today (Cairo time). No future dates |
| Attendance periods | Per group: office the calendar month, engineers and permanent workers the 26th → 25th, day laborers 1–15 and 16–end. The attendance lock is per (group, period) |
| Attendance lock | HR locks one group's period in one action, after confirming the missing days; nobody edits until HR reopens with a reason. Weekly off days and official holidays are derived on read while the period is open and stored at lock. ⚠️ (owner) HR may lock a period before it ends. ⚠️ (owner) Reopening keeps the stored derived days; a later holiday or weekly-off change does not refresh them on re-lock |
| Attendance concurrency | Each day carries a `version`; a write with a stale version returns `409 concurrency_conflict`, never an overwrite. Days are never hard-deleted; clearing a day is an audited update |

## Modules by release

### Release 1 — end of Month 1: Foundation & daily operations
1. **Auth**: email + password, sessions. Passwords are set and changed only by the Developer role (no self-service reset or change).
2. **Developer console**:
   - RBAC: create roles and edit permissions per role.
   - Account management.
   - Audit-log panel (who did what, before/after values).
   - Error & request/response log screen.
   - Retention: 1 year for audit, 90 days for request/error logs.
3. **Employees**:
   - Auto sequential code; optional `crm_user_id`.
   - Type (office / engineer / permanent worker / day laborer), with dated type history.
   - Identity: national ID, insurance no., gender, address, fingerprint name (English, for matching).
   - Pay: pay method (bank / cash) + bank account, salary + raise, insured basic, daily wage (day laborers), shift override.
   - Placement: department, residence, manager (one).
   - Contract dates, document expiry dates, hiring-documents checklist, termination date + reason.
   - HR notes.
4. **Sites & rates**: sites (deduplicated from both workbooks), leave day-types, site allowance rules, residence×site fares + per-worker overrides, manager↔sites assignment.
5. **HR attendance (1c)**: HR records every employee type for now, per employee per day:
   - Site or leave day-type; office staff are present or on a leave day-type.
   - The fields of the employee's type (in/out times, late / OT / outside-Alexandria minutes or amount, transport, advance amount, note).
   - The Excel template: download, fill, upload with a preview, confirm.
     The template is a grid like HR's timesheet: one row per employee, one column per day, and the site or day type in each cell. Times, overtime and advances stay in the app **(owner, 2026-10-20)**.
   - Official holidays, kept in Settings.
   - The attendance lock per group and period; HR reopens with a reason.
   - The missing-days bell. Job `attendance:alert-missing` runs at 21:00 Cairo time and notifies the users holding `attendance.edit.all`:
     - Input: the date.
     - Idempotent on (user, type, date), so a re-run is a no-op.
     - 3 tries with 60 s / 300 s backoff, on the `maintenance` queue.
     - Failures are logged. The counts are cumulative, so the next run covers a failed day.
   - Later, separate items:
     - **Manager mobile screen**: the manager enters today only, and past days are HR-only. «زي امبارح» quick fill; the manager's own sites only; the «تم التوقيع» sign-off per employee period, after which only HR edits; HR returns a period with a notification to the manager; an alert to HR when a manager skips a day.
     - **Paste site attendance messages**: HR pastes a site's attendance message in a fixed format, and the system parses it per person.
6. **Leave**: balances and rules above, recorded by the manager, HR approves or escalates.
7. **Notifications**: in-app, instant + daily digest.
8. **Migration step 1**: step 1 (master data: employees, sites, rates, fares) is done by `hrms:import-master`; opening leave and advance balances follow their modules.

### Release 2 — end of Month 2: Payroll
1. **Fingerprint import** (office) → late minutes with grace, exemptions.
2. **Payroll engines** for the 3 types, reproducing the Excel formulas (section 2.3 / 3.2–3.3 / 4.2 of the analysis doc).
   - The configurable multipliers above.
   - Known Excel bugs fixed in the engine (e.g., raises included in net), and an **Excel-difference report** (employee, amount, reason) the client signs off.
   - HR override on every computed value.
3. **Advances/loans ledger**: amount, installments, repayment, balance.
4. **Social insurance + income tax**: manual HR fields (permanent only).
5. **Period close**: HR closes → CEO approves → lock / HR reopen.
6. **Payout**: paid flag + how received.
7. **Payslip PDF** (template: مفردات مرتب).
8. **Migration step 2**: historical payroll archive (read-only) with error flags.
9. **Parallel run** against Excel starts this month and continues through Month 4.

### Release 3 — end of Month 3: Requests, documents, communication, lifecycle
1. **Request workflow** (manager on behalf of the employee → HR → optional CEO). Types: leave, late permission, early leave, advance, resignation. Effects on approval:
   - Late permission cancels that day's lateness deduction.
   - Leave deducts from the balance.
   - Resignation opens offboarding.
2. **11 PDF templates** (Arabic, auto-filled from the employee profile, HR/CEO signature stamping, "signed" mark or scanned upload):
   - job offer
   - إذن تأخير (late permission)
   - طلب انصراف باكراً (early-leave request)
   - طلب إجازة (leave request)
   - استقالة (resignation)
   - إنذار (warning)
   - استلام خط موبايل (mobile-line receipt)
   - إخلاء طرف (clearance)
   - شهادة خبرة (experience certificate)
   - مكافأة نهاية الخدمة (end-of-service bonus; amount entered manually)
   - مفردات مرتب (payslip; delivered in Release 2)
3. **HR communication center**:
   - Message types: congratulation, condolence, alert, warning, PDF.
   - Recipient: manager or employee.
   - Channels: email, wa.me button, in-app to manager.
   - Every message logged on the employee file.
   - Group send: HR filters employees (by site, manager or type) → the system shows their WhatsApp numbers and the message text, with copy buttons, for pasting into a WhatsApp broadcast list. It can also open each chat in turn (one wa.me per employee, HR presses send). No unofficial bulk-sending tools.
4. **Date-reminder engine**:
   - Lead time configurable per date type: contract end 30 days, probation end 14 days, ID/licence/permit expiry 30 days, document deadline from the job offer, return from leave, insurance forms, note reminders.
   - Shown on the HR dashboard, as a notification and by email.
5. **Custody register**: mobile line, devices, etc. Clearance is blocked until every item is returned.
6. **Onboarding**: job offer → documents checklist → 90-day probation review.
7. **Offboarding**: resignation/termination → clearance → final settlement → experience certificate.
8. **Attendance sign-off upload**: the scanned paper sign-off of an attendance period, stored with the employee's documents.

### Release 4 — end of Month 4: Talent, reports, hardening
1. **Recruitment (lite)**: jobs, pipeline Applied → Hired, interviews.
2. **Performance (lite)**: manager rates employees per cycle.
3. **Training (lite)**: courses, enrolment, completion.
4. **Assets**: extends the custody register with categories and statuses.
5. **Reports + CEO dashboard**: headcount, attendance, leave, payroll, turnover, **site labor cost** (from ملخص المواقع), Excel/PDF export.
6. **API documentation** for the future CRM integration.
7. **UAT, final Excel-parity sign-off, handover** (last 2 weeks).

## Risks / to tell the client
- ⚠️ **Backups are manual and not configured.** The client must confirm before we automate them.
- **Tight scope for 2 devs.** The talent modules ship as lite versions in Month 4. If anything slips, they move first, never payroll.
- **We need from the client:** remote access (VPN/SSH) to the on-prem server, plus an SMTP mailbox on the company domain. Without remote access, each release needs a site visit.
- **Rates:** the site-dependent hourly rate is unconfirmed. The rate table supports both a fixed and a per-site rate.
- **Historical data** carries the Excel errors. They are flagged, not corrected.

## Verification (per release)
- **Release 2 parity:** re-run October 2026 from the migrated data and compare with the Excel totals: engineers net 315,451, day laborers net 391,948. Every difference must trace to a documented Excel error.
- **Role matrix test:** each of Developer / CEO / HR / Manager sees and can do only what its permissions allow. A manager sees only their own employees.
- **Audit log:** every create/update/delete and approval shows an entry. Errors appear on the error screen.
- **PDFs:** each of the 11 templates renders correctly in Arabic and matches the client's Word layout.
- **Reminders:** create test dates → alert fires at the configured lead time.
- **Deployment:** `docker compose up` on a clean Linux VM reproduces production before each release.
