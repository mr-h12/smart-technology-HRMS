import { CalendarDays, CalendarPlus, ClipboardList, Scale } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useSearchParams } from 'react-router-dom'
import { DataList, type Field } from '@/components/common/DataList'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cairoToday, localName, ltrDates, type Row } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { useList } from '@/lib/query'
import { cn } from '@/lib/utils'
import { DecideDialog, RequestDialog, STATUS } from './leave-dialogs'

// the monthly types: a day laborer has no leave balance (the server refuses it), so it is not offered
const MONTHLY_TYPES = ['office', 'engineer', 'permanent_worker']
const PAGE = 20

/** A row of `GET /leave/requests`; `employee` is null once the employee was deleted. */
export type LeaveRequest = {
  id: string
  employee: { id: string; code: number; name_ar: string; name_en: string | null } | null
  kind: string
  from_date: string
  to_date: string
  days: number
  status: string
  note: string | null
  requested_by: { id: string; name: string } | null
  created_at: string
  version: number
}

/** The columns a leave request row shows wherever it is listed (`GET /leave/requests`); every number is the server's. */
export function useRequestColumns() {
  const { t } = useTranslation()
  const kind = { header: t('leave.requests.kind'), cell: (r: LeaveRequest) => t(`attendance.leaveName.${r.kind}`) }
  const fields: Field<LeaveRequest>[] = [
    { header: t('leave.requests.dates'), cell: (r) => ltrDates(t('leave.requests.range', { from: r.from_date, to: r.to_date })) },
    { header: t('leave.requests.days'), numeric: true, cell: (r) => r.days },
  ]
  const status = { header: t('common.status'), cell: (r: LeaveRequest) => <StatusBadge variant={STATUS[r.status] ?? 'neutral'} label={t(`leave.status.${r.status}`)} /> }
  return { kind, fields, status }
}

/** `/leave`: Requests (the default; `?request=` opens that request and marks its row) and Balances (`?tab=balances`), both over the reader's reach. */
export function LeavePage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const { key } = useLocation()
  const [asking, setAsking] = useState(false)
  // the navigation whose request dialog was closed: every new link to a request, the same one again included, opens it
  const [closed, setClosed] = useState<string>()
  const request = params.get('request')
  const tab = params.get('tab') === 'balances' && !request ? 'balances' : 'requests'
  const thisYear = Number(cairoToday().slice(0, 4))
  const [year, setYear] = useState(String(thisYear))

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('leave.page')}
        description={tab === 'balances' ? t('leave.period', { year }) : t('leave.requests.description')}
        action={can('leave.request') ? { label: t('leave.request.open'), icon: CalendarPlus, onClick: () => setAsking(true) } : undefined}
      />
      <RequestDialog open={asking} onOpenChange={setAsking} />
      {request && <DecideDialog id={request} open={closed !== key} onOpenChange={() => setClosed(key)} />}
      <Tabs value={tab} onValueChange={(v) => setParams(v === 'balances' ? { tab: v } : {}, { replace: true })}>
        <TabsList>
          <TabsTrigger value="requests"><ClipboardList className="size-4" aria-hidden /> {t('leave.tab.requests')}</TabsTrigger>
          <TabsTrigger value="balances"><Scale className="size-4" aria-hidden /> {t('leave.tab.balances')}</TabsTrigger>
        </TabsList>
        <TabsContent value="requests"><Requests thisYear={thisYear} highlight={request} /></TabsContent>
        <TabsContent value="balances"><Balances thisYear={thisYear} year={year} setYear={setYear} /></TabsContent>
      </Tabs>
    </div>
  )
}

/** `GET /leave/requests`: pending and escalated first (the server's order), every year unless one is chosen; a row opens its request. */
function Requests({ thisYear, highlight }: { thisYear: number; highlight: string | null }) {
  const { t, i18n } = useTranslation()
  const [status, setStatus] = useState('')
  const [year, setYear] = useState('')
  const [page, setPage] = useState(1)
  const qs = new URLSearchParams({ page: String(page), per_page: String(PAGE), ...(status && { status }), ...(year && { year }) })
  const { rows, total, err, loading } = useList(`/leave/requests?${qs}`)
  // a link to a request not on this page (the bell) starts the list unfiltered on page 1, where it can be found; a row's own link keeps the filters
  const [linked, setLinked] = useState(highlight)
  if (linked !== highlight) {
    setLinked(highlight)
    if (highlight && !rows.some((r) => r.id === highlight)) {
      setStatus('')
      setYear('')
      setPage(1)
    }
  }
  const { kind, fields, status: statusCol } = useRequestColumns()
  const filter = (set: (v: string) => void) => (v: string) => {
    set(v)
    setPage(1)
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center gap-3 border-b p-4">
        <FilterSelect value={status} onChange={filter(setStatus)} label={t('common.status')} allLabel={t('leave.requests.allStatuses')} options={Object.keys(STATUS).map((s) => [s, t(`leave.status.${s}`)])} />
        <FilterSelect value={year} onChange={filter(setYear)} label={t('leave.year')} allLabel={t('leave.requests.allYears')} options={[thisYear - 1, thisYear, thisYear + 1].map((y) => [String(y), String(y)])} />
      </div>
      <DataList<LeaveRequest>
        label={t('leave.requests.list')}
        rows={rows as LeaveRequest[]}
        rowKey={(r) => r.id}
        to={(r) => `/leave?request=${r.id}`}
        current={(r) => r.id === highlight}
        loading={loading}
        error={err}
        empty={{ icon: ClipboardList, title: t('leave.requests.empty') }}
        page={{ page, pageSize: PAGE, total, onPageChange: setPage }}
        title={{ header: t('employee.employee'), cell: (r) => (r.employee ? localName(r.employee, i18n.language) : '—') }}
        fields={[
          { header: t('employee.code'), tableFrom: 'xl', cell: (r) => <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.employee?.code ?? '—'}</bdi> },
          kind,
          ...fields,
        ]}
        status={statusCol}
      />
    </Card>
  )
}

