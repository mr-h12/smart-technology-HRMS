import { CalendarOff, Check, CircleHelp, Clock, Eye, type LucideIcon, Stethoscope, UserX } from 'lucide-react'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { localName, type Row } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { type SheetAction, type SheetRow, statusOf } from '@/lib/day-sheet'
import { cn } from '@/lib/utils'

export type Status = ReturnType<typeof statusOf>

export const FOCUS = 'outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring'
// "missing" (no record) and "absent" (غ) never share a look: different tokens, icon and border style
export const LOOK: Record<Status, { cls: string; text: string; icon: LucideIcon }> = {
  missing: { cls: 'border-dashed bg-warning-bg border-warning-border', text: 'text-warning', icon: CircleHelp },
  present: { cls: 'bg-success-bg border-success-border', text: 'text-success', icon: Check },
  late: { cls: 'bg-warning-bg border-warning-border', text: 'text-warning', icon: Clock },
  absent: { cls: 'bg-destructive-bg border-destructive-border', text: 'text-destructive', icon: UserX },
  leave: { cls: 'bg-info-bg border-info-border', text: 'text-info', icon: CalendarOff },
  derived: { cls: 'border-dashed bg-muted', text: 'text-muted-foreground', icon: CalendarOff },
}
// the row and bulk-bar quick actions: [action, label key, icon, status tone]
export const QUICK: [SheetAction, string, LucideIcon, string][] = [
  [{ kind: 'present' }, 'attendance.sheet.action.present', Check, 'text-success hover:bg-success-bg hover:border-success-border'],
  [{ kind: 'leave', code: 'absent' }, 'attendance.sheet.action.absent', UserX, 'text-destructive hover:bg-destructive-bg hover:border-destructive-border'],
  [{ kind: 'leave', code: 'sick' }, 'attendance.sheet.action.sick', Stethoscope, 'text-info hover:bg-info-bg hover:border-info-border'],
]
// a selected row or card: full accent, so it still shows in dark mode (accent/60 was too faint there), and kept under the pointer
const SELECTED = 'bg-accent hover:bg-accent'
const TOTALS = [['worked', 'text-success', 'attendance.sheet.kpi.worked'], ['leave', 'text-info', 'attendance.sheet.kpi.leave'], ['absent', 'text-destructive', 'attendance.sheet.kpi.absent'], ['missing', 'text-warning', 'attendance.state.missing']] as const

/**
 * One page of the day sheet: a table from xl up, cards below it (below xl the table is wider than its card, in both groups). Below 2xl
 * the table's actions are icons, and its totals always sit in two columns, so the table fits its card. Writes go back through `onApply` (the sheet's `apply()`).
 * `cursor` is the keyboard row (J/K): it carries `aria-current` and a ring.
 */
