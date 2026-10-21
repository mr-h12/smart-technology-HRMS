# Release 1b UI Slices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-ship the Release 1b screens as four horizontal slices that match HRMS-DEMO (UI kit, shell, IBM Plex fonts, owner wording, one Settings page, employees, system administration), plus the business-rules settings endpoint and Arabic API messages. Each slice ends in an owner approval gate.

**Architecture:** The frontend ports the demo's UI kit verbatim into `frontend/src/components/`. Pages are rebuilt on it page by page. Native `<select>` and native checkboxes/radios are kept, styled like the demo's triggers. Page-level tabs are Radix Tabs synced to `?tab=`. The backend gains one small `Settings` module (key/value business rules, audited, behind `setting.view` / `setting.manage`) and the Accept-Language middleware from the original plan's Task 9. No payroll code changes, so the October fixtures stay untouched.

**Tech Stack:** React 19 + Vite + TS, Tailwind 4, Radix (tabs, dropdown-menu, tooltip, switch, separator, progress), sonner, react-i18next, react-router 7, Vitest + Testing Library; Laravel 13 / PHP 8.4, PostgreSQL 17, PHPUnit 12, Pint, Larastan, deptrac.

**Spec:** `.superpowers/sdd/release-1b-accounts/research/approved-outline.md` (owner-approved and binding; every slice and task below maps to it). Next to it: `demo-design-brief.md` (the look, with the demo path) and `locale-audit.md` (the wording; transcribed into Task 3 below). Also read `CLAUDE.md`, `frontend/CLAUDE.md`, `backend/CLAUDE.md` and `CONTEXT.md`. The house format and the original Tasks 8–9 are in `docs/superpowers/plans/release-1b-accounts.md`. This plan replaces those two tasks: Task 8 ships here as Tasks 5 and 8, Task 9 as Task 12.

**Demo (read-only, never run):** `/private/tmp/claude-501/-Users-yousefhasabo-HRMS-Smart-TECH/2467f30f-f58e-4a9e-b067-85ab930caff8/scratchpad/demo/HRMS-DEMO`. Paths below are relative to its `src/`. When a task says "port verbatim", copy the file and change only what the task lists. The usual changes are English literals → `t()` keys and `@/utils/*` imports → local helpers.

**CRM reference clone (Task 12 only, read-only):** `/private/tmp/claude-501/-Users-yousefhasabo-HRMS-Smart-TECH/e305f12b-0482-4c03-9bd7-c5daa690b35e/scratchpad/crm-ref/crm`.

## Operating mode (binding, from the outline)

- The session that runs this plan is the **controller only**. It plans, dispatches, reviews and keeps the ledger (`.superpowers/sdd/release-1b-accounts/progress.md`). It never edits code.
- Every implementer and reviewer is a fresh subagent on **Opus 5.5** (`model: "opus"`). Use one implementer per task.
- Each implementer brief starts with: load `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `superpowers:test-driven-development`. UI tasks (1, 2, 3, 5–11) also load `ui-ux-pro-max:ui-ux-pro-max`, read the demo files the task names, and run that skill's pre-delivery checks: contrast, visible focus rings, 44 px nav targets, and an `aria-label` on every icon-only button. Each reviewer brief starts with `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and the `superpowers:requesting-code-review` rubric.
- Every slice ends with an **approval gate** (see each slice's last section). The controller starts the preview (`.claude/launch.json`, config `frontend`). It screenshots the listed screens, posts them to the owner and waits. No task of the next slice starts before the owner approves.
- At ~35 % context the controller writes the handoff (`/session-end`) and ends the turn.
- Branch: stay on `feature/release-1b-accounts`. Open one PR to `main` after Slice 4. Do not open an interim PR.

## Open questions for the owner

- ✅ `enum.document.work_stub`: owner ruled 2026-10-17 — ar `عقد عمل`, en `Employment contract` (Task 3).
- ✅ Business-rule grants (Task 4): controller ruling — Developer view+manage, HR view+manage, CEO view only, Managers nothing (matches CLAUDE.md: HR owns settings, CEO reads). Owner can change at gate 1.
- ✅ Bank-account message (Task 12): controller ruling — use "رقم الحساب البنكي مطلوب عند صرف المرتب بتحويل بنكي." Owner can change at gate 4.

## Outline points this plan settles (the outline was silent or asked for data the API does not return)

- Columns the API cannot fill without new backend work are dropped from this release. They are named so the owner sees them at the gate:
  - Sites: allowance-rule summary and managers count. The rules show in the البدلات dialog, the managers in the المديرون dialog.
  - Residences: fare count.
  - Employees list: current manager. It is on the detail page.
  - Employees list: the active filter. The list endpoint has no `active` filter.
  - Accounts: last login.
  - Audit: the event filter. `/audit-log` has no `event` filter.
  - Roles list: the active flag.
- The employees list has no row actions dropdown. Its only action would be "open", which the row click (and Enter on a focused row) already does.
- Settings has one `PageHeader` with the subtitle. Each tab's own title and description sit in its Card header, as in the demo's `SettingsPage`, instead of one PageHeader per tab.
- In the employees list the name subtext is `name_en`, not the type: the type already has its own badge column, and repeating it would duplicate text in the row.
- The demo's `ui/popover.tsx` and `@radix-ui/react-avatar` are not ported, because no screen uses them. `@radix-ui/react-progress` is added because the verbatim `ui/misc.tsx` imports it.
- The outline asks for the matrix test with "count unchanged". With the 15-permission fixture, the matrix renders 11 checkboxes and 2 three-way radio groups (`employee.view` and `site.view` have own and all scopes), all disabled when read-only.
- `GET /users` requires `user.view`. The manager dialogs (Tasks 5 and 8) load users from it, so they work for the seeded HR and Developer roles only. A custom role holding only an assign permission would get a 403 inside the dialog. This is accepted and deferred.
- Original plan Task 8's test calls `renderWithAuth` around a component that renders its own `MemoryRouter`. That call needs the `{ router: false }` option, which the original plan described in prose but left out of the code. It is added here.

## Global Constraints

- Frontend commands run on the host in `frontend/`. Backend commands run from the repo root as `docker compose exec app …`.
- The gates below must be green before every commit.
  - Frontend: `cd frontend && npm test && npm run build && npm run lint`
  - Backend (Tasks 4 and 12; run them after any task too if it touched `backend/`):
    - `docker compose exec app php artisan test`
    - `docker compose exec app vendor/bin/pint --test`
    - `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G`
    - `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`
- TDD: write the failing test and watch it fail before writing the code.
- Verbatim from `CLAUDE.md`:
  - "Every UI string goes through i18n keys in both Arabic (RTL, default) and English."
  - "Roles and permissions are data (CRM-style `resource.action.scope`), editable from the developer console. Check permissions by name, never by role name."
  - "Money: PostgreSQL `NUMERIC(18,6)` with bcmath decimal strings (as the CRM's `Precision`/`Decimal`). Never use floats. Do not round intermediate values (the Excel doesn't); display 2 decimals."
  - "Time zone `Africa/Cairo`; store UTC."
  - "Every create/update/delete, approval, override, login and period lock/reopen writes an audit-log entry with before/after values."
  - "These numbers are **settings** seeded with the values below. Read them from settings and keep them out of code literals" (Business rules).
- i18n:
  - Every new string is a key in both `src/locales/ar.json` and `src/locales/en.json`, and the two files have the same key set. `src/locales/locales.test.ts` enforces this.
  - Arabic is the default language. Its register is formal Egyptian business Arabic, as in the demo.
  - When a task removes the last use of a key, delete the key from both files.
- RTL: use logical classes only (`start/end`, `ps/pe`, `ms/me`, `border-s/e`, `text-start/end`). Directional icons get `rtl:-scale-x-100`. Never use `left/right/pl/pr/ml/mr`, except Radix props such as Sheet `side`.
- Look:
  - Port the demo class strings verbatim. Use only the tokens in `src/index.css`. Do not add colours or tokens.
  - Page roots are `space-y-6`. Pages start with `PageHeader`.
  - Lists sit in a `Card` with a `Table`. A table scrolls inside its Card (the existing `Table` wrapper has `overflow-x-auto`), never the page.
  - Secondary columns hide with `hidden md:table-cell`, `hidden lg:table-cell` or `hidden xl:table-cell`.
  - Breakpoints: sidebar at `lg`, breadcrumb at `md`, toggles at `sm`. The existing `DialogContent` turns dialogs into bottom sheets below `sm`.
- Controls:
  - Selects are native `<select className={selectCls}>` (through `SelectField` or `FilterSelect`). Checkboxes and radios are native `<input>` elements. Never use Radix Select: tests rely on `selectOptions` and on the `checkbox`/`radio` roles.
  - Row actions go through `RowActions` (Task 1), which is a DropdownMenu with **`modal={false}`**. A modal menu that opens a dialog leaves `pointer-events:none` on `<body>`, which also breaks user-event clicks. A dialog opened from a menu item is controlled (`open` / `onOpenChange`).
  - Page tabs are Radix `Tabs`, synced to `?tab=` through `useSearchParams` (`setSearchParams({ tab }, { replace: true })`). A missing, unknown or hidden tab value shows the first visible tab.
  - Toasts: call `toast.success(t('common.saved'))` only where a task says so. `<Toaster>` lives in `AppLayout`.
- Numbers, dates and money:
  - Digits are Latin in both languages. Intl formatters take `intlLocale(i18n.language)` from `src/components/form.tsx` (`'ar'` → `'ar-EG-u-nu-latn'`).
  - Counts render as plain numbers. Stored dates (`YYYY-MM-DD`) render as stored. Timestamps go through `useCairoTime()`.
  - Money and rule values stay strings end to end. Never call `Number()` or `parseFloat` on them.
  - Number inputs use `TextField` with `inputMode="decimal"` or `inputMode="numeric"`, so Arabic-Indic digits are converted to ASCII.
- Permissions: check them with `useAuth().can('resource.action')` only. Never compare role slugs.
- Tests:
  - Use Testing Library queries by role and accessible name.
  - Reuse `mockFetch`, `json`, `pagination`, `me`, `renderApp` and `renderWithAuth` from `src/test-utils.tsx`. `mockFetch` route keys ignore the query string.
  - When the API returns the full record after a write, render the returned record instead of refetching it.
- Dependencies: add none beyond what Task 1 installs.
- Backend:
  - Module layout is `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`. Presentation may not touch Infrastructure (deptrac).
  - Every response goes through `App\Support\Http\ApiEnvelope`. Routes sit inside the `auth` group with `permission:resource.action`.
  - Permissions are seeded in migrations. The precedent is `2026_10_18_000002_create_permissions_tables.php`.
  - New tables use `standardColumns()`, and their models use `HasStandardColumns` and `Audited`.
  - Feature tests use `RefreshDatabase` and `signIn()`.
  - Read the acting user as `Auth::hasUser() ? Auth::id() : null`.
- Ponytail: no speculative abstraction, the fewest files possible, and comments only for a non-obvious why.
- Commit once per task on `feature/release-1b-accounts`, with the task's subject and the trailer as a second paragraph: `git commit -m "<subject>" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"`.

## Review Focus

1. Old bookmarks (`/sites`, `/residences`, `/fares`) and a bad `?tab=` must land on the right Settings tab, never on a 404 or a blank panel. Tests: Task 5 `old routes redirect to their settings tab` and `an unknown tab falls back to sites`.
2. Business-rule values typed with Arabic-Indic digits (`٢٠`), with a comma decimal (`1,5`) or sent as a JSON number. The UI sends ASCII strings. The API answers 422 under the field and keeps the old value. Tests: Task 4 `test_put_rejects_malformed_values_under_their_field` and Task 6 `rules tab loads the settings, saves typed values and toasts`.
3. A view-only role (the CEO: `setting.view` without `setting.manage`) sees the rules read-only with no save button. A role without `setting.view` sees no rules tab. Tests: Task 6 `view-only users see the rules read-only` and `the rules tab is hidden without setting.view`.
4. A dialog opened from a row menu, closed, and then another control clicked: the page must stay clickable, with no `pointer-events:none` left on `<body>`. Test: Task 5 `a dialog opened from a row menu resets on reopen and leaves the page clickable`.
5. An audit entry whose old or new side is `null` (CREATED, DELETED) or holds a nested object. The details sheet shows `—` for the missing side and JSON text for objects, never `null` or `[object Object]`. Test: Task 11 `details sheet shows previous and new values side by side`.

---

# Slice 1 — Design system, shell, wording, Settings page

### Task 1: UI kit and fonts (outline 1.1)

**Files:**
- Create (port verbatim from the demo): `frontend/src/components/ui/{badge,card,dropdown-menu,tabs,misc}.tsx`, `frontend/src/components/common/{PageHeader,EmptyState}.tsx`, `frontend/src/components/cards/StatCard.tsx`
- Create (port with the changes listed in Step 4): `frontend/src/components/common/{StatusBadge,UserAvatar,Filters}.tsx`, `frontend/src/components/tables/Pagination.tsx`, `frontend/src/components/layout/PageSkeleton.tsx`
- Create: `frontend/src/components/common/RowActions.tsx`
- Create: `frontend/public/fonts/ibm-plex-sans-latin-{400,500,600,700}-normal.woff2`, `frontend/public/fonts/ibm-plex-sans-arabic-arabic-{400,500,600,700}-normal.woff2`, `frontend/public/fonts/LICENSE-IBMPlexSans.txt`, `frontend/public/fonts/LICENSE-IBMPlexSansArabic.txt`
- Delete: `frontend/public/fonts/inter-latin-*.woff2`, `frontend/public/fonts/noto-sans-arabic-arabic-*.woff2`, `frontend/public/fonts/LICENSE-Inter.txt`, `frontend/public/fonts/LICENSE-NotoSansArabic.txt` (Noto Sans Mono stays)
- Modify: `frontend/package.json` and `package-lock.json`, `frontend/src/index.css`, `frontend/src/components/form.tsx` (`intlLocale`), `frontend/src/components/layout/AppLayout.tsx` (Toaster only), `frontend/src/test-setup.ts`, `frontend/src/locales/{ar,en}.json`, `frontend/src/pages/employees.tsx` and `frontend/src/pages/audit.tsx` (the `common.pageOf` parameter), `frontend/src/pages/sites.test.tsx` (the `fmt` locale)
- Test: `frontend/src/components/kit.test.tsx`

**Interfaces:**
- Consumes: the demo files named above; the existing `ui/{button,dialog,input,label,select,sheet,table}.tsx` (already demo ports; leave them unchanged).
- Produces (later tasks import exactly these):
  - `Badge({ variant })`, where `variant` is one of `default|secondary|outline|success|warning|danger|info|violet|neutral`, and the `BadgeVariant` type.
  - `Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`, `CardFooter`.
  - `DropdownMenu*`; `Tabs`, `TabsList`, `TabsTrigger`, `TabsContent`.
  - `Tooltip({ content, side })`, `TooltipProvider`, `Switch`, `Separator`, `Skeleton`, `Progress`.
  - `PageHeader({ title, description?, actions? })`.
  - `EmptyState({ icon, title, description?, action?, className? })`.
  - `StatusBadge({ variant: BadgeVariant, label: string, dot?: boolean, className? })`.
  - `UserAvatar({ name, size?: 'xs'|'sm'|'md'|'lg'|'xl', className?, ring? })` and `PersonCell({ name, subtitle?, size?, onClick? })`.
  - `SearchInput({ value, onChange, placeholder, className? })`.
  - `FilterSelect({ value, onChange, label, allLabel, options: [string, string][] })`. It renders a native select whose first option has value `''`.
  - `RowActions({ items: { label: string; onSelect: () => void; destructive?: boolean }[] })`. It renders nothing when `items` is empty.
  - `StatCard`, `MiniStat({ label, value, tone?, icon? })`, and the `Tone` type.
  - `Pagination({ page, pageSize, total, onPageChange })` and `paginate<T>(items: T[], page: number, pageSize: number): T[]`.
  - `TableSkeleton({ rows? })`.
  - `intlLocale(lng: string): string` in `form.tsx`.
  - i18n keys: `common.showing`, `common.pageOf` (its parameter is now `pages`), `common.actions`, `common.clearSearch`, `common.clearFilters`, `common.noResults`, `common.noData`.

- [ ] **Step 1: Install the dependencies (versions are the demo's)**

```bash
cd frontend
npm install @radix-ui/react-tabs@^1.1.22 @radix-ui/react-dropdown-menu@^2.1.25 @radix-ui/react-tooltip@^1.3.0 @radix-ui/react-switch@^1.3.8 @radix-ui/react-separator@^1.1.16 @radix-ui/react-progress@^1.1.17 sonner@^2.0.8
npm install -D @fontsource/ibm-plex-sans @fontsource/ibm-plex-sans-arabic
```

- [ ] **Step 2: Write the failing test** (`frontend/src/components/kit.test.tsx`)

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import '@/i18n'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { intlLocale } from '@/components/form'
import { Pagination } from '@/components/tables/Pagination'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'

const user = userEvent.setup()

test('Card, StatusBadge and Tabs render and switch panels', async () => {
  render(
    <Card>
      <CardHeader><CardTitle>عنوان</CardTitle></CardHeader>
      <CardContent>
        <StatusBadge variant="success" label="نشط" />
        <Tabs defaultValue="a">
          <TabsList>
            <TabsTrigger value="a">أ</TabsTrigger>
            <TabsTrigger value="b">ب</TabsTrigger>
          </TabsList>
          <TabsContent value="a">لوحة أ</TabsContent>
          <TabsContent value="b">لوحة ب</TabsContent>
        </Tabs>
      </CardContent>
    </Card>,
  )
  expect(screen.getByText('عنوان')).toBeInTheDocument()
  expect(screen.getByText('نشط')).toBeInTheDocument()
  expect(screen.getByText('لوحة أ')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'ب' }))
  expect(screen.getByText('لوحة ب')).toBeInTheDocument()
  expect(screen.queryByText('لوحة أ')).toBeNull()
})

