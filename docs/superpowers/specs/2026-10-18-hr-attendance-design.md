# Release 1c: HR attendance, Excel import, missing-day alerts (design)

## Context
- Release 1 still has no attendance. Today the creator types every person-day into the Excel, and payroll in Release 2 needs exactly those daily inputs.
- The owner decided (2026-10-18, in Arabic, in session) that **for now HR alone records attendance, for every employee type including office staff**. HR gets two aids: a screen to enter days, and an Excel template it can fill and upload.
- The manager's phone screen comes in a later plan. So do the paste-the-site-messages parser and the manager's sign-off.
- This plan records only. No money is computed or shown; payroll is Release 2.
- **(owner)** marks the owner's answer. **(my call)** marks a decision of mine that the owner can override.

## Scope
- **In:**
  - Attendance days for all 4 types, entered by HR.
  - Excel template download and upload with a preview.
  - The official-holiday list.
  - HR's attendance lock per period.
  - An in-app bell with a daily missing-days alert to HR.
- **Later, separate items** (recorded in `docs/hrms-module-plan.md` in this PR):
  - **Manager mobile screen.** The manager enters **today only**, and past days are HR-only **(owner)**. It adds «زي امبارح» quick fill, the manager's own sites only, the «تم التوقيع» sign-off per employee period (after which only HR edits), HR returning a period with a manager notification, and an alert to HR when a manager skips a day **(owner)**.
  - **Paste site attendance messages** in a fixed format, which the system parses per person **(owner: «سجلها كنقطة منفصلة»)**.
  - The scanned paper sign-off upload, with documents in Release 3 **(owner)**.
- **Out:** any money calculation or display **(owner)**, the advances ledger (Release 2) **(owner)**, leave balances, email and WhatsApp.

## Rules

**One record per employee per day (owner)**
- A record is either one work site or one leave day-type: Friday, official holiday, sick, rest, or absent (غ).
- Absent is a day-type, never a flag.
- There is one site per day. A second site can go in the note.
- Office staff have **no site**. Their day is "present" (site empty) or a leave day-type **(my call)**.

**Fields per type (owner)**
- **Office:** in time and out time. Lateness is computed from the shift start, which is `office_shift_start` or the pay-row `shift_start` override, minus the `office_grace_minutes` setting (15). It is shown as a minutes hint. These are the same fields the Release 2 fingerprint import will fill **(owner)**.
- **Engineer:**
  - site or day-type;
  - in and out times, optional and used in no calculation;
  - late minutes and OT minutes, typed as in the Excel **(owner)**;
  - outside-Alexandria minutes;
  - advance amount;
  - note.
- **Permanent worker:**
  - site or day-type;
  - **scheduled** in and out times, stored on the day and defaulting from new settings `worker_shift_start` 08:00 and `worker_shift_end` 16:00, or the pay-row override;
  - actual in and out times, from which late and OT are computed and shown as minutes (calculations.md §3);
  - outside-Alexandria **amount**;
  - transport: full, half or none (default full);
  - advance amount;
  - note.
- **Day laborer:** site or day-type, OT minutes, late minutes, advance amount, note.
- **Outside-Alexandria field:** shown only when the chosen site has `outside_alexandria = true` **(owner)**.
- **Leave day-types (my call):**
  - reject times, minutes, the outside-Alexandria field and transport (transport is stored as none);
  - an advance is allowed on any day, because cash can be handed over on a Friday;
  - the leave code `other` is HR-pickable.

**Advance:** the amount only, recorded on the day. Release 2 imports these amounts into the ledger, where HR chooses one payment or installments **(owner)**.

**Dates and editing**
- HR may enter or edit any day of an **open** period, up to today in Cairo time. There are no future dates **(owner, applied to HR as my call)**.
- Every write is audited with before and after values.

**Fridays and official holidays**
- Fridays come from `weekly_off_field`, or `weekly_off_office` (Friday and Saturday) for office staff. Fridays and official holidays default automatically. HR can override a day with a site if the person worked **(owner)**.
- HR keeps the dated holiday list in a new «الإجازات الرسمية» Settings tab **(owner)**.
- **(my call)** These defaults are derived on read while a period is open. **On lock they are materialized** as stored rows (`source = derived`), so a later change to a setting or to the holiday list cannot rewrite a locked period (coding-standards §6).
- Holiday edits that fall in a locked period are refused.
- When a Friday is also a holiday, the holiday wins.
- Each derived default points to the single active leave site for its code. A new partial unique index enforces one active leave site per `leave_code`.

**Missing days:** a field or office day with no record that is not a derived Friday or holiday is **missing**. HR sees it highlighted in the period grid. It is never treated as absence **(owner)**.