export function SheetRows({ rows, office, editable, date, sites, selected, pending, cursor, leaveSite, onToggle, onApply, onOpenDay }: {
  rows: SheetRow[]
  office: boolean
  editable: boolean
  date: string
  sites: Row[]
  selected: ReadonlySet<string>
  pending: ReadonlySet<string>
  cursor: string | null
  leaveSite: (code: string) => string | undefined
  onToggle: (ids: string[], on: boolean) => void
  onApply: (action: SheetAction, row: SheetRow, label: string) => void
  onOpenDay: (employee: Row, date: string) => void
}) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const name = (r: SheetRow) => localName(r.employee, lang)
  const siteName = (id: string | null) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, lang) : '—'
  }
  const status = (r: SheetRow) => statusOf(r, office)
  // the day's leave type (a derived day has no site: its code's leave type); the seeded ones have no name_en, so English falls back to the code's name
  const leaveName = (r: SheetRow, code: string) => {
    const s = sites.find((x) => x.id === r.day.site_id) ?? sites.find((x) => x.kind === 'leave' && x.leave_code === code)
    return s ? localName(s, lang, t(`attendance.leaveName.${code}`, { defaultValue: s.name_ar })) : t(`attendance.leaveName.${code}`, { defaultValue: code })
  }
  const pageIds = rows.map((r) => r.employee.id)
  const pageAll = pageIds.every((id) => selected.has(id))
  const pageSome = pageIds.some((id) => selected.has(id))
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveSite(a.code)
  // a field row is present at its last site; without one there is nowhere to put it (bulk paths skip such rows with a toast)
  const noSite = (a: SheetAction, r: SheetRow) => a.kind === 'present' && !office && !r.last_site_id
  const noSiteId = useId()
  // the select-page box: in the table header from xl, on its own line above the cards below it
  const pageCheck = <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={pageAll} ref={(el) => { if (el) el.indeterminate = pageSome && !pageAll }} onChange={() => onToggle(pageIds, !pageAll)} />
  const check = (r: SheetRow) => (
    <label className="flex size-11 cursor-pointer items-center justify-center">
      <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={selected.has(r.employee.id)} onChange={(e) => onToggle([r.employee.id], e.target.checked)} />
      <span className="sr-only">{t('attendance.sheet.select', { name: name(r) })}</span>
    </label>
  )
  // compact (the table): icon-only below 2xl, named by its tooltip; the aria-label always names the action and the employee
  const quick = (r: SheetRow, h: string, compact = false) => QUICK.map(([action, label, Icon, tone]) => (
    <Button key={label} type="button" variant="outline" size="sm" className={cn(FOCUS, h, 'px-2.5', tone)} disabled={pending.has(r.employee.id) || noLeave(action) || noSite(action, r)}
      {...(compact && { title: t(label) })} {...(noSite(action, r) && { title: t('attendance.sheet.noLastSite'), 'aria-describedby': noSiteId })}
      aria-label={t('attendance.sheet.rowAction', { action: t(label), name: name(r) })} onClick={() => onApply(action, r, t('attendance.sheet.actionOn', { action: t(label), name: name(r) }))}>
      <Icon aria-hidden />
      <span className={cn(compact && 'hidden 2xl:inline')}>{t(label)}</span>
    </Button>
  ))
  const inTime = (r: SheetRow, h: string) => {
    const was = r.day.in_time ? String(r.day.in_time).slice(0, 5) : ''
    const commit = (v: string) => v && v !== was && onApply({ kind: 'time', in_time: v }, r, t('attendance.sheet.actionOn', { action: t('attendance.day.in_time'), name: name(r) }))
    return (
      <input key={`${r.employee.id}/${r.day.version}`} type="time" defaultValue={was} disabled={pending.has(r.employee.id)} aria-label={t('attendance.sheet.inTime', { name: name(r) })}
        className={cn(FOCUS, h, 'rounded-md border bg-card px-2 text-sm tabular disabled:opacity-50')}
        onBlur={(e) => { if (!e.currentTarget.value) e.currentTarget.value = was; commit(e.currentTarget.value) }} onKeyDown={(e) => { if (e.key === 'Escape') e.currentTarget.value = was; else if (e.key === 'Enter') e.currentTarget.blur() }} />
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
      : leave ? leaveName(r, leave) : t('attendance.state.derived')
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
    if (s === 'missing' && r.last_site_id) return <span className="text-xs text-muted-foreground">{t('attendance.sheet.lastSite', { site: siteName(r.last_site_id) })}</span>
    return null
  }
  const details = (r: SheetRow, cls: string, compact = false) => (
    <Button type="button" variant="outline" size="sm" className={cn(FOCUS, cls, compact && 'px-2.5')} {...(compact && { title: t('attendance.sheet.action.details') })}
      aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.details'), name: name(r) })} onClick={() => onOpenDay(r.employee, date)}>
      {compact && <Eye aria-hidden />}
      <span className={cn(compact && 'hidden 2xl:inline')}>{t('attendance.sheet.action.details')}</span>
    </Button>
  )
  // the keyboard cursor: aria-current plus an inset ring (the sheet scrolls it into view)
  const here = (r: SheetRow) => (r.employee.id === cursor ? true : undefined)
  // the table's totals: two content-sized columns of "number label" (fr tracks let the labels overlap); a card spreads four across
  const totals = (r: SheetRow, table = false) => (
    <div className={table ? 'grid w-max grid-cols-[auto_auto] gap-x-4 gap-y-0.5' : 'grid grid-cols-4 gap-3'}>
      {TOTALS.map(([k, tone, label]) => (
        <div key={k} className={table ? 'flex items-baseline gap-1' : 'min-w-14'}>
          <div className={cn('text-sm font-semibold tabular', r.totals[k] ? tone : 'text-muted-foreground')}>{r.totals[k]}</div>
          <div className={cn('text-[11px] text-muted-foreground', !table && 'truncate')}>{t(office && k === 'worked' ? 'attendance.present' : label)}</div>
        </div>
      ))}
    </div>
  )

  return (
    <>
      {editable && !office && <span id={noSiteId} className="sr-only">{t('attendance.sheet.noLastSite')}</span>}
      <div className="hidden xl:block">
        <Table>
          <TableHeader>
            <TableRow>
              {editable && (
                <TableHead className="w-0 px-1 first:ps-2">
                  <label className="flex size-11 cursor-pointer items-center justify-center">
                    {pageCheck}
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
            {rows.map((r) => (
              <TableRow key={r.employee.id} aria-current={here(r)} className={cn(selected.has(r.employee.id) && SELECTED, here(r) && 'ring-2 ring-primary ring-inset')}>
                {editable && <TableCell className="px-1 py-0 first:ps-2">{check(r)}</TableCell>}
                <TableCell className="tabular text-muted-foreground">{r.employee.code}</TableCell>
                <TableCell className="whitespace-normal">
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
                    {details(r, 'h-9', true)}
                  </div>
                </TableCell>
                <TableCell className={cn('border-s', !selected.has(r.employee.id) && 'bg-muted/40')}>{totals(r, true)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {editable && (
        <label className="flex min-h-11 cursor-pointer items-center gap-1.5 border-b ps-1.5 pe-4 text-sm font-medium xl:hidden">
          <span className="flex size-11 items-center justify-center">{pageCheck}</span>
          {t('attendance.sheet.selectPage')}
        </label>
      )}
      <ul aria-label={t('attendance.employee')} className="divide-y xl:hidden">
        {rows.map((r) => (
          <li key={r.employee.id} aria-current={here(r)} className={cn('space-y-3 p-4', selected.has(r.employee.id) && SELECTED, here(r) && 'ring-2 ring-primary ring-inset')}>
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
            {/* not on a row already at a site today: there the disabled Present is no problem to explain */}
            {editable && noSite({ kind: 'present' }, r) && status(r) !== 'present' && <p className="-mt-1.5 text-xs text-muted-foreground">{t('attendance.sheet.noLastSite')}</p>}
            <div className="border-t pt-3">
              <p className="mb-1.5 text-[11px] font-medium text-muted-foreground">{t('attendance.sheet.totals')}</p>
              {totals(r)}
            </div>
          </li>
        ))}
      </ul>
    </>
  )
}