test('Pagination shows the range in Arabic and pages forward', async () => {
  const onPageChange = vi.fn()
  render(<Pagination page={1} pageSize={12} total={30} onPageChange={onPageChange} />)
  expect(screen.getByText('عرض 1–12 من 30')).toBeInTheDocument()
  expect(screen.getByText('صفحة 1 من 3')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'السابق' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  expect(onPageChange).toHaveBeenCalledWith(2)
})

test('RowActions opens its menu and runs the chosen item', async () => {
  const onSelect = vi.fn()
  render(<RowActions items={[{ label: 'تعديل', onSelect }]} />)
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  expect(onSelect).toHaveBeenCalledOnce()
})

test('Arabic numbers and dates use Latin digits', () => {
  expect(intlLocale('ar')).toBe('ar-EG-u-nu-latn')
  expect(intlLocale('en')).toBe('en')
  expect(new Intl.NumberFormat(intlLocale('ar'), { minimumFractionDigits: 2 }).format(7.5)).toBe('7.50')
})
```

- [ ] **Step 3: Run it and see it fail**

Run: `cd frontend && npx vitest run src/components/kit.test.tsx`
Expected: FAIL (cannot resolve `@/components/common/RowActions` and the other new modules).

- [ ] **Step 4: Port the kit**

- Port verbatim: `ui/badge.tsx`, `ui/card.tsx`, `ui/dropdown-menu.tsx`, `ui/tabs.tsx`, `ui/misc.tsx`, `common/PageHeader.tsx`, `common/EmptyState.tsx`, `cards/StatCard.tsx`.
- `common/StatusBadge.tsx`: keep the demo markup and its `DOT` map. Replace the English `MAP` and the `status` prop with `variant` and `label`.
- `common/UserAvatar.tsx`: port it verbatim. Inline the demo's `avatarColor` (with `AVATAR_COLORS`, from `utils/colors.ts`) and `initials` (from `utils/format.ts`) into the same file.
- `common/Filters.tsx`:
  - `SearchInput` is verbatim, except that the clear button's `aria-label` is `t('common.clearSearch')`.
  - `FilterSelect` becomes a native `<select aria-label={label} className={cn(selectCls, 'w-full sm:w-44', value !== '' && 'border-primary/40 bg-primary/5 text-foreground')}>`. Its first option is `<option value="">{allLabel}</option>`.
- `common/RowActions.tsx`: wrap the trigger in `<span onClick={(e) => e.stopPropagation()}>` so a row click does not fire. Inside it, render `<DropdownMenu modal={false}>`. The trigger is a `Button variant="ghost" size="icon-sm" aria-label={t('common.actions')}` holding `<MoreHorizontal />`. Use `DropdownMenuContent align="end"`. Each item is a `DropdownMenuItem onSelect={item.onSelect}` with `variant="destructive"` when `destructive` is set.
- `tables/Pagination.tsx`: keep the demo markup. The text is `t('common.showing', { from, to, total })` and `t('common.pageOf', { page, pages })`, as plain numbers with no `formatNumber`. The icon buttons get `aria-label={t('common.prev')}` and `aria-label={t('common.next')}`. Keep `paginate`.
- `layout/PageSkeleton.tsx`: port only `TableSkeleton`. Its root gets `aria-busy="true"` and `aria-label={t('auth.loading')}`, and no `role="status"` (tests use `status` for the too-many notice).
- `form.tsx`: add `export const intlLocale = (lng: string) => (lng === 'ar' ? 'ar-EG-u-nu-latn' : lng)`. Make `useCairoTime` and `useMoney` use `intlLocale(i18n.language)`.
- `sites.test.tsx`: `fmt` uses `new Intl.NumberFormat('ar-EG-u-nu-latn', …)`.
- `employees.tsx` and `audit.tsx`: pass `{ page, pages: … }` to `t('common.pageOf')`, replacing `total`.
- `AppLayout.tsx`: render this inside the root `div`, using the `theme` from the existing `useTheme()` call and `rtl = i18n.language === 'ar'`:

  ```tsx
  <Toaster theme={theme} dir={rtl ? 'rtl' : 'ltr'} position={rtl ? 'top-left' : 'top-right'} richColors closeButton toastOptions={{ className: 'font-sans' }} />
  ```

  This is the demo's `App.tsx` line 75.
- `test-setup.ts`: add `globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver` (Radix Popper needs it). If another Radix primitive throws on a jsdom API that is missing, stub that API here and nothing else.
- Fonts:

  ```bash
  cd frontend
  for w in 400 500 600 700; do
    cp node_modules/@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-$w-normal.woff2 public/fonts/
    cp node_modules/@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-$w-normal.woff2 public/fonts/
  done
  cp node_modules/@fontsource/ibm-plex-sans/LICENSE public/fonts/LICENSE-IBMPlexSans.txt
  cp node_modules/@fontsource/ibm-plex-sans-arabic/LICENSE public/fonts/LICENSE-IBMPlexSansArabic.txt
  git rm public/fonts/inter-latin-* public/fonts/noto-sans-arabic-arabic-* public/fonts/LICENSE-Inter.txt public/fonts/LICENSE-NotoSansArabic.txt
  ```

  If a source path differs, list the files with `ls node_modules/@fontsource/ibm-plex-sans*/files | grep -- '-normal.woff2'`. Take the `latin` subset of IBM Plex Sans and the `arabic` subset of IBM Plex Sans Arabic. If the licence file has another name, list it with `ls node_modules/@fontsource/ibm-plex-sans*/`.
- `index.css`:
  - Replace the eight Inter and Noto Sans Arabic `@font-face` blocks with eight new ones: `'IBM Plex Sans'` → `url('/fonts/ibm-plex-sans-latin-<w>-normal.woff2')` and `'IBM Plex Sans Arabic'` → `url('/fonts/ibm-plex-sans-arabic-arabic-<w>-normal.woff2')`, for weights 400, 500, 600 and 700, with `font-display: swap`.
  - Set `--font-sans: 'IBM Plex Sans', 'IBM Plex Sans Arabic', sans-serif;` and update its comment.
  - Keep `html[lang='ar'] * { letter-spacing: 0 !important }`, `tabular-nums` and the Noto Sans Mono faces.
- Locale keys (ar / en):

  | Key | ar | en |
  |---|---|---|
  | `common.showing` | `عرض {{from}}–{{to}} من {{total}}` | `Showing {{from}}–{{to}} of {{total}}` |
  | `common.pageOf` | `صفحة {{page}} من {{pages}}` | `Page {{page}} of {{pages}}` |
  | `common.actions` | `الإجراءات` | `Actions` |
  | `common.clearSearch` | `مسح البحث` | `Clear search` |
  | `common.clearFilters` | `مسح عوامل التصفية` | `Clear filters` |
  | `common.noResults` | `لا توجد نتائج` | `No results` |
  | `common.noData` | `لا توجد سجلات بعد.` | `No records yet.` |

- [ ] **Step 5: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS. Then open `npm run dev` once and confirm in the browser's network tab that the Plex files load and nothing returns 404.

- [ ] **Step 6: Commit**

```bash
git add frontend
git commit -m "Port the demo UI kit and switch to IBM Plex Sans and IBM Plex Sans Arabic" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: App shell and login (outline 1.2)

**Files:**
- Modify: `frontend/src/components/layout/AppLayout.tsx` (rewrite), `frontend/src/hooks/useTheme.tsx` (shared store), `frontend/src/pages/login.tsx`, `frontend/src/pages/login.test.tsx`, `frontend/src/locales/{ar,en}.json`
- Test: `frontend/src/components/layout/AppLayout.test.tsx`

**Interfaces:**
- Consumes: Task 1 `Tooltip`, `TooltipProvider`, `DropdownMenu*`, `UserAvatar`, `Card`; the existing `useAuth()` (`me`, `logout`, `can`), `useTheme()`, `SegmentedControl` and `Sheet`.
- Produces:
  - In `AppLayout.tsx`:
    - `NAV: { group: string; items: { path: string; key: string; icon: LucideIcon; ability: string }[] }[]`.
    - `homePath(can)`, with the signature unchanged; it returns the first visible item's path, else `/employees`.
    - `LanguageToggle()`, `ThemeToggle()`, `BrandMark({ className? })`, `BrandName({ product })` and `AppLayout()`.
  - `NAV` for now:
    - `nav.group.hr`: `/employees` (`nav.employees`, `Users`, `employee.view`).
    - `nav.group.settings`: `/sites` (`nav.sites`, `MapPin`, `site.view`), `/residences` (`nav.residences`, `House`, `site.view`), `/fares` (`nav.fares`, `Bus`, `site.view`). Task 5 replaces these three with one Settings item.
    - `nav.group.admin`: `/console/accounts` (`nav.accounts`, `UserCog`, `user.view`), `/console/roles` (`nav.roles`, `ShieldCheck`, `role.view`), `/console/audit` (`nav.audit`, `ScrollText`, `audit_log.view`).
  - A group renders only when one of its items is visible.
  - i18n keys: `nav.group.hr`, `nav.group.settings`, `nav.group.admin`, `nav.collapse`, `nav.expand`, `nav.profile`, `nav.breadcrumb`, `app.product`, `auth.title`. `nav.console` is removed, because `nav.group.admin` replaces it.

- [ ] **Step 1: Write the failing tests**

`frontend/src/components/layout/AppLayout.test.tsx`:
```tsx
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, me, mockFetch, pagination, renderApp } from '@/test-utils'

const user = userEvent.setup()
const signedIn = (permissions: string[]) => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(permissions) }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
  })
}

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('collapsing the sidebar flips aria-expanded and keeps link names', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  const collapse = await screen.findByRole('button', { name: 'طي القائمة الجانبية' })
  expect(collapse).toHaveAttribute('aria-expanded', 'true')
  await user.click(collapse)
  expect(screen.getByRole('button', { name: 'توسيع القائمة الجانبية' })).toHaveAttribute('aria-expanded', 'false')
  expect(screen.getByRole('link', { name: 'الموظفون' })).toBeInTheDocument()
})

test('nav groups appear only when the user may open one of their pages', async () => {
  signedIn(['employee.view.all', 'user.view.all'])
  renderApp('/employees')
  expect(await screen.findByRole('link', { name: 'الحسابات' })).toBeInTheDocument()
  expect(screen.getByText('إدارة النظام')).toBeInTheDocument()
  expect(screen.queryByText('الإعدادات')).toBeNull()
  expect(screen.queryByRole('link', { name: 'الأدوار والصلاحيات' })).toBeNull()
})

test('the breadcrumb names the group and the current page', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  const crumb = await screen.findByRole('navigation', { name: 'مسار التنقل' })
  expect(crumb).toHaveTextContent('الموارد البشرية')
  expect(crumb).toHaveTextContent('الموظفون')
})

test('the profile menu shows the account and signs out from there', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  expect(await screen.findByRole('menuitem', { name: 'خروج' })).toBeInTheDocument()
  expect(screen.getAllByText('hr@smart.test').length).toBeGreaterThan(0)
})

test('the theme item in the profile menu switches the whole app theme', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الوضع الداكن' }))
  expect(document.documentElement.classList.contains('dark')).toBe(true)
  expect(screen.getByRole('button', { name: 'الوضع الداكن' })).toHaveAttribute('aria-pressed', 'true')
  await user.click(screen.getByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الوضع الفاتح' }))
  expect(document.documentElement.classList.contains('dark')).toBe(false)
})
```

In `login.test.tsx`:
- In `sign out calls the API, clears the token and shows the form`, replace `await user.click(await screen.findByRole('button', { name: 'خروج' }))` with these two lines:

  ```tsx
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'خروج' }))
  ```

- At the end of `without a token any page redirects to login`, add `expect(screen.getByRole('heading', { name: 'تسجيل الدخول إلى حسابك' })).toBeInTheDocument()`.

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/components/layout/AppLayout.test.tsx src/pages/login.test.tsx`
Expected: FAIL. The collapse button, breadcrumb, profile menu and login heading do not exist yet.

- [ ] **Step 3: Rebuild `AppLayout.tsx` on the demo shell**

Read the demo's `components/layout/AppLayout.tsx`, `components/sidebar/Sidebar.tsx`, `components/navbar/Navbar.tsx`, `components/navbar/ProfileMenu.tsx` and `components/navbar/SegmentedControl.tsx`. Port their markup and class strings into the one file `AppLayout.tsx`, which keeps the existing `SegmentedControl.tsx` import.
- Root: wrap everything in `<TooltipProvider>`. Keep the skip link (`t('skip')`). The page wrapper is `<main id="page-content" className="mx-auto w-full max-w-[1600px] flex-1 scroll-mt-20 p-4 sm:p-6"><div key={pathname} className="animate-fade-in"><Outlet /></div></main>`.
- Sidebar:
  - The demo `Sidebar` is `w-64`, or `w-[72px]` when collapsed. The `collapsed` state lives in `AppLayout`, with no persistence.
  - `SidebarHeader` has the collapse button (`aria-expanded={!collapsed}`, `aria-label={t(collapsed ? 'nav.expand' : 'nav.collapse')}`, `PanelLeft` with `rtl:-scale-x-100`). Expanded, it also shows `BrandMark` and `BrandName product={t('app.product')}` in a `Link to={homePath(can)} aria-label={t('app.name')}`.
  - `SidebarNav`:
    - It renders `NAV` groups. Each group heading uses the demo heading class; in collapsed mode it becomes the divider.
    - Items use the demo item class, with the 3 px active bar and the icon colours.
    - Collapsed, the label is `<span className="sr-only">` so the link keeps its name, and the link sits inside `Tooltip content={label} side="right"`.
  - Drop the demo's `SidebarFooter`, role switcher, count badges and "workspace" caption.
- Navbar:
  - It holds the mobile menu button (`nav.open`, `lg:hidden`).
  - The breadcrumb is `<nav aria-label={t('nav.breadcrumb')} className="me-auto hidden min-w-0 items-center gap-2 text-table text-muted-foreground md:flex">`, with the group label, `/`, and the page label in `text-section-title text-foreground`. The labels come from the `NAV` item whose path prefixes `pathname`, the longest match first.
  - Then `LanguageToggle` and `ThemeToggle`, each in `hidden sm:block`, then `ProfileMenu`.
  - Drop the demo's GlobalSearch, Notifications and RoleSwitcher.
- `ProfileMenu`:
  - It uses `DropdownMenu modal={false}`. The trigger keeps the demo markup with `aria-label={t('nav.profile')}`: `UserAvatar` plus, at `lg`, `me.name` and the role (`role.name_ar` in Arabic, `role.name` in English).
  - The content shows a header with the avatar, name and email, then a separator.
  - Two items are `sm:hidden`: one toggles the language (label `English` or `العربية`), the other toggles the theme (`toggle.light` / `toggle.dark`).
  - After another separator comes `DropdownMenuItem variant="destructive"` (`LogOut` icon, `t('auth.logout')`), which calls `logout()`.
- Mobile: a `Sheet side="left" className="w-64 p-0"` holds the `SidebarHeader` (without the collapse button) and `SidebarNav` with `onNavigate` closing it. Keep its sr-only title and description.
- `ThemeToggle` is the theme `SegmentedControl` the header already renders, extracted and exported so the login page can use it. Keep the `<Toaster>` from Task 1.
- `hooks/useTheme.tsx` becomes a shared store, so the Toaster theme, `ThemeToggle` and the profile-menu theme item never drift apart.
  - The theme lives in module-level state, read through `useSyncExternalStore`.
  - The hook keeps its name and its return value `{ theme, setTheme }`.
  - `setTheme` keeps today's side effects (the `dark` class on `<html>` and `localStorage` `hrms-theme`) and notifies every subscriber.

- [ ] **Step 4: Restyle `login.tsx` as the demo card**

- Layout: a header with `flex justify-end gap-2 p-3 sm:p-4`, holding `LanguageToggle` and `ThemeToggle`. A `main` with `flex flex-1 items-start justify-center px-4 pt-[10vh]`.
- The form is `<Card className="w-full max-w-sm"><CardContent className="space-y-5 p-6">`. It holds a brand row (`BrandMark className="size-9"` and `BrandName product={t('app.product')}`), then `<h1 className="text-section-title">{t('auth.title')}</h1>`, the `ErrorBanner`, the two `TextField`s (labels above), and `<Button type="submit" className="w-full">`.

- [ ] **Step 5: Add the keys and remove `nav.console`**

| Key | ar | en |
|---|---|---|
| `nav.group.hr` | `الموارد البشرية` | `Human resources` |
| `nav.group.settings` | `الإعدادات` | `Settings` |
| `nav.group.admin` | `إدارة النظام` | `System administration` |
| `nav.collapse` | `طي القائمة الجانبية` | `Collapse sidebar` |
| `nav.expand` | `توسيع القائمة الجانبية` | `Expand sidebar` |
| `nav.profile` | `قائمة الحساب` | `Account menu` |
| `nav.breadcrumb` | `مسار التنقل` | `Breadcrumb` |
| `app.product` | `إدارة الموارد البشرية` | `Human resources` |
| `auth.title` | `تسجيل الدخول إلى حسابك` | `Sign in to your account` |

- [ ] **Step 6: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS, including the unchanged `nav hides what the user cannot do` and `signs in, stores the token and opens the app`.

- [ ] **Step 7: Commit**

```bash
git add frontend
git commit -m "Rebuild the app shell and login on the demo layout" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Wording rewrite and glossary (outline 1.3)

**Files:**
- Modify: `frontend/src/locales/ar.json`, `frontend/src/locales/en.json`, `frontend/src/locales/locales.test.ts`, `CONTEXT.md`
- Modify (test strings only): `frontend/src/pages/{login,sites,employees,console,audit}.test.tsx`, `frontend/src/components/layout/AppLayout.test.tsx`

**Interfaces:**
- Consumes: the keys added by Tasks 1 and 2 (leave them as they are).
- Produces: the final wording for every existing key, plus these new keys, which later tasks use: `nav.settings`, `settings.tab.{sites,residences,leaveTypes,fares,rules}`, `common.saveChanges`, `status.{active,inactive}`, `manager.{title,assign,none}`, `site.managers`, `employee.tab.{profile,manager,history,documents}` and `perm.scope.{none,own,all}`.

- [ ] **Step 1: Write the failing tests** (append to `frontend/src/locales/locales.test.ts`)

```ts
const flat = (o: object, p = ''): [string, string][] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? flat(v, `${p}${k}.`) : [[`${p}${k}`, v as string]]))

test('Arabic copy has no Latin letters outside placeholders', () => {
  const allowed = ['console.ip']
  const latin = flat(ar).filter(([k, v]) => !allowed.includes(k) && /[A-Za-z]/.test(v.replace(/\{\{\w+\}\}/g, '')))
  expect(latin).toEqual([])
})

test('the owner wording rulings are in place', () => {
  const a = Object.fromEntries(flat(ar))
  expect(a['employee.pay_method']).toBe('طريقة صرف المرتب')
  expect(a['nav.group.admin']).toBe('إدارة النظام')
  expect(a['app.name']).toBe('سمارت تكنولوجي لإدارة الموارد البشرية')
  expect(a['enum.pay_method.bank']).toBe('تحويل بنكي')
  expect(a['settings.tab.leaveTypes']).toBe('أنواع الإجازات')
})
```

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/locales/locales.test.ts`
Expected: FAIL. `app.name` contains "HRMS", and the other values are still the old ones.

- [ ] **Step 3: Rewrite `ar.json` (current → new; keep every key not listed)**

| Key | Current | New |
|---|---|---|
| `audit.entity.residences` | محال الإقامة | محل الإقامة |
| `nav.residences`, `nav.fares`, `page.sites`, `page.residences`, `page.fares` | — | unchanged here; deleted in Task 5 |
| `fare.amount` | الأجرة | قيمة المواصلات |
| `fare.add` | — | unchanged here; deleted in Task 5 |
| `fare.tooMany` | عدد الأجور أكبر من 100، قد لا تظهر كل الأجور في الجدول. | يتجاوز عدد السجلات 100، وقد لا تظهر جميعها في الجدول. |
| `audit.entity.fares` | الأجرة | المواصلات |
| `audit.entity.employee_fare_overrides` | أجرة خاصة بالموظف | مواصلات خاصة بالموظف |
| `auth.error.account_locked` | الحساب مقفل بعد محاولات فاشلة كثيرة. تواصل مع المطور. | تم قفل الحساب لتكرار محاولات الدخول الفاشلة. يرجى التواصل مع مسؤول النظام. |
| `auth.error.account_suspended` | الحساب معطّل. تواصل مع المطور. | تم تعطيل الحساب. يرجى التواصل مع مسؤول النظام. |
| `auth.error.rate_limited` | محاولات كثيرة، حاول بعد دقيقة. | تجاوزت الحد المسموح من المحاولات. يرجى المحاولة بعد دقيقة. |
| `auth.error.unknown` | تعذّر تسجيل الدخول. | تعذّر تسجيل الدخول. يرجى المحاولة مرة أخرى. |
| `auth.submit` | دخول | تسجيل الدخول |
| `auth.logout` | خروج | تسجيل الخروج |
| `audit.event.ADMIN_SESSION_TERMINATED` | إنهاء جلسة بواسطة المطور | إنهاء الجلسة بواسطة مسؤول النظام |
| `audit.event.LOGIN_SUCCEEDED` | تسجيل دخول | تسجيل دخول ناجح |
| `audit.event.LOGIN_BLOCKED` | رفض الدخول | منع الدخول |
| `audit.actor` | المستخدم | المنفِّذ |
| `audit.time` | الوقت | التاريخ والوقت |
| `audit.old_values` | قبل | القيمة السابقة |
| `audit.new_values` | بعد | القيمة الجديدة |
| `audit.entity.site_allowance_rules` | قواعد بدل المواقع | قواعد بدلات المواقع |
| `audit.entity.employee_manager_assignments` | تعيينات المديرين | تعيين المديرين |
| `common.tooMany` | أكثر من 100 صف، يتم عرض أول 100 فقط. | يتجاوز العدد 100 سجل، ويُعرض أول 100 سجل فقط. |
| `app.name` | سمارت تكنولوجي HRMS | سمارت تكنولوجي لإدارة الموارد البشرية |
| `nav.main` | التنقل الرئيسي | القائمة الرئيسية |
| `nav.accounts`, `page.accounts`, `audit.entity.users` | الحسابات | حسابات المستخدمين |
| `console.slug` | الرمز | المعرّف |
| `console.setPassword` | تعيين كلمة المرور | تعيين كلمة مرور جديدة |
| `console.ip` | عنوان IP | عنوان الـIP |
| `console.sessions` | الجلسات | إنهاء الجلسات |
| `perm.role.view` | الأدوار: عرض | الأدوار والصلاحيات: عرض |
| `perm.role.manage` | الأدوار: إدارة | الأدوار والصلاحيات: إدارة |
| `scope.own`, `scope.all` | — | unchanged here; deleted in Task 10 |
| `employee.allTypes` | كل الأنواع | جميع الأنواع |
| `site.allKinds` | — | unchanged here; deleted in Task 5 |
| `employee.insurance_no` | رقم التأمين | الرقم التأميني |
| `employee.phone` | الهاتف | رقم الهاتف |
| `employee.contract_end_date` | نهاية العقد | تاريخ انتهاء العقد |
| `employee.pay_method` | طريقة الدفع | طريقة صرف المرتب |
| `enum.pay_method.cash` | نقدي | نقدًا |
| `enum.pay_method.bank` | بنكي | تحويل بنكي |
| `employee.placement` | التعيين | بيانات التعيين |
| `employee.pay` | الأجر | البيانات المالية |
| `enum.document.national_id_copy` | صورة البطاقة | صورة بطاقة الرقم القومي |
| `enum.document.work_stub` | عقب عمل | عقد عمل |
| `enum.document.criminal_record` | فيش وتشبيه | صحيفة الحالة الجنائية |
| `enum.document.qualification` | المؤهل | المؤهل الدراسي |
| `enum.document.insurance_form_6` | استمارة 6 تأمينات | استمارة رقم 6 (التأمينات الاجتماعية) |
| `enum.document.military_status` | موقف التجنيد | الموقف من التجنيد |
| `enum.leave_code.sick` | مرضي | إجازة مرضية |
| `enum.leave_code.rest` | راحة | راحة أسبوعية |
| `enum.allowance_mode.none` | بدون | بدون بدل |
| `enum.allowance_mode.share_of_wage` | نسبة من الأجر | نسبة من الأجر اليومي |
| `site.allowances` | بدلات | البدلات |
| `site.mode` | نمط البدل | نوع البدل |
| `site.addRule` | إضافة | إضافة قاعدة |
| `site.allowanceHistory` | سجل البدلات | سجل تغيّر البدلات |
| `change.effective_from` | ساري من | ساري اعتبارًا من |
| `history.types` | سجل تغيير النوع | سجل تغيّر نوع الموظف |
| `history.pay` | سجل تغيير الأجر | سجل تغيّر الأجر |
| `document.note` | ملاحظة | ملاحظات |
| `pay.shift_start` | بداية الوردية | بدء الوردية |
| `pay.shift_end` | نهاية الوردية | انتهاء الوردية |

- [ ] **Step 4: Rewrite `en.json` (current → new; keep every key not listed)**

| Key | Current | New |
|---|---|---|
| `nav.fares`, `page.fares` | — | unchanged here; deleted in Task 5 |
| `auth.error.invalid_credentials` | Wrong email or password. | Incorrect email or password. |
| `auth.error.account_locked` | The account is locked after too many failed attempts. Contact the developer. | Your account has been locked after too many failed sign-in attempts. Please contact your system administrator. |
| `auth.error.account_suspended` | The account is deactivated. Contact the developer. | Your account has been deactivated. Please contact your system administrator. |
| `auth.error.rate_limited` | Too many attempts; try again in a minute. | Too many attempts. Please try again in a minute. |
| `auth.error.unknown` | Could not sign in. | Unable to sign in. Please try again. |
| `auth.email` | Email | Email address |
| `scope.own`, `scope.all` | — | unchanged here; deleted in Task 10 |
| `employee.insurance_no` | Insurance no. | Insurance number |
| `employee.contract_end_date` | Contract end | Contract end date |
| `employee.pay_method` | Pay method | Payment method |
| `employee.identity` | Identity | Personal information |
| `employee.placement` | Placement | Employment details |
| `employee.pay` | Pay | Compensation |
| `document.received_at` | Received | Date received |
| `document.expires_at` | Expires | Expiry date |
| `enum.document.criminal_record` | Criminal record | Criminal record certificate |
| `enum.document.insurance_form_6` | Insurance form 6 | Social insurance form No. 6 |
| `enum.document.qualification` | Qualification | Educational qualification |
| `enum.document.military_status` | Military status | Military service status |
| `enum.document.work_stub` | Work stub | Employment contract |
| `enum.leave_code.sick` | Sick | Sick leave |
| `enum.leave_code.rest` | Rest | Rest day |
| `enum.allowance_mode.share_of_wage` | Share of wage | Percentage of daily wage |
| `site.mode` | Allowance mode | Allowance type |
| `site.addRule` | Add | Add rule |
| `console.slug` | Code | Identifier |
| `console.inactive` | Deactivated | Inactive |
| `console.sessions` | Sessions | End sessions |
| `audit.actor` | Actor | User |
| `audit.time` | Time | Date and time |
| `audit.event.LOGIN_BLOCKED` | Sign-in refused | Sign-in blocked |
| `audit.event.LOGIN_FAILED` | Failed sign-in | Failed sign-in attempt |
| `audit.event.ADMIN_SESSION_TERMINATED` | Session ended by the developer | Session ended by an administrator |
| `audit.event.ROLE_PERMISSIONS_UPDATED` | Role permissions changed | Role permissions updated |
| `audit.event.SITE_MANAGERS_UPDATED` | Site managers changed | Site managers updated |
| `fare.tooMany` | More than 100 fares exist; the grid may not show them all. | More than 100 fares exist. Some may not appear in the table. |
| `common.tooMany` | More than 100 rows; showing the first 100. | More than 100 rows. Showing the first 100. |
| `change.sameType` | Choose a type different from the one in force on that date. | Select a type different from the one in effect on that date. |

- [ ] **Step 5: Add the new keys**

| Key | ar | en |
|---|---|---|
| `nav.settings` | الإعدادات | Settings |
| `settings.tab.sites` | المواقع | Sites |
| `settings.tab.residences` | محل الإقامة | Residences |
| `settings.tab.leaveTypes` | أنواع الإجازات | Leave types |
| `settings.tab.fares` | المواصلات | Transport fares |
| `settings.tab.rules` | قواعد العمل | Business rules |
| `common.saveChanges` | حفظ التغييرات | Save changes |
| `status.active` | نشط | Active |
| `status.inactive` | غير نشط | Inactive |
| `manager.title` | المدير | Manager |
| `manager.assign` | تعيين مدير | Assign manager |
| `manager.none` | بدون مدير | No manager |
| `site.managers` | المديرون | Managers |
| `employee.tab.profile` | البيانات | Profile |
| `employee.tab.manager` | المدير | Manager |
| `employee.tab.history` | السجل | History |
| `employee.tab.documents` | المستندات | Documents |
| `perm.scope.none` | لا | No |
| `perm.scope.own` | الخاص به | Own |
| `perm.scope.all` | الكل | All |

- [ ] **Step 6: Update every test string whose value changed, in the same commit**

At least these. Afterwards, grep the test files for each "Current" value in the tables above and fix any it still finds.
- `login.test.tsx`:
  - `'دخول'` → `'تسجيل الدخول'` (every button query).
  - `'خروج'` → `'تسجيل الخروج'`.
  - `'الحساب مقفل بعد محاولات فاشلة كثيرة. تواصل مع المطور.'` → `'تم قفل الحساب لتكرار محاولات الدخول الفاشلة. يرجى التواصل مع مسؤول النظام.'`
- `AppLayout.test.tsx`: `'الحسابات'` → `'حسابات المستخدمين'`, `'خروج'` → `'تسجيل الخروج'`.
- `sites.test.tsx`:
  - `'الأجرة'` → `'قيمة المواصلات'`.
  - `'ساري من'` → `'ساري اعتبارًا من'`.
  - `'بدلات'` → `'البدلات'`.
  - `'نمط البدل'` → `'نوع البدل'`.
  - The button `'إضافة'` → `'إضافة قاعدة'`.
- `employees.test.tsx`: `'نقدي'` → `'نقدًا'`, `'ملاحظة'` → `'ملاحظات'`, `'ساري من'` → `'ساري اعتبارًا من'`.
- `console.test.tsx`: no change (`scope.*` keep their values until Task 10 deletes them).
- `audit.test.tsx`: `'Actor'` → `'User'`, `'المستخدم'` → `'المنفِّذ'`.

- [ ] **Step 7: Update the `CONTEXT.md` glossary**

- Residence: change the heading to `**Residence** (محل الإقامة):`. The definition and the _Avoid_ line stay.
- Fare: keep `**Fare** (المواصلات):`. Extend its _Avoid_ line to `_Avoid_: الأجرة; transport allowance (for office tower trips, see Tower transport)`.
- Leave day-type: append "Stored as a `sites` row with `kind=leave` and a `leave_code`; the UI calls them أنواع الإجازات / Leave types and never "leave site"."

- [ ] **Step 8: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add frontend CONTEXT.md
git commit -m "Rewrite Arabic and English copy to the owner's wording" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Business-rule settings endpoint (outline 1.4, قواعد العمل backend)

The backend has no settings table or endpoint today. Its migrations cover employees, sites, identity, permissions, audit_log and manager_assignments. This task adds them.

**Files:**
- Create: `backend/database/migrations/2026_10_19_000001_create_settings.php`
- Create: `backend/app/Modules/Settings/Infrastructure/Eloquent/Setting.php`, `backend/app/Modules/Settings/Application/BusinessRules.php`, `backend/app/Modules/Settings/Presentation/SettingController.php`
- Modify: `backend/routes/api.php`, `backend/tests/Feature/RbacTest.php`, `backend/tests/Feature/AdministrationTest.php`
- Test: `backend/tests/Feature/SettingsApiTest.php`

**Interfaces:**
- Consumes: `standardColumns()`, `HasStandardColumns`, `Audited` (which writes UPDATED with old and new values inside the save's transaction), `ApiEnvelope::single`, the `permission:` middleware and `signIn()`.
- Produces:
  - Table `settings`: `standardColumns()`, `key` varchar(64) and `value` varchar(64), with a partial unique index on `key` `where deleted_at is null`. The migration seeds the 15 rows below with `Str::uuid7()` ids.
  - Permissions `setting.view.all` and `setting.manage.all`, inserted by the same migration and granted to developer (both), hr (both) and ceo (`setting.view.all`). Grants ruled by the controller (see Open questions). `down()` removes the grants, the two permissions and the table.
  - `GET /api/v1/settings` (`permission:setting.view`) returns `{ data: { <key>: "<value>", … } }` with all 15 keys as strings.
  - `PUT /api/v1/settings` (`permission:setting.manage`) takes any subset of the keys as strings and saves the changed rows. Each change is one audited UPDATED row on `settings`, with `old_values {value}` and `new_values {value}`. It returns the full map. Keys outside the list are ignored. A bad value answers 422 with the key as `error.details[].field`.
  - `Setting` (Eloquent, `Audited`, `HasStandardColumns`, `$guarded = []`).
  - `BusinessRules::all(): array<string, string>` (key → value, ordered by key) and `BusinessRules::update(array<string, string> $values): array<string, string>`. The update runs in one `DB::transaction` and saves each row through the model, so an unchanged value writes no audit row.
  - `SettingController::RULES: array<string, string>`, mapping each key to its format rule. The controller validates with `['sometimes', 'string', $rule]` per key, so a JSON number is refused (no floats).

| Key | Seed value | Rule |
|---|---|---|
| `month_days` | `30` | integer `regex:/^\d{1,3}$/D` |
| `day_hours` | `8` | decimal `regex:/^\d{1,6}(\.\d{1,6})?$/D` |
| `overtime_multiplier` | `1.5` | decimal |
| `lateness_multiplier_office` | `1` | decimal |
| `lateness_multiplier_engineer` | `1.5` | decimal |
| `lateness_multiplier_permanent_worker` | `2` | decimal |
| `lateness_multiplier_day_laborer` | `2` | decimal |
| `office_grace_minutes` | `15` | integer |
| `office_shift_start` | `09:00` | `date_format:H:i` |
| `office_shift_end` | `17:00` | `date_format:H:i` |
| `weekly_off_office` | `friday,saturday` | days `regex:/^(saturday\|sunday\|monday\|tuesday\|wednesday\|thursday\|friday)(,(saturday\|sunday\|monday\|tuesday\|wednesday\|thursday\|friday))*$/D` |
| `weekly_off_field` | `friday` | days |
| `leave_days_under_one_year` | `10` | integer |
| `leave_days_one_year_plus` | `21` | integer |
| `leave_casual_days` | `6` | integer |

(In the PHP regex the `\|` above is a plain `|`. It is escaped here only for the Markdown table.)

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/SettingsApiTest.php`:
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_seeded_business_rules(): void
    {
        $this->signIn('hr');
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.month_days', '30')
            ->assertJsonPath('data.day_hours', '8')
            ->assertJsonPath('data.overtime_multiplier', '1.5')
            ->assertJsonPath('data.lateness_multiplier_office', '1')
            ->assertJsonPath('data.lateness_multiplier_engineer', '1.5')
            ->assertJsonPath('data.lateness_multiplier_permanent_worker', '2')
            ->assertJsonPath('data.lateness_multiplier_day_laborer', '2')
            ->assertJsonPath('data.office_grace_minutes', '15')
            ->assertJsonPath('data.office_shift_start', '09:00')
            ->assertJsonPath('data.office_shift_end', '17:00')
            ->assertJsonPath('data.weekly_off_office', 'friday,saturday')
            ->assertJsonPath('data.weekly_off_field', 'friday')
            ->assertJsonPath('data.leave_days_under_one_year', '10')
            ->assertJsonPath('data.leave_days_one_year_plus', '21')
            ->assertJsonPath('data.leave_casual_days', '6');
    }

    public function test_put_saves_changed_rules_exactly_and_audits_each(): void
    {
        $hr = $this->signIn('hr');
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20', 'month_days' => '30', 'overtime_multiplier' => '1.333333'])
            ->assertOk()
            ->assertJsonPath('data.office_grace_minutes', '20')
            ->assertJsonPath('data.overtime_multiplier', '1.333333');
        $id = DB::table('settings')->where('key', 'office_grace_minutes')->value('id');
        $row = DB::table('audit_log')->where(['entity_type' => 'settings', 'entity_id' => $id, 'event' => 'UPDATED'])->first();
        $this->assertSame(['value' => '15'], json_decode($row->old_values, true));
        $this->assertSame(['value' => '20'], json_decode($row->new_values, true));
        $this->assertSame($hr->id, $row->user_id);
        $this->assertSame(2, DB::table('audit_log')->where(['entity_type' => 'settings', 'event' => 'UPDATED'])->count()); // month_days did not change
    }

    public function test_put_rejects_malformed_values_under_their_field(): void
    {
        $this->signIn('hr');
        $bad = ['overtime_multiplier' => '1,5', 'month_days' => '30.5', 'office_shift_start' => '25:00', 'weekly_off_field' => 'fri', 'lateness_multiplier_office' => 1.5];
        foreach ($bad as $key => $value) {
            $this->putJson('/api/v1/settings', [$key => $value])->assertStatus(422)->assertJsonPath('error.details.0.field', $key);
        }
        $this->assertSame('1.5', DB::table('settings')->where('key', 'overtime_multiplier')->value('value'));
        $this->assertSame('1', DB::table('settings')->where('key', 'lateness_multiplier_office')->value('value'));
    }

    public function test_view_and_manage_are_separate_permissions(): void
    {
        $this->signIn('manager');
        $this->getJson('/api/v1/settings')->assertStatus(403);
        $this->signIn('ceo');
        $this->getJson('/api/v1/settings')->assertOk();
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20'])->assertStatus(403);
    }
}
```

Update the counts the new permissions change:
- In `RbacTest::test_seeded_matrix`:
  - `assertSame(15, Permission::count())` → `17`.
  - The ceo list → `['audit_log.view.all', 'employee.view.all', 'setting.view.all', 'site.view.all']`.
  - `assertCount(9, $grants('hr'))` → `11`.
  - `assertCount(13, $grants('developer'))` → `15`.
- In `AdministrationTest`: `assertJsonCount(15, 'data')` on `GET /api/v1/permissions` → `17`.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --filter='SettingsApiTest|RbacTest|AdministrationTest'`
Expected: FAIL. `/api/v1/settings` returns 404 and the permission counts are 15.

