import { CalendarCheck, ChevronLeft, ChevronRight, FileSpreadsheet, ListChecks, Lock, LockOpen, Users } from 'lucide-react'
import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cairoToday, Dates, ErrorBanner, Field, FormDialog, localName, ltrDates, type Row, SelectField, useCairoTime, weekdayOf } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useDebounced } from '@/hooks/useDebounced'
import { cn, OFF } from '@/lib/utils'
import { useAuth } from '@/lib/auth'
import { NO_SITE } from '@/lib/day-sheet'
import { SITES, useApi, useList, useSave, WORK_SITES } from '@/lib/query'
import { DayEditor } from './attendance-day'
import { ImportTab } from './attendance-import'
import { DaySheet } from './attendance-sheet'
import { NotFoundPage } from './pages'

const GROUPS = ['office', 'engineer', 'permanent_worker', 'day_laborer']
/** The every-type view of the grid and the sheet (owner, 2026-11-08): engineers' 26th→25th window, no lock (locking is per group). */
const ALL = 'all'
const PER_PAGE = 50
const TABS = ['sheet', 'grid', 'import'] // import only with attendance.edit

type Period = { group: string; start: string; end: string; prev_start: string; next_start: string | null; is_locked: boolean; lock: { locked_by: { name: string } | null; locked_at: string | null } | null; missing: number }
/** `POST /attendance/locks`: the period header plus what a re-lock changed and the leave days on a holiday. */
type Locked = Period & { holidays_removed: string[]; holidays_added: string[]; leave_on_holidays: (Row & { date: string; leave_code: string })[] }
export type Day = { date: string; state: 'not_employed' | 'stored' | 'derived' | 'future' | 'missing'; source?: string | null; site_id: string | null; leave_code: string | null }
type GridRow = { employee: Row; days: Day[] }

/** A day's state label and look, shared with the employee's attendance tab. */
export const STATE_KEY: Partial<Record<Day['state'], string>> = { missing: 'attendance.state.missing', future: 'attendance.state.future', not_employed: 'attendance.state.notEmployed' }
const LOOK: Record<Day['state'], string> = {
  stored: 'hover:bg-muted',
  derived: 'border border-dashed border-muted-foreground/60 text-muted-foreground hover:bg-muted',
  missing: 'bg-warning/15 text-warning ring-1 ring-warning/40 hover:bg-warning/25',
  future: 'text-muted-foreground opacity-50',
  not_employed: 'text-muted-foreground opacity-50',
}
const CELL = 'block min-h-tap w-full min-w-14 max-w-28 truncate rounded-md px-1.5 text-center text-xs transition-colors outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed'

/** `?date=` when it is a real date not after today in Cairo, else today. */
function pickDate(s: string | null) {
  const today = cairoToday()
  const d = new Date(`${s}T00:00:00Z`)
  return s && s <= today && !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s ? s : today
}

/**
 * HR's attendance page, URL state `?group=&period=&date=&tab=&site=&q=` (site: all-scope attendance only, the sheet also takes `none`;
 * q: the grid's name or code search); the group defaults to engineers, the grid's period to the current one, the sheet's date to today.
 */
