import { CalendarOff, Check, ChevronDown, CircleHelp, Clock, Ellipsis, Eye, type LucideIcon, MapPin, Stethoscope, UserX } from 'lucide-react'
import { type FocusEvent, type MouseEvent, type ReactNode, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { formatTime, isHhmm, localName, type Row, TimeInput } from '@/components/form'
import { useAuth } from '@/lib/auth'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { hhmm, isOfficeRow, type SheetAction, type SheetRow, statusOf } from '@/lib/day-sheet'
import { cn, FOCUS } from '@/lib/utils'
import { MoveSiteDialog } from './employees'

export type Status = ReturnType<typeof statusOf>
type TimeField = 'in_time' | 'out_time'

// "missing" (no record) and "absent" (غ) never share a look: different tokens, icon and border style
const LOOK: Record<Status, { cls: string; text: string; icon: LucideIcon }> = {
  missing: { cls: 'border-dashed bg-warning-bg border-warning-border', text: 'text-warning', icon: CircleHelp },
  present: { cls: 'bg-success-bg border-success-border', text: 'text-success', icon: Check },
  late: { cls: 'bg-warning-bg border-warning-border', text: 'text-warning', icon: Clock },
  absent: { cls: 'bg-destructive-bg border-destructive-border', text: 'text-destructive', icon: UserX },
  leave: { cls: 'bg-info-bg border-info-border', text: 'text-info', icon: CalendarOff },
  derived: { cls: 'border-dashed bg-muted', text: 'text-muted-foreground', icon: CalendarOff },
}
// the quick actions: [action, label key, icon, status tone]. The bulk bar and the paste dialog show all three; a row shows Present and Absent and puts Sick in its ⋯ menu
export const QUICK: [SheetAction, string, LucideIcon, string][] = [
  [{ kind: 'present' }, 'attendance.sheet.action.present', Check, 'text-success hover:bg-success-bg hover:border-success-border'],
  [{ kind: 'leave', code: 'absent' }, 'attendance.sheet.action.absent', UserX, 'text-destructive hover:bg-destructive-bg hover:border-destructive-border'],
  [{ kind: 'leave', code: 'sick' }, 'attendance.sheet.action.sick', Stethoscope, 'text-info hover:bg-info-bg hover:border-info-border'],
]
// a selected row or card: full accent, so it still shows in dark mode (accent/60 was too faint there), and kept under the pointer
const SELECTED = 'bg-accent hover:bg-accent'
// the line between two employees: --border alone is nearly invisible against the card, in both themes
const LINE = 'border-b border-input/60'
const TOTALS = [['worked', 'text-success', 'attendance.sheet.kpi.worked'], ['leave', 'text-info', 'attendance.sheet.kpi.leave'], ['absent', 'text-destructive', 'attendance.sheet.kpi.absent'], ['missing', 'text-warning', 'attendance.state.missing']] as const

/**
 * One page of the day sheet: a table from xl up, cards below it (below xl the table is wider than its card, in both groups). Below 2xl
 * the table's actions are icons, and its totals always sit in two columns, so the table fits its card. Writes go back through `onApply` (the sheet's `apply()`).
 */
export function SheetRows({ rows, allIds, group, editable, date, sites, selected, pending, leaveTypeId, onToggle, onApply, onOpenDay }: {
  rows: SheetRow[]
  /** Every row the filter shows, on every page: what the select-all box toggles. */
  allIds: string[]
  /** The group shown; a row's own type decides how it reads, the group only the table's columns. */
  group: string
  editable: boolean
  date: string
  sites: Row[]
  selected: ReadonlySet<string>
  pending: ReadonlySet<string>
  leaveTypeId: (code: string) => string | undefined
  onToggle: (ids: string[], on: boolean) => void
  /** Resolves truthy once the row is written. */
  onApply: (action: SheetAction, row: SheetRow, label: string) => Promise<unknown>
  onOpenDay: (employee: Row, date: string) => void
}) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const { can } = useAuth()
  const office = group === 'office'
  const all = group === 'all'
  const name = (r: SheetRow) => localName(r.employee, lang)
  const siteName = (id: string | null) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, lang) : '—'
  }
  const status = (r: SheetRow) => statusOf(r, isOfficeRow(r))
  // a row in a locked period (in All, its own type's) reads as on a read-only sheet
  const writable = (r: SheetRow) => editable && !r.locked
  const workSites = sites.filter((s) => s.kind === 'work' && s.active)
  // the day's leave type (a derived day has no site: its code's leave type); the seeded ones have no name_en, so English falls back to the code's name
  const leaveName = (r: SheetRow, code: string) => {
    const s = sites.find((x) => x.id === r.day.site_id) ?? sites.find((x) => x.kind === 'leave' && x.leave_code === code)
    return s ? localName(s, lang, t(`attendance.leaveName.${code}`, { defaultValue: s.name_ar })) : t(`attendance.leaveName.${code}`, { defaultValue: code })
  }
  const allOn = allIds.every((id) => selected.has(id))
  const someOn = allIds.some((id) => selected.has(id))
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveTypeId(a.code)
  // a field row is present at its last site; without one there is nowhere to put it (bulk paths skip such rows with a toast)
  const noSite = (a: SheetAction, r: SheetRow) => a.kind === 'present' && !isOfficeRow(r) && !r.last_site_id
  const noSiteId = useId()
  // the select-all box (every filtered row, not just this page): in the table header from xl, on its own line above the cards below it
  const allCheck = <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={allOn} ref={(el) => { if (el) el.indeterminate = someOn && !allOn }} onChange={() => onToggle(allIds, !allOn)} />
  const check = (r: SheetRow) => (
    <label className="flex min-h-tap min-w-tap cursor-pointer items-center justify-center">
      <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={selected.has(r.employee.id)} onChange={(e) => onToggle([r.employee.id], e.target.checked)} />
      <span className="sr-only">{t('attendance.sheet.select', { name: name(r) })}</span>
    </label>
  )
  // Present (QUICK[0]) or Absent (QUICK[1]), pressed when the row already is. compact (the table): icon-only below 2xl, named by its
  // tooltip; the aria-label always names the action and the employee
  const quick = (i: 0 | 1, r: SheetRow, compact = false) => {
    const [action, label, Icon, tone] = QUICK[i]
    const s = status(r)
    return (
      <Button type="button" variant="outline" size="sm" className={cn('px-2.5', tone, i ? 'aria-pressed:border-destructive/25 aria-pressed:bg-destructive/10' : 'aria-pressed:border-success/25 aria-pressed:bg-success/10')}
        aria-pressed={i ? s === 'absent' : s === 'present' || s === 'late'}
        disabled={pending.has(r.employee.id) || noSite(action, r) || noLeave(action)} {...(compact && { title: t(label) })} {...(noSite(action, r) && { title: t('attendance.sheet.noLastSite'), 'aria-describedby': noSiteId })}
        aria-label={t('attendance.sheet.rowAction', { action: t(label), name: name(r) })} onClick={() => onApply(action, r, t('attendance.sheet.actionOn', { action: t(label), name: name(r) }))}>
        <Icon aria-hidden />
        <span className={cn(compact && 'hidden 2xl:inline')}>{t(label)}</span>
      </Button>
    )
  }
  // ⋯: Sick and Details. Not modal, so the day editor it opens never inherits a blocked page
  const more = (r: SheetRow) => (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="outline" size="sm" className="px-2.5" title={t('attendance.sheet.action.more')}
          aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.more'), name: name(r) })}>
          <Ellipsis aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {QUICK.slice(2).map(([action, label, Icon]) => (
          <DropdownMenuItem key={label} disabled={pending.has(r.employee.id) || noLeave(action)} onSelect={() => onApply(action, r, t('attendance.sheet.actionOn', { action: t(label), name: name(r) }))}>
            <Icon aria-hidden />
            {t(label)}
          </DropdownMenuItem>
        ))}
        <DropdownMenuItem onSelect={() => onOpenDay(r.employee, date)}>
          <Eye aria-hidden />
          {t('attendance.sheet.action.details')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
  /**
   * A row's times, each placed by `cell`: office rows the in-time, present field rows arrival and departure with the server's late/OT
   * hints; boxes while the day is editable, plain text otherwise. Keyed by the stored times, so a refetch never wipes typing.
   */
  const times = (r: SheetRow, cell: (field: TimeField, node: ReactNode) => ReactNode) => {
    const fields: TimeField[] = isOfficeRow(r) ? ['in_time'] : status(r) === 'present' ? ['in_time', 'out_time'] : []
    if (!fields.length) return null
    const late = r.hints.late_minutes ?? 0
    const over = r.hints.ot_minutes ?? 0
    const hint = (f: TimeField) => (isOfficeRow(r) ? undefined : f === 'in_time' ? (late > 0 ? t('attendance.sheet.lateBy', { minutes: late }) : undefined) : over > 0 ? t('attendance.sheet.overBy', { minutes: over }) : undefined)
    if (!writable(r)) return fields.map((f) => cell(f, <><span className="text-sm">{formatTime(r.day[f], lang)}</span><Hint text={hint(f)} /></>))
    return (
      <TimeCells key={`${r.employee.id}/${r.day.in_time}/${r.day.out_time}`} cell={cell} disabled={pending.has(r.employee.id)}
        cells={fields.map((f) => ({ field: f, was: hhmm(r.day[f]) ?? '', label: t('attendance.sheet.rowAction', { action: t(`attendance.day.${f}`), name: name(r) }), hint: hint(f) }))}
        onCommit={(v) => onApply({ kind: 'time', ...v }, r, t('attendance.sheet.actionOn', { action: t(v.in_time && v.out_time ? 'attendance.sheet.bulk.times' : v.in_time ? 'attendance.day.in_time' : 'attendance.day.out_time'), name: name(r) }))} />
    )
  }
  // min-w-38 holds "12:00" and its AM/PM pair, so the auto-width table never crushes them; the status cell wraps instead
  const tableCell = (f: TimeField, node: ReactNode) => <TableCell key={f} className="px-1.5"><div className="min-w-38">{node}</div></TableCell>
  // a card labels each box above it below sm (the pair stacks in ≈309 px of card at 375) and beside it from sm, where the pair sits side by side
  const cardCell = (f: TimeField, node: ReactNode) => (
    <div key={f} className="sm:flex sm:items-start sm:gap-2">
      <p aria-hidden className="mb-1 text-xs font-medium whitespace-nowrap text-muted-foreground sm:mt-2.5 sm:mb-0">{t(`attendance.day.${f}`)}</p>
      <div className="min-w-0 sm:grow">{node}</div>
    </div>
  )
  const pill = (r: SheetRow) => {
    const s = status(r)
    const { cls, text, icon: Icon } = LOOK[s]
    const leave = r.day.leave_code
    const label = s === 'missing' ? t('attendance.state.missing')
      : s === 'present' ? t('attendance.present')
      : s === 'late' ? t('attendance.sheet.lateBy', { minutes: r.hints.late_minutes })
      : s === 'absent' ? t('attendance.sheet.kpi.absent')
      : leave ? leaveName(r, leave) : t('attendance.state.derived')
    return (
      <Badge variant="outline" className={cn('font-semibold', cls, text)}>
        <Icon className="shrink-0" aria-hidden />
        {label}
      </Badge>
    )
  }
  // a field row's site: the day's site when present, else the last site on a missing row, else «choose a site»; a pick writes that site
  const siteChip = (r: SheetRow) => {
    const s = status(r)
    const at = s === 'present' ? r.day.site_id : s === 'missing' ? r.last_site_id : null
    const text = at ? siteName(at) : t('attendance.sheet.bulk.pickSite')
    const checked = s === 'present' ? (r.day.site_id ?? '') : ''
    return (
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button type="button" variant="outline" size="sm" className="max-w-48 min-w-0 px-2.5" disabled={pending.has(r.employee.id)} aria-label={t('attendance.sheet.siteFor', { site: text, name: name(r) })}>
            <MapPin aria-hidden />
            <span className="truncate">{text}</span>
            <ChevronDown aria-hidden className="text-muted-foreground" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuRadioGroup value={checked}>
            {workSites.map((x) => (
              <DropdownMenuRadioItem key={x.id} value={x.id} onSelect={() => x.id !== checked && onApply({ kind: 'site', siteId: x.id }, r, t('attendance.sheet.actionOn', { action: t('attendance.sheet.bulk.setSite'), name: name(r) }))}>
                {localName(x, lang)}
              </DropdownMenuRadioItem>
            ))}
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  }
  const canSetSite = (r: SheetRow) => r.needs_site && !r.locked && can('employee.assign_site') // a locked day's date is always refused
  const setSite = (r: SheetRow) => canSetSite(r) && <MoveSiteDialog empId={r.employee.id} from={date} ariaLabel={t('attendance.sheet.rowAction', { action: t('site.set'), name: name(r) })} />
  const where = (r: SheetRow) => {
    if (all) return r.site_id ? <span className="rounded-md border bg-card px-2 py-0.5 text-xs">{siteName(r.site_id)}</span> : !canSetSite(r) && <span className="text-xs text-muted-foreground">{t('attendance.noSite')}</span>
    if (isOfficeRow(r)) return null
    if (writable(r)) return siteChip(r)
    const s = status(r)
    if (s === 'present') return <span className="rounded-md border bg-card px-2 py-0.5 text-xs">{siteName(r.day.site_id)}</span>
    if (s === 'missing' && r.last_site_id) return <span className="text-xs text-muted-foreground">{t('attendance.sheet.lastSite', { site: siteName(r.last_site_id) })}</span>
    return null
  }
  const details = (r: SheetRow, compact = false) => (
    <Button type="button" variant="outline" size="sm" className={cn(compact && 'px-2.5')} {...(compact && { title: t('attendance.sheet.action.details') })}
      aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.details'), name: name(r) })} onClick={() => onOpenDay(r.employee, date)}>
      {compact && <Eye aria-hidden />}
      <span className={cn(compact && 'hidden 2xl:inline')}>{t('attendance.sheet.action.details')}</span>
    </Button>
  )
  const totalLabel = (r: SheetRow, k: (typeof TOTALS)[number][0], label: string) => t(isOfficeRow(r) && k === 'worked' ? 'attendance.present' : label)
  const totalTone = (r: SheetRow, k: (typeof TOTALS)[number][0], tone: string) => (r.totals[k] ? tone : 'text-muted-foreground')
  // two content-sized columns of "number label" (fr tracks let the labels overlap)
  const tableTotals = (r: SheetRow) => (
    <div className="grid w-max grid-cols-[auto_auto] gap-x-4">
      {TOTALS.map(([k, tone, label]) => (
        <div key={k} className="flex items-baseline gap-1">
          <div className={cn('text-sm leading-4 font-semibold', totalTone(r, k, tone))}>{r.totals[k]}</div>
          <div className="text-[11px] leading-4 text-muted-foreground">{totalLabel(r, k, label)}</div>
        </div>
      ))}
    </div>
  )
  // one line of "label number" pairs; only a phone drops the visible heading, to keep the pairs on one line
  const cardTotals = (r: SheetRow) => (
    <p className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5 text-xs">
      <span className="sr-only sm:not-sr-only sm:me-1 sm:font-medium sm:text-muted-foreground">{t('attendance.sheet.totals')}</span>
      {TOTALS.map(([k, tone, label], i) => (
        <span key={k} className="flex items-baseline gap-1">
          {i > 0 && <span aria-hidden className="me-0.5 text-muted-foreground">·</span>}
          <span className="text-muted-foreground">{totalLabel(r, k, label)}</span>
          <span className={cn('font-semibold', totalTone(r, k, tone))}>{r.totals[k]}</span>
        </span>
      ))}
    </p>
  )

  const subline = (r: SheetRow, cls = '') => (all || r.employee.department) && (
    <div className={cn('text-xs text-muted-foreground', cls)}>
      {all && <span>{t(`enum.type.${r.employee.type}`)}</span>}
      {all && r.employee.department && ' · '}
      {r.employee.department}
    </div>
  )
  // the arrival and departure columns: an office row in All has no departure
  const fieldTimes = (r: SheetRow) => {
    const cells = times(r, tableCell)
    return !cells ? <><TableCell /><TableCell /></> : isOfficeRow(r) ? <>{cells}<TableCell /></> : cells
  }

  return (
    <>
      {editable && !office && <span id={noSiteId} className="sr-only">{t('attendance.sheet.noLastSite')}</span>}
      <div className="hidden xl:block">
        <Table>
          <TableHeader>
            <TableRow>
              {editable && (
                <TableHead className="w-0 px-1 first:ps-2">
                  <label className="flex min-h-tap min-w-tap cursor-pointer items-center justify-center">
                    {allCheck}
                    <span className="sr-only">{t('attendance.sheet.selectAll', { count: allIds.length })}</span>
                  </label>
                </TableHead>
              )}
              <TableHead>{t('attendance.employee')}</TableHead>
              {office && <TableHead>{t('attendance.day.in_time')}</TableHead>}
              <TableHead>{t('common.status')}</TableHead>
              {!office && <TableHead>{t('attendance.day.in_time')}</TableHead>}
              {!office && <TableHead>{t('attendance.day.out_time')}</TableHead>}
              <TableHead><span className="sr-only">{t('common.actions')}</span></TableHead>
              <TableHead className="border-s">{t('attendance.sheet.totals')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.employee.id} className={cn(LINE, '[&>td]:py-1.5', selected.has(r.employee.id) && SELECTED)}>
                {editable && <TableCell className="px-1 first:ps-2">{writable(r) && check(r)}</TableCell>}
                {/* the code shares the employee cell (no column of its own): in English the "Employee ID" header alone pushed the table past its card at 1280 */}
                <TableCell className="whitespace-normal">
                  <span className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-muted-foreground">{r.employee.code}</span>
                    <bdi className="font-medium">{name(r)}</bdi>
                  </span>
                  {subline(r)}
                </TableCell>
                {office && times(r, tableCell)}
                <TableCell>
                  <div className="flex flex-wrap items-center gap-1.5">
                    {pill(r)}
                    {where(r)}
                    {setSite(r)}
                  </div>
                </TableCell>
                {!office && fieldTimes(r)}
                <TableCell>
                  <div className="flex items-center justify-end gap-1.5">
                    {writable(r) ? <>{quick(0, r, true)}{quick(1, r, true)}{more(r)}</> : details(r, true)}
                  </div>
                </TableCell>
                <TableCell className={cn('border-s', !selected.has(r.employee.id) && 'bg-muted/40')}>{tableTotals(r)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {editable && (
        <label className={cn(LINE, 'flex min-h-tap cursor-pointer items-center gap-1.5 ps-1.5 pe-4 text-sm font-medium xl:hidden')}>
          <span className="flex min-h-tap min-w-tap items-center justify-center">{allCheck}</span>
          {t('attendance.sheet.selectAll', { count: allIds.length })}
        </label>
      )}
      <ul aria-label={t('attendance.employee')} className="xl:hidden">
        {rows.map((r) => (
          <li key={r.employee.id} className={cn(LINE, 'space-y-1.5 px-4 py-2.5 last:border-0', selected.has(r.employee.id) && SELECTED)}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
              <div className="flex min-w-0 grow basis-40 items-center gap-1.5">
                {writable(r) && <div className="-my-2.5 -ms-2.5">{check(r)}</div>}
                <div className="min-w-0">
                  <span className="flex items-center gap-2">
                    <span className="text-muted-foreground">{r.employee.code}</span>
                    <bdi className="truncate font-medium">{name(r)}</bdi>
                  </span>
                  {subline(r, 'truncate')}
                </div>
              </div>
              {pill(r)}
              <div className="flex min-w-0 grow items-center gap-2 sm:grow-0">
                <div className="min-w-0">{where(r)}</div>
                {setSite(r)}
                <div className="ms-auto flex gap-2">{writable(r) ? <>{quick(0, r)}{quick(1, r)}{more(r)}</> : details(r)}</div>
              </div>
            </div>
            {(isOfficeRow(r) || status(r) === 'present') && <div className="grid gap-x-3 gap-y-1.5 sm:grid-cols-2">{times(r, cardCell)}</div>}
            {cardTotals(r)}
          </li>
        ))}
      </ul>
    </>
  )
}

/** Spread on a time box: focus selects its text, so typing replaces it (mouseup would drop the selection a click's focus just made). */
export const selectOnFocus = {
  onFocus: (e: FocusEvent<HTMLInputElement>) => e.currentTarget.setSelectionRange(0, e.currentTarget.value.length),
  onMouseUp: (e: MouseEvent) => e.preventDefault(),
}

/** A server late/OT hint under a time, in the warning tone. */
function Hint({ text }: { text?: string }) {
  return text ? <p className="mt-1 flex items-center gap-1 text-xs text-warning"><Clock className="size-3.5 shrink-0" aria-hidden />{text}</p> : null
}

/**
 * A row's time boxes, one unit: nothing is written while focus moves between them; when it leaves them, the changed times go out in one
 * write (Enter in a box leaves it; an AM/PM click keeps the cursor in its box, it never writes). Escape, a blank, an unreadable time or
 * one still waiting for AM/PM (a "10:30" with neither chosen is never read as morning) puts the stored time back. Focus selects the
 * time, so typing replaces it. `cell` places each box (a table cell, a card slot).
 */
function TimeCells({ cells, disabled, onCommit, cell }: {
  cells: { field: TimeField; was: string; label: string; hint?: string }[]
  disabled: boolean
  /** Resolves truthy once written; a refused write puts the stored times back, so a later blur does not send them again. */
  onCommit: (times: Partial<Record<TimeField, string>>) => Promise<unknown>
  cell: (field: TimeField, node: ReactNode) => ReactNode
}) {
  const { t } = useTranslation()
  const stored = () => Object.fromEntries(cells.map((c) => [c.field, { v: c.was, needs: false }]))
  const [draft, setDraft] = useState<Record<string, { v: string; needs: boolean }>>(stored)
  const boxes = useRef<(HTMLDivElement | null)[]>([])
  const done = () => {
    const out: Partial<Record<TimeField, string>> = {}
    const next = { ...draft }
    for (const c of cells) {
      const { v, needs } = draft[c.field]
      const ok = !needs && isHhmm(v) ? v : c.was
      // say why a typed time is not written, then put the stored one back
      if (v && ok !== v) toast.error(t(needs ? 'time.choosePeriod' : 'time.invalid'))
      next[c.field] = { v: ok, needs: false }
      if (ok !== c.was) out[c.field] = ok
    }
    setDraft(next)
    if (Object.keys(out).length) void onCommit(out).then((written) => { if (!written) setDraft(stored()) })
  }
  return cells.map((c, i) => cell(c.field, (
    <div ref={(el) => { boxes.current[i] = el }} onBlur={(e) => { if (!boxes.current.some((b) => b?.contains(e.relatedTarget))) done() }}
      onKeyDown={(e) => { if (e.key === 'Escape') setDraft(stored()); else if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.target.blur() }}>
      <TimeInput value={draft[c.field].v} label={c.label} aria-label={c.label} quietHint disabled={disabled} {...selectOnFocus}
        onChange={(v, needs) => setDraft((d) => ({ ...d, [c.field]: { v, needs } }))} />
      <Hint text={c.hint} />
    </div>
  )))
}
