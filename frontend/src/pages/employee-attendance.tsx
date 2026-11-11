import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cairoToday, ErrorBanner, intlLocale, localName, ltrDates, type Row, weekdayOf } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { SITES, useApi } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'
import { type Day, STATE_KEY } from './attendance'
import { DayEditor } from './attendance-day'

/** `GET /attendance/employees/{id}/period`: the employee's own payroll period; no wage is sent. */
type EmployeePeriod = {
  period: { group: string; start: string; end: string; prev_date: string | null; next_date: string | null; is_locked: boolean }
  days: Day[]
}

/** The calendar's week, Saturday first (owner, 2026-11-11). */
const WEEK = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']
/** Weekly off and official holidays: no colour, as the server's `off` count (AttendanceGrid::employeePeriod). */
const OFF_CODES = ['friday', 'saturday', 'official_holiday', 'rest']
/**
 * What a day means on the calendar; the one source of its colour, its legend entry, the legend's count and whether it opens.
 * A derived day after today (a coming Friday or holiday) is future: nothing recorded it yet. A stored one keeps its kind.
 */
type Kind = 'present' | 'absent' | 'sick' | 'leave' | 'missing' | 'off' | 'future' | 'not_employed'
const kind = (d: Day, today: string): Kind =>
  d.state === 'missing' || d.state === 'future' || d.state === 'not_employed' ? d.state
  : d.state === 'derived' && d.date > today ? 'future'
  : !d.leave_code ? 'present' // a stored day with no leave code; a derived day always has one
  : d.leave_code === 'absent' || d.leave_code === 'sick' ? d.leave_code
  : OFF_CODES.includes(d.leave_code) ? 'off'
  : 'leave' // annual, casual, unpaid, other
/** A 20% fill is the strongest tint that keeps the number at AA contrast on all five, in both themes. */
const LOOK: Record<Kind, string> = {
  present: 'bg-success/20 text-success ring-1 ring-success/40',
  absent: 'bg-destructive/20 text-destructive ring-1 ring-destructive/40',
  sick: 'bg-info/20 text-info ring-1 ring-info/40',
  leave: 'bg-warning/20 text-warning ring-1 ring-warning/40',
  missing: 'bg-muted-foreground/20 text-muted-foreground ring-1 ring-muted-foreground/40',
  off: 'text-muted-foreground',
  future: 'text-muted-foreground opacity-50',
  not_employed: 'text-muted-foreground opacity-30',
}
const LEGEND = ['present', 'absent', 'sick', 'leave', 'missing'] as const

/**
 * The employee's days in their own payroll period as a month calendar (owner, 2026-11-11, after the iPhone Calendar): Saturday first,
 * one section per month so a 26th→25th period splits its week where the month changes. The arrows step to the server's neighbouring
 * dates. A day opens the day editor; its save marks every attendance query stale (useSave), so the calendar refetches.
 */
export function EmployeeAttendance({ emp }: { emp: Row }) {
  const { t, i18n } = useTranslation()
  const [date, setDate] = useState<string>() // none: the period holding today
  const [open, setOpen] = useState<string>()
  const { data, err } = useApi<EmployeePeriod>(`/attendance/employees/${emp.id}/period${date ? `?date=${date}` : ''}`, { keep: true })
  const { data: sites, err: sitesErr } = useApi<Row[]>(SITES) // shared with the grid and the day editor
  const today = cairoToday()
  const p = data?.period
  const label = (d: Day) => {
    const state = STATE_KEY[d.state]
    if (state) return t(state)
    if (d.leave_code) return t(`attendance.leaveName.${d.leave_code}`, { defaultValue: d.leave_code })
    const site = d.site_id && sites?.find((s) => s.id === d.site_id)
    return site ? localName(site, i18n.language) : d.site_id ? '—' : t('attendance.present')
  }
  const monthName = new Intl.DateTimeFormat(intlLocale(i18n.language), { month: 'long', year: 'numeric', timeZone: 'UTC' })
  const months = data ? [...new Set(data.days.map((d) => d.date.slice(0, 7)))].map((m) => data.days.filter((d) => d.date.startsWith(m))) : []

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
          <div className="mx-auto max-w-xl space-y-4 p-4">
            <ul aria-label={t('attendance.employeeTab.legend')} className="flex flex-wrap gap-x-4 gap-y-2 text-sm">
              {LEGEND.map((k) => (
                <li key={k} className="flex items-center gap-1.5">
                  <span aria-hidden className={cn('size-3 rounded-full', LOOK[k])} />
                  {t(`attendance.employeeTab.${k}`)} <span className="font-semibold">{data.days.filter((d) => kind(d, today) === k).length}</span>
                </li>
              ))}
            </ul>
            {months.map((days, i) => (
              <div key={days[0].date}>
                <h3 className={i ? 'mt-2 mb-1 text-sm font-semibold' : 'mb-2 text-section-title'}>{monthName.format(new Date(`${days[0].date}T00:00:00Z`))}</h3>
                {!i && (
                  // the buttons' names carry the full weekday, so screen readers skip this row
                  <div aria-hidden className="grid grid-cols-7 pb-1 text-center text-xs text-muted-foreground">
                    {WEEK.map((w) => <span key={w} className="truncate">{t(`weekdayShort.${w}`)}</span>)}
                  </div>
                )}
                {/* the month's first day starts in its weekday column; the line above each day draws the weeks, split where a month starts */}
                <ol className="grid grid-cols-7">
                  {days.map((d, j) => {
                    const text = label(d)
                    const isToday = d.date === today
                    const k = kind(d, today)
                    return (
                      <li key={d.date} className="border-t py-1.5" style={j ? undefined : { gridColumnStart: WEEK.indexOf(weekdayOf(d.date)) + 1 }}>
                        <button type="button" disabled={k === 'future' || k === 'not_employed'} onClick={() => setOpen(d.date)}
                          aria-label={`${t(`weekday.${weekdayOf(d.date)}`)} ${d.date}: ${text}`} title={text} aria-current={isToday ? 'date' : undefined}
                          className={cn('flex min-h-tap w-full items-center justify-center rounded-md enabled:hover:bg-muted disabled:cursor-not-allowed', FOCUS)}>
                          <span className={cn('flex size-9 items-center justify-center rounded-full text-base font-semibold sm:size-10 sm:text-lg', LOOK[k], isToday && 'ring-2 ring-primary ring-offset-2 ring-offset-card')}>
                            {Number(d.date.slice(8))}
                          </span>
                        </button>
                      </li>
                    )
                  })}
                </ol>
              </div>
            ))}
          </div>
        )}
      </Card>
      {open && <DayEditor employee={emp} date={open} onOpenChange={(o) => !o && setOpen(undefined)} />}
    </>
  )
}