export function AttendancePage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const { can } = useAuth()
  const tab = TABS.find((x) => x === params.get('tab') && (x !== 'import' || can('attendance.edit'))) ?? TABS[0]
  const groups = tab === 'import' ? GROUPS : [ALL, ...GROUPS]
  const group = groups.find((g) => g === params.get('group')) ?? 'engineer'
  const all = group === ALL
  const periodGroup = all ? 'engineer' : group // All shares the engineers' window
  const chosen = params.get('period')
  const date = pickDate(params.get('date'))
  const set = (patch: Record<string, string | null>) =>
    setParams((p) => {
      for (const [k, v] of Object.entries(patch)) {
        if (v === null) p.delete(k)
        else p.set(k, v)
      }
      return p
    }, { replace: true })
  const allowed = can('attendance.view') // without it nothing is fetched and the page is not found
  const siteFilter = can('attendance.view.all') && tab !== 'import'
  const urlSite = siteFilter ? params.get('site') ?? '' : ''
  const site = urlSite === NO_SITE && tab !== 'sheet' ? '' : urlSite
  const { data: workSites } = useApi<Row[]>(siteFilter ? WORK_SITES : null)
  // the sheet's period is the one holding its date; the grid's is the chosen one
  // the sheet keeps the last period while the next date's loads, so the header does not blink inside one period; a kept period
  // of another group, or one not holding the date, is not current: the header shows loading and offers no arrows or lock until the real one arrives
  const { data: kept, err } = useApi<Period>(allowed ? `/attendance/period?${new URLSearchParams(tab === 'sheet' ? { group: periodGroup, date } : { group: periodGroup, ...(chosen && { period_start: chosen }) })}` : null, { keep: tab === 'sheet' })
  const period = tab !== 'sheet' || (kept && kept.group === periodGroup && kept.start <= date && date <= kept.end) ? kept : undefined
  const start = chosen ?? period?.start // the grid follows the URL as soon as an arrow moves it
  const move = (to: string) => set(tab === 'sheet' ? { date: to } : { period: to })
  const [q, setQ] = useState(params.get('q') ?? '') // the router writes the URL in a transition, which would lag a box bound to it
  const search = useDebounced(q, 250) // the box stays instant; the grid asks again after a pause in typing
  const [day, setDay] = useState<{ employee: Row; date: string }>()
  const [dlg, setDlg] = useState<'lock' | 'reopen'>()
  const send = useSave()
  const at = useCairoTime()
  if (!allowed) return <NotFoundPage />
  const openDay = (e: Row, d: string) => setDay({ employee: e, date: d })
  // group, period arrows and lock line: shared by both tabs; the arrows move the grid's period or the sheet's date
  const bar = (
    <>
      <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:flex-wrap sm:items-end">
        <div className="sm:w-56">
          <SelectField name="group" label={t('attendance.groupLabel')} values={{ group }} set={(_, v) => set({ group: v, period: null, site: null })} errs={{}} options={groups.map((g) => [g, t(`attendance.group.${g}`)])} />
        </div>
        {siteFilter && (
          <FilterSelect value={site} onChange={(v) => set({ site: v || null })} label={t('attendance.siteFilter')} allLabel={t('attendance.allSites')} options={[...(tab === 'sheet' ? [[NO_SITE, t('attendance.noSite')] as [string, string]] : []), ...(workSites ?? []).map((s): [string, string] => [s.id, localName(s, i18n.language)])]} />
        )}
        {tab === 'grid' && <SearchInput value={q} onChange={(v) => { setQ(v); set({ q: v || null }) }} placeholder={t('attendance.sheet.search')} maxLength={100} />}
        <div className="flex flex-wrap items-center gap-1">
          <Button variant="outline" size="icon" disabled={!period} onClick={() => period && move(period.prev_start)} aria-label={t('attendance.prevPeriod')}>
            <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
          </Button>
          <span className="px-1 text-sm font-medium whitespace-nowrap">
            {period ? ltrDates(t('attendance.periodRange', { start: period.start, end: period.end })) : '—'}
          </span>
          <Button variant="outline" size="icon" disabled={!period?.next_start} onClick={() => period?.next_start && move(period.next_start)} aria-label={t('attendance.nextPeriod')}>
            <ChevronRight className="rtl:-scale-x-100" aria-hidden />
          </Button>
          {!all && period?.is_locked && <StatusBadge variant="neutral" label={t('attendance.locked')} />}
        </div>
      </div>
      {!all && period?.is_locked && period.lock && (
        <div className="border-b p-4">
          <Alert tone="neutral" icon={Lock}>
            {t('attendance.lockedBy', { name: period.lock.locked_by?.name ?? '—', time: at(period.lock.locked_at) })}
          </Alert>
        </div>
      )}
      {!!err && (
        <div className="border-b p-4">
          <ErrorBanner err={err} shown={[]} />
        </div>
      )}
    </>
  )
  // Lock is the grid's main action; on the sheet and import tabs their own action leads, so Lock and Reopen are alternatives
  const lockAction = period && !all && can('attendance.lock')
    ? period.is_locked
      ? { label: t('attendance.reopen'), icon: LockOpen, onClick: () => setDlg('reopen') }
      : { label: t('attendance.lock'), icon: Lock, onClick: () => setDlg('lock') }
    : undefined
  const main = tab === 'grid' && !period?.is_locked

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('nav.attendance')}
        description={period && !all ? ltrDates(t('attendance.periodLine', { start: period.start, end: period.end, missing: period.missing })) : t('attendance.subtitle')}
        action={main ? lockAction : undefined}
        secondary={!main && lockAction ? [lockAction] : undefined}
      />
      <Tabs value={tab} onValueChange={(v) => set({ tab: v, ...(v !== 'sheet' && urlSite === NO_SITE && { site: null }) })}>
        <TabsList>
          <TabsTrigger value="sheet">
            <ListChecks className="size-4" aria-hidden /> {t('attendance.tab.sheet')}
          </TabsTrigger>
          <TabsTrigger value="grid">
            <CalendarCheck className="size-4" aria-hidden /> {t('attendance.tab.grid')}
          </TabsTrigger>
          {can('attendance.edit') && (
            <TabsTrigger value="import">
              {/* the full label does not fit beside the other two on a phone */}
              <FileSpreadsheet className="size-4" aria-hidden /> <span className="sm:hidden">{t('attendance.tab.importShort')}</span>
              <span className="max-sm:hidden">{t('attendance.tab.import')}</span>
            </TabsTrigger>
          )}
        </TabsList>
        <TabsContent value="sheet" className="space-y-4">
          <Card className="overflow-hidden [&>:last-child]:border-b-0">{bar}</Card>
          <DaySheet key={group} group={group} date={date} site={site} period={period} onDate={(d) => set({ date: d })} onOpenDay={openDay} />
        </TabsContent>
        <TabsContent value="grid">
          <Card className="overflow-hidden">
            {bar}
            {start ? <Grid key={`${group}/${start}/${site}/${search}`} group={group} start={start} site={site} search={search} onOpenDay={openDay} /> : !err && <TableSkeleton rows={8} />}
          </Card>
        </TabsContent>
        {can('attendance.edit') && (
          <TabsContent value="import" className="space-y-4">
            <Card className="overflow-hidden [&>:last-child]:border-b-0">{bar}</Card>
            {start ? <ImportTab key={`${group}/${start}`} group={group} periodStart={start} /> : !err && <TableSkeleton rows={3} />}
          </TabsContent>
        )}
      </Tabs>
      {period && dlg === 'lock' && <LockDialog period={period} onClose={() => setDlg(undefined)} />}
      {period && dlg === 'reopen' && (
        <FormDialog
          title={t('attendance.reopenTitle')}
          open
          onOpenChange={(o) => !o && setDlg(undefined)}
          init={{ reason: '' }}
          submit={(f) => send('/attendance/locks/reopen', { body: { group: period.group, period_start: period.start, reason: f.reason } })}
          onDone={() => toast.success(t('common.saved'))}
          shown={['reason']}
          footer="attendance.reopen"
        >
          {({ values, set, errs }) => (
            <Field name="reason" label={t('attendance.reopenReason')} errs={errs}>
              {(p) => <Textarea {...p} required maxLength={500} value={values.reason} onChange={(e) => set('reason', e.target.value)} />}
            </Field>
          )}
        </FormDialog>
      )}
      {day && <DayEditor employee={day.employee} date={day.date} onOpenChange={(o) => !o && setDay(undefined)} />}
    </div>
  )
}

