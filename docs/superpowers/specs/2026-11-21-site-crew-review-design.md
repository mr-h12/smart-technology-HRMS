# Site crew review and site moves by the site manager: design

Date: 2026-11-21. Issue #78. Status: **proposal, waiting for Youssef's approval. No code is written until it is approved.**

Youssef's request (2026-11-19), translated: «As a site manager I have, say, three sites. After I choose a site I want to review each site's employees: if an employee is coming from another site, I add them; if one went to another site, I remove them from mine. Apart from that, I don't want anyone who doesn't belong to this site to show on the site's screen. After that, the attendance screen appears.»

How to read this spec:

- **Decided** is what Youssef said on 2026-11-19 (copied from #78).
- **⚑ Proposed** is an answer this spec suggests to an open question. Each has a reason and the alternative. Youssef ticks it or writes the change beside it. Nothing marked ⚑ is built until it is ticked.
- File and line cites are on `main` at `1124f04`.

This spec assumes PR #72 (site manager workflow) merges first. #72 changes `SiteDayCrew.php`, `DayCrew.php`, `CLAUDE.md`, `CONTEXT.md` and `docs/hrms-module-plan.md`, which the build below also changes. The build PRs start from `main` after #72.

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

⚑ **Proposed, starting site at migration:** each engineer starts on the site of their **latest recorded attendance day that has a site**, if that site is one of their current manager's sites. Every other engineer starts on **no site**. Before deploy, HR runs a dry-run command that lists every engineer and the site they would get, so HR can set the missing ones the same day.

- Reason: it puts most engineers where they actually work today, and nobody is placed on a site their current manager does not cover.
- Alternative: HR picks a site for every engineer before deploy. Safer but more work for HR; the dry-run list makes this possible anyway.

### Q2. "No site"

⚑ **Proposed:** a new forward migration makes `employee_site_assignments.site_id` nullable. A row with `site_id = null` means "on no site from this date". `Employee::SITE_ON` already returns null for a person taken off every crew, so readers already handle null.

- Reason: removal is then the same kind of dated row as a move: one history, audited the same way.
- Alternative: end the last row with an end date. Rejected: the table has no end dates and every reader would change.

### Q3. Day laborers' one-day crew changes

⚑ **Proposed:** the new move **replaces** the site manager's one-day crew change for day laborers. «إضافة عامل يومية» (search and add an existing laborer) becomes the review step's «إضافة شخص». The form that proposes a **new** laborer with a wage stays as it is, inside the review step; the new laborer starts on this site as a home-site row, not a crew change.

- `day_crew_changes` stays: its rows are history and `SITE_ON` keeps reading them for the dates they cover. No new rows are written by the site manager. HR's site workbook import is unchanged (it writes neither).
- Reason: two ways to move one laborer (for today only, or until moved again) would confuse the manager and the reports.
- Alternative: keep both, with a choice "today only / until moved again". More UI and two rules to explain.

### Q4. The date a move starts

⚑ **Proposed:** **today only (Cairo)**. A move or removal on a past date stays HR's (HR's move form already takes any date). The yesterday window does not apply to moves.

- A move inside a locked period is refused with the same check HR's move uses (`EmployeeService::assertUnlocked`). Today is normally not locked, so this is a guard, not a flow.
- A move on the same date as an existing row (the person was already moved today) replaces that row, so the unique `(employee_id, effective_from)` index holds and the last move of the day wins. Audited.
- A **future** row HR already set (for example a return date) stays and takes effect on its date. The review step shows it under the person: «ينتقل إلى X من يوم …».
- Reason: today is the only date the manager actually sees people on site.

### Q5. The search

⚑ **Proposed:** keep the 2026-11-05 rule: **exact employee code or exact full name (Arabic or English), at most one result**, never a list as you type. It now covers the three field types. The result shows name, code, type and where the person is today («في موقع: X», «بدون موقع»). Never a wage, a national ID, a phone or a manager name.

- Reason: a site manager must not be able to page through the company's people (owner, 2026-11-05). The exact lookup is enough when the person is standing in front of them.
- Alternative: list matches as you type, limited to 5. Easier to use, but it lists people outside the manager's reach.

### Q6. HR's notification

⚑ **Proposed:** **at once**, one notification per add or remove, to every holder of `employee.assign_site.all` (HR, Developer today). Kind `site_move`, payload: person, from site, to site (or none), date, manager. A crew change was routine and counted in the 21:00 alert; a move until further notice changes who reaches the person, so HR hears of it at once.

- Alternative: counts in the 21:00 alert, as crew changes today (`docs/hrms-module-plan.md:241`).

### Q7. Open items at the old site

⚑ **Proposed:**

- **An open day** (arrival, no departure) anywhere: the add is **refused** (`person_has_open_day`) until the old site's manager or HR completes it. Reason: otherwise nobody can close it; the old manager loses reach from today.
- **A saved day today at another site:** refused (`person_has_day_elsewhere`), the extended `crew_moved_has_day` rule (assumption 3).
- **A pending or escalated leave request, or another pending request:** the move is **allowed**; the request stays with HR, who decides it as today. From the move date the new site's manager sees it in their lists and the old one does not, because the lists follow reach.
- Alternative for leave: refuse the move until the request is decided. Rejected: it would block a real move for days.

### Q8. Past days

**Confirmed by the model** (⚑ please confirm): days recorded at the old site stay there with their `site_id`. Reach on a past date follows the site on that date, so the old site's manager still reaches the days before the move, inside their usual window, and the new manager does not.

### Q9. Who cannot be moved

⚑ **Proposed:** the search and the add refuse:

