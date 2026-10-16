# Daily wage lives on the day laborer, not in a site × wage-tier table

The day-laborer workbook appears to price work by site × wage tier, but its 41 "tier" tables are each a single wage for every site (175 → 500). Their labels (عامل عالي، عامل عادي…) are inconsistent, and 21 workers take their wage from one table and their allowance from another. So each day laborer gets an effective-dated daily wage, and each site gets an allowance rule (a share of the wage or a fixed amount). The original "never put a rate on the employee" rule is dropped. Fares follow the same pattern: a residence × site default plus an optional per-worker override, because 30 of 99 routes are paid differently to different workers.

## Considered Options
- **Keep 41 tier tables as in the Excel**: rejected. It reproduces the confusion, and it still fails the 21 mixed workers.

## Consequences
- If the client confirms that a worker's wage itself differs by site (open question in `docs/client-questions.md`), add a per-worker × site wage override rather than bringing back tiers.