- [ ] **Step 3: Write the migration, `Setting`, `BusinessRules` and `SettingController`; add the two routes inside the `auth` group in `routes/api.php`**

```php
Route::get('settings', [SettingController::class, 'index'])->middleware('permission:setting.view');
Route::put('settings', [SettingController::class, 'update'])->middleware('permission:setting.manage');
```

- [ ] **Step 4: Run the tests and every backend gate**

Run: `docker compose exec app php artisan test`, then `pint --test`, `phpstan analyse --memory-limit=1G` and `deptrac analyse --config-file=deptrac.layers.yaml`
Expected: PASS. `RbacTest::test_every_api_route_requires_auth_and_a_permission` covers the new routes without changes.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "Add business-rule settings with view and manage permissions" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Settings page with sites, residences and leave types (outline 1.4, part 1, and original plan Task 8's site-managers dialog)

**Files:**
- Create: `frontend/src/pages/settings.tsx` (`SettingsPage`)
- Create: `frontend/src/pages/settings.test.tsx` (every test from `sites.test.tsx`, moved and adapted)
- Delete: `frontend/src/pages/sites.test.tsx`
- Modify: `frontend/src/pages/sites.tsx`:
  - Rename `SitesPage` → `SitesTab`, `ResidencesPage` → `ResidencesTab` and `FaresPage` → `FaresTab`.
  - Add `LeaveTypesTab` and `ManagersDialog`.
  - Give `SiteDialog` a fixed `kind`.
- Modify: `frontend/src/components/form.tsx` (`FormDialog` controlled mode), `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx` (`NAV` shape and `homePath`), `frontend/src/components/layout/AppLayout.test.tsx`, `frontend/src/test-utils.tsx`, `frontend/src/pages/login.test.tsx`, `frontend/src/locales/{ar,en}.json`. `src/locales/locales.test.ts` needs no change: after Task 3 it asserts no key this task deletes.

**Interfaces:**
- Consumes:
  - Sites: `GET /sites?per_page=100&kind=work|leave`, `POST /sites`, `PATCH /sites/{id}`, `GET /sites/{id}` (returns `managers: {id, name}[]` and `allowance_rules`), `POST /sites/{id}/allowance-rules`, `PUT /sites/{id}/managers {manager_ids}`.
  - `GET /users?active=true&per_page=100`; `GET|POST|PATCH /residences`; the fares endpoints (unchanged).
  - Task 1 kit; Task 3 keys.
- Produces:
  - `SettingsPage` at `/settings`, with tab values `sites | residences | leave-types | fares`. Task 6 adds `rules`.
    - The `sites`, `residences`, `leave-types` and `fares` tabs need `can('site.view')`.
    - `/sites`, `/residences` and `/fares` become `<Navigate to="/settings?tab=sites|residences|fares" replace />`. Keep these redirects for one release.
  - `SitesTab`, `ResidencesTab`, `LeaveTypesTab` and `FaresTab` (no props), exported from `sites.tsx`.
  - `ListCard`, local to `sites.tsx` and used by all four tabs, so the card anatomy is written once. Its props: `{ title: string; description: string; action?: ReactNode; err: unknown; loading: boolean; empty: boolean; icon: LucideIcon; notice?: ReactNode; page?: { page: number; total: number; onPageChange: (p: number) => void }; children: ReactNode }`. Pagination renders only when `page` is given and `page.total > 12`.
  - `FormDialog` gains `open?: boolean` and `onOpenChange?: (open: boolean) => void`, and `trigger` becomes optional. When `open` is passed, the dialog is controlled.
  - `renderWithAuth(ui, permissions, { path = '/', router = true } = {})`. `path` seeds the `MemoryRouter` entries. `router: false` renders without a router (Task 8 uses it).
  - `NAV` items change from `ability: string` to `abilities: string[]` (any-of). Every existing item becomes a one-element list. An item and its group show when `abilities.some(can)`, and `homePath(can)` uses the same test.
  - The settings group becomes the one item `{ path: '/settings', key: 'nav.settings', icon: Settings, abilities: ['site.view', 'setting.view'] }`.
  - Keys: add `settings.subtitle`, `settings.desc.{sites,residences,leaveTypes,fares}`, `site.addLeaveType` and `common.name`. Delete the orphaned keys from both files: `nav.sites`, `nav.residences`, `nav.fares`, `page.sites`, `page.residences`, `page.fares`, `site.kind`, `site.allKinds`, `site.allActive`, `site.inactive`, `site.no`, every `enum.site_kind.*` and `fare.add`.

**Layout** (the demo's `pages/hr/SettingsPage.tsx`; its "Leave policies" tab is the model for every list tab):
- `PageHeader title={t('nav.settings')} description={t('settings.subtitle')}`.
- `Tabs` with a `TabsList`. Each `TabsTrigger` has a `size-4` lucide icon: `MapPin` for sites, `House` for residences, `CalendarOff` for leave types, `Bus` for fares (and, from Task 6, `SlidersHorizontal` for rules).
- Each `TabsContent` holds one `ListCard`, which renders a `Card className="overflow-hidden"` holding:
  - `CardHeader` with `CardTitle` (`settings.tab.*`) and `CardDescription` (`settings.desc.*`). Its end side has the `action` slot: the add button, `Button variant="outline" size="sm"` with `<Plus />`, shown only with `can('site.manage')`. The fares tab passes no `action`, because fares are added through the grid cells.
  - Then the `ErrorBanner`, the too-many notice (the existing `Notice`, `role="status"`), and a `TableSkeleton` while the first load is pending.
  - Then the `Table`. If there are no rows, show `EmptyState icon={…} title={t('common.noData')}` with no action, so the header button stays the only add button.
  - Then `Pagination` (page size 12, client-side through `paginate`), only when there are more than 12 rows.
- Sites columns:
  - Name: `name_ar`, with `name_en` under it as `text-xs text-muted-foreground`.
  - `site.outside_alexandria` (`hidden md:table-cell`): a neutral `Badge` reading `site.yes`, or `—`.
  - Status: `StatusBadge` (`success` + `status.active`, or `neutral` + `status.inactive`).
  - `RowActions` with these items:
    - `site.edit` → `SiteDialog`, with `site.manage`.
    - `site.allowances` → `RulesDialog`, with `site.manage`.
    - `site.managers` → `ManagersDialog`, with `site.assign_manager`.
- Residences columns: name, `RowActions` (`site.edit`, with `site.manage`).
- Leave types columns:
  - Name.
  - Code (`hidden sm:table-cell`): a neutral `Badge` with `enum.leave_code.*`.
  - Status.
  - `RowActions` (`site.edit`, with `site.manage`).
- `SiteDialog`:
  - It takes `kind: 'work' | 'leave'` and drops the kind select.
  - The leave-code select shows only for `leave`.
  - The body sends `kind` and `leave_code: kind === 'leave' ? leave_code || null : null`.
  - The add buttons are `site.add` on the sites tab and `site.addLeaveType` on the leave-types tab.
- `ManagersDialog`:
  - A controlled `Dialog`. Its body mounts on open and loads `GET /sites/{id}` and `GET /users?active=true&per_page=100`.
  - It renders one native checkbox per user, labelled with the user's name and pre-checked from the site's `managers`.
  - Saving (`common.save`) sends `PUT /sites/{id}/managers` with `{ manager_ids }` in the user-list order, then closes.
  - Errors go to the `ErrorBanner`.
- `FaresTab` keeps today's grid inside its `ListCard` (no `action`, no `page`) for now; Task 6 restyles it.

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/pages/settings.test.tsx` with this header and the tests below:
```tsx
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, me, mockFetch, renderApp, renderWithAuth } from '@/test-utils'
import { SettingsPage } from './settings'

const user = userEvent.setup()
const MANAGE = ['site.view.all', 'site.manage.all']
const pg = (total = 1) => ({ page: 1, per_page: 100, total, total_pages: 1, has_next_page: false, has_previous_page: false })
const list = (data: unknown[], total = data.length) => () => json(200, { data, meta: { pagination: pg(total) } })
const calls = (m: string) => vi.mocked(fetch).mock.calls.filter(([, i]) => (i?.method ?? 'GET') === m)
const urlWith = (part: string) => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes(part))!
const body = (fn: { mock: { calls: unknown[][] } }) => JSON.parse((fn.mock.calls[0] as [string, RequestInit])[1].body as string)
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)

