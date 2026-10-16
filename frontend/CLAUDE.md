# Frontend

React 19 + Vite + TS, Tailwind 4, shadcn/Radix in `src/components/ui`. Talks to the Laravel API at `/api/v1` through `src/lib/api.ts` (Vite proxies `/api` to `API_PROXY_TARGET`, default `http://localhost:8000`; set it if `APP_PORT` differs).

Full stack: from the repo root `docker compose up -d` and `docker compose exec app php artisan migrate --seed`, then here `npm run dev`.

Run on the host, in this folder:

- `npm install`
- `npm run dev`: dev server
- `npm run build`: typecheck + production build
- `npm run test`: Vitest (locale key parity, api client)
- `npm run lint`: oxlint

Every UI string is an i18n key in `src/locales/ar.json` and `en.json` (same keys in both; a test enforces it). Arabic RTL is the default.
