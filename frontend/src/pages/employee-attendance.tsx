import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { StatRow } from '@/components/common/StatRow'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cairoToday, ErrorBanner, formatTime, localName, ltrDates, type Row, useMoney, weekdayOf } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { DayValue } from '@/lib/day-sheet'
import { SITES, useApi } from '@/lib/query'
import { cn, OFF } from '@/lib/utils'
import { type Day as GridDay, LOOK, STATE_KEY } from './attendance'
import { DayEditor } from './attendance-day'

/** `GET /attendance/employees/{id}/period`: the employee's own payroll period; every count is the server's, and no wage is sent. */
type Day = GridDay & { version: number | null; hints: Record<string, number> } & Partial<Record<DayValue, string | number | null>>
type EmployeePeriod = {
  period: { group: string; start: string; end: string; prev_date: string | null; next_date: string | null; is_locked: boolean }
  fields: string[]
  days: Day[]
  summary: Record<'worked' | 'absent' | 'leave' | 'off' | 'missing', number>
}

const TIMES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out']
const MINUTES = ['late_minutes', 'ot_minutes', 'outside_minutes']
const AMOUNTS = ['outside_amount', 'advance_amount']
const COUNTS = ['absent', 'leave', 'off', 'missing'] as const

/**
 * The employee's days in their own payroll period (owner, 2026-11-10): one row per day, the arrows step to the server's neighbouring dates,
 * the summary is day counts only. A day opens the day editor; its save marks every attendance query stale (useSave), so this table refetches.
 * Like the grid, the table scrolls sideways on a phone with the day column pinned.
 */
export function EmployeeAttendance({ emp }: { emp: Row }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const [date, setDate] = useState<string>() // none: the period holding today
  const [open, setOpen] = useState<string>()
  const { data, err } = useApi<EmployeePeriod>(`/attendance/employees/${emp.id}/period${date ? `?date=${date}` : ''}`, { keep: true })
  const { data: sites, err: sitesErr } = useApi<Row[]>(SITES) // shared with the grid and the day editor
  const today = cairoToday()
  const p = data?.period
  // office lateness is computed from the in time, so it shows as a hint column after the times
  const cols = !data ? [] : p?.group === 'office' ? [...data.fields, 'late_minutes'] : data.fields
  const numeric = (f: string) => MINUTES.includes(f) || AMOUNTS.includes(f)
  const label = (d: Day) => {
    const state = STATE_KEY[d.state]
    if (state) return t(state)
    if (d.leave_code) return t(`attendance.leaveName.${d.leave_code}`, { defaultValue: d.leave_code })
    const site = d.site_id && sites?.find((s) => s.id === d.site_id)
    return site ? localName(site, i18n.language) : d.site_id ? '—' : t('attendance.present')
  }
  // a minutes cell shows the stored value, else the computed hint, quieter; amounts with 2 decimals (never through a float)
  const value = (d: Day, f: string) => {
    const v = d[f as DayValue] ?? null
    if (TIMES.includes(f)) return formatTime(v, i18n.language)
    if (MINUTES.includes(f)) return v ?? (d.hints[f] != null ? <span className="text-muted-foreground">{d.hints[f]}</span> : '—')
    if (AMOUNTS.includes(f)) return money(v as string | null)
    if (f === 'transport') return v ? t(`attendance.transport.${v}`) : '—'
    return v == null ? '—' : <span title={String(v)} className="block max-w-48 truncate">{v}</span>
  }

  return (
    <>
      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-1 border-b p-4">
          <Button variant="outline" size="icon" disabled={!p?.prev_date} onClick={() => p?.prev_date && setDate(p.prev_date)} aria-label={t('attendance.prevPeriod')}>
            <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
          </Button>
          <span className="px-1 text-sm font-medium whitespace-nowrap">{p ? ltrDates(t('attendance.periodRange', { start: p.start, end: p.end })) : '—'}</span>
          <Button variant="outline" size="icon" disabled={!p?.next_date} onClick={() => p?.next_date && setDate(p.next_date)} aria-label={t('attendance.nextPeriod')}>
            <ChevronRight className="rtl:-scale-x-100" aria-hidden />
          </Button>
          {p?.is_locked && <StatusBadge variant="neutral" label={t('attendance.locked')} />}
        </div>
        {!!(err ?? sitesErr) && (
          <div className="border-b p-4">
            <ErrorBanner err={err ?? sitesErr} shown={[]} />
          </div>
        )}
        {!(data && sites) ? (
          !(err ?? sitesErr) && <TableSkeleton rows={8} />
        ) : (
          <>
            <div className="border-b p-4">
              <StatRow
                layout="strip"
                label={t('attendance.employeeTab.summary')}
                hero={{ label: t('attendance.employeeTab.worked'), value: data.summary.worked }}
                stats={COUNTS.map((k) => ({ label: t(`attendance.employeeTab.${k}`), value: data.summary[k], tone: k === 'missing' && data.summary.missing > 0 ? 'warning' : undefined }))}
              />
            </div>
            {/* each cell owns its row line, as in the grid: a sticky cell with collapsed borders leaves a band unpainted */}
            <Table className="border-separate border-spacing-0">
              <TableHeader>
                <TableRow>
                  <TableHead className="sticky start-0 z-10 border-b bg-muted">{t('attendance.employeeTab.day')}</TableHead>
                  <TableHead className="border-b">{t('attendance.day.siteOrType')}</TableHead>
                  {cols.map((f) => (
                    <TableHead key={f} className={cn('border-b', numeric(f) && 'text-end')}>{t(`attendance.day.${f}`)}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody className="[&_tr:last-child>td]:border-b-0">
                {data.days.map((d) => {
                  const text = label(d)
                  const closed = d.state === 'future' || d.state === 'not_employed'
                  const isToday = d.date === today
                  return (
                    // the row opens the day; its first cell's button is the keyboard and screen-reader control, and its click bubbles here
                    <TableRow key={d.date} aria-current={isToday ? 'date' : undefined} onClick={() => !closed && setOpen(d.date)}
                      className={cn(!closed && 'cursor-pointer', (d.state === 'derived' || d.source === 'derived') && OFF, isToday && 'bg-accent hover:bg-accent')}>
                      <TableCell className={cn('sticky start-0 z-10 border-b py-2', isToday ? 'bg-accent' : 'bg-card')}>
                        <button type="button" disabled={closed} aria-label={`${t(`weekday.${weekdayOf(d.date)}`)} ${d.date}: ${text}`}
                          className="rounded-md text-start outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed">
                          <span className="block text-xs text-muted-foreground">{t(`weekdayShort.${weekdayOf(d.date)}`)}</span>
                          <bdi dir="ltr" className="whitespace-nowrap">{d.date}</bdi>
                        </button>
                      </TableCell>
                      <TableCell className="border-b py-2">
                        <span className={cn('inline-block rounded-md px-1.5 py-0.5 text-xs', LOOK[d.state])}>{text}</span>
                      </TableCell>
                      {cols.map((f) => (
                        <TableCell key={f} className={cn('border-b py-2', numeric(f) && 'text-end')}>{value(d, f)}</TableCell>
                      ))}
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </>
        )}
      </Card>
      {open && <DayEditor employee={emp} date={open} onOpenChange={(o) => !o && setOpen(undefined)} />}
    </>
  )
}