const sites = [
  { id: 's1', name_ar: 'موقع أ', kind: 'work', leave_code: null, active: true },
  { id: 's2', name_ar: 'موقع ب', kind: 'work', leave_code: null, active: true },
]
const residences = [
  { id: 'r1', name_ar: 'العجمي' },
  { id: 'r2', name_ar: 'برج العرب' },
]

const rowMenu = async (name: string) => {
  const row = await screen.findByRole('row', { name: new RegExp(name) })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
}

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  vi.clearAllMocks()
})
```
If Radix does not open on `user.click` under jsdom, change only `rowMenu` so it focuses the trigger and presses `{Enter}`.

Move these tests from `sites.test.tsx` unchanged, except for the render call:
- `fares grid puts the fare in force today in the right cell`
- `clicking a fare cell posts a dated fare for that residence and site`
- `fares grid shows a notice when more than 100 fares exist`
- `a failing secondary load shows the banner on the fares page`
- `site save error shows under the field`
- `residences list renders and a load failure shows the banner`
- `a double click on Save submits once`
- `fares grid asks the server for the fares in force today`
- `fares grid warns when sites or residences overflow the page`

The render calls change like this:
- `render(<MemoryRouter><FaresPage /></MemoryRouter>)` → `renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })`
- `<ResidencesPage />` → `{ path: '/settings?tab=residences' }`
- `<SitesPage />` → no path (the sites tab is the default)

Replace the remaining old tests with these:
```tsx
test('sites tab lists work sites and its add dialog sends kind work without a leave code', async () => {
  mockFetch({ 'GET /api/v1/sites': list([]), 'POST /api/v1/sites': () => json(201, { data: {} }) })
  renderWithAuth(<SettingsPage />, MANAGE)
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  expect(screen.queryByLabelText('كود الإجازة')).toBeNull()
  expect(screen.queryByLabelText('نوع الموقع')).toBeNull()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'سموحة')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(urlWith('/sites')).toContain('kind=work')
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toMatchObject({ name_ar: 'سموحة', kind: 'work', leave_code: null })
})

