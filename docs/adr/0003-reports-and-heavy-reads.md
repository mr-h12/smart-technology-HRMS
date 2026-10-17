# Reports and heavy reads run on queues and stored snapshots, not in the request

Reports, the CEO dashboard and the generated PDFs arrive in Releases 2–4 (payslip PDF and the Excel-difference report in Release 2, the 11 forms in Release 3, reports with Excel/PDF export and site costing in Release 4). The API runs on php-fpm with `pm.max_children = 8`, so one slow request holds one of eight workers. The stack already has what heavy work needs, mirroring the CRM (ADR 0001): queue workers `critical`, `pdf`, `reports` and `maintenance`, a `scheduler`, and Redis. Reads are cheap today: an authenticated request costs 2 queries and no writes before the controller (role grants and settings are cached in Redis under versioned keys, `App\Support\Cache\VersionedCache`), and the frontend caches reads with TanStack Query (30 s stale time, saves invalidate by resource prefix, cache cleared on login, logout and 401). So we keep request-time reads plain and move every heavy job off the request:

- **Exports run as queued jobs.** A PDF or Excel export runs on the `pdf` or `reports` queue, writes a file to storage, and the UI polls an export status that the API contract defines. No request renders a PDF or builds a workbook inline.
- **Closed periods are served from snapshots.** A closed period is read-only, so its report results are computed once at close and stored. Later reads serve the stored data. Only HR's reopen invalidates them, and the next close recomputes them.
- **Open-period summaries are plain indexed queries.** A materialized view or a summary table refreshed by the scheduler is added only after a measured query exceeds its budget.
- **Lists stay paginated** (offset pagination in the CRM envelope). The audit log moves to cursor pagination only when its row count makes `COUNT(*)` measurably slow.
- **Libraries are chosen when the first report needs one.** PDFs follow the CRM (browsershot on the `pdf` queue), confirmed when the payslip PDF is built. No Excel library is picked now.

## Considered Options
- **Render exports and reports inside the request**: rejected. With 8 fpm workers, a few concurrent payroll exports would block manager attendance and every other call.
- **Pre-aggregated tables or materialized views from day one**: rejected. They add refresh jobs and staleness bugs before any query has been shown to be slow. The scheduler can add them later without changing the API.

## Consequences
- Each queued job declares its input, idempotency key, retry limit, failure handling, audit and log behaviour, and an observable status (`docs/coding-standards.md` §12). A retried job must not create a second file, serial number or audit entry.
- A generated PDF is an immutable snapshot that carries its per-form-type serial number. Regenerating a PDF creates a new document; it never overwrites the old one.
- Snapshots must stay consistent with reopen: a reopen deletes or marks the period's snapshots stale in the same transaction that writes its audit entry.
- **Production deploy** (deferred from the dev compose file, which bind-mounts the source):
  - run `php artisan optimize` on deploy;
  - set `APP_DEBUG=false`, `LOG_LEVEL=warning`, `display_errors=Off` and `opcache.validate_timestamps=0`;
  - bake `vendor` into the image instead of bind-mounting it.
  - In dev, 20 parallel health requests take a median of about 22 ms without `optimize` and about 14 ms with it (51 ms on the old `artisan serve` setup). The remaining cost is Laravel booting with uncached config and routes.
- **Production Redis must not use an `allkeys-*` maxmemory policy.** Use `noeviction` or a `volatile-*` policy. The versioned grant cache assumes its version keys are never evicted; evicting one could bring back a revoked permission.
