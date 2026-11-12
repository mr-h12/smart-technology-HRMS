# Site manager accounts: design

Date: 2026-11-12. Builds Q4 of the owner's decisions of 2026-11-03 (client revision item 12, R3): a site manager can be both an
employee and an account holder, and HR records their attendance like anyone else's. The owner settled the details on 2026-11-12.

## Context
Accounts and employees were unrelated. A site manager on the payroll existed twice, as an account and as an employee row, with
nothing joining them: HR made the account in the developer console, set its sites on each site's page and assigned engineers on
each engineer's page. Their own employee row could fall inside their reach, and a site manager who left kept a working login.

## Owner's decisions (2026-11-12)
1. **One step from the employee's page:** create an account with the site-manager role, named after the employee, or link an
   existing site-manager account; link it to the employee; set the sites it covers; assign the ticked engineers from one
   effective date. Any refusal rolls everything back. Any employee type may become a site manager, unless archived.
2. **Who:** HR and the Developer. HR holds `user.manage.all` since R22 (PR #44) and acts within its reach.
3. **Read side:** only HR and the Developer see the account on the employee's page. The link grants nothing on pay; the site
   manager still never sees wages.
4. **Own record:** a site manager never reaches their own employee record. HR records and reviews their attendance, leave and
   requests like anyone else's.
5. **Archiving deactivates the account,** at once or overnight. Clearing the termination date does not reactivate it;
   reactivation is manual.
6. **Engineers are only added** from this dialog, never removed: an engineer's manager assignment has no end date, so an engineer
   leaves by being assigned another manager from their own page.
7. No new permission. `manager_sites` stays undated (ADR 0004 unchanged).

## Data model
- `employees.user_id`: nullable uuid, foreign key to `users` (migration `2026_11_12_000001_link_employees_to_accounts.php`). The
  partial unique index `employees_user_id_unique` on `user_id` where `deleted_at is null` allows one live employee per account;
  the service refuses a second link on an employee.
- Set only by `POST employees/{id}/site-manager`. `PATCH employees/{id}` ignores it, and no route clears it.
- Hidden from every employee response (`EmployeeService::HIDDEN`). HR and the Developer see the account through the
  `site_manager` block.
- Linking is audited as `UPDATED` on `employees`, with the old and new `user_id`.

## API
Both routes answer with the employee in the `GET employees/{id}` shape, `site_manager` block included. Each runs in one
transaction with the employee row locked: a refusal leaves no account, link, site change or assignment behind.

### POST /api/v1/employees/{employee}/site-manager
Creates or links the account, then sets its team. Middleware: `permission:user.manage`, `permission:site.assign_manager`,
`permission:employee.assign_manager`, `employee.scope`; HR and the Developer hold them today. Answers 201.

| Field | Rule |
|---|---|
| `user_id` | Optional uuid: link this existing account instead of creating one. `email` and `password` are then ignored. |
| `email` | Required without `user_id`. The account rules (`UserAdministration`): an email, at most 255 characters, unique among live accounts; stored trimmed and lowercase. |
| `password` | Required without `user_id`. At least 8 characters, with letters and numbers. Never audited. |
| `site_ids` | Optional array of distinct ids of live work sites (an inactive site passes). Replaces the sites the account covers; absent keeps them, so a new account covers none and a linked account keeps its own. |
| `engineer_ids` | Optional array of distinct ids of live employees, each assigned to the account from `effective_from`. |
| `effective_from` | `Y-m-d`; required when `engineer_ids` is not empty. |
| anything else | Ignored, `role_id` and `name` included. |

Steps:
1. Refuse an employee already linked.
2. With `user_id`, the account must be a manager candidate (an active account whose role holds `site.view.own` without
   `site.view.all`, `SiteService::managerCandidateIds`) and linked to no live employee. Without it, `UserAdministration::create`
   makes the account: the employee's Arabic name, the site-manager role (below), `email` and `password` checked by its rules,
   the caller's account reach checked (R22), `USER_CREATED` audited without the password.
3. Link the employee to the account.
4. With `site_ids`, `SiteService::setSitesOf` adds or removes the account on each changed site through `setManagers`, which keeps
   that site's other managers and audits `SITE_MANAGERS_UPDATED` per changed site. Unchanged sites write nothing.
5. Each engineer must be an engineer on `effective_from`, not archived, and not the employee being made site manager. One already
   assigned to this account on `effective_from` is skipped. Otherwise a manager assignment from `effective_from` is created
   (`CREATED` on `employee_manager_assignments`) with the checks of `POST employees/{id}/manager-assignments`: no other assignment
   of theirs starts that day, and their period is not locked on that date.

### PUT /api/v1/employees/{employee}/site-manager
Changes the linked account's sites and adds engineers. Middleware: `permission:site.assign_manager`,
`permission:employee.assign_manager`, `employee.scope`; no `user.manage`, since the account itself is not touched. Answers 200.

Body: `site_ids`, `engineer_ids` and `effective_from`, with the rules above. The employee must be linked (else 404) and the
linked account must still be an active site-manager account. Then steps 4 and 5 run as for POST.

### The `site_manager` block on GET /api/v1/employees/{employee}
Present only for a caller holding `user.view` at all scope: HR and the Developer, never the CEO or a site manager. `null` when the
employee is not linked; otherwise:

| Key | Value |
|---|---|
| `id`, `email`, `is_active` | The account's. |
| `role` | `{id, slug, name, name_ar}` |
| `site_ids` | The sites the account covers now (`manager_sites` is undated). |
| `engineer_ids` | The engineers the account reaches today (assigned to it today, not archived), by code. |

### Errors
The usual envelope: `error.code`, and for 422 `error.details[]` with `field`, `code: invalid` and the translated `message`
(`errors.*` keys in `backend/lang`; the key is named below).

| Status, code | Field | Cause |
|---|---|---|
| 403 `permission_denied` | | The caller lacks one of the route's permissions. |
| 403 `account_out_of_reach` | | POST creating an account: the site-manager role holds an `.all` grant the caller lacks (R22). |
| 404 `not_found` | | Unknown employee; an own-scope caller outside reach; PUT on an employee with no linked account. |
| 409 `employee_archived` | | The employee is archived (the `employee.scope` write guard). |
| 422 `validation_failed` | `email` | Missing without `user_id`, not an email, or taken. |
| 422 `validation_failed` | `password` | Missing without `user_id`, under 8 characters, or without both letters and numbers. |
| 422 `validation_failed` | `user_id` | Not a uuid. `not_site_manager_account`: not an active site-manager account. `account_linked`: already linked to another live employee, also when a concurrent request linked it first (caught by `employees_user_id_unique`). |
| 422 `validation_failed` | `employee` | `already_site_manager`: the employee is already linked. |
| 422 `validation_failed` | `role` | `site_manager_role`: no single role holds `site.view.own` without `site.view.all`. |
| 422 `validation_failed` | `site_ids.N` | Not a uuid, repeated, or not a live work site. |
| 422 `validation_failed` | `engineer_ids.N` | Not a uuid, repeated, or not a live employee. |
| 422 `validation_failed` | `engineer_ids` | `not_an_engineer` (names them): not an engineer on `effective_from`, archived, or the employee themself. `engineer_refused` (names them and the reason): the assignment's own refusal, `duplicate_date` (an assignment of theirs already starts that day) or `dated_change_period_locked`. |
| 422 `validation_failed` | `effective_from` | Missing with `engineer_ids`, or not `Y-m-d`. |
| 422 `validation_failed` | `account` | PUT: `not_site_manager_account`, the linked account is inactive or no longer a site-manager account. |

## The site-manager role
`SiteService::managerRoleId` picks the one live role holding `site.view.own` without `site.view.all`: the rule
`managerCandidateIds` applies to accounts, so a new account is a manager candidate at once. A `role_id` sent is ignored. No such
role, or several, refuses the request (`role`, `site_manager_role`) until the Developer fixes the roles; supporting a second
site-manager role means letting the caller choose (the `ponytail:` note on the method).

## Own record
`EmployeeReach::exceptOwn` leaves out the employee linked to the caller's account (`employees.user_id is distinct from ?`). It is
applied in:
- `EmployeeReach::apply`, so in everything reach decides: the `employee.scope` middleware (404 on their own page and on every route
  keyed by their id), the site manager's employees list and summary, leave requests and balances, team fares, the employee a
  notification names (shown as unknown), `reaches` and `ids`.
- `EmployeeRoster::onSite`, which now goes through `EmployeeReach::apply`: the site-day list, and its write (404 for their own id).
- `DayCrew::find`: the crew search by code or full name.

HR's screens do not change: the site manager's row is on the day sheet and the period grid like anyone's.

## Archiving deactivates the account
Archived means a termination date before today in Cairo (`2026-11-11-hide-archived-employees-design.md`).
- **At once:** a `PATCH employees/{id}` that changes the termination date and leaves the employee archived deactivates the linked
  account in the same transaction.
- **Overnight:** the scheduled job `employees:deactivate-archived-accounts` (`backend/routes/console.php`, a `Schedule::call`,
  no queue) runs daily at 00:05 Africa/Cairo and deactivates the account of every archived linked employee. It catches a
  termination date that passes later, and makes up a missed night. Replay command: `backend/CLAUDE.md`.
- Both go through `UserAdministration::deactivateForArchivedEmployee`: `is_active` false, sessions revoked, `USER_DEACTIVATED`
  audited on `users` with `employee_id` and `reason: employee_archived` in the new values. The actor is the HR user who saved, or
  none for the job. HR's account reach is not checked, since the system acts. An inactive account is left as is, with no second
  audit row.
- A deactivated account cannot sign in (`account_suspended`).
- Clearing the termination date does not reactivate the account. Reactivate it by hand (`PATCH /api/v1/users/{id}/reactivate`)
  **after reactivating the employee**: an account reactivated while its employee is still archived is deactivated again by the
  next nightly run.

## Screens
HR and the Developer see «تعيين كمدير موقع» on an employee's page, and the account's email on a linked one. The same button
opens one dialog that creates a new account (with sites and engineers) or links an existing site-manager account (keeping its
sites), and on a linked employee becomes «مواقع ومهندسو مدير الموقع» to change the sites and add engineers (PUT).

## Tests
`backend/tests/Feature/SiteManagerAccountTest.php`:
- One step: account, link, sites and an engineer; the audit rows (account, link, each changed site, the assignment) with no
  password anywhere in the audit log; the new account signs in, lists only its reach and gets 404 on its own record.
- Callers: the Developer may; a site manager and the CEO get 403 and nothing is written.
- Role: a `role_id` sent is ignored; a second role holding `site.view.own` alone refuses the request.
- Refusals leave nothing: a taken email, a short password, a leave type or a bad id as a site, a non-engineer, an archived
  engineer or the employee as an engineer, a missing `effective_from`; a locked period and a duplicate assignment date name the
  engineer and roll back the new account.
- Links: an employee is linked once; an archived employee is refused (409); an existing site-manager account is linked and keeps
  its sites; a linked, non-site-manager or inactive account is refused.
- PUT: replaces the sites (other managers kept, only changed sites audited), skips an engineer already assigned, answers 404 when
  unlinked and refuses an inactive account.
- Read side: only HR and the Developer get the block (`null` when unlinked); `user_id` never appears; `PATCH` never sets the link.
- Archive: a past date deactivates at once (sessions revoked, audit row with HR as the actor), clearing the date does not
  reactivate, sign-in is refused; a date of today waits for the night and a second sweep writes nothing; HR's save deactivates an
  account outside HR's reach; an unlinked archived employee and a linked active one are untouched; the schedule is `5 0 * * *`
  Africa/Cairo.
- Own record: the site day (list and write), the employees list, leave requests and balances, team fares, notifications, the
  summary count and the crew search.

## Non-goals
- No unlink, no change of the linked account, no account deletion.
- No automatic reactivation of the account.
- The dialog removes no engineer; the engineer's own page does not change.
- No new permission; `manager_sites` stays undated (ADR 0004).
- No change to who sees pay or wages.

## Known limits
Known when built; not scheduled.
- Ticking an engineer who belongs to another site manager moves them from `effective_from` without a warning.
- The dialog lists at most 100 engineers.
- Removing a site also removes the account's reach to that site's past days (`manager_sites` is undated, ADR 0004).
- An archived site manager's engineers stay assigned to the deactivated account until HR reassigns them.
- The manager candidates list (`GET /api/v1/sites/manager-candidates`) does not say which accounts are already linked; the server
  refuses a linked one with 422 (`account_linked`).
- The engineer-manager picker on the engineer's own page still accepts any active account (review finding 13-E13, kept as debt by
  the owner).
- `POST /api/v1/attendance/site-day/crew` does not refuse the site manager's own employee id specifically; the id is no longer
  shown to them anywhere.