test('leave types tab lists kind=leave rows and adds one with its code', async () => {
  mockFetch({ 'GET /api/v1/sites': list([{ id: 'l1', name_ar: 'جمعة', kind: 'leave', leave_code: 'friday', active: true }]), 'POST /api/v1/sites': () => json(201, { data: {} }) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=leave-types' })
  expect(await screen.findByText('جمعة')).toBeInTheDocument()
  expect(urlWith('/sites')).toContain('kind=leave')
  await user.click(screen.getByRole('button', { name: 'إضافة نوع إجازة' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'مرضي')
  await user.selectOptions(screen.getByLabelText('كود الإجازة'), 'sick')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toMatchObject({ name_ar: 'مرضي', kind: 'leave', leave_code: 'sick' })
})

test('tabs follow ?tab= and switching shows the other table', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=residences' })
  expect(await screen.findByRole('tab', { name: 'محل الإقامة' })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByText('العجمي')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'المواقع' }))
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
})

test('an unknown tab falls back to sites', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=bogus' })
  expect(await screen.findByRole('tab', { name: 'المواقع' })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
})

test('old routes redirect to their settings tab', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(['site.view.all']) }),
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': list(residences),
    'GET /api/v1/fares': list([]),
  })
  renderApp('/fares')
  expect(await screen.findByRole('tab', { name: 'المواصلات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getByRole('link', { name: 'الإعدادات' })).toBeInTheDocument()
})

test('allowance rule history lists rules and adds one', async () => {
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [{ id: 'a1', effective_from: '2020-01-01', mode: 'fixed', value: '50.000000' }] } }),
    'POST /api/v1/sites/s1/allowance-rules': () => json(201, { data: { ...sites[0], allowance_rules: [] } }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  expect(await screen.findByText(fmt(50))).toBeInTheDocument()
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-10-01')
  await user.selectOptions(screen.getByLabelText('نوع البدل'), 'share_of_wage')
  await user.type(screen.getByLabelText('القيمة'), '0.5')
  await user.click(screen.getByRole('button', { name: 'إضافة قاعدة' }))
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toEqual({ effective_from: '2026-10-01', mode: 'share_of_wage', value: '0.5' })
})

test('rules are fetched only when the dialog opens, once', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [] } }) })
  renderWithAuth(<SettingsPage />, MANAGE)
  const detail = () => calls('GET').filter(([u]) => /\/sites\/s\d/.test(String(u)))
  await rowMenu('موقع أ')
  expect(detail()).toHaveLength(0)
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  await screen.findByLabelText('ساري اعتبارًا من')
  expect(detail()).toHaveLength(1)
})

test('share-of-wage rule shows the exact multiplier, not money', async () => {
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [{ id: 'a1', effective_from: '2020-01-01', mode: 'share_of_wage', value: '0.125000' }] } }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  expect(await screen.findByText('×0.125')).toBeInTheDocument()
})

test('a dialog opened from a row menu resets on reopen and leaves the page clickable', async () => {
  let n = 0
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'PATCH /api/v1/sites/s1': () => (n++ === 0 ? json(500, { error: { code: 'server_error', message: 'تعذر الحفظ' } }) : json(200, { data: {} })),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  const edit = async () => {
    await rowMenu('موقع أ')
    await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  }
  await edit()
  await user.clear(screen.getByLabelText('الاسم بالعربية'))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'قديم')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByRole('alert')
  await user.keyboard('{Escape}')
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await edit()
  expect(screen.queryByRole('alert')).toBeNull()
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('موقع أ')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await user.click(screen.getByRole('tab', { name: 'محل الإقامة' }))
  expect(screen.getByRole('tab', { name: 'محل الإقامة' })).toHaveAttribute('aria-selected', 'true')
})

test('site managers dialog pre-checks current managers and saves the ticked ids', async () => {
  const put = vi.fn(() => json(200, { data: { ...sites[0], managers: [] } }))
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [{ id: 'u1', name: 'خالد' }], allowance_rules: [] } }),
    'GET /api/v1/users': list([{ id: 'u1', name: 'خالد', is_active: true }, { id: 'u2', name: 'سامي', is_active: true }]),
    'PUT /api/v1/sites/s1/managers': put,
  })
  renderWithAuth(<SettingsPage />, [...MANAGE, 'site.assign_manager.all'])
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'المديرون' }))
  expect(await screen.findByRole('checkbox', { name: 'خالد' })).toBeChecked()
  expect(screen.getByRole('checkbox', { name: 'سامي' })).not.toBeChecked()
  await user.click(screen.getByRole('checkbox', { name: 'سامي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(put)).toEqual({ manager_ids: ['u1', 'u2'] })
  expect(urlWith('/users')).toContain('active=true')
})

test('without manage permissions there is no add button and no row actions', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, ['site.view.own'])
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة موقع' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
})

test('site.manage alone shows no managers action', async () => {
  mockFetch({ 'GET /api/v1/sites': list([sites[0]]) })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  expect(await screen.findByRole('menuitem', { name: 'تعديل' })).toBeInTheDocument()
  expect(screen.queryByRole('menuitem', { name: 'المديرون' })).toBeNull()
})
```

Append to `frontend/src/components/layout/AppLayout.test.tsx`:
```tsx
test('the Settings link shows for setting.view alone', async () => {
  signedIn(['setting.view.all'])
  renderApp('/settings')
  expect(await screen.findByRole('link', { name: 'الإعدادات' })).toBeInTheDocument()
})
```

In `login.test.tsx`, `nav hides what the user cannot do` still renders `/sites`. It now expects `await screen.findByRole('link', { name: 'الإعدادات' })` in place of the `'المواقع'` link.

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/settings.test.tsx src/pages/login.test.tsx`
Expected: FAIL (`./settings` does not exist).

- [ ] **Step 3: Implement** the `FormDialog` controlled mode, the `renderWithAuth` options, `ListCard`, the four tab bodies, `ManagersDialog`, `SettingsPage`, the routes and redirects in `App.tsx`, the `NAV` shape and `homePath` change, the keys below, and the key deletions listed in Interfaces. Then delete `sites.test.tsx`.

| Key | ar | en |
|---|---|---|
| `settings.subtitle` | إدارة المواقع ومحل الإقامة وأنواع الإجازات والمواصلات وقواعد العمل. | Manage sites, residences, leave types, transport fares and business rules. |
| `settings.desc.sites` | أماكن العمل التي تُسجَّل عليها أيام الحضور. | Places where attendance days are recorded. |
| `settings.desc.residences` | المناطق التي ينتقل منها الموظفون إلى المواقع. | Areas employees travel from to reach a site. |
| `settings.desc.leaveTypes` | الأيام التي تُسجَّل بدلًا من موقع العمل، مثل الجمعة والإجازات الرسمية والغياب. | Days recorded instead of a work site, such as Fridays, official holidays and absences. |
| `settings.desc.fares` | قيمة المواصلات من كل محل إقامة إلى كل موقع، سارية من تاريخها. | The fare from each residence to each site, effective from its date. |
| `site.addLeaveType` | إضافة نوع إجازة | Add leave type |
| `common.name` | الاسم | Name |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Add the Settings page with sites, residences and leave types tabs" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Settings page with transport fares and business rules (outline 1.4, part 2)

**Files:**
- Modify: `frontend/src/pages/settings.tsx` (the `rules` tab and `RulesTab`), `frontend/src/pages/sites.tsx` (the `FaresTab` grid), `frontend/src/pages/settings.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 4 `GET /api/v1/settings` → `{ data: Record<RuleKey, string> }` and `PUT /api/v1/settings` (body: the same map, all 15 keys); the permissions `setting.view` and `setting.manage`; Task 5 `SettingsPage`.
- Produces:
  - Tab value `rules`, shown with `can('setting.view')`.
  - Keys `rule.*`, `rule.group.*`, `weekday.*` and `settings.desc.rules`.
  - Keys used by later tasks: `common.saved`, `perm.setting.{view,manage}` (Task 10) and `audit.entity.settings` (Task 11).

**Layout:**
- `FaresTab`:
  - The grid sits in the tab's Card. The first column is sticky: `sticky start-0 z-10 bg-muted` on its `th` and `sticky start-0 z-10 bg-card` on its `td`.
  - Each cell is the `FareDialog` trigger, `<Button type="button" variant="soft" size="sm" className="min-w-20 tabular-nums">`, showing the amount in force or `—`.
  - The row keeps no other buttons, so the moved test's `getAllByRole('button')[1]` is still the second site.
- `RulesTab`:
  - One Card, with `CardTitle` `settings.tab.rules` and `CardDescription` `settings.desc.rules`.
  - `CardContent className="space-y-6"` holds four `<fieldset>` sections. Each has a `<legend className="mb-3 text-sm font-semibold">` and a grid `grid gap-4 sm:grid-cols-2 lg:grid-cols-3`:
    - `rule.group.time`: `month_days`, `day_hours`, `overtime_multiplier`.
    - `rule.group.lateness`: the four `lateness_multiplier_*` keys and `office_grace_minutes`.
    - `rule.group.schedule`: `office_shift_start`, `office_shift_end` (`type="time"`), `weekly_off_office`, `weekly_off_field`.
    - `rule.group.leave`: the three `leave_*` keys.
  - Integer keys use `TextField inputMode="numeric"`; decimal keys use `inputMode="decimal"`.
  - Each weekly-off key is a nested `<fieldset>` whose `<legend>` is its `rule.*` label. Inside are seven native checkboxes in Saturday→Friday order (`weekday.*`), laid out `flex flex-wrap gap-x-4 gap-y-2`.
    - The value is a comma list. Checking a day appends it; unchecking removes it.
    - Untouched values are sent back exactly as loaded.
    - When every day is unchecked, the UI sends `''`. There is no client-side guard: the server's 422 for that key shows under the fieldset (`aria-describedby` on the fieldset), like any other field error.
  - `CardFooter className="justify-end"` holds `Button` `common.saveChanges`. It is disabled until the form differs from the last loaded or saved values, and while a save runs.
  - On success: replace the loaded values with the response and call `toast.success(t('common.saved'))`. Errors use `toErrs` under each field, and `ErrorBanner` for the rest.
  - Without `can('setting.manage')`, every input and checkbox is `disabled` and there is no footer.

- [ ] **Step 1: Write the failing tests** (append to `settings.test.tsx`; put the `vi.mock` call and the import right under the existing imports)

```tsx
vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'

const rules = {
  month_days: '30', day_hours: '8', overtime_multiplier: '1.5',
  lateness_multiplier_office: '1', lateness_multiplier_engineer: '1.5', lateness_multiplier_permanent_worker: '2', lateness_multiplier_day_laborer: '2',
  office_grace_minutes: '15', office_shift_start: '09:00', office_shift_end: '17:00',
  weekly_off_office: 'friday,saturday', weekly_off_field: 'friday',
  leave_days_under_one_year: '10', leave_days_one_year_plus: '21', leave_casual_days: '6',
}
const RULES = ['site.view.all', 'setting.view.all', 'setting.manage.all']

