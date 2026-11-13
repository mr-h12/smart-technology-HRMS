import { useQueryClient } from '@tanstack/react-query'
import { CheckCheck, ClipboardPaste, Clock, Lock, Undo2, Users } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { SearchInput } from '@/components/common/Filters'
import { StatRow, type StatTone } from '@/components/common/StatRow'
import { cairoToday, ErrorBanner, FormDialog, intlLocale, localName, ltrDates, type Row, SelectField, weekdayOf } from '@/components/form'
import { ActionBar } from '@/components/layout/ActionBar'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { paginate, Pagination } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { atSite, type BulkRow, bulkRows, isOfficeRow, MAX_BATCH, matches, type SheetAction, type SheetRow, statusOf, undoRows } from '@/lib/day-sheet'
import { queryKey, SITES, useApi, useSave } from '@/lib/query'
import { cn, FOCUS, OFF } from '@/lib/utils'
import { PasteDialog, SiteMessageDialog, TimesDialog } from './attendance-sheet-command'
import { QUICK, SheetRows, type Status } from './attendance-sheet-rows'

type Filter = 'all' | 'missing' | 'present' | 'late' | 'leave' | 'absent'
type Sheet = { group: string; date: string; days: { date: string; missing: number | null; off: boolean }[]; rows: SheetRow[] }
/** What one write changed, for undo (Task 21): the rows as they were, in request order, and the versions the server gave back. */
export type Applied = { label: string; before: SheetRow[]; after: { employee_id: string; version: number | null }[] }

const PER_PAGE = 50
const [ABSENT, , AbsentIcon, ABSENT_TONE] = QUICK[1]
// [filter, label key, the status's tone (a real category, so its number is coloured)]
const KPIS: [Filter, string, StatTone][] = [
  ['missing', 'attendance.state.missing', 'warning'],
  ['present', 'attendance.sheet.kpi.worked', 'success'],
  ['leave', 'attendance.sheet.kpi.leave', 'info'],
  ['absent', 'attendance.sheet.kpi.absent', 'destructive'],
]
const UNDO_DEPTH = 15
const APPLIED_TOAST = 'attendance-sheet-applied'
const OFFICE_KPIS: [Filter, string, StatTone][] = [KPIS[0], ['present', 'attendance.sheet.kpi.onTime', 'success'], ['late', 'attendance.sheet.kpi.late', 'warning'], KPIS[2], KPIS[3]]
const ALL_KPIS: [Filter, string, StatTone][] = [KPIS[0], ['present', 'attendance.present', 'success'], KPIS[2], KPIS[3]]

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
 * One group's roster for one date: KPI filters, the period's days, cohorts with their sweeps, a search, then the rows (quick
 * actions, selection, office in-time) with their period totals last, and the bulk bar for a selection under them.
 * Kept mounted across dates, so the filters stay; page and selection reset.
 * Every write goes through `apply()` and one `POST /attendance/days/bulk`, and lands on a session-only undo stack.
 */
