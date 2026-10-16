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

`app` serves the API with `php artisan serve` on `127.0.0.1:${APP_PORT:-8000}` (`curl http://127.0.0.1:8000/api/v1/health`).
Full stack: `docker compose up -d`, `docker compose exec app php artisan migrate --seed`, then `npm run dev` in `frontend/`.

If the host ports 5432/6379/8000 are taken, set `POSTGRES_PORT` / `REDIS_PORT` / `APP_PORT` in the root `.env`.
The postgres init script only runs on an empty volume; after changing it, `docker compose down -v`.

Layout: `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`; shared code in `app/Support`. Domain imports nothing from `Illuminate\*`.
API lives under `/api/v1` and answers through `App\Support\Http\ApiEnvelope`.