test('rules tab loads the settings, saves typed values and toasts', async () => {
  const put = vi.fn(() => json(200, { data: { ...rules, office_grace_minutes: '20', weekly_off_field: 'friday,thursday' } }))
  mockFetch({ 'GET /api/v1/settings': () => json(200, { data: rules }), 'PUT /api/v1/settings': put })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  const grace = await screen.findByLabelText('فترة السماح للتأخير (دقيقة)')
  expect(grace).toHaveValue('15')
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeDisabled()
  await user.clear(grace)
  await user.type(grace, '٢٠')
  const field = screen.getByRole('group', { name: 'الراحة الأسبوعية: العاملون بالمواقع' })
  await user.click(within(field).getByRole('checkbox', { name: 'الخميس' }))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(body(put)).toEqual({ ...rules, office_grace_minutes: '20', weekly_off_field: 'friday,thursday' })
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم حفظ التغييرات.'))
})

test('a rejected rule value shows under its field', async () => {
  mockFetch({
    'GET /api/v1/settings': () => json(200, { data: rules }),
    'PUT /api/v1/settings': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'overtime_multiplier', code: 'invalid', message: 'صيغة القيمة غير صحيحة' }] } }),
  })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  const ot = await screen.findByLabelText('معامل الوقت الإضافي')
  await user.type(ot, '5')
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  await waitFor(() => expect(ot).toHaveAccessibleDescription('صيغة القيمة غير صحيحة'))
  expect(toast.success).not.toHaveBeenCalled()
})

test('view-only users see the rules read-only', async () => {
  mockFetch({ 'GET /api/v1/settings': () => json(200, { data: rules }) })
  renderWithAuth(<SettingsPage />, ['site.view.all', 'setting.view.all'], { path: '/settings?tab=rules' })
  expect(await screen.findByLabelText('عدد أيام الشهر')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
})

test('the rules tab is hidden without setting.view', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=rules' })
  expect(await screen.findByRole('tab', { name: 'المواقع' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'قواعد العمل' })).toBeNull()
})
```

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/settings.test.tsx`
Expected: FAIL (there is no rules tab).

- [ ] **Step 3: Implement `RulesTab`, the `rules` tab, the `FaresTab` restyle and these keys**

| Key | ar | en |
|---|---|---|
| `settings.desc.rules` | الأرقام التي تعتمد عليها حسابات الرواتب والتأخير والإجازات. | The numbers payroll, lateness and leave calculations rely on. |
| `rule.group.time` | الأجر وساعات العمل | Pay and working hours |
| `rule.group.lateness` | التأخير | Lateness |
| `rule.group.schedule` | الوردية والراحة الأسبوعية | Shift and weekly rest |
| `rule.group.leave` | رصيد الإجازات السنوية | Annual leave entitlement |
| `rule.month_days` | عدد أيام الشهر | Days per month |
| `rule.day_hours` | عدد ساعات اليوم | Hours per day |
| `rule.overtime_multiplier` | معامل الوقت الإضافي | Overtime multiplier |
| `rule.lateness_multiplier_office` | معامل التأخير: موظفو الشركة | Lateness multiplier: office staff |
| `rule.lateness_multiplier_engineer` | معامل التأخير: المهندسون | Lateness multiplier: engineers |
| `rule.lateness_multiplier_permanent_worker` | معامل التأخير: العمال المثبتون | Lateness multiplier: permanent workers |
| `rule.lateness_multiplier_day_laborer` | معامل التأخير: عمال اليومية | Lateness multiplier: day laborers |
| `rule.office_grace_minutes` | فترة السماح للتأخير (دقيقة) | Lateness grace period (minutes) |
| `rule.office_shift_start` | بدء وردية المكتب | Office shift start |
| `rule.office_shift_end` | انتهاء وردية المكتب | Office shift end |
| `rule.weekly_off_office` | الراحة الأسبوعية: موظفو الشركة | Weekly rest: office staff |
| `rule.weekly_off_field` | الراحة الأسبوعية: العاملون بالمواقع | Weekly rest: site staff |
| `rule.leave_days_under_one_year` | أيام الإجازة لمن خدمته أقل من سنة | Leave days, under one year of service |
| `rule.leave_days_one_year_plus` | أيام الإجازة لمن أتم سنة أو أكثر | Leave days, one year of service or more |
| `rule.leave_casual_days` | أيام الإجازة العارضة | Casual leave days |
| `weekday.saturday` | السبت | Saturday |
| `weekday.sunday` | الأحد | Sunday |
| `weekday.monday` | الاثنين | Monday |
| `weekday.tuesday` | الثلاثاء | Tuesday |
| `weekday.wednesday` | الأربعاء | Wednesday |
| `weekday.thursday` | الخميس | Thursday |
| `weekday.friday` | الجمعة | Friday |
| `common.saved` | تم حفظ التغييرات. | Changes saved. |
| `perm.setting.view` | قواعد العمل: عرض | Business rules: view |
| `perm.setting.manage` | قواعد العمل: إدارة | Business rules: manage |
| `audit.entity.settings` | قواعد العمل | Business rules |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS, including the moved fares tests.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Add the transport fares grid and business rules tabs to Settings" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Approval gate 1 (controller)

Setup:
1. Run `docker compose up -d` and `docker compose exec app php artisan migrate --seed`.
2. If no local account exists, create one: `docker compose exec app php artisan identity:create-user dev@smart.test --role=developer`. Add an `hr` account the same way to check permission-scoped views.
3. Start the preview with `preview_start` `frontend`.
4. Check the console and network panels for errors before taking screenshots.

Take every screen below at 1440 px and at 375 px, in light and dark mode, in Arabic and in English (8 shots each), unless the screen notes otherwise:
1. Login, showing the error banner after a wrong password.
2. Shell, sidebar expanded, on `/employees` (desktop only).
3. Shell, sidebar collapsed, with a tooltip showing (desktop only).
4. Shell, mobile menu sheet open (375 px only).
5. Profile menu open.
6. Settings → المواقع, with a row menu open.
7. Settings → المواقع → المديرون dialog (a bottom sheet at 375 px).
8. Settings → محل الإقامة.
9. Settings → أنواع الإجازات.
10. Settings → المواصلات grid, scrolled sideways at 375 px to show the sticky first column.
11. Settings → قواعد العمل, after a save, with the toast showing.

Post the screenshots with the list of dropped columns from "Outline points this plan settles" and the three ⚠️ questions. Wait for the owner's approval.

---

# Slice 2 — Employees

### Task 7: Employees list (outline 2.1)

**Files:**
- Modify: `frontend/src/pages/employees.tsx` (`EmployeesPage`), `frontend/src/pages/employees.test.tsx`, `frontend/src/components/cards/StatCard.tsx` (`MiniStat` gets `role="group" aria-label={label}`), `frontend/src/components/form.tsx` (receives `cairoToday`, moved from `sites.tsx`), `frontend/src/pages/sites.tsx` (imports it), `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes:
  - `GET /employees?page=&per_page=12[&search=][&type=]`. Each row has `id`, `code`, `name_ar`, `name_en`, `department`, `hire_date`, `termination_date`, `current_type`, and the list carries `meta.pagination`.
  - Stat totals: `GET /employees?per_page=1` and `GET /employees?per_page=1&type=<type>`, reading `meta.pagination.total`. These counts follow the caller's scope, so a manager sees their own employees.
  - Task 1: `PageHeader`, `MiniStat`, `Card`, `SearchInput`, `FilterSelect`, `UserAvatar`, `Badge`, `StatusBadge`, `Pagination`, `EmptyState`, `TableSkeleton`.
- Produces:
  - `cairoToday(): string` exported from `form.tsx`.
  - Keys: `employee.subtitle`, `employee.stat.{total,office,engineer,permanent_worker,day_laborer}`, `employee.status` and `employee.employee`. `employee.code` and `employee.search` get new values.

**Layout** (read the demo's `pages/hr/EmployeesPage.tsx`):
- `PageHeader title={t('page.employees')} description={t('employee.subtitle')}`. The `actions` slot holds the add-employee button (`Button asChild` → `Link to="/employees/new"`, with `UserPlus`), shown only with `can('employee.create')`.
- MiniStat row, `grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5`: total (`Users`), then one tile per type. These are five `per_page=1` calls on mount.
  - A tile shows `—` until its count arrives. A failed tile request is silent and leaves `—`; only the list request shows the `ErrorBanner`.
  - ponytail: one count call per tile; replace them with a counts endpoint if the list grows slow.
- One `Card className="overflow-hidden"` holds the rest:
  - Filters block `space-y-3 border-b p-4`, with a row `flex flex-col gap-3 xl:flex-row xl:items-center`:
    - `SearchInput` (placeholder and label `employee.search`).
    - `FilterSelect` (label `employee.type`, all-label `employee.allTypes`, options `enum.type.*`).
    - A ghost `sm` button `common.clearFilters`, shown only when a filter is set.
    - Any change resets the page to 1.
  - The table:

    | Column | Content | Hidden below |
    |---|---|---|
    | `employee.employee` | `UserAvatar`, `name_ar` as plain text (`font-medium`; no link inside the clickable row), `name_en` under it as `text-xs text-muted-foreground` | — |
    | `employee.code` | `font-mono text-xs text-muted-foreground` | — |
    | `employee.type` | info `Badge` with `enum.type.*` | — |
    | `employee.department` | text, or `—` | `md` |
    | `employee.hire_date` | as stored, or `—` | `lg` |
    | `employee.status` | `StatusBadge`: `neutral` + `status.inactive` when `termination_date && termination_date <= cairoToday()`, else `success` + `status.active` | — |

  - Rows are `cursor-pointer` and navigate to the detail page on click. They also get `tabIndex={0}` and navigate on Enter, so the keyboard can open them.
  - Before the first response, show a `TableSkeleton`. With no rows, show `EmptyState icon={Users} title={t('common.noResults')}`.
  - `Pagination` uses page size 12 and the server's `total`.

- [ ] **Step 1: Write the failing tests** (in `employees.test.tsx`)

Replace `list renders rows from the API`. Change `list load failure shows the banner` to render through `renderWithAuth(<EmployeesPage />, ['employee.view.all'])`, and end it with `expect(screen.getAllByRole('alert')).toHaveLength(1)`: the failing tile requests stay silent. Add `import { renderWithAuth } from '@/test-utils'` and `const user = userEvent.setup()` if they are missing.
```tsx
const row1 = { id: 'e1', code: 7, name_ar: 'أحمد علي', name_en: 'Ahmed Ali', department: 'المبيعات', hire_date: '2024-02-01', termination_date: null, pay_method: 'cash', current_type: 'engineer' }

test('list rows show the employee, ID, type badge, department, hire date and status', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const row = await screen.findByRole('row', { name: /أحمد علي/ })
  expect(within(row).getByText('7')).toBeInTheDocument()
  expect(within(row).getByText('مهندس')).toBeInTheDocument()
  expect(within(row).getByText('المبيعات')).toBeInTheDocument()
  expect(within(row).getByText('2024-02-01')).toBeInTheDocument()
  expect(within(row).getByText('نشط')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'إضافة موظف' })).toBeNull()
})

test('stat tiles show the server totals per type', async () => {
  const totals: Record<string, number> = { '': 40, office: 9, engineer: 7, permanent_worker: 13, day_laborer: 11 }
  mockFetch({
    'GET /api/v1/employees': (url) =>
      json(200, { data: [], meta: { pagination: { ...pagination, total: totals[new URL(url, 'http://x').searchParams.get('type') ?? ''] } } }),
  })
  renderWithAuth(<EmployeesPage />, ['employee.view.all', 'employee.create.all'])
  expect(await within(screen.getByRole('group', { name: 'إجمالي الموظفين' })).findByText('40')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'المهندسون' })).findByText('7')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'عمال اليومية' })).findByText('11')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'إضافة موظف' })).toBeInTheDocument()
})

test('search and type filter the query and clear filters resets them', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const main = () => list.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('per_page=12')).at(-1)!
  await user.type(await screen.findByRole('textbox', { name: 'بحث بالاسم أو الرقم القومي أو الرقم الوظيفي' }), 'أحمد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'نوع الموظف' }), 'engineer')
  await waitFor(() => expect(main()).toContain('type=engineer'))
  expect(decodeURIComponent(main())).toContain('search=أحمد')
  expect(main()).toContain('page=1')
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(main()).not.toContain('type='))
  expect(screen.queryByRole('button', { name: 'مسح عوامل التصفية' })).toBeNull()
})

test('clicking a row opens the employee', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(
    <Routes>
      <Route path="/" element={<EmployeesPage />} />
      <Route path="/employees/:id" element={<p>صفحة الموظف</p>} />
    </Routes>,
    ['employee.view.all'],
  )
  await user.click(await screen.findByText('المبيعات'))
  expect(await screen.findByText('صفحة الموظف')).toBeInTheDocument()
})
```

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/employees.test.tsx`
Expected: FAIL. There are no stat tiles, the labels are the old ones, and rows do not navigate.

- [ ] **Step 3: Implement the page and set these keys**

| Key | ar | en |
|---|---|---|
| `employee.subtitle` | سجل موظفي الشركة وعمالها بجميع أنواعهم. | Every employee and worker, of every type. |
| `employee.stat.total` | إجمالي الموظفين | Total employees |
| `employee.stat.office` | موظفو الشركة | Office staff |
| `employee.stat.engineer` | المهندسون | Engineers |
| `employee.stat.permanent_worker` | العمال المثبتون | Permanent workers |
| `employee.stat.day_laborer` | عمال اليومية | Day laborers |
| `employee.status` | الحالة | Status |
| `employee.employee` | الموظف | Employee |
| `employee.code` (changed) | الرقم الوظيفي | Employee ID |
| `employee.search` (changed) | بحث بالاسم أو الرقم القومي أو الرقم الوظيفي | Search by name, national ID or employee ID |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Rebuild the employees list on the demo table" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Employee detail with manager tab (outline 2.2 and original plan Task 8's assignment screen)

**Files:**
- Modify: `frontend/src/pages/employees.tsx`:
  - `EmployeeFormPage` becomes a header card plus tabs when an `id` is present. With no `id`, it renders the same profile Cards as a create form: one form component serves both modes.
  - `DocRow` is replaced by a documents table with a row dialog.
  - Add `AssignManagerDialog`.