- archived employees and anyone not employed on today's date (hire date after today, or termination date before today), as `DayCrew::find` does today;
- the manager's own employee record (`EmployeeReach::exceptOwn`);
- **any employee linked to a site-manager account** (another site manager). Reason: moving a site manager is an HR decision about their sites, not a crew change;
- office staff (not field employees; HR's office site stays a filter only, owner 2026-11-08).

## The assumptions in #78

Each is proposed as written in the issue, with the notes below. ⚑ Youssef ticks each.

- [ ] ⚑ Removing a person leaves them on no site (Q2) until another manager adds them or HR assigns them. They then show on no site's attendance. HR sees them in a new «بدون موقع» filter on the employees list.
- [ ] ⚑ The previous site's manager is notified when someone is taken from their site (at once, kind `site_move`, same payload as HR's).
- [ ] ⚑ A person who already has a saved day at another site on that date cannot be added for that date (Q7).
- [ ] ⚑ The search shows only name, code, type and current site (Q5).
- [ ] ⚑ The review step shows each time a site is opened **for today**, with a single «متابعة» when nothing changed. A past date in the manager's window shows that date's list without the step.
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
| `POST people` | `site_id`, `employee_id`, `request_id` | 200 with the review list. Repeating the same add (same person already on this site today) is a 200 no-op. A repeated `request_id` returns the first answer (`ClientRequestReused`, as #72 adds). |
| `DELETE people/{employee}` | `site_id`, `request_id` | 200 with the review list. Removing someone already on no site today, or no longer on this site, is 404. |

Refusal codes (422, `error.details[0].field = employee_id`, message from `lang/*/attendance.php`):

- `person_has_day_elsewhere`: a saved day today at another site.
- `person_has_open_day`: an arrival with no departure.
- `person_not_movable`: archived, not employed today, office, own record, or a site manager.
- `period_locked`: today's period is locked (guard).

The old routes `GET laborers` and `POST crew` are removed in the same PR as the new screen.

## Permissions

⚑ **Proposed:** a new permission `employee.move_site.own`, granted to `manager` by a migration, checked by name on the four routes together with `attendance.record`. `employee.crew.own` is removed with the old routes (a migration revokes it; the permission row is soft-deleted, never reused). HR and the Developer keep `employee.assign_site.all`, which now also covers engineers and «بدون موقع».

Negative tests: a caller without `employee.move_site.own` gets 403 on each route; a manager on a site that is not theirs gets 404.

## Data and migrations (all forward, none edited)

1. `make_site_assignment_site_nullable`: `alter column site_id drop not null`. Down refuses if a null row exists.
2. `place_engineers_on_sites`: for each engineer not archived, insert one row `effective_from = <deploy date>` with the site of their latest attendance day that has a site (any source: a site manager's, HR's or the site workbook's) when that site is one of their current manager's sites; else no row. Dry run first: `php artisan hrms:place-engineers --dry-run` prints code, name, chosen site or «none». Down deletes only the rows it inserted (it records their ids).
3. `grant_move_site`: adds `employee.move_site.own` to `manager`; revokes `employee.crew.own` in the PR that removes the old routes.

Every add and remove is a new `employee_site_assignments` row (or the replaced row of the same date), written through the `Audited` model, so the audit log has before and after values.

## Code that changes

- `EmployeeReach`: one rule for the three field types by `SITE_ON`; `ENGINEER` goes.
- `Employee::SITE_ON`: unchanged for days that have a crew change; reads the home site otherwise, now for engineers too.
- `EmployeeRoster::onSite` lists the three field types; `engineersOf` is removed, and `SiteDay::reachOn` (`SiteDay.php:390-393`) reads `onSite` alone.
- `EmployeeService::assignSite`: accepts engineers and a null site.
- `EmployeeService::setTeam`: the «make site manager» dialog no longer assigns engineers to the manager; it sets the manager's sites only. HR places engineers on sites with the move form. ⚑ (consequence of Q1)
- `SiteDayCrew` becomes the site manager's move service (`SiteDayPeople`), with the locks and checks above, in one transaction with the person locked, as `SiteDayCrew::add` does.
- `DayCrew::find` searches the three field types.
- Notifications: `site_move` to HR and to the old site's managers.
- Leave and the employee pages follow reach and need no change; tests prove it.

## Tests (each is its own test)

- Reach: a person moved today is reached by the new site's manager from today, not before; by the old site's manager before today, not from today. Same for leave lists and the employee page (`employee.scope`).
- An engineer is listed on one site only; «حضور الكل» on another site of the same manager does not fill them.
- Add from another manager's site; add from no site; remove; repeated add is a no-op; repeated `request_id` returns the first answer.
- Refusals: saved day elsewhere, open day, archived, not employed today, office, own record, a site manager, a site not the caller's, a locked period, no permission (403).
- Two moves of one person on one day: the second replaces the first row.
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
- `docs/hrms-module-plan.md:240-241`: the 2026-11-05 crew decisions marked superseded by 2026-11-19, with the notification rule from Q6.
- `docs/coding-standards.md:129`: the same reach sentence as CLAUDE.md.

## Build order

1. **PR A, data and reach:** migrations 1 and 2, `EmployeeReach`, `SITE_ON` readers, `assignSite` for engineers and no site, the HR move form, `setTeam`, the docs for reach. The site day then lists engineers on one site.
2. **PR B, the review step:** the API, permission, notifications, the review screen, removal of the old crew routes, the remaining docs.

Each PR runs the gates in `backend/CLAUDE.md` and `frontend/CLAUDE.md`.

## Not in scope

- Office staff moves.
- Moves on past dates by a site manager.
- Dating `manager_sites` (ADR 0004 consequence); still undated.
