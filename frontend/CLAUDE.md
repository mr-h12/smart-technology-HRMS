# Frontend

React 19 + Vite + TS, Tailwind 4, shadcn/Radix in `src/components/ui`. Talks to the Laravel API at `/api/v1` through `src/lib/api.ts` (Vite proxies `/api` to `localhost:8000`).

Run on the host, in this folder:

- `npm install`
- `npm run dev`: dev server
- `npm run build`: typecheck + production build
- `npm run test`: Vitest (locale key parity, api client)
- `npm run lint`: oxlint

Every UI string is an i18n key in `src/locales/ar.json` and `en.json` (same keys in both; a test enforces it). Arabic RTL is the default.