**Periods (my call)**
- **Office:** the calendar month.
- **Engineers and permanent workers:** the 26th to the 25th.
- **Day laborers:** the 1st–15th, and the 16th to month end (CLAUDE.md).
- The lock is per (group, period).

**Lock (owner)**
- HR locks a period's attendance in one action.
- If unsigned or missing days remain, HR is shown them and must confirm **(owner: «بعد تأكيد»)**. Sign-off arrives with the manager plan; for now the list shows missing days only.
- After the lock nobody edits.
- HR reopens with a required reason. Reopening keeps the materialized rows.
- In Release 2 this lock becomes part of the payroll period close.

**Daily missing-days alert (owner: «نبنيها دلوقتي»)**
- An in-app bell in `AppLayout`.
- A scheduled job at 21:00 Cairo time creates **one** notification per recipient per date. It lists the counts of missing days per group for the open periods, and links to the grid.
- **Recipients:** users holding `attendance.edit.all`. This is checked by permission, never by role.
- **Job spec (coding-standards §10 and §12):**
  - **Input:** the date.
  - **Idempotent:** unique on (user_id, type, date), so a re-run is a no-op.
  - **Retries:** 3 with backoff, on `worker-maintenance`.
  - **On failure:** log it, and the next run catches up on the missing dates.
- Email and WhatsApp are out.

**Who may enter a day:** HR, holding `attendance.edit.all`. The employee must be active on that date, which means hired and not terminated. Their type is resolved on the date with `EffectiveDated` **(my call)**.

**Concurrency:** day rows carry a `version`, and a stale write returns `409 concurrency_conflict` (coding-standards §111). This is new infrastructure: nothing in the backend has it yet. Rows are never hard-deleted; clearing a day is an audited update.

**Permissions (my call)**
- New: `attendance.view.all`, `attendance.edit.all`, `attendance.lock.all`, `holiday.manage.all`.
  - HR: all four.
  - CEO: `attendance.view.all`.
  - Developer: every `.all` permission, as the migration already does.
  - Manager: nothing yet.
- The `.own` permissions come with the manager plan.
- They are added in a **new** migration, never by editing an applied one (coding-standards §7). The same applies to the new setting keys.

## Excel template (owner: «قالب بسيط السيستم ينزله»)
- **Download:** HR picks a group and period and downloads an .xlsx.
  - It has one row per active employee per day of the period.
  - Prefilled: code, name, date, and any existing values, with Fridays and holidays already filled.
  - Columns are the union of the per-type fields. Columns that don't apply to a row's type are greyed.
  - Sites and day-types are typed by name; a hidden sheet holds the valid lists.
- **Upload:** HR uploads the file and sees a **preview**. It shows every row's changes compared with what is stored, plus row-level errors (unknown code, unknown site, a field not allowed for the type, a future date, a locked period, a value out of bounds). Nothing is saved until HR confirms.
- **Confirm:**
  - Valid rows are upserted in one transaction, each one audited.
  - Error rows are skipped and listed in the result.
  - An unchanged row writes nothing.
- **Library:** `openspout/openspout`, a single small dependency that reads and writes .xlsx. No Excel library is installed today **(my call)**.
- **Limits:** a maximum file size of 5 MB, the .xlsx MIME type and extension checked, and the file parsed in memory and never stored (coding-standards §9).

## Validation (my call)
- Minute fields are integers from 0 to 960.
- Amounts are positive decimals (`NUMERIC(18,6)`, bcmath).
- Times are `HH:MM`.
- **Night shifts:** for permanent workers, an out time earlier than the in time crosses midnight, and the day belongs to the in-time's date.
- The Excel error #6 case (money typed into a minutes field) is impossible by type.

## Build on (existing code)
- `Employees/Domain/EffectiveDated::at` gives the type, pay or shift in force on a date.
- `Identity/Presentation/RequestScope` plus the `permission:` middleware.
- `Sites`: `SiteKind`, `LeaveCode`, `outside_alexandria`. Make `SiteService::visibleTo` public (it is private at `SiteService.php:120`) for the later manager plan, only if that is needed now.
- `Settings/Application/BusinessRules::all()`, plus new keys in a new migration and in `SettingController::RULES`.
- `Audit/Infrastructure/Audited` plus `AuditLog::record`, and a new `AttendanceAuditEvents` class.
- The permission seeding pattern from `2026_10_19_000001_create_settings.php`, copied into a new migration.
- `standardColumns()`, `money()` and `Support/Money/Decimal`.
- `scheduler` and `worker-maintenance` (already in compose). The schedule goes in `routes/console.php`.
- Frontend:
  - `lib/query.ts` (`useList`, `useApi`, `useSave`);
  - `components/form.tsx` (`TextField type="time"`, `SelectField`, `cairoToday()`, `useMoney()`);
  - the Settings tabs and the `AppLayout` header;
  - the mobile patterns in `pages/employees.tsx`.

