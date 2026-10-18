import { CalendarCheck, CalendarX, ChevronLeft, ChevronRight, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { MiniStat } from '@/components/cards/StatCard'
import { EmptyState } from '@/components/common/EmptyState'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { ErrorBanner, intlLocale, type Row, SelectField } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn } from '@/lib/utils'
import { SITES, useApi, useList } from '@/lib/query'
import { DayEditor } from './attendance-day'

const GROUPS = ['office', 'engineer', 'permanent_worker', 'day_laborer']
const PER_PAGE = 50
const TABS = ['grid'] // the import tab joins with the template import

type Period = { group: string; start: string; end: string; prev_start: string; next_start: string | null; is_locked: boolean; missing: number }
type Day = { date: string; state: 'not_employed' | 'stored' | 'derived' | 'future' | 'missing'; site_id: string | null; leave_code: string | null }
type GridRow = { employee: Row; days: Day[] }

const STATE_KEY: Partial<Record<Day['state'], string>> = { missing: 'attendance.state.missing', future: 'attendance.state.future', not_employed: 'attendance.state.notEmployed' }
const LOOK: Record<Day['state'], string> = {
  stored: 'hover:bg-muted',
  derived: 'border border-dashed text-muted-foreground hover:bg-muted',
  missing: 'bg-warning/15 text-warning ring-1 ring-warning/40 hover:bg-warning/25',
  future: 'text-muted-foreground opacity-50',
  not_employed: 'text-muted-foreground opacity-50',
}
const CELL = 'block min-h-11 w-full min-w-11 max-w-28 truncate rounded-md px-1.5 text-center text-xs transition-colors outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed'

/** HR's attendance page, URL state `?group=&period=&tab=`; the group defaults to engineers, the period to the current one. */
export function AttendancePage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const group = GROUPS.find((g) => g === params.get('group')) ?? 'engineer'
  const chosen = params.get('period')
  const set = (patch: Record<string, string | null>) =>
    setParams((p) => {
      for (const [k, v] of Object.entries(patch)) {
        if (v === null) p.delete(k)
        else p.set(k, v)
      }
      return p
    }, { replace: true })
  const { data: period, err } = useApi<Period>(`/attendance/period?${new URLSearchParams({ group, ...(chosen && { period_start: chosen }) })}`)
  const start = chosen ?? period?.start // the grid follows the URL as soon as an arrow moves it
  const tab = TABS.find((x) => x === params.get('tab')) ?? TABS[0]
  const [day, setDay] = useState<{ employee: { id: string; name_ar: string }; date: string }>()

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('nav.attendance')}
        description={t('attendance.subtitle')}
        actions={<MiniStat label={t('attendance.missingCount')} value={period?.missing ?? '—'} icon={CalendarX} tone="amber" />}
      />
      <Tabs value={tab} onValueChange={(v) => set({ tab: v })}>
        <TabsList>
          <TabsTrigger value="grid">
            <CalendarCheck className="size-4" aria-hidden /> {t('attendance.tab.grid')}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="grid">
          <Card className="overflow-hidden">
            <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-end">
              <div className="sm:w-56">
                <SelectField name="group" label={t('attendance.groupLabel')} values={{ group }} set={(_, v) => set({ group: v, period: null })} errs={{}} options={GROUPS.map((g) => [g, t(`attendance.group.${g}`)])} />
              </div>
              <div className="flex items-center gap-2">
                <Button variant="outline" size="icon" className="size-11" disabled={!period} onClick={() => period && set({ period: period.prev_start })} aria-label={t('attendance.prevPeriod')}>
                  <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
                </Button>
                <span className="px-1 text-sm font-medium tabular">
                  {period ? t('attendance.periodRange', { start: period.start, end: period.end }) : '—'}
                </span>
                <Button variant="outline" size="icon" className="size-11" disabled={!period?.next_start} onClick={() => period?.next_start && set({ period: period.next_start })} aria-label={t('attendance.nextPeriod')}>
                  <ChevronRight className="rtl:-scale-x-100" aria-hidden />
                </Button>
                {period?.is_locked && <StatusBadge variant="neutral" label={t('attendance.locked')} />}
              </div>
            </div>
            {!!err && (
              <div className="border-b p-4">
                <ErrorBanner err={err} shown={[]} />
              </div>
            )}
            {start ? <Grid key={`${group}/${start}`} group={group} start={start} onOpenDay={(e, date) => setDay({ employee: { id: e.id, name_ar: e.name_ar }, date })} /> : !err && <TableSkeleton rows={8} />}
          </Card>
        </TabsContent>
      </Tabs>
      {day && <DayEditor employee={day.employee} date={day.date} onOpenChange={(o) => !o && setDay(undefined)} />}
    </div>
  )
}

/** One page of the period grid: a sticky employee column, then one button per day. */
function Grid({ group, start, onOpenDay }: { group: string; start: string; onOpenDay: (employee: Row, date: string) => void }) {
  const { t, i18n } = useTranslation()
  const [page, setPage] = useState(1)
  const { rows, total, err, loading } = useList(`/attendance/grid?${new URLSearchParams({ group, period_start: start, page: String(page), per_page: String(PER_PAGE) })}`)
  const { data: sites = [] } = useApi<Row[]>(SITES)
  const grid = rows as GridRow[]
  const weekday = new Intl.DateTimeFormat(intlLocale(i18n.language), { weekday: 'narrow', timeZone: 'UTC' })
  const siteName = (id: string) => sites.find((s) => s.id === id)?.name_ar ?? '—'
  const label = (d: Day) => {
    const state = STATE_KEY[d.state]
    if (state) return t(state)
    if (d.leave_code) return t(`attendance.short.${d.leave_code}`, { defaultValue: d.leave_code })
    return d.site_id ? siteName(d.site_id) : t('attendance.present')
  }

  if (loading) return <TableSkeleton rows={8} />
  if (err) return <div className="p-4"><ErrorBanner err={err} shown={[]} /></div>
  if (!grid.length) return <EmptyState icon={Users} title={t('attendance.empty')} />
  return (
    <>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="sticky start-0 z-10 bg-muted">{t('attendance.employee')}</TableHead>
            {grid[0].days.map(({ date }) => (
              <TableHead key={date} className="px-1 text-center first:ps-1 last:pe-1">
                <div className="tabular text-foreground">{Number(date.slice(8))}</div>
                <div className="normal-case">{weekday.format(new Date(`${date}T00:00:00Z`))}</div>
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {grid.map(({ employee, days }) => (
            <TableRow key={employee.id}>
              <TableCell className="sticky start-0 z-10 bg-card">
                <span className="me-2 tabular text-muted-foreground">{employee.code}</span>
                {employee.name_ar}
              </TableCell>
              {days.map((d) => {
                const text = label(d)
                const off = d.state === 'future' || d.state === 'not_employed'
                return (
                  <TableCell key={d.date} className="px-0.5 py-1 first:ps-0.5 last:pe-0.5">
                    <button type="button" disabled={off} aria-label={`${employee.name_ar} ${d.date}: ${text}`} className={cn(CELL, LOOK[d.state])} onClick={() => onOpenDay(employee, d.date)}>
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
