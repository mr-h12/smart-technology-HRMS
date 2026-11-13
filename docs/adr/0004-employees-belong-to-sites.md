# Field employees belong to sites, not to one manager

CLAUDE.md first said every employee has exactly one manager at a time. On 2026-10-21 the owner changed this: a field employee belongs to a site, and a manager covers one or more sites. So the manager's reach is derived from the sites, and reach is taken on the date being read or written, never today:

- **Workers have a home site.** A permanent worker or day laborer has one dated home site (`employee_site_assignments`) and no manager assignment. Only HR moves a worker, always with a date; the move can carry a return date, so a one-day loan is one action. A worker helping at another site, even for one day, is moved there first. 2026-11-12 (site workbook, owner): a one-day visit recorded by HR's site workbook import is stored on the attendance day's site alone, with no home-site move and no crew change; the write guard below is unchanged for site managers. Pay, fare, site allowance and site costing read only the day's site, and an imported day is HR's and read-only to every site manager, so a move or a crew change would only change whose list shows the visitor.
- **Engineers float between their manager's sites.** An engineer keeps a dated manager (`employee_manager_assignments`) and can be recorded at any site of that manager, with no HR move.
- **Reach on date D:** the workers whose home site on D is one of the manager's sites (`manager_sites`), plus the engineers assigned to the manager on D.
- **Write guard:** every manager write checks that the day's site is one of the manager's sites, and for a worker that it is also the worker's home site on that date.
- **A site may have more than one manager.** Both see its people; the per-day `version` makes the second save of the same day reload instead of overwriting.
- **Office staff are in no manager's reach.** They keep a dated department manager for reference only; HR enters their attendance, leave and requests.

## Considered Options
- **Both links (model A): a worker keeps a manager and also has a site**: rejected. A real-code probe (`ProbeSiteScopeTest`, 2026-10-21) showed the two managers contradict each other: manager a wrote E's day at site S-b, while S-b's own manager b could not read E and saw 0 rows on the day sheet. The site-only model matched today's scope on all 6 replayed (manager, date) sets.

## Consequences
- `manager_sites` is undated: the manager's sites on D are the current set. Moving a site to another manager changes who reaches its past days too. If that matters, date `manager_sites` like the other assignments.
- The migration step 1 importer (`hrms:import-master`, PR #4) writes a manager per employee. After PR #4 merges it needs a site column for workers; the manager column stays for engineers and office staff.
- 2026-11-12 (site workbook): the visited site's manager does not list a one-day visitor HR recorded through the site workbook. The home site's manager (an engineer's own manager) lists that day read-only, at the visited site. A new day laborer the workbook creates is on the visited site's crew for their hire date only, as any proposed laborer.