## New pieces
- **Backend module `Attendance`:**
  - Tables:
    - `attendance_days` (employee, date, site_id nullable for office, the type fields, `source` (entered / imported / derived), note, version), unique on (employee_id, date);
    - `attendance_locks` (group, period_start, locked_by/at, reopened_by/at, reopen_reason);
    - `official_holidays` (date, name).
  - A service that enforces every rule above inside a transaction.
  - `effectiveDay()`, which returns the stored row, else the derived Friday or holiday, else missing.
  - The API under `/api/v1/attendance/...`: the period grid, a day upsert, the template download, upload preview and confirm, lock and reopen, and holidays CRUD.
- **Backend module `Notifications`:**
  - The `notifications` table (user_id, type, data JSON, date, read_at), unique on (user_id, type, date).
  - Endpoints to list and mark read.
  - The daily job.
- **Frontend:**
  - `/attendance`: a group and period picker, and the period grid with employees as rows and days as columns.
    - Cells show the site or day-type in short form, missing days are highlighted, and derived days look distinct.
    - Clicking a cell opens a day editor sheet with the fields for that type.
    - A lock or reopen button.
  - Import: download the template, upload it, see the preview table, confirm.
  - The holidays Settings tab.
  - The bell with an unread count and a list.
  - Mobile: the grid scrolls sideways at 375 px with the employee column pinned, and the editor sheet is full-height.
- **Docs in the same PR:**
  - `CONTEXT.md`: attendance day, attendance lock, official holiday, derived day.
  - `docs/hrms-module-plan.md`: these decisions, plus the two later items.
  - `docs/calculations.md`: office lateness from times, the scheduled shift stored per day, the derived off days.

## Delivery: horizontal slices, each approved by the owner from screenshots (375/1024/1440, ar/en, light/dark)
1. **Backend core.** Opus implements and reviews, since this covers permissions, periods, the lock and concurrency.
   - It covers the tables, migrations, permissions, the rules, `effectiveDay`, materialize-on-lock, and the 409.
   - PHPUnit covers every rule:
     - the fields each type accepts or rejects;
     - leave-type restrictions;
     - future dates;
     - writes to a locked period refused;
     - reopen with a reason;
     - derived Fridays and holidays, and holiday-over-Friday;
     - holiday edits refused inside a locked period;
     - a stale version returning 409;
     - an inactive employee on the date;
     - the night shift;
     - the missing-days count;
     - an audit entry on every write.
2. **HR period grid, day editor, holidays tab, lock.** Sonnet implements and Opus reviews.
3. **Excel template and import with preview.** Opus builds the backend parsing and validation (a trust boundary). Sonnet builds the UI. Tests cover a round trip (download, edit, upload), each error kind, and that a preview saves nothing.
4. **Notifications.** Opus builds the job, its idempotency and the recipients. Sonnet builds the bell UI.

Every brief tells the subagent to load `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, the fitting superpowers skill, and `docs/coding-standards.md`. Branch: `feature/release-1c-attendance`, cut from `feature/perf-architecture` and stacked on PR #2.

## Next steps after approval
1. Save this design as `docs/superpowers/specs/2026-10-18-hr-attendance-design.md`, on the new branch.
2. Write the plan with `superpowers:writing-plans`: `docs/superpowers/plans/release-1c-attendance.md`, with tasks per slice. The owner approves it.
3. Execute it with `superpowers:subagent-driven-development`, stopping at each slice gate for screenshots.

## Verification
- Backend gates: `php artisan test` (every rule above has a test), pint, phpstan, deptrac.
- Frontend gates: vitest, build and lint.
- **Browser, as HR at 375 and 1440 px:**
  - pick the engineers group for 26-09 → 25-10;
  - enter a site day, a غ day and an advance;
  - override a Friday;
  - check that the missing days are highlighted;
  - download the template, edit 3 rows plus 1 bad row, and upload it: the preview shows 3 changes and 1 error, and confirm saves only the 3;
  - lock the period: an edit is now refused, and the derived Fridays are now stored;
  - reopen it with a reason.
- **As CEO:** the grid is read-only.
- **As Manager:** there is no Attendance nav entry and the API returns 403.
- **Alert:** run the job twice for one date. One notification appears per HR user, and the bell shows it.
- **Audit log:** every action above shows its before and after values.
