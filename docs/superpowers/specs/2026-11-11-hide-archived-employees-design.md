# Hide archived employees: design

Date: 2026-11-11. Owner request, in his words: «الموظف المؤرشف هو موظف ساب الشركه فا مينفعش تظهر بيانات، في حضور و غياب او في حته في السيستم».

## Intent

An archived employee has left the company. Today the archive is only a view of the employees list: the person is still a row in the attendance grid of every period they worked in (the current one included), in the day sheet, the template, the leave balances, and their page is fully editable by anyone who can reach it, site managers included. The owner wants them out of sight everywhere, while HR can still finish their last days and the recorded history stays intact for payroll.

## Terms (unchanged)

- **Archived** = `termination_date < today` in `Africa/Cairo`. The termination day itself is still worked; the employee is archived from the next day. There is no archive flag and none is added.
- **Reactivate** = clear `termination_date` and `termination_reason`. Nothing is deleted on archiving, so reactivating brings everything back. The days between leaving and returning count as employed again (existing rule, kept).

## Decisions the owner made (2026-11-11)

1. Archived employees are hidden by default from every attendance screen, for every role. HR (in permission terms: holders of `attendance.edit.all`, which the Developer also holds) gets one switch on the period grid, off by default, «إظهار من تركوا العمل», to finish and lock their last days. Recorded days are never deleted.
2. Only viewers with the all-employees scope (HR, Developer, CEO today) reach an archived employee, through the Archive switch of the employees list. The page carries an «مؤرشف» banner and is read-only: data, attendance, leave, document download. The only actions are Reactivate and correcting the termination date or reason. A site manager sees neither the Archive switch nor any archived employee.
3. Open items: saving a termination date is refused while the employee has a pending or escalated leave request or a pending wage proposal; the refusal lists them. Whatever slips through (a termination date set in the future, then a request filed before it) stays visible to HR only until it is decided. Advances and custody join this rule when they are built.
4. API by id: for a site manager, everything about an archived employee is refused, by one rule in `EmployeeReach` (404 where reach decides; attendance routes already answer 403 because managers hold no attendance view or edit permission). For all-scope users reads are allowed; writes that edit the archived employee's record are refused except reactivating, correcting the termination date or reason, and attendance days up to the termination date in an open period. Writes that close an open item (deciding or cancelling a leave request, deciding a wage proposal) stay allowed; the tables below are the full list.
5. Reactivating brings everything back.
6. Old notifications and the audit log stay as they are (history). No new alert counts an archived employee.

## Goals

- One definition of "archived", used by every caller, instead of a filter per screen.
- An archived employee appears in no list, search, picker, count or alert unless an all-scope user asks for them on purpose (Archive view, the grid switch, the lock dialog).
- A site manager cannot see or act on an archived employee by any route.
- Payroll inputs are untouched: stored attendance days, the lock's derived days and the leaver's last period stay exactly as today.

## Non-goals

- No archive flag, archive endpoint or archive permission.
- Review debt stays debt: M1 (a termination date inside a locked period) and 13-E8 (termination before hire, optional reason) are not fixed here.
- No delete button in the UI. If archived rows were used to bury duplicates, that is separate work (the owner has not said which case he saw).
- No account-to-employee link: a site manager who left keeps a working login until the Developer deactivates it.
- Rehire with a gap (a second employment window) is not modelled.

## Anti-goals

- Hiding must never change what a locked period stores or what payroll will read. Dropping leavers from the lock would lose their derived days and let their missing days pass unconfirmed.
- No per-screen `where termination_date …` copies. If a screen needs the rule, it gets it from the shared definition.

## The rule and where it lives

One definition on the `Employee` model, in an SQL form and a PHP form (the PHP form serves roster rows, the show field, the leave check and the write guard): **not archived on today** = `termination_date is null or termination_date >= today(Cairo)`. Every surface that returns a set of employees routes through one of three places; the by-id guards further down (write guard, show field, leave submit, leave requests filter) each call the same definition:

1. `EmployeeService::visibleTo` (employees list, summary): already has the rule inline; it switches to the shared definition. `archived=true` is honoured only for an all-scope viewer.
2. `EmployeeRoster`, the read model other modules use: every method that returns a set of employees (`ofType`, `allOfType`, `ofTypesInYear`, `onSite`, `engineersOf`, `officeIdsAt`) excludes archived employees by default, on top of its existing employment-window filter. A caller opts in explicitly (`withArchived`): the lock, the lock's missing list, the import, and the grid with its switch on (`officeIdsAt` opts in with the grid, or archived office staff vanish from a site-filtered grid). `AttendanceGrid::missingDays` is one path with three answers (header count follows the grid, lock list includes, 21:00 alert excludes), so it takes the flag from its caller. By-id reads (`find`, `findMany`, `names`) and `hiredBetween` (the anniversary alert, which already skips leavers) are unchanged.
3. `EmployeeReach::apply`, the site manager's reach: excludes archived employees whatever date is asked. This covers the `employee.scope` middleware, `isManagedBy`, the manager's leave lists, attendance writes in reach, team fares and the manager's employees list.