/** `GET /leave/balances`: one row per monthly employee, every number the server's (docs/calculations.md §7). The year is the page's: the header names it. */
function Balances({ thisYear, year, setYear }: { thisYear: number; year: string; setYear: (y: string) => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const today = cairoToday()
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250)
  const qs = new URLSearchParams({ year, page: String(page), per_page: String(PAGE), ...(type && { type }), ...(search && { q: search }) })
  const { rows, total, err, loading } = useList(`/leave/balances?${qs}`)
  const filter = (set: (v: string) => void) => (v: string) => {
    set(v)
    setPage(1)
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center gap-3 border-b p-4">
        <SearchInput value={q} onChange={filter(setQ)} placeholder={t('leave.search')} className="sm:w-full sm:max-w-md" />
        <FilterSelect value={type} onChange={filter(setType)} label={t('employee.type')} allLabel={t('employee.allTypes')} options={MONTHLY_TYPES.filter((v) => v !== 'office' || can('leave.view.all')).map((v) => [v, t(`enum.type.${v}`)])} />
        <SegmentedControl
          label={t('leave.year')}
          value={year}
          onChange={filter(setYear)}
          className="w-max"
          options={[thisYear - 1, thisYear, thisYear + 1].map(String).map((y) => ({ value: y, label: y, content: <span aria-hidden className="px-1.5">{y}</span> }))}
        />
        {(q || type) && (
          <Button variant="ghost" size="sm" onClick={() => { setQ(''); setType(''); setPage(1) }} className="lg:ms-auto">
            {t('common.clearFilters')}
          </Button>
        )}
      </div>
      <DataList<Row>
        label={t('leave.list')}
        rows={rows}
        rowKey={(r) => r.id}
        to={(r) => `/employees/${r.id}?tab=leave&year=${year}`}
        loading={loading}
        error={err}
        empty={{ icon: CalendarDays, title: t('common.noResults') }}
        page={{ page, pageSize: PAGE, total, onPageChange: setPage }}
        title={{
          header: t('employee.employee'),
          cell: (r) => (
            <>
              {localName(r, i18n.language)}
              {/* the termination day is still worked, so a leaver is one whose date is past */}
              {r.termination_date && <span className="block text-xs font-normal text-muted-foreground">{ltrDates(t(r.termination_date < today ? 'leave.leftOn' : 'employee.leavesOn', { date: r.termination_date }))}</span>}
            </>
          ),
        }}
        fields={[
          { header: t('employee.code'), tableFrom: 'xl', cell: (r) => <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.code ?? '—'}</bdi> },
          { header: t('leave.entitlementShort'), numeric: true, cell: (r) => r.entitlement ?? '—' },
          { header: t('leave.used'), numeric: true, cell: (r) => r.used },
          { header: t('leave.casualUsed'), numeric: true, cell: (r) => (r.casual_cap === null ? r.casual_used : <span className={cn(r.casual_used > r.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: r.casual_used, cap: r.casual_cap })}</span>) },
          { header: t('leave.sickShort'), numeric: true, cell: (r) => r.sick_days },
          { header: t('leave.unpaidShort'), numeric: true, cell: (r) => r.unpaid_days },
        ]}
        status={{ header: t('employee.type'), tableFrom: 'xl', cell: (r) => <Badge variant="info">{t(`enum.type.${r.type}`)}</Badge> }}
        // ltr: in Arabic text a leading minus would otherwise land after the digits
        hero={{ header: t('leave.remaining'), cell: (r) => <bdi dir="ltr" className={cn(r.remaining < 0 && 'text-destructive')}>{r.remaining ?? '—'}</bdi> }}
      />
    </Card>
  )
}
