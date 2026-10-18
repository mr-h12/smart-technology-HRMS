import { useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Check, CheckCheck, CircleHelp, Clock, Lock, type LucideIcon, Stethoscope, Users, UserX } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { EmptyState } from '@/components/common/EmptyState'
import { cairoToday, ErrorBanner, intlLocale, localName, type Row, SelectField } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { paginate, Pagination } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { bulkRows, MAX_BATCH, type SheetAction, type SheetRow, statusOf } from '@/lib/day-sheet'
import { queryKey, SITES, useApi, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

type Status = ReturnType<typeof statusOf>
type Filter = 'all' | 'missing' | 'present' | 'late' | 'leave' | 'absent'
type Sheet = { group: string; date: string; days: { date: string; missing: number }[]; rows: SheetRow[] }
/** What one write changed, for undo (Task 21): the rows as they were, in request order, and the versions the server gave back. */
export type Applied = { label: string; before: SheetRow[]; after: { employee_id: string; version: number | null }[] }

const PER_PAGE = 50
const FOCUS = 'outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring'
// "missing" (no record) and "absent" (غ) never share a look: different tokens, icon and border style
const LOOK: Record<Status, { cls: string; text: string; icon: LucideIcon }> = {
  missing: { cls: 'border-dashed bg-warning-bg border-warning-border', text: 'text-warning', icon: CircleHelp },
  present: { cls: 'bg-success-bg border-success-border', text: 'text-success', icon: Check },
  late: { cls: 'bg-warning-bg border-warning-border', text: 'text-warning', icon: Clock },
  absent: { cls: 'bg-destructive-bg border-destructive-border', text: 'text-destructive', icon: UserX },
  leave: { cls: 'bg-info-bg border-info-border', text: 'text-info', icon: CalendarOff },
  derived: { cls: 'border-dashed bg-muted', text: 'text-muted-foreground', icon: CalendarOff },
}
const bucket = (s: Status): Filter => (s === 'derived' ? 'leave' : s)
// [filter, label key, status whose look the card borrows]
const KPIS: [Filter, string, Status][] = [
  ['missing', 'attendance.state.missing', 'missing'],
  ['present', 'attendance.sheet.kpi.worked', 'present'],
  ['leave', 'attendance.sheet.kpi.leave', 'leave'],
  ['absent', 'attendance.sheet.kpi.absent', 'absent'],
]
const OFFICE_KPIS: [Filter, string, Status][] = [KPIS[0], ['present', 'attendance.sheet.kpi.onTime', 'present'], ['late', 'attendance.sheet.kpi.late', 'late'], KPIS[2], KPIS[3]]
// the row and bulk-bar quick actions: [action, label key, icon, status tone]
const QUICK: [SheetAction, string, LucideIcon, string][] = [
  [{ kind: 'present' }, 'attendance.sheet.action.present', Check, 'text-success hover:bg-success-bg hover:border-success-border'],
  [{ kind: 'leave', code: 'absent' }, 'attendance.sheet.action.absent', UserX, 'text-destructive hover:bg-destructive-bg hover:border-destructive-border'],
  [{ kind: 'leave', code: 'sick' }, 'attendance.sheet.action.sick', Stethoscope, 'text-info hover:bg-info-bg hover:border-info-border'],
]
const TOTALS = [['worked', 'text-success', 'attendance.sheet.kpi.worked'], ['leave', 'text-info', 'attendance.sheet.kpi.leave'], ['absent', 'text-destructive', 'attendance.sheet.kpi.absent'], ['missing', 'text-warning', 'attendance.state.missing']] as const

/**
 * Scrolls the day strip sideways to the selected day; only the strip, never the page. A module-level ref is stable, so React
 * calls it once when the strip mounts, not on every render; the strip remounts when a new date's sheet loads or the period
 * changes (it is keyed by the period's first day). scrollBy is in screen pixels, so RTL works.
 */
function showDay(strip: HTMLDivElement | null) {
  const day = strip?.querySelector('[aria-current="date"]')?.getBoundingClientRect()
  const box = strip?.getBoundingClientRect()
  if (!day || !box) return
  const dx = day.left < box.left ? day.left - box.left : day.right > box.right ? day.right - box.right : 0
  if (dx) strip!.scrollBy({ left: dx })
}

/**
 * One group's roster for one date: KPI filters, the period's days, cohorts with their sweeps, then the rows (quick actions,
 * selection, office in-time) with their period totals last. Kept mounted across dates, so the filters stay; page and selection reset.
 * Every write goes through `apply()` and one `POST /attendance/days/bulk`.
 */
export function DaySheet({ group, date, period, onDate, onOpenDay }: { group: string; date: string; period?: { group: string; start: string; end: string; is_locked: boolean }; onDate: (date: string) => void; onOpenDay: (employee: Row, date: string) => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const qc = useQueryClient()
  const save = useSave()
  const office = group === 'office'
  const path = `/attendance/sheet?${new URLSearchParams({ group, date })}`
  const { data: sheet, err, loading } = useApi<Sheet>(path)
  const { data: sites = [] } = useApi<Row[]>(SITES)
  const [filter, setFilter] = useState<Filter>('all')
  const [cohort, setCohort] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())
  const [shownDate, setShownDate] = useState(date)
  if (shownDate !== date) {
    setShownDate(date)
    setPage(1)
    setSelected(new Set())
  }
  // employee ids with a write in flight: the ref guards against a double send before React re-renders, the state disables their controls
  const flight = useRef(new Set<string>())
  const [pending, setPending] = useState<ReadonlySet<string>>(new Set())
  const daysId = useId()
  const cohortsId = useId()
  // the header's period may still be the previous one while the next loads: write only into the period that holds this date
  const editable = !!period && period.group === group && period.start <= date && date <= period.end && !period.is_locked && can('attendance.edit')
  const leaveSite = (code: string) => sites.find((s) => s.kind === 'leave' && s.leave_code === code && s.active)?.id as string | undefined
  const workSites = sites.filter((s) => s.kind === 'work' && s.active)

  const name = (r: SheetRow) => localName(r.employee, i18n.language)

  /** The one writer: sends `action` for `chosen` (minus rows in flight or already in that state) and returns what undo needs, or null when nothing was written. */
  async function apply(action: SheetAction, chosen: SheetRow[], label: string): Promise<Applied | null> {
    const free = chosen.filter((r) => !flight.current.has(r.employee.id))
    const { rows: out, skipped } = bulkRows(action, free, leaveSite, office)
    if (out.length > MAX_BATCH) {
      toast.error(t('attendance.sheet.toast.tooMany', { max: MAX_BATCH }))
      return null
    }
    if (skipped.length) toast.warning(t('attendance.sheet.toast.skippedNoSite', { count: skipped.length }))
    if (!out.length) return null
    const before = out.map((b) => free.find((r) => r.employee.id === b.employee_id)!)
    const mark = (on: boolean) => {
      for (const b of out) flight.current[on ? 'add' : 'delete'](b.employee_id)
      setPending(new Set(flight.current))
    }
    mark(true)
    try {
      const after = await save<Applied['after']>('/attendance/days/bulk', { body: { date, rows: out } })
      toast.success(t('attendance.sheet.toast.applied', { count: out.length }))
      return { label, before, after }
    } catch (e) {
      if (e instanceof ApiError && e.code === 'concurrency_conflict') {
        const names = e.details.flatMap((d) => before[Number(d.field?.split('.')[1])] ?? []).map(name)
        toast.error(t('attendance.sheet.toast.conflict', { names: new Intl.ListFormat(intlLocale(i18n.language)).format(names) }))
      } else toast.error(e instanceof Error ? e.message : String(e))
      return null
    } finally {
      // useSave has started the refetch; the rows stay in flight until it lands, so the next write carries the new versions
      await qc.invalidateQueries({ queryKey: queryKey(path) }, { cancelRefetch: false })
      mark(false)
    }
  }

  if (loading) return <Card><TableSkeleton rows={8} /></Card>
  if (err || !sheet) return <ErrorBanner err={err} shown={[]} />

  const lang = i18n.language
  const today = cairoToday()
  const weekday = new Intl.DateTimeFormat(intlLocale(lang), { weekday: 'narrow', timeZone: 'UTC' })
  const siteName = (id: string | null) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, lang) : '—'
  }
  const status = (r: SheetRow) => statusOf(r, office)
  const cohortOf = (r: SheetRow) => (office ? r.employee.department : r.last_site_id) ?? ''
  const rows = sheet.rows
  const count = (f: Filter) => rows.filter((r) => bucket(status(r)) === f).length
  const missing = count('missing')
  const groups = new Map<string, { total: number; done: number }>()
  for (const r of rows) {
    const g = groups.get(cohortOf(r)) ?? { total: 0, done: 0 }
    g.total++
    if (status(r) !== 'missing') g.done++
    groups.set(cohortOf(r), g)
  }
  const cohorts = [...groups].sort(([a], [b]) => Number(!a) - Number(!b)) // "none" last
  const cohortName = (key: string) => (office ? key || t('attendance.sheet.noDepartment') : key ? siteName(key) : t('attendance.sheet.noLastSite'))
  const shown = rows.filter((r) => (filter === 'all' || bucket(status(r)) === filter) && (cohort === null || cohortOf(r) === cohort))
  const pageRows = paginate(shown, page, PER_PAGE)
  const pick = (f: Filter) => { setFilter(f === filter ? 'all' : f); setPage(1) }
  const pickCohort = (key: string) => { setCohort(key === cohort ? null : key); setPage(1) }

  const isMissing = (r: SheetRow) => status(r) === 'missing'
  const rest = shown.filter(isMissing)
  const picked = rows.filter((r) => selected.has(r.employee.id))
  const pageIds = pageRows.map((r) => r.employee.id)
  const pageAll = pageIds.every((id) => selected.has(id))
  const pageSome = pageIds.some((id) => selected.has(id))
  const toggle = (ids: string[], on: boolean) => setSelected((cur) => {
    const next = new Set(cur)
    for (const id of ids) next[on ? 'add' : 'delete'](id)
    return next
  })
  const bulk = (action: SheetAction, label: string) => void apply(action, picked, label).then((done) => done && setSelected(new Set()))
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveSite(a.code)
  const check = (r: SheetRow) => (
    <label className="flex size-11 cursor-pointer items-center justify-center">
      <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={selected.has(r.employee.id)} onChange={(e) => toggle([r.employee.id], e.target.checked)} />
      <span className="sr-only">{t('attendance.sheet.select', { name: name(r) })}</span>
    </label>
  )
  // compact: icon-only below xl, so the desktop row fits beside the totals; the aria-label always names the action and the employee
  const quick = (r: SheetRow, h: string, compact = false) => QUICK.map(([action, label, Icon, tone]) => (
    <Button key={label} type="button" variant="outline" size="sm" className={cn(FOCUS, h, 'px-2.5', tone)} disabled={pending.has(r.employee.id) || noLeave(action)}
      aria-label={t('attendance.sheet.rowAction', { action: t(label), name: name(r) })} onClick={() => void apply(action, [r], `${t(label)} ${name(r)}`)}>
      <Icon aria-hidden />
      <span className={cn(compact && 'hidden xl:inline')}>{t(label)}</span>
    </Button>
  ))
  const inTime = (r: SheetRow, h: string) => {
    const was = r.day.in_time ? String(r.day.in_time).slice(0, 5) : ''
    const commit = (v: string) => v && v !== was && void apply({ kind: 'time', in_time: v }, [r], `${t('attendance.day.in_time')} ${name(r)}`)
    return (
      <input key={`${r.employee.id}/${r.day.version}`} type="time" defaultValue={was} disabled={pending.has(r.employee.id)} aria-label={t('attendance.sheet.inTime', { name: name(r) })}
        className={cn(FOCUS, h, 'rounded-md border bg-card px-2 text-sm tabular disabled:opacity-50')}
        onBlur={(e) => { if (!e.currentTarget.value) e.currentTarget.value = was; commit(e.currentTarget.value) }} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} />
    )
  }
  const pill = (r: SheetRow) => {
    const s = status(r)
    const { cls, text, icon: Icon } = LOOK[s]
    const leave = r.day.leave_code
    const label = s === 'missing' ? t('attendance.state.missing')
      : s === 'present' ? t('attendance.present')
      : s === 'late' ? t('attendance.sheet.lateBy', { minutes: r.hints.late_minutes })
      : s === 'absent' ? t('attendance.sheet.kpi.absent')
      : leave ? t(`attendance.short.${leave}`, { defaultValue: leave }) : t('attendance.state.derived')
    return (
      <span className={cn('inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', cls, text)}>
        <Icon className="size-3.5 shrink-0" aria-hidden />
        {label}
      </span>
    )
  }
  const where = (r: SheetRow) => {
    if (office && editable) return inTime(r, 'h-9')
    if (office) return <span className="text-sm tabular">{r.day.in_time ? String(r.day.in_time).slice(0, 5) : '—'}</span>
    const s = status(r)
    if (s === 'present') return <span className="rounded-md border bg-card px-2 py-0.5 text-xs">{siteName(r.day.site_id)}</span>
    if (s === 'missing' && r.last_site_id) return <span className="text-xs text-muted-foreground">{t('attendance.sheet.lastSite', { site: cohortName(r.last_site_id) })}</span>
    return null
  }
  const details = (r: SheetRow, cls: string) => (
    <Button type="button" variant="outline" size="sm" className={cn(FOCUS, cls)} aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.details'), name: name(r) })} onClick={() => onOpenDay(r.employee, date)}>
      {t('attendance.sheet.action.details')}
    </Button>
  )
  const totals = (r: SheetRow) => (
    <div className="grid grid-cols-4 gap-3">
      {TOTALS.map(([k, tone, label]) => (
        <div key={k} className="min-w-14">
          <div className={cn('text-sm font-semibold tabular', r.totals[k] ? tone : 'text-muted-foreground')}>{r.totals[k]}</div>
          <div className="truncate text-[11px] text-muted-foreground">{t(label)}</div>
        </div>
      ))}
    </div>
  )

  return (
    <div className="space-y-4">
      {period?.is_locked && (
        <p role="status" className="flex items-center gap-2 rounded-lg border border-info-border bg-info-bg px-4 py-3 text-sm text-info">
          <Lock className="size-4 shrink-0" aria-hidden />
          {t('attendance.sheet.locked')}
        </p>
      )}

      <div className={cn('grid grid-cols-2 gap-2.5 sm:grid-cols-3', office ? 'lg:grid-cols-6' : 'lg:grid-cols-5')}>
        <button type="button" aria-pressed={filter === 'all'} onClick={() => pick('all')} className={cn(FOCUS, 'col-span-2 rounded-lg border bg-card p-3 text-start shadow-1 transition-colors hover:border-primary sm:col-span-1 aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary')}>
          <span className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-muted-foreground">{t('attendance.sheet.kpi.total')}</span>
            <Users className="size-4 text-muted-foreground" aria-hidden />
          </span>
          <span className="mt-0.5 block text-2xl font-semibold tabular">{rows.length}</span>
          <span aria-hidden className="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-muted">
            <span className="block h-full rounded-full bg-primary transition-[width]" style={{ width: `${rows.length ? ((rows.length - missing) / rows.length) * 100 : 0}%` }} />
          </span>
          <span className="mt-1 block truncate text-xs text-muted-foreground tabular">{t('attendance.sheet.kpi.accounted', { done: rows.length - missing, total: rows.length })}</span>
        </button>
        {(office ? OFFICE_KPIS : KPIS).map(([f, label, look]) => {
          const Icon = LOOK[look].icon
          return (
            <button key={f} type="button" aria-pressed={filter === f} onClick={() => pick(f)} className={cn(FOCUS, 'rounded-lg border bg-card p-3 text-start shadow-1 transition-colors hover:border-primary aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary')}>
              <span className="flex items-center justify-between gap-2">
                <span className="truncate text-xs font-medium text-muted-foreground">{t(label)}</span>
                <span className={cn('flex size-7 shrink-0 items-center justify-center rounded-md border', LOOK[look].cls, LOOK[look].text)}>
                  <Icon className="size-4" aria-hidden />
                </span>
              </span>
              <span className={cn('mt-0.5 block text-2xl font-semibold tabular', LOOK[look].text)}>{count(f)}</span>
            </button>
          )
        })}
      </div>

      <Card>
        <p id={daysId} className="px-4 pt-3 text-xs font-medium text-muted-foreground">{t('attendance.sheet.days')}</p>
        <div key={sheet.days[0]?.date} ref={showDay} role="group" aria-labelledby={daysId} className="flex gap-1.5 overflow-x-auto p-3 pt-2 scrollbar-thin">
          {sheet.days.map((d) => {
            const current = d.date === date
            return (
              <button
                key={d.date}
                type="button"
                disabled={d.date > today}
                aria-current={current ? 'date' : undefined}
                aria-label={t('attendance.sheet.dayLabel', { date: d.date, count: d.missing })}
                onClick={() => onDate(d.date)}
                className={cn(FOCUS, 'flex min-h-14 min-w-11 shrink-0 flex-col items-center justify-center rounded-lg border px-1.5 py-1 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-40', current ? 'border-primary bg-accent text-accent-foreground' : 'bg-card hover:bg-muted')}
              >
                <span className="text-sm font-semibold tabular">{Number(d.date.slice(8))}</span>
                <span className="text-muted-foreground">{weekday.format(new Date(`${d.date}T00:00:00Z`))}</span>
                <span className={cn('text-[11px] font-semibold tabular text-warning', !d.missing && 'invisible')}>{d.missing}</span>
              </button>
            )
          })}
        </div>
      </Card>

      {rows.length > 0 && (
        <Card>
          <p id={cohortsId} className="px-4 pt-3 text-xs font-medium text-muted-foreground">{t(office ? 'attendance.sheet.cohortsDept' : 'attendance.sheet.cohortsSite')}</p>
          <div role="group" aria-labelledby={cohortsId} className="grid grid-cols-2 gap-2 p-3 pt-2 sm:grid-cols-3 lg:grid-cols-6">
            {cohorts.map(([key, { total, done }]) => (
              <div key={key} className="flex flex-col gap-1.5">
                <button type="button" aria-pressed={cohort === key} onClick={() => pickCohort(key)} className={cn(FOCUS, 'min-h-11 rounded-lg border p-2.5 text-start transition-colors', cohort === key ? 'border-primary bg-accent' : 'bg-muted/40 hover:bg-muted')}>
                  <span className="flex items-center justify-between gap-2 text-xs">
                    <span className="truncate font-semibold">{cohortName(key)}</span>
                    <span dir="ltr" className={cn('font-semibold tabular', done === total ? 'text-success' : 'text-muted-foreground')}>{done}/{total}</span>
                  </span>
                  <span aria-hidden className="mt-1.5 block h-1.5 overflow-hidden rounded-full border bg-card">
                    <span className={cn('block h-full rounded-full', done === total ? 'bg-success' : 'bg-primary')} style={{ width: `${(done / total) * 100}%` }} />
                  </span>
                </button>
                {/* a field cohort sweeps to its own site; the "no last site" cohort has none to sweep to */}
                {editable && (office || key) && (
                  <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-9 text-success')} disabled={done === total}
                    aria-label={t('attendance.sheet.sweepFor', { cohort: cohortName(key) })}
                    onClick={() => void apply({ kind: 'present' }, rows.filter((r) => cohortOf(r) === key && isMissing(r)), t('attendance.sheet.sweepFor', { cohort: cohortName(key) }))}>
                    <CheckCheck aria-hidden />
                    {t('attendance.sheet.sweep')}
                  </Button>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card className="overflow-hidden">
        {!rows.length ? (
          <EmptyState icon={Users} title={t('attendance.empty')} />
        ) : !shown.length ? (
          <EmptyState icon={Users} title={t('attendance.sheet.noMatch')} />
        ) : (
          <>
            {editable && (picked.length ? (
              <div role="group" aria-label={t('attendance.sheet.bulk.label')} className="flex flex-wrap items-end gap-2 border-b bg-accent px-4 py-3">
                <span className="me-auto self-center text-sm font-semibold tabular">{t('attendance.sheet.bulk.count', { count: picked.length })}</span>
                {QUICK.map(([action, label, Icon, tone]) => (
                  <Button key={label} type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 md:h-9', tone)} disabled={noLeave(action)} onClick={() => bulk(action, t(label))}>
                    <Icon aria-hidden />
                    {t(label)}
                  </Button>
                ))}
                {!office && (
                  <div className="w-44">
                    <SelectField name="bulk_site" label={t('attendance.sheet.bulk.setSite')} values={{ bulk_site: '' }} errs={{}} blank={t('attendance.sheet.bulk.pickSite')}
                      options={workSites.map((s) => [s.id, localName(s, lang)])} set={(_, v) => v && bulk({ kind: 'site', siteId: v }, `${t('attendance.sheet.bulk.setSite')} ${siteName(v)}`)} />
                  </div>
                )}
                <Button type="button" variant="ghost" size="sm" className={cn(FOCUS, 'h-11 md:h-9')} onClick={() => setSelected(new Set())}>{t('attendance.sheet.bulk.clear')}</Button>
              </div>
            ) : (
              <div className="flex justify-end border-b px-4 py-3">
                <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 text-success md:h-9')} disabled={!rest.length} onClick={() => void apply({ kind: 'present' }, rest, t('attendance.sheet.rest', { count: rest.length }))}>
                  <CheckCheck aria-hidden />
                  {t('attendance.sheet.rest', { count: rest.length })}
                </Button>
              </div>
            ))}
            <div className="hidden md:block">
              <Table>
                <TableHeader>
                  <TableRow>
                    {editable && (
                      <TableHead className="w-0 px-1 first:ps-2">
                        <label className="flex size-11 cursor-pointer items-center justify-center">
                          <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={pageAll} ref={(el) => { if (el) el.indeterminate = pageSome && !pageAll }} onChange={() => toggle(pageIds, !pageAll)} />
                          <span className="sr-only">{t('attendance.sheet.selectPage')}</span>
                        </label>
                      </TableHead>
                    )}
                    <TableHead>{t('employee.code')}</TableHead>
                    <TableHead>{t('attendance.employee')}</TableHead>
                    {office && <TableHead>{t('attendance.day.in_time')}</TableHead>}
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead><span className="sr-only">{t('common.actions')}</span></TableHead>
                    <TableHead className="border-s">{t('attendance.sheet.totals')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {pageRows.map((r) => (
                    <TableRow key={r.employee.id} className={cn(selected.has(r.employee.id) && 'bg-accent/60')}>
                      {editable && <TableCell className="px-1 py-0 first:ps-2">{check(r)}</TableCell>}
                      <TableCell className="tabular text-muted-foreground">{r.employee.code}</TableCell>
                      <TableCell>
                        <bdi className="font-medium">{name(r)}</bdi>
                        {r.employee.department && <div className="text-xs text-muted-foreground">{r.employee.department}</div>}
                      </TableCell>
                      {office && <TableCell>{where(r)}</TableCell>}
                      <TableCell>
                        <div className="flex items-center gap-1.5">
                          {pill(r)}
                          {!office && where(r)}
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-end gap-1.5">
                          {editable && quick(r, 'h-9', true)}
                          {details(r, 'h-9')}
                        </div>
                      </TableCell>
                      <TableCell className="border-s bg-muted/40">{totals(r)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <ul aria-label={t('attendance.employee')} className="divide-y md:hidden">
              {pageRows.map((r) => (
                <li key={r.employee.id} className="space-y-3 p-4">
                  <div className="flex items-start justify-between gap-3">
                    {editable && <div className="-my-2.5 -ms-2.5">{check(r)}</div>}
                    <div className="min-w-0 flex-1">
                      <span className="flex items-center gap-2">
                        <span className="tabular text-muted-foreground">{r.employee.code}</span>
                        <bdi className="truncate font-medium">{name(r)}</bdi>
                      </span>
                      {r.employee.department && <div className="truncate text-xs text-muted-foreground">{r.employee.department}</div>}
                    </div>
                    {pill(r)}
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0">{office && editable ? inTime(r, 'h-11') : where(r)}</div>
                    {details(r, 'h-11')}
                  </div>
                  {editable && <div className="grid grid-cols-3 gap-2">{quick(r, 'h-11')}</div>}
                  <div className="border-t pt-3">
                    <p className="mb-1.5 text-[11px] font-medium text-muted-foreground">{t('attendance.sheet.totals')}</p>
                    {totals(r)}
                  </div>
                </li>
              ))}
            </ul>
            <Pagination page={page} pageSize={PER_PAGE} total={shown.length} onPageChange={setPage} />
          </>
        )}
      </Card>
    </div>
  )
}
