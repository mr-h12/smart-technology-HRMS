# Backend (Laravel 13 API, PHP 8.4, PostgreSQL 17)

No PHP on the host. Run everything through Docker from the repo root. Copy `.env.example` to `.env` at the root first (compose refuses to start without it); `backend/.env` is the Laravel env (`cp backend/.env.example backend/.env`, then `php artisan key:generate`).

```sh
docker compose up -d                                   # postgres, redis, app (creates hrms + hrms_test on first boot)
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan test               # PHPUnit 12 on PostgreSQL (hrms_test)
docker compose exec app vendor/bin/pint --test         # lint (drop --test to fix)
docker compose exec app vendor/bin/phpstan analyse     # larastan level 8
docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml
```

`nginx` serves the API on `127.0.0.1:${APP_PORT:-8000}` and hands PHP to `app` (php-fpm, pool in `docker/php/zzz-pool.conf`); `curl http://127.0.0.1:8000/api/v1/health`.
Queue workers (`worker-critical|pdf|reports|maintenance`, `queue:work redis --tries=3`) and `scheduler` (`schedule:work`) run from the same image; restart them after code changes (`docker compose restart worker-critical worker-pdf worker-reports worker-maintenance scheduler`).
Full stack: `docker compose up -d`, `docker compose exec app php artisan migrate --seed`, then `npm run dev` in `frontend/`.

If the host ports 5432/6379/8000 are taken, set `POSTGRES_PORT` / `REDIS_PORT` / `APP_PORT` in the root `.env`.
The postgres init script only runs on an empty volume; after changing it, `docker compose down -v`.

Layout: `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`; shared code in `app/Support`. Domain imports nothing from `Illuminate\*`.
API lives under `/api/v1` and answers through `App\Support\Http\ApiEnvelope`.
Role grants and settings are cached in Redis with no expiry (versioned keys, `App\Support\Cache\VersionedCache`). Change them only through `RoleAdministration`, `BusinessRules` or a migration; after any other write (psql, tinker, a seeder) run `docker compose exec app php artisan cache:clear`.