export function DaySheet({ group, date, site, period, onDate, onOpenDay }: { group: string; date: string; site: string; period?: { group: string; start: string; end: string; is_locked: boolean }; onDate: (date: string) => void; onOpenDay: (employee: Row, date: string) => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const qc = useQueryClient()
  const save = useSave()
  const office = group === 'office'
  const all = group === 'all'
  const path = `/attendance/sheet?${new URLSearchParams({ group, date })}`
  const { data: sheet, err, loading } = useApi<Sheet>(path)
  const { data: sites = [] } = useApi<Row[]>(SITES)
  const [filter, setFilter] = useState<Filter>('all')
  const [cohort, setCohort] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())
  const [query, setQuery] = useState('') // the search: filters the list, never writes
  const [pasting, setPasting] = useState(false)
  const [pastingMessage, setPastingMessage] = useState(false)
  const [timing, setTiming] = useState(false) // the bulk bar's times dialog
  const [siteChoice, setSiteChoice] = useState('') // the bulk bar's Set site: chosen first, written only by its Apply button
  // the absent confirm, for the selection or for the rest; its count is fixed on open, so the closing dialog never shows the count after the write
  const [askAbsent, setAskAbsent] = useState({ open: false, picked: false, count: 0 })
  const unselect = () => { setSelected(new Set()); setSiteChoice('') }
  const [shownDate, setShownDate] = useState(date)
  if (shownDate !== date) {
    setShownDate(date)
    setPage(1)
    setPastingMessage(false)
    unselect()
  }
  // newest last; each entry carries its own date, so an undo after a date change still writes the day it changed
  const undos = useRef<(Applied & { date: string })[]>([])
  const [undoCount, setUndoCount] = useState(0)
  const [shownSite, setShownSite] = useState(site)
  if (shownSite !== site) {
    setShownSite(site)
    setCohort(null)
    setPage(1)
    unselect()
    // an undo left over from the old site would rewrite rows this filter hides
    undos.current = []
    setUndoCount(0)
    toast.dismiss(APPLIED_TOAST)
  }
  // employee ids with a write in flight: the ref guards against a double send before React re-renders, the state disables their controls
  const flight = useRef(new Set<string>())
  const [pending, setPending] = useState<ReadonlySet<string>>(new Set())
  const daysId = useId()
  const cohortsId = useId()
  // the header's period may still be the previous one while the next loads: write only into the period that holds this date.
  // All has no one period: each row carries its own type's lock, and a locked row is never written
  const editable = can('attendance.edit') && (all || (!!period && period.group === group && period.start <= date && date <= period.end && !period.is_locked))
  const leaveTypeId = (code: string) => sites.find((s) => s.kind === 'leave' && s.leave_code === code && s.active)?.id as string | undefined
  const workSites = sites.filter((s) => s.kind === 'work' && s.active)

  const name = (r: SheetRow) => localName(r.employee, i18n.language)

  /** Sends `out` for `day` with its rows in flight; a 409 names the stale rows' employees with the `conflict` message, any other refusal names each refused row's employee. Resolves to the new versions, or null. */
  async function write(day: string, out: BulkRow[], before: SheetRow[], conflict: string) {
    const mark = (on: boolean) => {
      for (const b of out) flight.current[on ? 'add' : 'delete'](b.employee_id)
      setPending(new Set(flight.current))
    }
    mark(true)
    try {
      return await save<Applied['after']>('/attendance/days/bulk', { body: { date: day, rows: out } })
    } catch (e) {
      // a detail's field is rows.N or rows.N.<field>, N indexing `out` (and so `before`)
      const who = (d: { field?: string | null }) => (d.field?.startsWith('rows.') ? before[Number(d.field.split('.')[1])] : undefined)
      // a 409 that names no row (a racing insert) falls through to the plain message
      if (e instanceof ApiError && e.code === 'concurrency_conflict' && e.details.some(who)) {
        const names = e.details.flatMap((d) => who(d) ?? []).map(name)
        toast.error(t(conflict, { names: new Intl.ListFormat(intlLocale(i18n.language)).format(names) }))
      } else if (e instanceof ApiError && e.details.some(who)) {
        toast.error(e.details.map((d) => { const r = who(d); return r ? t('attendance.sheet.toast.rowError', { name: name(r), message: d.message }) : d.message }).join(' '))
      } else toast.error(e instanceof Error ? e.message : String(e))
      return null
    } finally {
      // useSave has started the refetch; the rows stay in flight until it lands, so the next write carries the new versions
      await qc.invalidateQueries({ queryKey: queryKey(path) }, { cancelRefetch: false })
      mark(false)
    }
  }

  /** The one writer: sends `action` for `chosen` (minus rows in flight or already in that state), pushes it onto the undo stack and returns it, or null when nothing was written. */
  async function apply(action: SheetAction, chosen: SheetRow[], label: string): Promise<Applied | null> {
    const free = chosen.filter((r) => !r.locked && !flight.current.has(r.employee.id))
    const locked = chosen.filter((r) => r.locked).length // only a paste reaches a locked row: the row, rest and select-all leave them out
    const { rows: out, skipped } = bulkRows(action, free, leaveTypeId)
    if (out.length > MAX_BATCH) {
      toast.error(t('attendance.sheet.toast.tooMany', { max: MAX_BATCH }))
      return null
    }
    if (locked) toast.warning(t('attendance.sheet.toast.skippedLocked', { count: locked }))
    // Set site skips only office rows (no work site); every other skip is a field row with no site to use
    if (skipped.length) toast.warning(t(action.kind === 'site' ? 'attendance.sheet.toast.skippedOffice' : 'attendance.sheet.toast.skippedNoSite', { count: skipped.length }))
    else if (!out.length && !locked) toast.info(t('attendance.sheet.toast.nothingToChange'))
    if (!out.length) return null
    const before = out.map((b) => free.find((r) => r.employee.id === b.employee_id)!)
    return record(out, before, label)
  }

  async function record(out: BulkRow[], before: SheetRow[], label: string): Promise<Applied | null> {
    if (out.some((row) => flight.current.has(row.employee_id))) return null
    const after = await write(date, out, before, 'attendance.sheet.toast.conflict')
    if (!after) return null
    // one toast id: a newer write replaces this toast, so its Undo is always the newest write's
    // its Undo follows the bar's: while a write is in flight it does nothing, and preventing the default keeps the toast up
    toast.success(t('attendance.sheet.toast.applied', { count: out.length }), {
      id: APPLIED_TOAST,
      action: { label: t('common.undo'), onClick: (e) => { if (flight.current.size) e.preventDefault(); else void undo() } },
    })
    const done = { label, before, after }
    undos.current = [...undos.current, { ...done, date }].slice(-UNDO_DEPTH)
    setUndoCount(undos.current.length)
    return done
  }

  /** Puts the newest entry's rows back in one bulk write. ponytail: a refused undo (409 or any error) is dropped, not retried; the day stays editable from Details. */
  async function undo() {
    const entry = undos.current.at(-1)
    // any write in flight blocks undo: its entry is pushed only when it lands, so the newest entry may not be the newest write yet
    if (!entry || flight.current.size) return
    toast.dismiss(APPLIED_TOAST) // its Undo stood for the entry undone now; left up, it would undo an older one
    undos.current = undos.current.slice(0, -1)
    setUndoCount(undos.current.length)
    const out = undoRows(entry.before, entry.after)
    const before = out.map((b) => entry.before.find((r) => r.employee.id === b.employee_id)!)
    if (!out.length) return
    const done = await write(entry.date, out, before, 'attendance.sheet.toast.undoConflict')
    if (!done) return
    // an older entry for the same date that left a row at the version this undo started from now expects the version this undo wrote (versions are per employee and date)
    const from = new Map(before.map((r) => [r.employee.id, r.day.version]))
    const to = new Map(done.map((a) => [a.employee_id, a.version]))
    undos.current = undos.current.map((u) => (u.date !== entry.date ? u : { ...u, after: u.after.map((a) => (to.has(a.employee_id) && from.get(a.employee_id) === a.version ? { ...a, version: to.get(a.employee_id) ?? null } : a)) }))
    toast.success(entry.date === date ? t('attendance.sheet.toast.undone', { label: entry.label }) : ltrDates(t('attendance.sheet.toast.undoneOn', { label: entry.label, date: entry.date })))
  }

  if (loading) return <Card><TableSkeleton rows={8} /></Card>
  if (!sheet) return <ErrorBanner err={err} shown={[]} />

  const lang = i18n.language
  const today = cairoToday()
  const siteName = (id: string | null) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, lang) : '—'
  }
  const status = (r: SheetRow) => statusOf(r, isOfficeRow(r))
  // in All a late office employee counts as present: no other tile there splits out lateness
  const bucket = (s: Status): Filter => (s === 'derived' ? 'leave' : all && s === 'late' ? 'present' : s)
  const cohortOf = (r: SheetRow) => (office ? r.employee.department : r.last_site_id) ?? ''
  const rows = atSite(sheet.rows, site)
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
  const shown = rows.filter((r) => (filter === 'all' || bucket(status(r)) === filter) && (cohort === null || cohortOf(r) === cohort) && matches(r, query))
  const pageRows = paginate(shown, page, PER_PAGE)
  // a filter change drops the selection: a bulk action must never write rows the user can no longer see
  const pick = (f: Filter) => { setFilter(f === filter ? 'all' : f); setPage(1); unselect() }
  const pickCohort = (key: string) => { setCohort(key === cohort ? null : key); setPage(1); unselect() }

  const isMissing = (r: SheetRow) => status(r) === 'missing'
  const rest = shown.filter((r) => isMissing(r) && !r.locked)
  const picked = shown.filter((r) => selected.has(r.employee.id))
  const toggle = (ids: string[], on: boolean) => setSelected((cur) => {
    const next = new Set(cur)
    for (const id of ids) next[on ? 'add' : 'delete'](id)
    return next
  })
  const bulk = (action: SheetAction, label: string) => apply(action, picked, label).then((done) => done && unselect())
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveTypeId(a.code)

  return (
    <div className="space-y-4">
      {/* a failed background refetch: the last good sheet stays under the error */}
      <ErrorBanner err={err} shown={[]} />
      {!all && period?.is_locked && <Alert tone="info" icon={Lock}>{t('attendance.sheet.locked')}</Alert>}
      {all && rows.some((r) => r.locked) && <Alert tone="info" icon={Lock}>{t('attendance.sheet.someLocked')}</Alert>}

      <StatRow
        layout="compact"
        label={t('attendance.sheet.kpi.label')}
        hero={{
          label: t('attendance.sheet.kpi.total'),
          value: rows.length,
          detail: t('attendance.sheet.kpi.accounted', { done: rows.length - missing, total: rows.length }),
          progress: rows.length ? (rows.length - missing) / rows.length : 0,
          select: { pressed: filter === 'all', onSelect: () => { pick('all'); setCohort(null) } },
        }}
        stats={(office ? OFFICE_KPIS : all ? ALL_KPIS : KPIS).map(([f, label, tone]) => ({ label: t(label), value: count(f), tone, select: { pressed: filter === f, onSelect: () => pick(f) } }))}
      />

      <Card>
        <p id={daysId} className="px-4 pt-3 text-xs font-medium text-muted-foreground">{t('attendance.sheet.days')}</p>
        <div key={sheet.days[0]?.date} ref={showDay} role="group" aria-labelledby={daysId} className="flex gap-1.5 overflow-x-auto p-3 pt-2 scrollbar-thin">
          {sheet.days.map((d) => {
            const current = d.date === date
            const isToday = d.date === today
            const missingOn = site ? null : d.missing // the server counts the whole group, not the chosen site's rows
            return (
              <button
                key={d.date}
                type="button"
                disabled={d.date > today}
                aria-current={current ? 'date' : undefined}
                aria-label={missingOn === null ? d.date : t('attendance.sheet.dayLabel', { date: d.date, count: missingOn })}
                onClick={() => onDate(d.date)}
                className={cn(FOCUS, 'flex min-h-14 min-w-14 shrink-0 flex-col items-center justify-center rounded-lg border px-1.5 py-1 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-40', current ? 'border-primary bg-accent text-accent-foreground' : cn(d.off ? `${OFF} hover:bg-muted-foreground/15` : 'bg-card hover:bg-muted', isToday && 'text-primary'))}
              >
                <span className={cn(!current && !isToday && 'text-muted-foreground')}>{t(`weekdayShort.${weekdayOf(d.date)}`)}</span>
                <span className="text-sm font-semibold">{Number(d.date.slice(8))}</span>
                <span className={cn('text-[11px] font-semibold text-warning', !missingOn && 'invisible')}>{missingOn ?? 0}</span>
              </button>
            )
          })}
        </div>
      </Card>

      {!all && rows.length > 0 && (
        <Card>
          <p id={cohortsId} className="px-4 pt-3 text-xs font-medium text-muted-foreground">{t(office ? 'attendance.sheet.cohortsDept' : 'attendance.sheet.cohortsSite')}</p>
          {/* chips as wide as their label: only a name longer than the whole row is cut, and its title keeps it */}
          <div role="group" aria-labelledby={cohortsId} className="flex flex-wrap gap-2 p-3 pt-2">
            {cohorts.map(([key, { total, done }]) => (
              <div key={key} className="flex max-w-full min-w-36 flex-col gap-1.5">
                <button type="button" aria-pressed={cohort === key} onClick={() => pickCohort(key)} className={cn(FOCUS, 'min-h-tap rounded-xl border p-2.5 text-start transition-colors', cohort === key ? 'border-primary bg-accent' : 'bg-muted/40 hover:bg-muted')}>
                  <span className="flex items-center justify-between gap-2 text-xs">
                    <span className="truncate font-semibold" title={cohortName(key)}>{cohortName(key)}</span>
                    <span dir="ltr" className={cn('font-semibold', done === total ? 'text-success' : 'text-muted-foreground')}>{done}/{total}</span>
                  </span>
                  <span aria-hidden className="mt-1.5 block h-1.5 overflow-hidden rounded-full border bg-card">
                    <span className={cn('block h-full rounded-full', done === total ? 'bg-success' : 'bg-primary')} style={{ width: `${(done / total) * 100}%` }} />
                  </span>
                </button>
                {/* a field cohort sweeps to its own site; the "no last site" cohort has none to sweep to */}
                {editable && (office || key) && (
                  <Button type="button" variant="outline" size="sm" className="text-success" disabled={done === total}
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
        {rows.length > 0 && (
          <div className="border-b px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
              {/* Enter does nothing here: the search only filters */}
              <SearchInput value={query} onChange={(v) => { setQuery(v); setPage(1); unselect() }} placeholder={t('attendance.sheet.search')} />
              {editable && (
                <Button type="button" variant="ghost" size="sm" onClick={() => setPasting(true)}>
                  <ClipboardPaste aria-hidden />
                  {t('attendance.sheet.paste.button')}
                </Button>
              )}
              {editable && !office && (
                <Button type="button" variant="ghost" size="sm" onClick={() => setPastingMessage(true)}>
                  {t('attendance.sheet.message.title')}
                </Button>
              )}
              {editable && (
                <Button type="button" variant="ghost" size="sm" className="aria-disabled:cursor-not-allowed aria-disabled:opacity-50" aria-disabled={!undoCount || pending.size > 0} onClick={() => void undo()}>
                  <Undo2 aria-hidden />
                  {t('attendance.sheet.undo', { count: undoCount })}
                </Button>
              )}
              {editable && (
                <Button type="button" variant="outline" size="sm" className={cn('ms-auto', ABSENT_TONE)} disabled={!rest.length || noLeave(ABSENT)} onClick={() => setAskAbsent({ open: true, picked: false, count: rest.length })}>
                  <AbsentIcon aria-hidden />
                  {t('attendance.sheet.restAbsent', { count: rest.length })}
                </Button>
              )}
              {editable && (
                <Button type="button" size="sm" disabled={!rest.length} onClick={() => void apply({ kind: 'present' }, rest, t('attendance.sheet.rest', { count: rest.length }))}>
                  <CheckCheck aria-hidden />
                  {t('attendance.sheet.rest', { count: rest.length })}
                </Button>
              )}
            </div>
            <p aria-live="polite" className="mt-2 text-xs text-muted-foreground empty:mt-0">{query.trim() && t('attendance.sheet.found', { shown: shown.length, total: rows.length })}</p>
          </div>
        )}
        {editable && <PasteDialog open={pasting} onOpenChange={setPasting} rows={rows} leaveTypeId={leaveTypeId} onApply={async (action, chosen, label) => !!(await apply(action, chosen, label))} />}
        {editable && !office && pastingMessage && (
          <SiteMessageDialog key={`${group}:${date}`} rows={rows} sites={workSites} date={date} absentId={leaveTypeId('absent')} allowOvernight={group === 'permanent_worker'}
            pending={pending.size > 0} onClose={() => setPastingMessage(false)}
            onApply={async (out, before) => !!(await record(out, before, t('attendance.sheet.message.title')))} />
        )}
        {!rows.length ? (
          <EmptyState icon={Users} title={t(site ? 'attendance.sheet.noMatch' : 'attendance.empty')} />
        ) : !shown.length ? (
          <EmptyState icon={Users} title={t('attendance.sheet.noMatch')} />
        ) : (
          <>
            <SheetRows rows={pageRows} allIds={shown.flatMap((r) => (r.locked ? [] : [r.employee.id]))} group={group} editable={editable} date={date} sites={sites} selected={selected} pending={pending} leaveTypeId={leaveTypeId}
              onToggle={toggle} onApply={(action, r, label) => apply(action, [r], label)} onOpenDay={onOpenDay} />
            <Pagination page={page} pageSize={PER_PAGE} total={shown.length} onPageChange={setPage} />
          </>
        )}
      </Card>

      {/* after the Card, never inside it: its overflow-hidden would stop the bar sticking to the screen bottom */}
      {editable && picked.length > 0 && (
        <ActionBar label={t('attendance.sheet.bulk.label')}>
          <span className="me-auto text-sm font-semibold">{t('attendance.sheet.bulk.count', { count: picked.length })}</span>
          {QUICK.map(([action, label, Icon, tone]) => (
            <Button key={label} type="button" variant="outline" size="sm" className={tone} disabled={noLeave(action)}
              onClick={() => (action === ABSENT ? setAskAbsent({ open: true, picked: true, count: picked.length }) : void bulk(action, t(label)))}>
              <Icon aria-hidden />
              {t(label)}
            </Button>
          ))}
          <Button type="button" variant="outline" size="sm" onClick={() => setTiming(true)}>
            <Clock aria-hidden />
            {t('attendance.sheet.bulk.times')}
          </Button>
          {/* a closed native select changes on an arrow key or a letter, so choosing never writes; Apply does */}
          {!office && (
            <div className="flex items-end gap-2">
              <div className="w-44">
                <SelectField name="bulk_site" label={t('attendance.sheet.bulk.setSite')} values={{ bulk_site: siteChoice }} errs={{}} blank={t('attendance.sheet.bulk.pickSite')}
                  options={workSites.map((s) => [s.id, localName(s, lang)])} set={(_, v) => setSiteChoice(v)} />
              </div>
              <Button type="button" variant="outline" size="sm" disabled={!siteChoice}
                onClick={() => bulk({ kind: 'site', siteId: siteChoice }, t('attendance.sheet.actionOn', { action: t('attendance.sheet.bulk.setSite'), name: siteName(siteChoice) }))}>
                {t('attendance.sheet.bulk.apply')}
              </Button>
            </div>
          )}
          <Button type="button" variant="ghost" size="sm" onClick={unselect}>{t('attendance.sheet.bulk.clear')}</Button>
        </ActionBar>
      )}
      {/* a bulk absent (the rest, or the selection) asks first; one row's ✗ does not. apply() never throws: it toasts and resolves null, and the dialog closes either way */}
      {editable && (
        <FormDialog open={askAbsent.open} onOpenChange={(open) => setAskAbsent((a) => ({ ...a, open }))} title={t('attendance.sheet.absentConfirm.title')}
          description={t('attendance.sheet.absentConfirm.body', { count: askAbsent.count })} footer="attendance.sheet.absentConfirm.ok" destructive init={{}} shown={[]}
          submit={async () => { await (askAbsent.picked ? bulk(ABSENT, t(QUICK[1][1])) : apply(ABSENT, rest, t('attendance.sheet.restAbsent', { count: rest.length }))) }}>
          {() => null}
        </FormDialog>
      )}
      {editable && timing && (
        <TimesDialog rows={picked} onOpenChange={setTiming}
          onApply={async (action, chosen, label) => { const done = await apply(action, chosen, label); if (done) unselect(); return !!done }} />
      )}
    </div>
  )
}