- Modify: `frontend/src/pages/employees.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes:
  - `GET /employees/{id}`, which returns the full record: `current_manager: {id, name} | null`, `manager_assignments: {id, manager_id, manager_name, effective_from}[]`, `type_changes`, `pay_changes`, `documents`.
  - `PATCH /employees/{id}`, `POST /employees/{id}/type-changes|pay-changes`, `PUT /employees/{id}/documents/{kind}` (returns the full record).
  - `POST /employees/{id}/manager-assignments {manager_id, effective_from}`, which returns the full record (201).
  - `GET /users?active=true&per_page=100`, `GET /residences?per_page=100`.
  - Task 5 `renderWithAuth(…, { router: false })` and `FormDialog`; Task 1 kit.
- Produces:
  - Detail tabs `profile | manager | history | documents`, synced to `?tab=`.
  - Keys `manager.history` and `document.kind`.

**Layout** (read the demo's `drawers/EmployeeDrawer.tsx` for the header and `pages/shared/ProfilePage.tsx` for the Field grid):
- Root `mx-auto max-w-5xl space-y-6`.
- Header: `Card className="p-5"` with `flex flex-col gap-4 sm:flex-row sm:items-center`.
  - `UserAvatar size="xl"`.
  - The name block: `name_ar` (`h1 text-page-title`), `name_en`, and `code` (mono).
  - An info `Badge` with the current type.
  - `<dl className="flex gap-2 text-sm"><dt className="text-muted-foreground">{t('manager.title')}</dt><dd>{current_manager?.name ?? t('manager.none')}</dd></dl>`.
  - Quick actions at the end: `AssignManagerDialog` (an outline button `manager.assign`), shown only with `can('employee.assign_manager')`.
    - Its dialog has `SelectField name="manager_id"` (label `manager.title`, options from active users loaded when it opens) and `TextField name="effective_from" type="date"` (label `change.effective_from`).
    - The POST response becomes the page's employee state. Do not refetch.
- Tabs (`TabsList`, with an icon per tab: `IdCard`, `UserCog`, `History`, `FileText`):
  - `profile`: today's identity form, as three Cards (`employee.identity`, `employee.placement`, `employee.pay`), each a `grid gap-4 sm:grid-cols-2` of fields. Below them, `flex justify-end` with the submit button `common.save`, shown only with `can('employee.edit')`.
  - `manager`: a Card titled `manager.history` with a table (manager name, `change.effective_from`), newest first, or an `EmptyState` with `manager.none`.
  - `history`: two Cards, `history.types` and `history.pay`, holding today's tables. Each `CardHeader` has the `ChangeDialog` button (`change.add`), shown only with `can('employee.edit')`.
  - `documents`: a Card with a table, one row per `DOC_KINDS` entry. Columns: `document.kind` (`enum.document.*`), `document.received_at`, `document.expires_at` (`hidden md:table-cell`), `document.note` (`hidden lg:table-cell`), and an outline `sm` button `common.edit`, shown only with `can('employee.edit')`. The button opens a `FormDialog` titled with the document name, with the received, expires and note fields. It PUTs and renders the returned record as the page's employee state, with no reload.
- With no `id`: a `PageHeader` (`employee.add`) and the same profile-Cards component, whose `employee.pay` Card adds the type, effective-from and pay fields in create mode. Saving is gated by `can('employee.create')`.

- [ ] **Step 1: Write the failing tests** (in `employees.test.tsx`)

Add the helper, the fixture, and original plan Task 8's two tests verbatim. Only `'ساري من'` → `'ساري اعتبارًا من'` changes, plus the `{ router: false }` option that nested routers need:
```tsx
const ALL = ['employee.view.all', 'employee.create.all', 'employee.edit.all']
const emp = { id: 'e1', name_ar: 'أحمد', current_type: 'office', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }

function EmployeeDetailRoute({ id, tab }: { id: string; tab?: string }) {
  return (
    <MemoryRouter initialEntries={[`/employees/${id}${tab ? `?tab=${tab}` : ''}`]}>
      <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>
  )
}

test('assigning a manager posts the chosen user and date', async () => {
  const post = vi.fn(() => json(201, { data: { ...emp, current_manager: { id: 'u1', name: 'خالد' } } }))
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': residences,
    'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'خالد', is_active: true }], meta: { pagination } }),
    'POST /api/v1/employees/e1/manager-assignments': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'employee.assign_manager.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين مدير' }))
  await screen.findByRole('option', { name: 'خالد' })
  await user.selectOptions(screen.getByLabelText('المدير'), 'u1')
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-11-01')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ manager_id: 'u1', effective_from: '2026-11-01' })
  expect(await screen.findByText('خالد')).toBeInTheDocument()
})

test('without the permission there is no assign button', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_manager: null } }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.own'], { router: false })
  expect(await screen.findByText('بدون مدير')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تعيين مدير' })).toBeNull()
})

test('manager tab lists the assignment history', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp,
    current_manager: { id: 'u1', name: 'خالد' }, manager_assignments: [{ id: 'm1', manager_id: 'u1', manager_name: 'خالد', effective_from: '2026-01-01' }] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="manager" />, ['employee.view.all'], { router: false })
  const row = await screen.findByRole('row', { name: /2026-01-01/ })
  expect(within(row).getByText('خالد')).toBeInTheDocument()
})

test('a document is updated from its row dialog and the returned record is shown', async () => {
  const put = vi.fn(() => json(200, { data: { ...emp, documents: [{ id: 'd1', kind: 'criminal_record', received_at: '2026-10-01', expires_at: null, note: 'أصل' }] } }))
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'PUT /api/v1/employees/e1/documents/criminal_record': put })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, ALL, { router: false })
  const row = await screen.findByRole('row', { name: /صحيفة الحالة الجنائية/ })
  await user.click(within(row).getByRole('button', { name: 'تعديل' }))
  await user.type(screen.getByLabelText('ملاحظات'), 'أصل')
  await user.type(screen.getByLabelText('تاريخ الاستلام'), '2026-10-01')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((put.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ received_at: '2026-10-01', expires_at: null, note: 'أصل' })
  expect(await within(screen.getByRole('row', { name: /صحيفة الحالة الجنائية/ })).findByText('2026-10-01')).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.filter(([u]) => String(u).endsWith('/employees/e1'))).toHaveLength(1)
})
```

Make these changes to the existing detail and create tests:
- Delete `Enter in a document row does not submit the employee form`; the document test above replaces it.
- `createForm` and the inline create renders become `renderWithAuth(<MemoryRouter initialEntries={['/employees/new']}><Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes></MemoryRouter>, ALL, { router: false })`.
- `openEmp` becomes `renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })`. Its mocks and the two tests that use it stay as they are.

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/employees.test.tsx`
Expected: FAIL. There is no assign button, no tabs and no documents table.

- [ ] **Step 3: Implement the header, tabs, `AssignManagerDialog` and documents table, and add these keys**

| Key | ar | en |
|---|---|---|
| `manager.history` | سجل تعيين المديرين | Manager history |
| `document.kind` | المستند | Document |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Rebuild the employee page with tabs and manager assignment" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Approval gate 2 (controller)

Take every screen at 1440 and 375 px, light and dark, Arabic and English:
1. Employees list with data (seed a few employees through the UI or the API first).
2. Employees list with a filter applied, showing the clear-filters button.
3. Employees list empty, after a search with no match.
4. Employee detail → البيانات.
5. Employee detail → المدير.
6. Employee detail → السجل.
7. Employee detail → المستندات.
8. The assign-manager dialog (a bottom sheet at 375 px).
9. New employee form.

Post the screenshots and wait for approval.

---

# Slice 3 — System administration

### Task 9: Accounts (outline 3.1)

**Files:**
- Modify: `frontend/src/pages/console.tsx` (`AccountsPage`, `AccountDialog`, `PasswordDialog`, `SessionsDialog` become controlled dialogs opened from `RowActions`), `frontend/src/pages/console.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes:
  - `GET /users?per_page=100`. Each row has `id`, `name`, `email`, `is_active`, `locked_until` and `role {id, slug, name, name_ar}`.
  - `GET /roles`, `POST /users`, `PATCH /users/{id}`, `PATCH /users/{id}/deactivate|reactivate`, `PUT /users/{id}/password`, `GET /users/{id}/sessions`, `DELETE /users/{id}/sessions/{sid}`.
  - Task 1 kit; Task 5 controlled `FormDialog`.
- Produces: keys `console.accountsSubtitle` and `console.user`.

**Layout:**
- `PageHeader title={t('page.accounts')} description={t('console.accountsSubtitle')}`. The `actions` slot holds the create button `console.addAccount`, shown with `can('user.manage')`.
- A `Card className="overflow-hidden"` with a table:
  - `console.user`: `UserAvatar` and the name, with no subtext (the email has its own column).
  - `auth.email` (`hidden md:table-cell`, `dir="ltr"`, `text-start`).
  - `console.role`: a neutral `Badge` with the role name for the current language.
  - `console.status`: `StatusBadge` (`success` + `console.active`, or `neutral` + `console.inactive`), plus a `danger` `console.locked` badge when `locked_until` is in the future.
  - With `can('user.manage')`, a `RowActions` column with these items:
    - `common.edit` → `AccountDialog`.
    - `console.setPassword` → `PasswordDialog`.
    - `console.deactivate` or `console.reactivate` → PATCH, then reload. The deactivate item is destructive.
    - `console.sessions` → `SessionsDialog`, whose list and revoke behaviour stay the same.

- [ ] **Step 1: Write the failing tests** (append to `console.test.tsx`; the two existing account tests stay as they are)

```tsx
test('the accounts table shows role, status and locked badges', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [{ ...account, locked_until: '2999-01-01T00:00:00Z' }], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  expect(within(row).getByText('مدير')).toBeInTheDocument()
  expect(within(row).getByText('نشط')).toBeInTheDocument()
  expect(within(row).getByText('مقفل')).toBeInTheDocument()
})

test('the row menu deactivates an account', async () => {
  const deactivate = vi.fn(() => json(200, { data: { ...account, is_active: false } }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'PATCH /api/v1/users/u2/deactivate': deactivate })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعطيل' }))
  await waitFor(() => expect(deactivate).toHaveBeenCalledOnce())
})

test('the row menu sets a new password', async () => {
  const put = vi.fn(() => json(200, { data: {} }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'PUT /api/v1/users/u2/password': put })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعيين كلمة مرور جديدة' }))
  await user.type(screen.getByLabelText('كلمة المرور'), 'newpass123')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((put.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ password: 'newpass123' })
})
```
Add `waitFor` to the Testing Library import.

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/console.test.tsx`
Expected: FAIL. There is no row menu, and no badge for the role or the locked state.

- [ ] **Step 3: Implement the accounts page and add these keys**

| Key | ar | en |
|---|---|---|
| `console.accountsSubtitle` | إنشاء حسابات المستخدمين وإدارة أدوارهم وجلساتهم. | Create user accounts and manage their roles and sessions. |
| `console.user` | المستخدم | User |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Rebuild the accounts page with row actions" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Roles and permissions matrix (outline 3.2)

**Files:**
- Modify: `frontend/src/pages/console.tsx` (`RolesPage`, `Matrix`, `RoleDialog`), `frontend/src/pages/console.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes:
  - `GET /roles`. Each role has `id`, `slug`, `name`, `name_ar`, `permissions: string[]` (triples) and `users_count`.
  - `GET /permissions`. Each permission has `id`, `resource`, `action`, `scope` and `key`.
  - `PATCH /roles/{id}/permissions {permission_ids}`, `POST /roles`.
  - Task 6 keys `perm.setting.*` and `common.saved`; Task 3 keys `perm.scope.*` and `common.saveChanges`.
- Produces:
  - Keys `console.rolesSubtitle`, `console.resource`, `console.usersCount`, `console.matrixTitle`, `perm.resource.*` and `perm.action.*`.
  - Remove the orphaned `scope.own` and `scope.all`.

**Layout:**
- `PageHeader title={t('page.roles')} description={t('console.rolesSubtitle')}`. The `actions` slot holds `RoleDialog` (`console.addRole`), shown with `can('role.manage')`.
- Body: `grid gap-6 lg:grid-cols-[18rem_1fr]`.
- Start side: a `Card` holding the role list. Each role is a `button` with `aria-pressed`, class `w-full rounded-lg px-3 py-2.5 text-start hover:bg-muted aria-pressed:bg-muted`. Inside it:
  - the role name for the current language (`font-medium`);
  - the slug (`font-mono text-xs text-muted-foreground`, `dir="ltr"`);
  - `t('console.usersCount', { count: users_count })` (`text-xs text-muted-foreground`).
- End side, when a role is selected: a `Card className="overflow-hidden"` with `CardTitle` `t('console.matrixTitle', { role })`. Before a role is selected it shows the existing `console.pickRole` text.
  - The table has a sticky header and a sticky first column (`sticky start-0 bg-card`, labelled `console.resource`).
  - Rows: one per resource in `perms` order, labelled `perm.resource.<resource>`.
  - Columns: the actions present, in the order view, create, edit, delete, assign_manager, manage, labelled `perm.action.<action>`.
  - Each cell depends on the permissions for that resource and action:
    - With both an `own` and an `all` scope, the cell is `<div role="radiogroup" aria-label={t(`perm.${resource}.${action}`)} className="inline-flex rounded-lg border bg-muted p-0.5">`. It holds three native radios `لا / الخاص به / الكل` (`perm.scope.none|own|all`). Each radio is `<label><input type="radio" className="peer sr-only" …/><span className="inline-flex h-7 items-center rounded-md px-2.5 text-xs peer-checked:bg-card peer-checked:text-foreground peer-checked:shadow-1 peer-focus-visible:outline-2 peer-focus-visible:outline-ring">…</span></label>`.
    - With one permission, the cell is a native checkbox (`aria-label={t(`perm.${resource}.${action}`)}`, `className="size-4 accent-primary"`).
    - With none, the cell is `—` (`text-muted-foreground`).
  - Choosing `all` grants only the `.all` id, `own` only the `.own` id, and `none` neither.
  - Hover rows, no zebra stripes.
  - Save bar: `CardFooter className="justify-end gap-3 bg-muted/40"` with `Button` `common.saveChanges`. It is disabled until the selection differs from the role's permissions, or while saving.
    - On success: `toast.success(t('common.saved'))` and reload.
    - On a refusal: the `ErrorBanner` shows it and the controls reset to the role's permissions.
  - Without `can('role.manage')`: every control is disabled and there is no save bar.

- [ ] **Step 1: Write the failing tests** (in `console.test.tsx`, replace the three role tests)

Add `users_count: 2` to the `role` fixture. Add `vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))` and `import { toast } from 'sonner'` under the imports. Add `vi.clearAllMocks()` to `afterEach`.
```tsx
const pickRole = async () => user.click(await screen.findByRole('button', { name: /مدير/ }))
const scope = (group: string, option: string) => within(screen.getByRole('radiogroup', { name: group })).getByRole('radio', { name: option })
const sentIds = (patch: { mock: { calls: unknown[][] } }) => JSON.parse((patch.mock.calls[0] as [string, RequestInit])[1].body as string).permission_ids.sort()
const rolesApi = (patch?: () => Response) => mockFetch({
  'GET /api/v1/roles': () => json(200, { data: [role] }),
  'GET /api/v1/permissions': () => json(200, { data: perms }),
  ...(patch && { 'PATCH /api/v1/roles/r1/permissions': patch }),
})

