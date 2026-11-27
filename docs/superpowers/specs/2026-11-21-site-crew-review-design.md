# Site crew review and site moves by the site manager: design

Date: 2026-11-21, revised 2026-11-23 after Youssef's review of 2026-11-22. Issue #78. Status: **proposal, waiting for Youssef's approval. No code is written until it is approved.**

Youssef's request (2026-11-19), translated: «As a site manager I have, say, three sites. After I choose a site I want to review each site's employees: if an employee is coming from another site, I add them; if one went to another site, I remove them from mine. Apart from that, I don't want anyone who doesn't belong to this site to show on the site's screen. After that, the attendance screen appears.»

How to read this spec:

- **Decided** is what Youssef said on 2026-11-19 (copied from #78).
- **⚑ Proposed** is an answer this spec suggests to an open question. Each has a reason and the alternative. Youssef ticks it or writes the change beside it. Nothing marked ⚑ is built until it is ticked.
- **Open for Youssef** is a question the review of 2026-11-22 raised that only he can answer. The spec does not decide it; where the build depends on it, both answers are described.
- File and line cites are on `main` at `73ae470` (after #69, #71, #83 and #84).

This spec assumes PR #72 (site manager workflow) and PR #75 (one manager per site) merge first. #72 changes `SiteDayCrew.php`, `DayCrew.php`, `CLAUDE.md`, `CONTEXT.md` and `docs/hrms-module-plan.md`, which the build below also changes. The build PRs start from `main` after #72.

## Decided (Youssef, 2026-11-19)

1. After picking a site and before the attendance screen, the manager sees a **review step** for that site: the people on it, a way to remove someone who went to another site, and a way to add someone who came from another site.
2. The attendance screen then lists **only the people on that site**. Engineers no longer appear on every site of their manager.
3. The manager may take **engineers, permanent workers and day laborers** from any site in the company, another manager's included.
4. It takes effect **at once, with no HR approval**. HR is notified.
5. The person **stays on the new site until someone moves them again**. It is not a one-day loan.
6. An engineer or worker does not work at more than one site on the same day (client question 19).

## The model in one paragraph

Every field employee (engineer, permanent worker, day laborer) has one dated **home site**, or none, in `employee_site_assignments`. A site manager reaches, on date D, the field employees whose site on D is one of their sites. The site manager's review step writes a home-site row dated today, exactly as HR's move does, so one table answers "where is this person on D" for everyone. Days already recorded keep their own `site_id`; fares, site allowance and site costing keep reading the day's site and do not change.

## Answers to the questions in #78

### Q1. The engineer's site

⚑ **Proposed:** engineers get a dated home site in `employee_site_assignments`, like workers. The reach rule becomes one rule for the three field types (today's `EmployeeReach::WORKER`); `EmployeeReach::ENGINEER` goes. `employee_manager_assignments` stays for office staff's department manager and as history; nothing reads it for an engineer's reach any more.

- Reason: one place answers "who is on this site on D"; `Employee::SITE_ON`, the reach, the site list and HR's site filter already read it.
- Alternative: a separate table for engineers' sites. Rejected: two tables for the same fact, two code paths in every reader.

⚑ **Proposed, the cutover (review of 2026-11-22, gap 1):** the new rule applies from a stored **cutover date**, the deploy date, kept in the settings (`engineer_site_cutover`). On a date before it, an engineer's reach stays the old rule (`EmployeeReach::ENGINEER`: the engineer's manager on that date), so nothing changes for days before the deploy:

- the yesterday window still lists the engineers on deploy day (`SiteDay::load`, `SiteDay.php:389-392`);
- an engineer's open day from before the deploy stays the manager's to complete (`SiteDay::openDays`, `:314-344`), so it never blocks today's save as HR-only (`open_earlier_day`, `:175`);
- a backdated sick or leave request for a date before the deploy is still in reach (`LeaveRequests::check`, `LeaveRequests.php:399-401`);
- Q8 holds for engineers too: on dates before the cutover, the manager they had then reaches them.

`EmployeeReach::ENGINEER` stays, limited to dates before the cutover; it is removed only once every period before the cutover is locked (a follow-up, not this build).

- Alternative: no cutover; backdate each placement row to the start of the oldest open engineer period. Simpler code, but it rewrites who reached an engineer on dates already worked, and a period HR reopens later falls before it again.

⚑ **Proposed, starting site at migration:** each engineer starts on the site of their **latest attendance day at a work site** (`sites.kind = work`, `leave_code` null, date on or before today), if that site is one of their current manager's sites. Every other engineer starts on **no site**. Leave day-types are rows in `sites` with `kind = leave` (manager-stored absent, sick, rest and other, approved leave days including future ones, and the Fridays a period lock writes, `AttendanceLocks.php:113`), so they are never picked. Before deploy, HR runs a dry-run command that lists every engineer and the site they would get, so HR can set the missing ones the same day.

- Reason: it puts most engineers where they actually work today, and nobody is placed on a site their current manager does not cover.
- Alternative: HR picks a site for every engineer before deploy. Safer but more work for HR; the dry-run list makes this possible anyway.
- **Open for Youssef (5):** for engineers the migration leaves on no site, does HR place them before the deploy, or after?

### Q2. "No site"

⚑ **Proposed:** a new forward migration makes `employee_site_assignments.site_id` nullable. A row with `site_id = null` means "on no site from this date". The SQL `Employee::SITE_ON` already returns null for a person taken off every crew. The PHP readers do not: they cast the site to a string, so null becomes `''` (review of 2026-11-22, gap 2). Each is listed under "Code that changes" and maps null to null.

- Reason: removal is then the same kind of dated row as a move: one history, audited the same way.
- Alternative: end the last row with an end date. Rejected: the table has no end dates and every reader would change.

### Q3. Day laborers' one-day crew changes

⚑ **Proposed:** the new move **replaces** the site manager's one-day crew change for day laborers. «إضافة عامل يومية» (search and add an existing laborer) becomes the review step's «إضافة شخص». The form that proposes a **new** laborer with a wage stays as it is, inside the review step; the new laborer starts on this site as a home-site row, not a crew change.

- `day_crew_changes` stays: its rows are history and `SITE_ON` keeps reading them for the dates they cover. No new rows are written by the site manager.
- HR's site workbook import is **not** unchanged: it calls `DayCrew::propose` (`SiteWorkbookImport.php:337`), which writes a `day_crew_changes` row (`DayCrew.php:148`). ⚑ Proposed: `propose` takes where to put the new laborer. The site manager's review step writes a home-site row; the workbook import keeps writing its one-day crew row as today, so a workbook-created laborer is not made permanent on that site.
- A move soft-deletes the person's crew row for today, if any, because `SITE_ON` reads the day's crew change before the home site (`Employee.php:27-29`). Without that, a move does not take effect today on deploy day, or for a laborer the workbook proposed today.
- HR's pending wage-proposal list names the site from the first crew change (`DayCrew.php:198`, `:206`). It also reads the home site on the proposal's date when there is no crew change.
- Refusing a new laborer clears their crew changes from today (`DayCrew.php:233-235`). With a home-site row, the refused laborer stays listed at the site on the refusal day. **Open for Youssef (6):** should they? If not, refusing also writes a no-site row dated the refusal day.
- Reason: two ways to move one laborer (for today only, or until moved again) would confuse the manager and the reports.
- Alternative: keep both, with a choice "today only / until moved again". More UI and two rules to explain.

### Q4. The date a move starts

⚑ **Proposed:** **today only (Cairo)**. A move or removal on a past date stays HR's (HR's move form already takes any date). The yesterday window does not apply to moves.

- A move inside a locked period is refused with the same check HR's move uses (`EmployeeService::assertUnlocked`, private at `:613`). Today is normally not locked, so this is a guard, not a flow.
- **Same-day rows (review of 2026-11-22, gap 5).** `employee_site_assignments` is unique on `(employee_id, effective_from)`, and the index is not partial, so soft-deleted rows count (`create_employee_site_assignments.php:21`). A soft delete plus an insert on the same date hits it, and HR's `assignSite` refuses any date that already has a row (`assertFreeDate`, `EmployeeService.php:457`, `:625-629`). ⚑ Proposed: **one write path in Employees**, `EmployeeSites::place(employee, site or null, date, by)`, that HR's form and the site manager's move both call. It checks the lock, and **updates a same-date row in place** (audited, before and after) instead of inserting. So the last change of the day wins, and HR can still correct a person's site for today after a manager's move (assumption 7), or after the migration's row.
- **Open for Youssef (3):** may a site manager override a move HR set for today? If not, `place` refuses a manager's change to a same-date row HR wrote (`site_set_by_hr`).
- A **future** row HR already set (for example a return date) stays and takes effect on its date. The review step shows it under the person: «ينتقل إلى X من يوم …». **Open for Youssef (4):** does a manager's move today cancel that future row, or does the person still go back on that date? Until he answers, the spec keeps it.
- `returns_on` on HR's form puts the person back on "the site they had the day before" (`EmployeeService.php:463-465`). After a no-site row that is no site, not "no earlier row": the check looks for an earlier **row**, and a row whose site is null is one.
- Reason: today is the only date the manager actually sees people on site.

### Q5. The search

⚑ **Proposed:** keep the 2026-11-05 rule: **exact employee code or exact full name (Arabic or English), at most one result**, never a list as you type. It now covers the three field types. The result shows name, code, type and where the person is today («في موقع: X», «بدون موقع»). Never a wage, a national ID, a phone or a manager name.

- Reason: a site manager must not be able to page through the company's people (owner, 2026-11-05). The exact lookup is enough when the person is standing in front of them.
- Alternative: list matches as you type, limited to 5. Easier to use, but it lists people outside the manager's reach.

### Q6. HR's notification

⚑ **Proposed:** **at once**, one notification per add or remove, to every holder of `employee.assign_site.all` (HR, Developer today). Kind `site_move`, payload: ids only (person, from site, to site or none, date, manager), as every notification. A crew change was routine and counted in the 21:00 alert; a move until further notice changes who reaches the person, so HR hears of it at once.

- **One notice per change (review of 2026-11-22, gap 7).** `notifyOnce` is unique on `(user_id, type, date, ref)` (`2026_11_03_100001_add_ref_to_notifications.php:17`). With `ref` = the person or the assignment row, a second change to the same person on the same day is dropped (remove then re-add; "the last move wins"). ⚑ Proposed: `ref` = the change's audit entry id, unique per change.
- **The name in the notice.** The inbox names `data.employee_id` only for people the reader reaches today (`NotificationInbox.php:55-58`). HR reaches everyone. The old site's manager no longer reaches the moved person, so their notice would name no one. **Open for Youssef (7):** should the old site's manager see the moved person's name? If yes, the inbox names the person on a `site_move` notice addressed to its reader. If no, their notice carries only the site names and the count, and says so.

- Alternative: counts in the 21:00 alert, as crew changes today (`docs/hrms-module-plan.md:242`).

### Q7. Open items at the old site

⚑ **Proposed:**

- **An open day** (arrival, no departure) anywhere: the add is **refused** (`person_has_open_day`) until the old site's manager or HR completes it. Reason: otherwise nobody can close it; the old manager loses reach from today. "Open day" is the one `SiteDay::openDays` reads (field days in open periods), so an old open day in a locked period never blocks a move.
- **A saved day today at another site:** refused (`person_has_day_elsewhere`), the extended `crew_moved_has_day` rule (assumption 3). **Open for Youssef (1):** does a leave day saved today at the old site (sick or absent) block the move too, or only a work day? Until he answers, the spec refuses on any saved day.
- **A pending or escalated leave request, or another pending request:** the move is **allowed**; the request stays with HR, who decides it as today. From the move date the new site's manager sees it in their lists and the old one does not, because the lists follow reach.
- Alternative for leave: refuse the move until the request is decided. Rejected: it would block a real move for days.

### Q8. Past days

**Confirmed by the model** (⚑ please confirm): days recorded at the old site stay there with their `site_id`. Reach on a past date follows the site on that date, so the old site's manager still reaches the days before the move, inside their usual window, and the new manager does not.

### Q9. Who cannot be moved

⚑ **Proposed:** the search and the add refuse:

- archived employees and anyone not employed on today's date (hire date after today, or termination date before today), as `DayCrew::find` does today;
- the manager's own employee record (`EmployeeReach::exceptOwn`);
- **any employee linked to a site-manager account** (another site manager). Reason: moving a site manager is an HR decision about their sites, not a crew change. "A site manager" is decided by permission (`SiteService::managerCandidateIds()`), never by role slug, and never by `employees.user_id is not null`, since that link survives a deactivation and a role change;
- office staff (not field employees; HR's office site stays a filter only, owner 2026-11-08).

## The assumptions in #78

Each is proposed as written in the issue, with the notes below. ⚑ Youssef ticks each.

- [ ] ⚑ Removing a person leaves them on no site (Q2) until another manager adds them or HR assigns them. They then show on no site's attendance. HR sees them in a new «بدون موقع» filter on the employees list.
- [ ] ⚑ The previous site's manager is notified when someone is taken from their site (at once, kind `site_move`, same payload as HR's).
- [ ] ⚑ A person who already has a saved day at another site on that date cannot be added for that date (Q7).
- [ ] ⚑ The search shows only name, code, type and current site (Q5).
- [ ] ⚑ The review step shows each time a site is opened **for today**, with a single «متابعة» when nothing changed. A past date in the manager's window shows that date's list without the step. **Open for Youssef (2):** every time, or only the first time each day?
- [ ] ⚑ «حضور الكل» keeps its rule: fill every listed row that has no day. Since a person is listed on one site only, it never claims someone for the wrong site.
- [ ] ⚑ HR can still move anyone on any date, as now; HR's move form now also accepts engineers and «بدون موقع». Office staff are not part of this.

## Flow, screen by screen (375 px first)

1. **Site picker** (`site-day-picker.tsx`): unchanged. Picking a site for today opens step 2; a past date opens step 3.
2. **Review step** (new, `site-day-review.tsx`), title «مراجعة طاقم الموقع», the site name and today's date:
   - one row per person on the site today, grouped «مهندسين», «عمال مثبتين», «عمال يومية»: name, code, and «إزالة» on the right (RTL). A future move HR already set shows as a second line.
   - «إزالة» opens a confirm sheet: «سيُزال X من هذا الموقع ولن يظهر في أي موقع حتى يُضاف أو تنقله الموارد البشرية.» with «إزالة» / «إلغاء».
   - «إضافة شخص» opens one field «الكود أو الاسم بالكامل» and «بحث». The result card shows name, code, type, «في موقع: X» or «بدون موقع», and «إضافة إلى هذا الموقع». If the person is on another site: a confirm line «سيُنقل من موقع X إلى هذا الموقع من اليوم». Refusals show under the field in plain Arabic.
   - «إضافة عامل يومية جديد» opens today's proposed-laborer form (unchanged).
   - «متابعة» at the bottom, sticky on 375 px, opens step 3.
3. **Attendance screen** (`site-day.tsx`): lists only the people on this site on the date. The «مهندسين» section lists only engineers whose site it is. The «في موقع: …» marker stays for days saved elsewhere before the change.

Every string is added to `ar.json` and `en.json`; the screens are checked in RTL and LTR and at 375 px.

## API

All under `/api/v1/attendance/site-day`, envelope and errors as the rest of the API. The site must be one of the caller's sites, else 404 as today (`SiteDayCrew::mySites`).

| Method and path | Body or query | Answer |
|---|---|---|
| `GET people` | `site_id` | `{data: {date, groups: {engineer: [...], permanent_worker: [...], day_laborer: [...]}}}`; a person is `{id, code, name_ar, name_en, type, next_move: ?{site_name_ar, site_name_en, date}}` |
| `GET people/search` | `site_id`, `q` | `{data: {match: ?{id, code, name_ar, name_en, type, site: ?{id, name_ar, name_en}, here: bool, can_add: bool, refusal: ?code}, ambiguous: bool}}` |
| `POST people` | `site_id`, `employee_id` | 200 with the review list. Repeating the same add (the person is already on this site today) is a 200 no-op. |
| `DELETE people/{employee}` | `site_id` | 200 with the review list. Repeating the same remove (the person's row for today, written by this caller, took them off this site) is a 200 no-op. Removing someone this site never had today is 404. |

⚑ **Retries (review of 2026-11-22, gap 6):** no idempotency key. `request_id` is already the server's per-request id (`ApiEnvelope.php:14`, `AuditLog.php:23`), and #72's `client_request_id` is stored on its own table to replay a create. A move has nothing to replay: both repeats are 200 no-ops for the same caller and today, so a network retry of a successful add or remove never shows an error. The review list in the answer is read fresh.

- Alternative: a `client_request_id` store for moves (a table unique on `(user_id, client_request_id)`), whose replay returns the move's own result, not the list. More code for the same outcome.

Refusal codes (422, `error.details[0].field = employee_id`, message from `lang/*/attendance.php`):

- `person_has_day_elsewhere` (add): a saved day today at another site.
- `person_has_day_here` (remove; today's `CrewHasDay`, `SiteDayCrew.php:86-88`): a saved day today at this site. Otherwise the manager records X's arrival, removes X, and loses reach on X (`AttendanceDays.php:489-492`): only HR could enter the departure, and X would have a work day at a site X is not on (review of 2026-11-22, gap 4).
- `person_has_open_day` (add and remove): an arrival with no departure, as `SiteDay::openDays` reads it. On a remove, the open day is at this site.
- `person_not_movable`: archived, not employed today, office, own record, or a site manager.
- `period_locked`: today's period is locked (guard).

The old routes `GET laborers` and `POST crew` are removed in the same PR as the new screen.

## Permissions

⚑ **Proposed:** a new permission `employee.move_site.own`, granted to `manager` by a migration, checked by name on the four routes together with `attendance.record`. `employee.crew.own` is removed with the old routes (a migration revokes it; the permission row is soft-deleted, never reused). HR and the Developer keep `employee.assign_site.all`, which now also covers engineers and «بدون موقع».

Negative tests: a caller without `employee.move_site.own` gets 403 on each route; a manager on a site that is not theirs gets 404.

## Data and migrations (all forward, none edited)

1. `make_site_assignment_site_nullable`: `alter column site_id drop not null`. Down refuses if a null row exists.
2. `place_engineers_on_sites`: stores the cutover date (Q1), then for each engineer not archived inserts one row `effective_from = <cutover date>` with the site of their latest attendance day at a work site (`kind = work`, `leave_code` null, date on or before today; any source: a site manager's, HR's or the site workbook's) when that site is one of their current manager's sites; else a row with no site. Dry run first: `php artisan hrms:place-engineers --dry-run` prints code, name, chosen site or «none». The ids of the rows it inserts are kept in a `migration_engineer_placements` table, which down reads to delete only those rows, then drops.
3. `grant_move_site`: adds `employee.move_site.own` to `manager`; revokes `employee.crew.own` in the PR that removes the old routes by deleting its `role_permissions` rows (that table has no soft delete) and soft-deleting the permission row; `RbacTest`'s lists change with it.

Every add and remove goes through `EmployeeSites::place` (Q4): a new `employee_site_assignments` row, or the same-date row updated in place, written through the `Audited` model, so the audit log has before and after values.

## Code that changes

- `EmployeeReach`: one rule for the three field types by `SITE_ON` from the cutover date; `ENGINEER` stays for dates before it (Q1).
- `Employee::SITE_ON`: unchanged for days that have a crew change; reads the home site otherwise, now for engineers too.
- `EmployeeRoster::onSite` lists the three field types. `engineersOf` stays for dates before the cutover only. Its callers, each listing engineers by site from the cutover:
  - `SiteDay::load` (`SiteDay.php:389-392`), which builds the day's list;
  - `SiteDay::reachOn`, which `openDays` uses;
  - `EmployeeRoster::siteWorkbookPeople` (`EmployeeRoster.php:144`, used by `SiteWorkbookTemplate.php:112`), HR's workbook list.
- Null site, mapped to null instead of `''` (Q2):
  - `EmployeeRoster::row` (`EmployeeRoster.php:320`) and `DayCrew::homeOf` (`DayCrew.php:272`), so `Employment::homeSiteOn` returns null for "no site";
  - `AttendanceGrid::rowSite` (`AttendanceGrid.php:275`), so `needs_site` (`:219`) is true and HR's sheet offers «set site» for a person on no site;
  - `EmployeeService::show` returns `current_site: null`, not `{id: null, …}` (`EmployeeService.php:164`, `:177`);
  - the search's `site` and `can_add` (today `SiteDayCrew::search`, `SiteDayCrew.php:44-51`) treat no site as no site.
- `EmployeeSites::place` (new, Employees application, Q4): the one write path for a home site. `EmployeeService::assignSite` calls it and accepts engineers and a null site; the site manager's move calls it too.
- `AttendanceDays::assertInReach` (`AttendanceDays.php:489-491`) checks the home site for workers only; from the cutover, engineers too.
- `DayCrew::propose` takes where to put the new laborer (Q3); `DayCrew`'s pending list reads the home site when there is no crew change; the refusal follows Youssef's answer to open question 6.
- `EmployeeService::setTeam`: the «make site manager» dialog no longer assigns engineers to the manager; it sets the manager's sites only. HR places engineers on sites with the move form. ⚑ (consequence of Q1)
- `SiteDayCrew` becomes the site manager's move service (`SiteDayPeople`), with the locks and checks above, in one transaction with the person locked, as `SiteDayCrew::add` does.
- `DayCrew::find` searches the three field types.
- Notifications: `site_move` to HR and to the old site's manager (one, after #75), `ref` = the audit entry id (Q6).
- `AttendanceGrid::missingCounts` and the 21:00 alert's `crew_changes` (`AttendanceGrid.php:289-300`) read zero once no crew change is written: the count is dropped from the alert.
- #76's `FareService::siteTeam` lists every engineer of the team at every site of the manager; from the cutover it lists the engineers on that site, like the site day.
- Leave and the employee pages follow reach and need no change; tests prove it.

## Tests (each is its own test)

- Reach: a person moved today is reached by the new site's manager from today, not before; by the old site's manager before today, not from today. Same for leave lists and the employee page (`employee.scope`).
- An engineer is listed on one site only; «حضور الكل» on another site of the same manager does not fill them.
- Add from another manager's site; add from no site; remove; repeated add is a no-op; repeated `request_id` returns the first answer.
- Refusals: saved day elsewhere, open day, archived, not employed today, office, own record, a site manager, a site not the caller's, a locked period, no permission (403).
- Two moves of one person on one day: the second updates the first row in place; HR then corrects the same day through its form.
- Remove refused with a saved day here today, and with an open day here; a repeated remove is a 200 no-op.
- Before the cutover an engineer is still reached through their manager: yesterday's list on deploy day, an open day before the deploy, a backdated sick request.
- A null site reads as null in `homeSiteOn`, HR's sheet (`needs_site`) and the employee page (`current_site`).
- A remove then a re-add of one person on one day gives two `site_move` notices.
- A future HR row stays and takes effect on its date.
- HR and the old site's manager each get one `site_move` notification per change.
- An audit entry with before and after values for an add and a remove.
- Fares and site costing of a moved person's days follow each day's site (one day before, one after the move).
- Migrations: engineer placement on a copied database with the dry-run output; nullable site down path refuses with a null row.

## Docs this changes (rewritten in the build PR that changes each rule)

- `CLAUDE.md:22`, replace the reach sentences with: «A site manager covers one or more sites and reaches, on each date, the engineers, permanent workers and day laborers whose site on that date is one of their sites. Every field employee has one dated home site, or none. HR moves anyone on any date; a site manager moves a field employee to one of their sites, or off it, from today (owner, 2026-11-19).»
- `docs/adr/0004-employees-belong-to-sites.md`: a new `docs/adr/0005-site-managers-move-field-employees.md` supersedes its points «Only HR moves a worker», «Engineers float between their manager's sites», the reach on D and the write guard. ADR 0004 gets a line at the top: «Partly superseded by ADR 0005 (2026-11-…)».
- `docs/superpowers/specs/2026-10-22-manager-attendance-design.md:33-40`: a note under Part 1 pointing to this spec, the old lines kept as history.
- `CONTEXT.md`: **Site manager** (`:20`) reach as in CLAUDE.md; **Home site** (`:49`) «until HR or a site manager moves them; engineers have one too; a person may be on no site»; **Crew** (`:53`) becomes «the field employees on a site's list for one date», the one-day crew change kept as history only.
- `docs/hrms-module-plan.md:242`: the 2026-11-05 crew decisions marked superseded by 2026-11-19, with the notification rule from Q6; `:310` (item 8) likewise.
- `docs/calculations.md:345` (W14), `docs/superpowers/specs/2026-11-05-team-lead-fares-design.md:69` and `docs/superpowers/specs/2026-11-12-site-workbook-design.md:108`, `:177`: a dated note each, pointing here.
- `docs/coding-standards.md:129`: the same reach sentence as CLAUDE.md.

## Build order

1. **PR A, data and reach:** migrations 1 and 2, the cutover, `EmployeeReach`, the null-site readers, `EmployeeSites::place`, `assignSite` for engineers and no site, the HR move form, `setTeam`, the docs for reach. The site day then lists engineers on one site.
2. **PR B, the review step:** the API, permission, notifications, the review screen, removal of the old crew routes, the remaining docs.

⚑ **A and B deploy together, in one release.** A may merge first, but a deploy of A alone would list engineers on one site with only HR able to move them.

Each PR runs the gates in `backend/CLAUDE.md` and `frontend/CLAUDE.md`.

## Not in scope

- Office staff moves.
- Moves on past dates by a site manager.
- Dating `manager_sites` (ADR 0004 consequence); still undated.

## Open for Youssef (review of 2026-11-22)

The spec's own ⚑ proposals, the seven assumptions and the new permission `employee.move_site.own` are his to approve. These questions are his to answer; the spec does not decide them:

1. Should a leave day saved today at the old site (sick or absent) block the move, or only a work day? (Q7)
2. Should the review step show every time the site is opened today, or only the first time each day? (assumption 5)
3. May a site manager override a move HR set for today? (Q4)
4. When HR has set a future "returns on" row, does a manager's move today cancel it, or does the person still go back on that date? (Q4)
5. For engineers the migration leaves on no site: does HR place them before the deploy, or after? (Q1)
6. Should a refused new laborer stay listed at the site on the refusal day? (Q3)
7. Should the old site's manager see the moved person's name in the notification, although they no longer reach them? (Q6)
