import { useQueryClient } from '@tanstack/react-query'
import { CheckCheck, ClipboardPaste, Lock, ScanBarcode, Undo2, Users } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { EmptyState } from '@/components/common/EmptyState'
import { cairoToday, ErrorBanner, intlLocale, localName, type Row, SelectField } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { paginate, Pagination } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { type BulkRow, bulkRows, MAX_BATCH, parseBatch, type SheetAction, type SheetRow, statusOf, undoRows } from '@/lib/day-sheet'
import { queryKey, SITES, useApi, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'
import { BatchPreview, PasteDialog } from './attendance-sheet-command'
import { FOCUS, LOOK, QUICK, SheetRows, type Status } from './attendance-sheet-rows'

type Filter = 'all' | 'missing' | 'present' | 'late' | 'leave' | 'absent'
type Sheet = { group: string; date: string; days: { date: string; missing: number }[]; rows: SheetRow[] }
/** What one write changed, for undo (Task 21): the rows as they were, in request order, and the versions the server gave back. */
export type Applied = { label: string; before: SheetRow[]; after: { employee_id: string; version: number | null }[] }

const PER_PAGE = 50
const bucket = (s: Status): Filter => (s === 'derived' ? 'leave' : s)
// [filter, label key, status whose look the card borrows]
const KPIS: [Filter, string, Status][] = [
  ['missing', 'attendance.state.missing', 'missing'],
  ['present', 'attendance.sheet.kpi.worked', 'present'],
  ['leave', 'attendance.sheet.kpi.leave', 'leave'],
  ['absent', 'attendance.sheet.kpi.absent', 'absent'],
]
const UNDO_DEPTH = 15
// the status keys, in QUICK's order (Present, Absent, Sick); keys match on event.code so they work on an Arabic layout
const MARK = ['KeyP', 'KeyA', 'KeyS']
// the only keys that act again while held: holding P must not mark row after row, nor Ctrl+Z drain the stack
const MOVE = ['KeyJ', 'KeyK', 'ArrowDown', 'ArrowUp']
// the shortcuts line: [<kbd> glyphs (code, not i18n), description key under attendance.sheet.keys, needs a writable sheet]
const KEYS: [string[], string, boolean][] = [[['/'], 'command', false], [['J', 'K'], 'move', false], [['P', 'A', 'S'], 'mark', true], [['E'], 'details', false], [['Ctrl+Z'], 'undo', true], [['Esc'], 'clear', false]]
const OFFICE_KPIS: [Filter, string, Status][] = [KPIS[0], ['present', 'attendance.sheet.kpi.onTime', 'present'], ['late', 'attendance.sheet.kpi.late', 'late'], KPIS[2], KPIS[3]]

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
 * Every write goes through `apply()` and one `POST /attendance/days/bulk`, and lands on a session-only undo stack.
 * Keyboard (one document listener): / command bar, J/K or arrows move the row cursor, P/A/S mark it and advance, E opens
 * Details, Ctrl/Cmd+Z undoes, Esc clears. No shortcut fires while typing, while a dialog or menu is open, or with Alt/Ctrl/Meta (but Z).
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
  const [query, setQuery] = useState('') // the command bar: filters the list; Enter writes only an ID batch
  const [pasting, setPasting] = useState(false)
  const [siteChoice, setSiteChoice] = useState('') // the bulk bar's Set site: chosen first, written only by its Apply button
  const unselect = () => { setSelected(new Set()); setSiteChoice('') }
  const [cursor, setCursor] = useState<string | null>(null) // the keyboard row (employee id)
  const [shownDate, setShownDate] = useState(date)
  if (shownDate !== date) {
    setShownDate(date)
    setPage(1)
    unselect()
    setCursor(null)
  }
  // employee ids with a write in flight: the ref guards against a double send before React re-renders, the state disables their controls
  const flight = useRef(new Set<string>())
  const [pending, setPending] = useState<ReadonlySet<string>>(new Set())
  const daysId = useId()
  const cohortsId = useId()
  const commandId = useId()
  const commandRef = useRef<HTMLInputElement>(null)
  const box = useRef<HTMLDivElement>(null)
  // newest last; each entry carries its own date, so an undo after a date change still writes the day it changed
  const undos = useRef<(Applied & { date: string })[]>([])
  const [undoCount, setUndoCount] = useState(0)
  // the listener is added once and calls the latest render's handler
  const onKey = useRef<(e: KeyboardEvent) => void>(() => {})
  useEffect(() => {
    const listen = (e: KeyboardEvent) => onKey.current(e)
    document.addEventListener('keydown', listen)
    return () => document.removeEventListener('keydown', listen)
  }, [])
  useEffect(() => {
    for (const el of box.current?.querySelectorAll('[aria-current="true"]') ?? []) el.scrollIntoView?.({ block: 'nearest' })
  }, [cursor])
  // the header's period may still be the previous one while the next loads: write only into the period that holds this date
  const editable = !!period && period.group === group && period.start <= date && date <= period.end && !period.is_locked && can('attendance.edit')
  const leaveSite = (code: string) => sites.find((s) => s.kind === 'leave' && s.leave_code === code && s.active)?.id as string | undefined
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
      if (e instanceof ApiError && e.code === 'concurrency_conflict') {
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
    const free = chosen.filter((r) => !flight.current.has(r.employee.id))
    const { rows: out, skipped } = bulkRows(action, free, leaveSite, office)
    if (out.length > MAX_BATCH) {
      toast.error(t('attendance.sheet.toast.tooMany', { max: MAX_BATCH }))
      return null
    }
    if (skipped.length) toast.warning(t('attendance.sheet.toast.skippedNoSite', { count: skipped.length }))
    else if (!out.length) toast.info(t('attendance.sheet.toast.nothingToChange'))
    if (!out.length) return null
    const before = out.map((b) => free.find((r) => r.employee.id === b.employee_id)!)
    const after = await write(date, out, before, 'attendance.sheet.toast.conflict')
    if (!after) return null
    toast.success(t('attendance.sheet.toast.applied', { count: out.length }))
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
    toast.success(entry.date === date ? t('attendance.sheet.toast.undone', { label: entry.label }) : t('attendance.sheet.toast.undoneOn', { label: entry.label, date: entry.date }))
  }

  onKey.current = () => {} // no key acts on a sheet that is not on screen (the next date loading, an error); set again below
  if (loading) return <Card><TableSkeleton rows={8} /></Card>
  if (!sheet) return <ErrorBanner err={err} shown={[]} />

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
  const batch = query.trim() ? parseBatch(query, rows) : null
  const hits = batch && new Set(batch.matches)
  const shown = rows.filter((r) => (filter === 'all' || bucket(status(r)) === filter) && (cohort === null || cohortOf(r) === cohort) && (!hits || hits.has(r)))
  const pageRows = paginate(shown, page, PER_PAGE)
  // a filter change drops the selection: a bulk action must never write rows the user can no longer see
  const pick = (f: Filter) => { setFilter(f === filter ? 'all' : f); setPage(1); unselect() }
  const pickCohort = (key: string) => { setCohort(key === cohort ? null : key); setPage(1); unselect() }

  const isMissing = (r: SheetRow) => status(r) === 'missing'
  const rest = shown.filter(isMissing)
  const picked = shown.filter((r) => selected.has(r.employee.id))
  const toggle = (ids: string[], on: boolean) => setSelected((cur) => {
    const next = new Set(cur)
    for (const id of ids) next[on ? 'add' : 'delete'](id)
    return next
  })
  const bulk = (action: SheetAction, label: string) => void apply(action, picked, label).then((done) => done && unselect())
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveSite(a.code)
  const noSite = (a: SheetAction, r: SheetRow) => a.kind === 'present' && !office && !r.last_site_id
  // Enter acts on the shown matches only: an ID batch is marked present, a name search only selects (it never writes)
  const command = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter' || e.nativeEvent.isComposing || !batch || !editable || !shown.length) return
    e.preventDefault()
    if (batch.byName) setSelected(new Set(shown.map((r) => r.employee.id)))
    else void apply({ kind: 'present' }, shown, t('attendance.sheet.actionCount', { action: t('attendance.sheet.action.present'), count: shown.length })).then((done) => done && setQuery(''))
  }

  // a key does what its button would: P/A/S skip a row whose button is disabled, and a read-only sheet only moves and opens
  onKey.current = (e) => {
    if (document.querySelector('[role="dialog"],[role="alertdialog"],[role="menu"]')) return
    if (e.repeat && !MOVE.includes(e.code)) return
    // text entry only: a checkbox, radio or button input keeps the shortcuts
    const el = e.target
    const typing = el instanceof HTMLElement && (el.isContentEditable || ['TEXTAREA', 'SELECT'].includes(el.tagName) || (el instanceof HTMLInputElement && !['checkbox', 'radio', 'button'].includes(el.type)))
    if (e.code === 'Escape') {
      unselect()
      setCursor(null)
      if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
      return
    }
    if (typing || e.altKey) return
    if (e.ctrlKey || e.metaKey) {
      if (e.code === 'KeyZ' && !e.shiftKey && editable) {
        e.preventDefault()
        void undo()
      }
      return
    }
    const at = pageRows.findIndex((r) => r.employee.id === cursor)
    const row = at < 0 ? undefined : pageRows[at]
    const move = (by: number) => setCursor(pageRows[at < 0 ? 0 : Math.min(Math.max(at + by, 0), pageRows.length - 1)]?.employee.id ?? null)
    const quick = QUICK[MARK.indexOf(e.code)]
    if (e.code === 'Slash') commandRef.current?.focus()
    else if (e.code === 'KeyJ' || e.code === 'ArrowDown') move(1)
    else if (e.code === 'KeyK' || e.code === 'ArrowUp') move(-1)
    else if (e.code === 'KeyE' && row) onOpenDay(row.employee, date)
    else if (quick && row && editable) {
      const [action, label] = quick
      if (pending.has(row.employee.id) || noLeave(action) || noSite(action, row)) return
      void apply(action, [row], t('attendance.sheet.actionOn', { action: t(label), name: name(row) }))
      move(1)
    } else return
    e.preventDefault()
  }

  return (
    <div ref={box} className="space-y-4">
      {/* a failed background refetch: the last good sheet stays under the error */}
      <ErrorBanner err={err} shown={[]} />
      {period?.is_locked && (
        <p role="status" className="flex items-center gap-2 rounded-lg border border-info-border bg-info-bg px-4 py-3 text-sm text-info">
          <Lock className="size-4 shrink-0" aria-hidden />
          {t('attendance.sheet.locked')}
        </p>
      )}

      <div className={cn('grid grid-cols-2 gap-2.5 sm:grid-cols-3', office ? 'lg:grid-cols-6' : 'lg:grid-cols-5')}>
        <button type="button" aria-pressed={filter === 'all'} onClick={() => { pick('all'); setCohort(null) }} className={cn(FOCUS, 'col-span-2 rounded-lg border bg-card p-3 text-start shadow-1 transition-colors hover:border-primary sm:col-span-1 aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary')}>
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
                  <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 text-success md:h-9')} disabled={done === total}
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
          <div className="space-y-2 border-b px-4 py-3">
            <div className="flex flex-wrap items-end gap-2">
              <div className="flex min-w-0 flex-1 basis-64 flex-col gap-1.5">
                <Label htmlFor={commandId}>{t('attendance.sheet.command')}</Label>
                <div className="relative">
                  <ScanBarcode className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                  <Input ref={commandRef} id={commandId} aria-keyshortcuts="/" value={query} autoComplete="off" enterKeyHint="go" placeholder={t('attendance.sheet.commandHint')} className="h-11 ps-9 md:h-9"
                    onChange={(e) => { setQuery(e.target.value); setPage(1) }} onKeyDown={command} />
                </div>
              </div>
              {editable && (
                <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 md:h-9')} onClick={() => setPasting(true)}>
                  <ClipboardPaste aria-hidden />
                  {t('attendance.sheet.paste.button')}
                </Button>
              )}
              {editable && (
                <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 md:h-9 aria-disabled:cursor-not-allowed aria-disabled:opacity-50')} aria-disabled={!undoCount || pending.size > 0} aria-keyshortcuts="Control+Z Meta+Z" onClick={() => void undo()}>
                  <Undo2 aria-hidden />
                  {t('attendance.sheet.undo', { count: undoCount })}
                </Button>
              )}
            </div>
            <div role="status" aria-label={t('attendance.sheet.command')} className="flex min-h-6 flex-wrap items-center gap-1.5">
              {batch ? <BatchPreview batch={batch} count={shown.length} /> : editable && <span className="text-xs text-muted-foreground">{t('attendance.sheet.commandHelp')}</span>}
            </div>
            <ul aria-label={t('attendance.sheet.keys.label')} className="hidden flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground lg:flex">
              {KEYS.filter(([, , write]) => editable || !write).map(([glyphs, key]) => (
                <li key={key} className="flex items-center gap-1">
                  {glyphs.map((g) => <kbd key={g} dir="ltr" className="rounded border bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">{g}</kbd>)}
                  <span>{t(`attendance.sheet.keys.${key}`)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        {editable && <PasteDialog open={pasting} onOpenChange={setPasting} rows={rows} leaveSite={leaveSite} onApply={async (action, chosen, label) => !!(await apply(action, chosen, label))} />}
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
                {/* a closed native select changes on an arrow key or a letter, so choosing never writes; Apply does */}
                {!office && (
                  <div className="flex items-end gap-2">
                    <div className="w-44">
                      <SelectField name="bulk_site" label={t('attendance.sheet.bulk.setSite')} values={{ bulk_site: siteChoice }} errs={{}} blank={t('attendance.sheet.bulk.pickSite')}
                        options={workSites.map((s) => [s.id, localName(s, lang)])} set={(_, v) => setSiteChoice(v)} />
                    </div>
                    <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 md:h-9')} disabled={!siteChoice}
                      onClick={() => bulk({ kind: 'site', siteId: siteChoice }, t('attendance.sheet.actionOn', { action: t('attendance.sheet.bulk.setSite'), name: siteName(siteChoice) }))}>
                      {t('attendance.sheet.bulk.apply')}
                    </Button>
                  </div>
                )}
                <Button type="button" variant="ghost" size="sm" className={cn(FOCUS, 'h-11 md:h-9')} onClick={unselect}>{t('attendance.sheet.bulk.clear')}</Button>
              </div>
            ) : (
              <div className="flex justify-end border-b px-4 py-3">
                <Button type="button" variant="outline" size="sm" className={cn(FOCUS, 'h-11 text-success md:h-9')} disabled={!rest.length} onClick={() => void apply({ kind: 'present' }, rest, t('attendance.sheet.rest', { count: rest.length }))}>
                  <CheckCheck aria-hidden />
                  {t('attendance.sheet.rest', { count: rest.length })}
                </Button>
              </div>
            ))}
            <SheetRows rows={pageRows} office={office} editable={editable} date={date} sites={sites} selected={selected} pending={pending} cursor={cursor} leaveSite={leaveSite}
              onToggle={toggle} onApply={(action, r, label) => void apply(action, [r], label)} onOpenDay={onOpenDay} />
            <Pagination page={page} pageSize={PER_PAGE} total={shown.length} onPageChange={setPage} />
          </>
        )}
      </Card>
    </div>
  )
}
