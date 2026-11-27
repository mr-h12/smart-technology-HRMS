# HR sets the transport fare per team lead (option C)

Date: 2026-11-05. Owner decision: option C, which reverses the morning's option A (team lead writes).
Research: `hr-fares-per-team-lead-research.md` (side-session notes) and `docs/research/2026-11-05-team-lead-fares-options.html`.
Owner of the touched files: Youssef (Sites & rates, `routes/api.php`, `App.tsx`, locales). Mohanad's files are out of scope (see Follow-ups).

## What changes for whom

- **HR** (`fare.manage.all`) is the only writer of transport fares. HR works from one screen, `/team-fares` («مواصلات التيم»): pick a team lead, see that lead's sites, then every worker on the team with residence and the fare in force today. On that screen HR:
  - fills a missing residence inline (through the existing `PATCH /employees/{id}` and `employee.edit`); the residence × site amount then applies on its own;
  - sets the residence × site amount (the dialog's «لكل اللي ساكنين في … وبيشتغلوا في …» option);
  - sets a per-worker override (the dialog's default «للعامل ده بس»).
  Settings → Fares stays HR's flat list of every residence × site amount, with who set it. It is shown to `site.view.all` (HR, CEO); team leads read fares on `/team-fares` only.
- **Team lead** (role `manager`) sees the same page read-only, for their own team and sites only: no edit button, no residence button. *(Superseded for the manager on 2026-11-19: a site manager now works one site at a time on «مواصلات الموقع» and asks HR for a fare change per employee; HR's page is unchanged. See the Fare change request decision in `docs/hrms-module-plan.md`.)* They never see or set wages.
- **CEO** has no team-fares page (the only team-lead list, `/sites/manager-candidates`, needs `site.assign_manager`); the CEO reads every residence × site amount on Settings → Fares, as before.
- **Fix kept from option A**: `GET /fares` returns a manager only their own sites' rows.
- Nobody is notified of a fare write; the audit log holds every write.

## Data shape

No new tables. The two effective-dated tables stay the source of truth, and `FareLookup` stays the one resolver (unchanged from PR #27):

- `fares` (residence × site × effective_from → amount): the default for workers.
- `employee_fare_overrides` (employee × site × effective_from → amount): the per-worker exception.
- `updated_by` on each row says who set it last. A row with no writer is a starting value and reads «من الإكسيل». The one-time migration `2026_11_05_000002_mark_starting_fares_from_excel` clears both writer columns on every existing fare and override, and `MasterDataImport` (through `FareService::addFare` / `addOverride`) clears them on what it writes. The CREATED audit entry keeps the real writer.

The resolved fare of an employee at a site on a date (`FareLookup::describe`):

| Employee | Result | `source` |
|---|---|---|
| any | override in force on the date | `override` |
| permanent worker, day laborer | else residence × site fare in force | `default` |
| permanent worker, day laborer, no `residence_id` | else nothing | `no_residence` |
| anyone | else nothing (amount 0) | `none` |

Engineers never take the residence × site default: only an override. The team lead is a way to find workers, not a pricing key.

## Permission

`fare.manage.all` only, granted by migration `2026_11_05_000001` to every role that holds `site.manage.all` (developer, HR). There is no `fare.manage.own`: the migration is edited in this PR (it has not run anywhere but throwaway databases).
The team lead reads through `employee.view.own`, which the role already holds: the team-fares rows are employees reached through `EmployeeReach`, the reach of that permission. Databases that ran the option A version of 000001 still hold `fare.manage.own` granted to `manager`; migration `2026_11_05_000003_drop_fare_manage_own` deletes that permission (the grant cascades, and the grant cache is cleared after every migrate). It is a no-op on a fresh database.

## API

| Route | Permission | Behaviour |
|---|---|---|
| `GET /fares` | `site.view` | Own scope: only the user's sites. Filter `manager_id` (that manager's sites). Rows add `set_by` (`{id, name}` or null = from Excel). |
| `POST /fares` | `fare.manage` (was `site.manage`) | Same (residence, site, effective_from) updates the amount in place. Refused when a locked period of either worker type ends on or after `effective_from`. |
| `POST /employees/{employee}/fare-overrides` | `fare.manage` + `employee.scope` (was `employee.edit`) | Same key updates in place. Refused when a locked period of the employee's type on that date (every type, if they have none then) ends on or after `effective_from`. |
| `GET /employees/{employee}/fare?site_id=&date=` | `employee.view` + `employee.scope` | Own scope: a `site_id` outside the user's sites is refused (422 `site_id`). |
| `GET /team-fares?date=&manager_id=` | `employee.view` (was `fare.manage`) | Own scope: the user's own team (`manager_id` ignored). All scope: `manager_id` required. `date` defaults to today (Cairo). Unpaginated by decision: a team is tens of rows (ponytail note in `FareService::team`). |
| `PATCH /employees/{employee}` | `employee.edit` + `employee.scope` | Unchanged; the screen sends `{residence_id}` only. |

Writes have no date floor and no reach check (HR reaches everyone). A write records CREATED, or UPDATED with before and after (`Audited`). No notification.

Locked periods: with no later row, a fare or override governs every period after its date, so a write is refused (422 `effective_from`, `errors.fare_period_locked`) when any locked, not reopened, attendance period of a relevant type ends on or after `effective_from`. The check runs inside the write's transaction and holds `attendance_locks` in share mode until the commit (`PeriodGuard::lockedFrom`), so a lock or reopen taken meanwhile waits for the write.

### `GET /team-fares` row

```json
{ "employee_id": "…", "code": 123, "name": "…", "type": "permanent_worker",
  "residence": {"id": "…", "name": "…"} | null,
  "site": {"id": "…", "name": "…"} | null,
  "amount": "35.000000" | null, "source": "override|default|none|no_residence",
  "default_amount": "35.000000" | null,
  "overrides": [{"site_id": "…", "amount": "…"}] }
```

Unchanged from PR #27: team = `EmployeeReach::apply` on the date (home site one of the lead's sites, or engineers assigned to the lead), employed on the date; `sites` lists the lead's live sites.

## Screen: «مواصلات التيم» (`frontend/src/pages/team-fares.tsx`, `/team-fares`)

- Nav link and page for `fare.manage` or `employee.view.own`: HR, developer, team leads. Each button checks the permission of the route it calls.
- `employee.view.all`: team-lead picker first. Own scope: the user's team.
- Subtitle: HR «اختار مسؤول التيم، سجّل السكن الناقص، وحدد المواصلات لكل سكن في كل موقع.»; team lead «مواصلات عمال التيم حسب السكن والموقع.»
- Chips: «كل التيم (N)», one per site, «من غير مبلغ (N)». Table: worker, residence, site, daily fare, source.
- With `employee.edit`, a worker with no residence shows «سجّل السكن», a dialog with one residence picker that sends `PATCH /employees/{id}` `{residence_id}`; the row then shows the residence × site amount, or «مفيش مبلغ للسكن ده»;
- With `fare.manage`, «تعديل» opens the fare dialog: «التلقائي {default}», amount, «يسري من» (today, no floor), scope radio «للعامل ده بس» (default) or the residence × site option. Engineers pick one of the lead's sites and get an override only.
- Without them: no buttons; source labels only. The `no_residence` source reads «مستني السكن» (the residence cell already says «السكن مش متسجل»).

## Settings → Fares (HR's flat list)

One row per residence × site in force today; filter by team lead; columns site, residence, amount, effective from, set by («من الإكسيل»), edit (`fare.manage.all`). The «اتغيرت آخر ٧ أيام» filter (`changed_since`) and «كان X» (`previous_amount`) are removed: they existed to review team-lead edits, and the audit log keeps every before/after.

## Docs (same PR)

- `calculations.md` §3 and §4: HR sets every fare (residence × site default, per-worker override); engineers only by override; day laborers 0 without a fare.
- `CONTEXT.md` Fare: set by HR only; the team lead reads it.
- `hrms-module-plan.md`: the decision (2026-11-05, option C, replacing option A).

## Risks

- Same-key writes are last-write-wins between HR users; the list shows who set each row.
- 94 of 109 real workers have no residence; until HR fills them the page shows many «السكن مش متسجل» rows. That is the point of the inline residence button.
- `employees.residence_id` is not dated, so filling it changes the fare on past dates too, locked periods included. Harmless while no payroll reads fares; payroll must date or snapshot it.
- A residence-only `PATCH` can 422 on `bank_account` for a bank-paid worker with no account; the residence dialog shows that error.

## Follow-ups (after Mohanad's PRs #22, #24, #26 merge)

1. Full/half/none per day, chosen by the team lead with attendance and editable by HR: `SiteDay.php` FORMAT, `site-day-row.tsx`, `lib/site-day.ts`, `SiteDaySaveTest`.
2. The nav entry in `AppLayout.tsx` conflicts with #26; it stays the last commit on the branch.