/**
 * Scrolls the grid's own box (the Table wrapper) so today's column shows beside the sticky name column, never the page. Like the
 * strip's showDay, a module-level ref runs once when the table mounts, not on every render. The free area is the wider side of
 * the box next to the sticky head cell (left of it in RTL, right of it in LTR); scrollBy is in screen pixels, so both work.
 */
function showToday(table: HTMLTableElement | null) {
  const box = table?.parentElement
  const b = box?.getBoundingClientRect()
  const pin = table?.querySelector('th')?.getBoundingClientRect()
  const day = table?.querySelector('th[aria-current="date"]')?.getBoundingClientRect()
  if (!box || !b || !pin || !day) return
  const [left, right] = pin.left - b.left > b.right - pin.right ? [b.left, pin.left] : [pin.right, b.right]
  const dx = day.left < left ? day.left - left : day.right > right ? day.right - right : 0
  if (dx) box.scrollBy({ left: dx })
}

/** One page of the period grid: a sticky employee column, then one button per day; a column is off when some row's day is derived. */
function Grid({ group, start, site, search, onOpenDay }: { group: string; start: string; site: string; search: string; onOpenDay: (employee: Row, date: string) => void }) {
  const { t, i18n } = useTranslation()
  const [page, setPage] = useState(1)
  const { rows, total, err, loading } = useList(`/attendance/grid?${new URLSearchParams({ group, period_start: start, ...(site && { site_id: site }), ...(search && { search }), page: String(page), per_page: String(PER_PAGE) })}`)
  const { data: sites = [], loading: sitesLoading } = useApi<Row[]>(SITES)
  const grid = rows as GridRow[]
  const today = cairoToday()
  const offDays = new Set(grid.flatMap((r) => r.days.filter((d) => d.state === 'derived' || d.source === 'derived').map((d) => d.date)))
  const siteName = (id: string) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, i18n.language) : '—'
  }
  const label = (d: Day) => {
    const state = STATE_KEY[d.state]
    if (state) return t(state)
    if (d.leave_code) return t(`attendance.short.${d.leave_code}`, { defaultValue: d.leave_code })
    return d.site_id ? siteName(d.site_id) : t('attendance.present')
  }

  if (loading || sitesLoading) return <TableSkeleton rows={8} /> // site names widen the columns, so showToday measures after them
  if (err) return <div className="p-4"><ErrorBanner err={err} shown={[]} /></div>
  if (!grid.length) return <EmptyState icon={Users} title={t(search ? 'attendance.sheet.noMatch' : 'attendance.empty')} />
  return (
    <>
      {/* each cell owns its row line: with collapsed borders a sticky cell leaves a 1 px band unpainted at some sub-pixel offsets,
          and a tinted off column scrolling under the names shows through it */}
      <Table ref={showToday} className="border-separate border-spacing-0">
        <TableHeader>
          <TableRow>
            <TableHead className="sticky start-0 z-10 border-b bg-muted">{t('attendance.employee')}</TableHead>
            {grid[0].days.map(({ date }) => (
              <TableHead key={date} aria-current={date === today ? 'date' : undefined} className={cn('min-w-14 border-b px-1 py-1 text-center normal-case tracking-normal first:ps-1 last:pe-1', offDays.has(date) && OFF, date === today && 'border-b-2 border-primary bg-accent text-accent-foreground')}>
                <div>{t(`weekdayShort.${weekdayOf(date)}`)}</div>
                <div className={cn('text-sm font-semibold', date !== today && 'text-foreground')}>{Number(date.slice(8))}</div>
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody className="[&_tr:last-child>td]:border-b-0">
          {grid.map(({ employee, days }) => (
            <TableRow key={employee.id}>
              <TableCell className="sticky start-0 z-10 border-b bg-card">
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">{employee.code}</span>
                  <bdi>{localName(employee, i18n.language)}</bdi>
                </span>
              </TableCell>
              {days.map((d) => {
                const text = label(d)
                const off = d.state === 'future' || d.state === 'not_employed'
                return (
                  <TableCell key={d.date} className={cn('border-b px-0.5 py-1 first:ps-0.5 last:pe-0.5', offDays.has(d.date) && OFF)}>
                    <button type="button" disabled={off} aria-label={`${localName(employee, i18n.language)} ${d.date}: ${text}`} className={cn(CELL, LOOK[d.state], offDays.has(d.date) && d.state !== 'missing' && 'hover:bg-muted-foreground/15')} onClick={() => onOpenDay(employee, d.date)}>
                      {off ? <span aria-hidden>—</span> : text}
                    </button>
                  </TableCell>
                )
              })}
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <div role="group" aria-label={t('attendance.legend')} className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-5 py-3 text-xs text-muted-foreground">
        <span className="font-medium text-foreground">{t('attendance.legend')}</span>
        {(['missing', 'derived', 'future'] as const).map((s) => (
          <span key={s} className="flex items-center gap-1.5">
            <span aria-hidden className={cn('size-4 rounded-sm', LOOK[s], s === 'future' && 'border bg-muted')} />
            {s === 'future' ? `${t('attendance.state.future')} / ${t('attendance.state.notEmployed')}` : t(`attendance.state.${s}`)}
          </span>
        ))}
      </div>
      <Pagination page={page} pageSize={PER_PAGE} total={total} onPageChange={setPage} />
    </>
  )
}

/**
 * Locks the period; with missing days it lists them and needs an explicit confirmation (HR may lock before the period ends).
 * A lock that changed holidays, or found leave on one, stays open on those lists so HR reviews them.
 */
function LockDialog({ period, onClose }: { period: Period; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const send = useSave()
  const [confirmed, setConfirmed] = useState(false)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<Locked>()
  const missing = period.missing > 0
  function lock() {
    setBusy(true)
    setErr(undefined)
    send<Locked>('/attendance/locks', { body: { group: period.group, period_start: period.start, confirm_missing: confirmed } })
      .then((r) => {
        if (r.holidays_removed.length || r.holidays_added.length || r.leave_on_holidays.length) setDone(r)
        else {
          toast.success(t('common.saved'))
          onClose()
        }
      }, setErr)
      .finally(() => setBusy(false))
  }
  if (done)
    return (
      <Dialog open onOpenChange={(o) => !o && onClose()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('attendance.lockTitle')}</DialogTitle>
          </DialogHeader>
          <DialogBody className="grid gap-4">
            <DialogDescription role="status" className="text-sm font-medium text-foreground">{t('attendance.relock.done')}</DialogDescription>
            {!!done.holidays_removed.length && <div><p className="text-sm">{t('attendance.relock.removed')}</p><Dates dates={done.holidays_removed} /></div>}
            {!!done.holidays_added.length && <div><p className="text-sm">{t('attendance.relock.added')}</p><Dates dates={done.holidays_added} /></div>}
            {!!done.leave_on_holidays.length && (
              <div>
                <p className="text-sm">{t('attendance.relock.leaveOnHoliday')}</p>
                <ul className="divide-y text-sm">
                  {done.leave_on_holidays.map((d) => (
                    <li key={`${d.employee_id}/${d.date}`} className="flex flex-wrap gap-x-3 py-2">
                      <span className="text-muted-foreground">{d.code}</span>
                      <span className="flex-1">{localName(d, i18n.language)}</span>
                      <span>{ltrDates(d.date)}</span>
                      <span>{t(`attendance.leaveName.${d.leave_code}`)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </DialogBody>
          <DialogFooter>
            <Button type="button" onClick={onClose}>{t('common.close')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    )
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('attendance.lockTitle')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={[]} />
          <DialogDescription className="text-sm text-foreground">{t('attendance.lockBody')}</DialogDescription>
          {missing && (
            <>
              <MissingDays group={period.group} start={period.start} />
              <label className="flex min-h-tap items-center gap-3 text-sm font-medium">
                <input type="checkbox" required className="size-4" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
                {t('attendance.lockConfirmMissing')}
              </label>
            </>
          )}
        </DialogBody>
        <DialogFooter>
          <Button type="button" disabled={busy || (missing && !confirmed)} onClick={lock}>
            {t('attendance.lock')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function MissingDays({ group, start }: { group: string; start: string }) {
  const { t, i18n } = useTranslation()
  const { rows, total, err, loading } = useList(`/attendance/missing?${new URLSearchParams({ group, period_start: start, per_page: '100' })}`)
  const id = useId()
  return (
    <div className="grid gap-2">
      <p id={id} className="text-sm font-medium">{t('attendance.lockMissing')}</p>
      <ErrorBanner err={err} shown={[]} />
      {loading ? (
        <TableSkeleton rows={3} />
      ) : (
        // its own scroll region, so the confirm checkbox and the Lock button stay in view
        <div role="region" aria-labelledby={id} tabIndex={0} className="max-h-64 overflow-y-auto rounded-md border outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring">
          <ul className="divide-y text-sm">
            {rows.map((d) => (
              <li key={`${d.employee_id}/${d.date}`} className="flex gap-3 px-3 py-2">
                <span className="text-muted-foreground">{d.code}</span>
                <span className="flex-1">{localName(d, i18n.language)}</span>
                <span>{ltrDates(d.date)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {total > rows.length && <Alert tone="warning">{t('common.tooMany')}</Alert>}
    </div>
  )
}