The local window copies in `DayCrew` and `FareService` already hide people past their last day and are left alone.

## Behaviour by surface

### Attendance
| Surface | After this change |
|---|---|
| Period grid, its name search and site filter | Archived hidden. With `with_archived=1` they are rows again in periods overlapping their employment, each row flagged as archived. The param is honoured only for a caller who may edit attendance at all scope; otherwise it is ignored. |
| Period header missing count | Follows the grid: archived left out unless `with_archived=1`. The lock dialog no longer decides from this count (see Missing list). |
| Day sheet (one group and All), its day strip counts | Archived hidden. No switch here. |
| Site manager's day (`site-day`), crew search and add | Archived hidden, including on dates up to their termination. HR records those days through the grid switch. |
| Lock | Unchanged: includes archived employees employed in the period. Their missing days still need confirming, an open day still blocks, derived days are still stored. |
| Missing list (the lock dialog) | Includes archived employees, each flagged, so HR sees whose days block the lock. The dialog shows the list and the confirm checkbox from this list's total, not from the header count; otherwise a period where only leavers have missing days could not be locked. My call. |
| Template download | Archived employees are not rows. Someone whose termination date is today or later is still a row with grey cells after it. |
| Import preview and confirm | Unchanged: a row for an archived employee employed in the period is matched by code and its cells up to the termination date are accepted (this is the allowed attendance write). My call, not the owner's. |
| `GET attendance/employees/{id}/period`, day read | All scope: returned as today. Site manager: refused (403 by permission with today's seeded roles; 404 by reach for any own-scope holder, which the tests grant through `AttendanceFixtures`). |
| Day write, clear, bulk | All scope: as today (allowed up to the termination date in an open period, `employee_not_active` after); the write guard exempts these routes. Site manager: refused, same as the read. |
| 21:00 missing-days alert | Counts leave archived employees out. |

### Employees
| Surface | After this change |
|---|---|
| List, search, filters, summary | As today for the active view. `archived=true` returns archived employees only to an all-scope viewer; for a site manager it returns an empty list. |
| `GET employees/{id}` and the read-only sub-resources (fare, leave tab, documents list, document file) | All scope: returned, with a field saying the employee is archived (computed on the server, so the browser's clock and time zone do not matter). Site manager: 404. |
| `PATCH employees/{id}` | On an archived employee only `termination_date` and `termination_reason` may change (reactivate or correct). Judged on changed values, not on which keys are sent, because the profile form sends every identity field on each save. Any other changed field → refused with error code `employee_archived` (409). |
| Every other write keyed by the employee id in the URL: code change, type change, pay change, manager assignment, home site, document upload/edit/delete, fare override, leave adjustment | Refused with `employee_archived`. One guard in the `employee.scope` middleware, which all of these already pass (today it reads nothing for an all-scope user; it now loads the employee for non-GET requests). The guard exempts `DELETE employees/{id}` and the attendance day PUT/DELETE. Swapping codes from an active employee's page with an archived holder stays allowed. |
| `DELETE employees/{id}` | Unchanged (still allowed, still vetoed by open leave requests). My call: delete removes the person everywhere, which is the direction the owner wants, and there is no UI for it. |
| Setting a termination date (null → a date, or changing it) through `PATCH employees/{id}` | Refused with `employee_has_open_items` (409) while a pending or escalated leave request or a pending wage proposal exists; the error lists them. The check runs in `EmployeeService::update`, not in a model hook, so the refusal of a proposed laborer (`DayCrew::decide`, which writes the date while its own proposal is still pending) is untouched. Leave contributes its open requests through provider-level wiring like the delete veto's (`AppServiceProvider`), so Employees does not import Leave; it needs a method that returns the open requests rather than throwing on the first, and the delete's own error code stays. Clearing the date is never refused. Wrap the update in a transaction if the builder finds the race with a request being filed matters. |
| Wage proposal approve / refuse | Unchanged, also for a laborer archived meanwhile: it closes a pending item. |

### Leave
| Surface | After this change |
|---|---|
| Balances list, every year, and its search | Archived hidden. Their balance stays readable on their own page. |
| Requests list | Requests of archived employees are hidden, except pending or escalated ones, which all-scope users keep seeing until decided. When an all-scope caller filters by `employee_id` (the Leave tab of the archived employee's page) nothing is hidden, so their history stays readable there. A site manager sees none (reach). |
| Request show, approve, refuse, escalate, cancel | All scope: unchanged, so a slipped-through request can be closed and an approved one cancelled. Site manager: 404 through reach. |
| Preview and submit a new request | Refused for an archived employee (`employee_archived`). |
| Employee picker in the request dialog | Already hides them (employees list default). |

### Other
- Pending wage proposals list: unchanged; a pending item stays visible to HR until decided.
- Team fares: already hidden.
- Notification inbox: old notifications stay. For a site manager, an old notification about a now-archived employee behaves as it already does for an employee outside reach: the API returns `employee: null` and the bell shows its generic "unknown" text. The bell stops linking such a notification, because the link would now open a 404.
- Audit log: unchanged.

## Frontend

- **Employees list:** the Active/Archive switch shows only to an all-scope viewer (checked by permission name, not role). A site manager who opens `?view=archived` by URL gets the active view.
- **Archived employee page:** a banner under the header, «مؤرشف — ترك العمل في {date}» with the reason when there is one. Every edit affordance on every tab is hidden or disabled (profile form, type/pay/manager/site actions, change ID, document upload/edit/delete, leave request and adjustment, the attendance calendar's day editor). Two actions remain: Reactivate (exists) and «تصحيح بيانات الترك», which edits only the termination date and reason. Document download stays. "Archived" comes from the server field, through one helper.
- **Saving a termination date with open items:** the form shows the refusal and the listed items, with links to them.
- **Attendance grid:** a switch «إظهار من تركوا العمل» next to the name search, kept in the URL like the search, shown only to a user who may edit attendance at all scope. Rows of archived employees carry a small «ترك العمل» mark. Their cells open the day editor as usual.
- **Lock dialog:** missing-day rows of archived employees carry the same mark; the list and the confirm checkbox show when the missing list has rows.
- **Notification bell:** a notification whose employee is `null` is not a link.
- Permission names: the Archive switch needs `employee.view.all` (Developer, CEO, HR); the grid switch needs `attendance.edit.all` (Developer, HR).
- Every new string is an i18n key in Arabic and English. Follows the existing tokens and components; no new dependency.
- Known ceiling, accepted: after an archive or reactivate save only the employees queries refresh, so attendance and leave screens can be stale for up to 30 seconds.

## Docs changed in the same PR

- `CONTEXT.md`: the Archive entry gains the visibility rule (hidden everywhere by default; who can reach; read-only page).
- `docs/calculations.md`: the template rule (archived are not rows), the leave balances list rule (archived left out), and a note that the lock still includes them.
- `docs/hrms-module-plan.md`: the settled decisions above, dated 2026-11-11.

## Tests

Backend (PHPUnit), written first:
- The shared rule at the Cairo midnight edge: termination today = active, yesterday = archived.
- `EmployeeReach`: a site manager gets 404 on show, sub-resources, attendance period, day write and leave for an archived employee they used to reach; `archived=true` returns nothing for them.
- Roster default: grid, sheet, header count, template, balances list, site-day and the 21:00 counts leave an archived employee out; `with_archived=1` brings the grid row back for an attendance editor and is ignored for others.
- Lock and missing list still include the archived employee; derived days are stored for them.
- Import still accepts their row up to the termination date.
- Write guard: each write route keyed by employee id answers `employee_archived`; PATCH with only termination fields passes; reactivating restores every list above.
- Open items: termination refused with a pending leave request, with an escalated one, with a pending wage proposal; allowed once decided; clearing never refused.
- Leave requests list: decided requests of an archived employee hidden, pending shown to HR.
- Existing tests that assert the old visibility are updated, not deleted: `AttendanceTemplateTest:183`, `LeaveBalancesTest:345, 402`, `EmployeesApiTest:241`.

Frontend (Vitest): the Archive switch by permission; the banner and the read-only page; the correction dialog sends only the two fields; the open-items refusal renders; the grid switch sends `with_archived=1` and shows the mark; the lock dialog mark.

Gates: PHPUnit, pint, phpstan, deptrac, Vitest, oxlint, build. Screenshots from the running app on a throwaway copy of the dev database, per UI slice.

## Slices

1. Backend rule, guards and tests. No UI change beyond what the API now withholds.
2. Employees list switch by permission, archived page banner and read-only state, termination correction, open-items refusal.
3. Attendance grid switch and marks, lock dialog marks.

Slices 2 and 3 are each shown to the owner as screenshots before the next step. One PR into `main`; no deploy.

## Left to the builder

- The exact shape of the shared definition (scope vs constant) and of the roster opt-in.
- The permission names for "all-scope viewer" and "may edit attendance at all scope", read from the seeded permissions.
- The error envelope details for the two new codes, following the existing ones (`employee_not_active`, `code_taken`).
- Where the banner sits in the existing page header, following the demo's look.