test('role matrix saves the checked permission ids', async () => {
  const patch = vi.fn(() => json(200, { data: { ...role, permissions: ['employee.view.own', 'site.view.own', 'audit_log.view.all'] } }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  expect(scope('الموظفون: عرض', 'الخاص به')).toBeChecked()
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeDisabled()
  await user.click(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' }))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-audit', 'p-emp-own', 'p-site-own'])
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم حفظ التغييرات.'))
})

test('choosing a wider scope swaps the own permission for the all permission', async () => {
  const patch = vi.fn(() => json(200, { data: role }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(scope('الموظفون: عرض', 'الكل'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-employee.view.all', 'p-site-own'])
})

test('choosing none removes the permission', async () => {
  const patch = vi.fn(() => json(200, { data: role }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(scope('المواقع: عرض', 'لا'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-emp-own'])
})

test('a refused save shows the error and puts the controls back to the role permissions', async () => {
  rolesApi(() => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'permission_ids', code: 'invalid', message: 'آخر مدير للأدوار' }] } }))
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' }))
  await user.click(scope('الموظفون: عرض', 'الكل'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(await screen.findByText('آخر مدير للأدوار')).toBeInTheDocument()
  expect(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' })).not.toBeChecked()
  expect(scope('الموظفون: عرض', 'الخاص به')).toBeChecked()
})

test('without role.manage the matrix is read-only', async () => {
  rolesApi()
  renderWithAuth(<RolesPage />, ['role.view.all'])
  await pickRole()
  expect(screen.getAllByRole('checkbox')).toHaveLength(11)
  expect(screen.getAllByRole('radiogroup')).toHaveLength(2)
  for (const c of [...screen.getAllByRole('checkbox'), ...screen.getAllByRole('radio')]) expect(c).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
})
```

- [ ] **Step 2: Run them and see them fail**

Run: `cd frontend && npx vitest run src/pages/console.test.tsx`
Expected: FAIL. There are no radio groups, and the save button is still `حفظ`.

- [ ] **Step 3: Implement the matrix, add these keys, and delete `scope.own` and `scope.all`**

| Key | ar | en |
|---|---|---|
| `console.rolesSubtitle` | حدد ما يستطيع كل دور عرضه أو تعديله. | Choose what each role can view or change. |
| `console.resource` | القسم | Area |
| `console.usersCount` | عدد المستخدمين: {{count}} | Users: {{count}} |
| `console.matrixTitle` | صلاحيات {{role}} | {{role}} permissions |
| `perm.resource.employee` | الموظفون | Employees |
| `perm.resource.site` | المواقع | Sites |
| `perm.resource.user` | حسابات المستخدمين | User accounts |
| `perm.resource.role` | الأدوار والصلاحيات | Roles and permissions |
| `perm.resource.audit_log` | سجل التدقيق | Audit log |
| `perm.resource.setting` | قواعد العمل | Business rules |
| `perm.action.view` | عرض | View |
| `perm.action.create` | إنشاء | Create |
| `perm.action.edit` | تعديل | Edit |
| `perm.action.delete` | حذف | Delete |
| `perm.action.assign_manager` | تعيين المدير | Assign manager |
| `perm.action.manage` | إدارة | Manage |

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Rebuild the roles matrix with scope controls and a save bar" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Audit log (outline 3.3)

**Files:**
- Modify: `frontend/src/pages/audit.tsx`, `frontend/src/pages/audit.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes:
  - `GET /audit-log?user_id=&entity_type=&from=&to=&page=&per_page=20`. Each row has `id`, `event`, `entity_type`, `entity_id`, `occurred_at`, `user {id, name} | null`, `old_values` and `new_values`, and the list carries `meta.pagination`.
  - `GET /users?per_page=100` (only with `can('user.view')`).
  - Task 1 kit; `Sheet`; `useCairoTime`.
- Produces: keys `audit.details` and `audit.subtitle`. `ENTITIES` gains `settings`.

**Layout:**
- `PageHeader title={t('page.audit')} description={t('audit.subtitle')}`.
- A `Card className="overflow-hidden"`:
  - Filters block `border-b p-4`, a grid `grid gap-3 sm:grid-cols-2 lg:grid-cols-4`:
    - actor (`SelectField` `user_id`, only with `can('user.view')`);
    - `audit.entityType` (`SelectField` over `ENTITIES`);
    - `audit.from` and `audit.to` (`type="date"`).
    - Then a ghost `sm` button `common.clearFilters`, shown only when a filter is set. Any change resets the page to 1.
  - The table:

    | Column | Content | Hidden below |
    |---|---|---|
    | `audit.time` | `useCairoTime()` | — |
    | `audit.actor` | `UserAvatar size="sm"` and the name, or `—` | — |
    | `audit.action` | `Badge`: `success` for CREATED, `info` for UPDATED, `danger` for DELETED, `neutral` for the rest; label `audit.event.*`, falling back to the raw event | — |
    | `audit.entityType` | `audit.entity.*`, falling back to the raw value | `md` |
    | `audit.entityId` | `font-mono text-xs`, `dir="ltr"` | `xl` |
    | (actions) | ghost `sm` button `audit.details` | — |

  - `Pagination` uses page size 20 and the server's `total`. With no rows, show `EmptyState` (`common.noResults`).
- The details button opens a `Sheet side="right"` (`sm:max-w-[440px]`) titled with the event label and the entity label.
  - Its body is `grid gap-4 sm:grid-cols-2` with two `<section aria-label>`s: `audit.old_values` and `audit.new_values`.
  - Each section is a `<dl>` over the union of both sides' keys: `dt` is the key (`font-mono text-xs`, `dir="ltr"`); `dd` is the value for that side.
  - A string value shows as is. An object or array shows as `JSON.stringify` in `dir="ltr"`. A missing side or `null` shows `—`.

- [ ] **Step 1: Write the failing test** (append to `audit.test.tsx`; the two existing tests stay as they are)

```tsx
test('details sheet shows previous and new values side by side', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [{ id: 'a1', event: 'CREATED', entity_type: 'sites', entity_id: 's1', occurred_at: '2026-10-16T22:30:00Z',
    user: { id: 'u1', name: 'منى' }, old_values: null, new_values: { name_ar: 'سموحة', meta: { a: 1 } } }], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  const before = within(sheet).getByRole('region', { name: 'القيمة السابقة' })
  const after = within(sheet).getByRole('region', { name: 'القيمة الجديدة' })
  expect(within(before).getByText('name_ar')).toBeInTheDocument()
  expect(within(before).getAllByText('—')).toHaveLength(2)
  expect(within(after).getByText('سموحة')).toBeInTheDocument()
  expect(within(after).getByText('{"a":1}')).toBeInTheDocument()
  expect(sheet).not.toHaveTextContent('null')
  expect(sheet).not.toHaveTextContent('[object Object]')
})
```
Add `within` to the Testing Library import.

- [ ] **Step 2: Run it and see it fail**

Run: `cd frontend && npx vitest run src/pages/audit.test.tsx`
Expected: FAIL (no `عرض التفاصيل` button).

- [ ] **Step 3: Implement the page and add these keys**

| Key | ar | en |
|---|---|---|
| `audit.details` | عرض التفاصيل | View details |
| `audit.subtitle` | كل عملية إنشاء أو تعديل أو حذف أو تسجيل دخول، مع المنفِّذ والقيم قبل التغيير وبعده. | Every create, update, delete and sign-in, with who did it and the values before and after. |

Delete `audit.changes` if nothing uses it any more.

- [ ] **Step 4: Run the tests and every gate**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Rebuild the audit log with filters and a details sheet" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Approval gate 3 (controller)

Take every screen at 1440 and 375 px, light and dark, Arabic and English:
1. Accounts list, with a row menu open.
2. The create-account dialog.
3. The sessions dialog.
4. Roles: the role list and the matrix for `manager`, with the save bar dirty.
5. Roles at 375 px, with the matrix scrolled sideways to show the sticky first column.
6. Audit log with a filter applied.
7. The audit details sheet for an UPDATED entry and for a CREATED entry.

Post the screenshots and wait for approval.

---

# Slice 4 — Arabic API messages

### Task 12: Arabic API messages chosen by Accept-Language (outline Slice 4 = original plan Task 9; the strings follow the new wording)

**Files:**
- Create: `backend/app/Http/Middleware/SetLocaleFromRequest.php` (a copy of the CRM's)
- Create: `backend/lang/ar/validation.php`. It holds the CRM's Arabic messages, with `attributes` replaced by the table below.
- Create: `backend/lang/en/validation.php`. It holds only `attributes`; Laravel merges it over the framework's English file.
- Create: `backend/lang/{en,ar}/errors.php`
- Modify:
  - `backend/bootstrap/app.php`: register the middleware with `$middleware->api(prepend: [SetLocaleFromRequest::class])`, and send the envelope messages through `__('errors.*')`.
  - `backend/phpunit.xml`: add `<env name="APP_LOCALE" value="ar" force="true"/>`.
  - The ten literal `ValidationException::withMessages` strings in `EmployeeService` (lines ~169, 218, 227, 235), `SiteService` (~92, 96, 137, 140) and `FareService` (~91, 106) → `__('errors.<key>')`.
- Test: `backend/tests/Feature/LocaleTest.php`

**Interfaces:**
- Consumes: every earlier message; the frontend's new `ar.json` wording from Tasks 3–8 (the Arabic attribute labels must match it); Task 4's settings keys.
- Produces:
  - The locale is the first of `ar`/`en` in `Accept-Language`, else `config('app.locale')` (`ar`). The response carries `Content-Language` and `Vary: Accept-Language`.
  - `errors.php` keys (ar / en):

| Key | ar | en |
|---|---|---|
| `validation_failed` | البيانات المُرسلة غير صحيحة. | The given data was invalid. |
| `not_found` | المورد غير موجود. | Resource not found. |
| `unauthenticated` | يرجى تسجيل الدخول. | Unauthenticated. |
| `server_error` | حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى. | the current English text in `bootstrap/app.php` |
| `bank_account_required` | رقم الحساب البنكي مطلوب عند صرف المرتب بتحويل بنكي. | Bank account is required when paying by bank transfer. |
| `pay_field_required` | حقل :field مطلوب لنوع الموظف :type. | :field is required for :type. |
| `no_type_on_date` | لا يوجد نوع ساري للموظف في هذا التاريخ. | The employee has no type in force on this date. |
| `duplicate_date` | يوجد سجل آخر بنفس التاريخ. | A record already exists on this date. |
| `leave_code_required` | كود الإجازة مطلوب لأنواع الإجازات. | Leave code is required for leave types. |
| `leave_code_not_allowed` | كود الإجازة يخص أنواع الإجازات فقط. | Leave code applies to leave types only. |
| `allowance_value_required` | القيمة مطلوبة ما لم يكن نوع البدل "بدون بدل". | Value is required unless the allowance type is "None". |
| `employee_type.office` | موظف مثبت شركة | Office employee |
| `employee_type.engineer` | مهندس | Engineer |
| `employee_type.permanent_worker` | عامل مثبت | Permanent worker |
| `employee_type.day_laborer` | عامل باليومية | Day laborer |

  `duplicate_date` serves the four "already exists on this date" literals (rule, fare, override, change). `pay_field_required` gets `field` = `__('validation.attributes.'.$need)` and `type` = `__('errors.employee_type.'.$type->value)`.

  - `validation.attributes` (ar / en) covers every request field. The Arabic labels equal `ar.json`:

| Field | ar | en |
|---|---|---|
| `name_ar` | الاسم بالعربية | Arabic name |
| `name_en` | الاسم بالإنجليزية | English name |
| `fingerprint_name` | اسم البصمة | Fingerprint name |
| `gender` | النوع | Gender |
| `national_id` | الرقم القومي | National ID |
| `insurance_no` | الرقم التأميني | Insurance number |
| `phone` | رقم الهاتف | Phone |
| `address` | العنوان | Address |
| `department` | القسم | Department |
| `residence_id` | محل الإقامة | Residence |
| `pay_method` | طريقة صرف المرتب | Payment method |
| `bank_account` | الحساب البنكي | Bank account |
| `hire_date` | تاريخ التعيين | Hire date |
| `contract_end_date` | تاريخ انتهاء العقد | Contract end date |
| `termination_date` | تاريخ إنهاء الخدمة | Termination date |
| `termination_reason` | سبب إنهاء الخدمة | Termination reason |
| `hr_notes` | ملاحظات الموارد البشرية | HR notes |
| `crm_user_id` | معرّف المستخدم في نظام إدارة العملاء | CRM user ID |
| `type` | نوع الموظف | Employee type |
| `effective_from` | ساري اعتبارًا من | Effective from |
| `salary` | المرتب | Salary |
| `raise` | العلاوة | Raise |
| `insured_basic` | الأساسي التأميني | Insured basic |
| `daily_wage` | الأجر اليومي | Daily wage |
| `shift_start` | بدء الوردية | Shift start |
| `shift_end` | انتهاء الوردية | Shift end |
| `received_at` | تاريخ الاستلام | Date received |
| `expires_at` | تاريخ الانتهاء | Expiry date |
| `note` | ملاحظات | Notes |
| `kind` | النوع | Type |
| `leave_code` | كود الإجازة | Leave code |
| `outside_alexandria` | خارج الإسكندرية | Outside Alexandria |
| `active` | نشط | Active |
| `mode` | نوع البدل | Allowance type |
| `value` | القيمة | Value |
| `site_id` | الموقع | Site |
| `amount` | قيمة المواصلات | Fare |
| `date` | التاريخ | Date |
| `email` | البريد الإلكتروني | Email address |
| `password` | كلمة المرور | Password |
| `role_id` | الدور | Role |
| `name` | الاسم | Name |
| `slug` | المعرّف | Identifier |
| `permission_ids` | الصلاحيات | Permissions |
| `manager_id` | المدير | Manager |
| `manager_ids` | المديرون | Managers |
| `user_id` | المنفِّذ | User |
| `entity_type` | نوع السجل | Record type |
| `entity_id` | معرّف السجل | Record ID |
| `from` | من | From |
| `to` | إلى | To |
| `search` | البحث | Search |
| `per_page` | عدد السجلات في الصفحة | Rows per page |
| each of the 15 settings keys | its `rule.*` value from Task 6 | its `rule.*` value from Task 6 |

- [ ] **Step 1: Write the failing tests** (`backend/tests/Feature/LocaleTest.php`, `use RefreshDatabase`, `signIn('hr')` in `setUp`; this is original plan Task 9 verbatim, except that the bank message follows the new wording)

```php
public function test_validation_messages_follow_accept_language(): void
{
    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
        ->assertStatus(422)->assertJsonPath('error.details.0.message', 'حقل الاسم بالعربية مطلوب.')
        ->assertHeader('Content-Language', 'ar');
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
        ->assertJsonPath('error.details.0.message', 'The Arabic name field is required.')
        ->assertJsonPath('error.message', 'The given data was invalid.');
}

public function test_unsupported_or_missing_language_falls_back_to_arabic(): void
{
    $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/employees/'.Str::uuid7())->assertNotFound()->assertHeader('Content-Language', 'ar');
    $this->getJson('/api/v1/employees/'.Str::uuid7())->assertJsonPath('error.message', 'المورد غير موجود.');
}

public function test_service_rule_messages_are_translated(): void
{
    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['name_ar' => 'أ', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1', 'pay_method' => 'bank'])
        ->assertJsonPath('error.details.0.message', 'رقم الحساب البنكي مطلوب عند صرف المرتب بتحويل بنكي.');
}

public function test_identity_refusals_follow_accept_language(): void
{
    $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/login', ['email' => 'x@smart.test', 'password' => 'x'])
        ->assertStatus(401)->assertJsonPath('error.message', 'These credentials do not match our records.');
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --filter=LocaleTest`
Expected: FAIL. The messages are the framework's English ones and there is no `Content-Language` header.

- [ ] **Step 3: Add the middleware, the lang files and the `phpunit.xml` env line; switch the bootstrap envelope messages and the ten service literals to `__()` keys**

- [ ] **Step 4: Run the tests and every backend gate**

Run: `docker compose exec app php artisan test`, then `pint --test`, `phpstan analyse --memory-limit=1G` and `deptrac analyse --config-file=deptrac.layers.yaml`
Expected: PASS. Earlier tests assert codes and fields, not English text. If one breaks anyway, fix the test so it sends `Accept-Language: en`; do not change the message. Also run the frontend gate once, so that no frontend test depends on an API message.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "Serve Arabic or English API messages from Accept-Language" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

### Gate 4 (controller, no screenshots)

All four backend gates and the frontend gate are green on HEAD. In the running app, post the owner one screenshot (Arabic, 1440 px, light) of the employee create form after saving it empty, showing the Arabic validation messages under the fields.

---

## Finish (controller)

1. Run one final whole-branch review on Opus 5.5 over `515b042..HEAD`, with the ledger's deferred and parked lines and this plan's Review Focus. Make one fix dispatch, then one scoped re-review.
2. Both gate sets are green on HEAD, `git status` is clean, the branch is pushed, and the October fixtures are untouched.
3. Run `gh pr create --base main --head feature/release-1b-accounts`. The body summarises the four slices, lists the "Outline points this plan settles" and the answered ⚠️ questions, and ends with the attribution line. The other developer reviews it and the owner merges it.
