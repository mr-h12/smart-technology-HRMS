import { useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, ClipboardList } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { StatRow } from '@/components/common/StatRow'
import { cairoToday, type Errs, ErrorBanner, Field, FormDialog, type Row, TextField } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/misc'
import { useAuth } from '@/lib/auth'
import { queryKey, useApi, useList, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'
import { type LeaveRequest, useRequestColumns } from './leave'
import { RequestDialog } from './leave-dialogs'

/** `GET /employees/{id}/leave`: every number is the server's (docs/calculations.md §7); `balance` is null for a day laborer. */
type Balance = { entitlement: number | null; casual_cap: number | null; used: number; casual_used: number; remaining: number | null; sick_days: number; unpaid_days: number }
type Adjustment = { entitlement: number | null; opening_used: number; opening_casual_used: number; reason: string | null; version: number }
type EmployeeLeave = { balance: Balance | null; adjustment: Adjustment | null }

/**
 * The year's leave balance and requests; the year selector offers the current Cairo year and one either side, and starts on `initialYear`
 * when it is one of them. `readOnly` (archived): no request and no adjustment.
 */
export function Leave({ emp, initialYear, readOnly = false }: { emp: Row; initialYear?: string | null; readOnly?: boolean }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const thisYear = Number(cairoToday().slice(0, 4))
  const years = [thisYear - 1, thisYear, thisYear + 1].map(String)
  const [year, setYear] = useState(Number(years.find((y) => y === initialYear) ?? thisYear))
  const path = `/employees/${emp.id}/leave?year=${year}`
  // fresh + ready: attendance edits change the balance but refresh only attendance queries, so a cached copy may be stale
  const { data, err, ready } = useApi<EmployeeLeave>(path, { fresh: true })
  const b = data?.balance

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex-wrap items-center">
          <CardTitle>{t('leave.balance')}</CardTitle>
          <div className="flex flex-wrap items-center gap-2">
            <SegmentedControl
              label={t('leave.year')}
              value={String(year)}
              onChange={(y) => setYear(Number(y))}
              options={years.map((y) => ({ value: y, label: y, content: <span aria-hidden className="px-1.5">{y}</span> }))}
            />
            {can('leave.adjust') && !readOnly && ready && b && <AdjustDialog empId={emp.id} year={year} path={path} adjustment={data.adjustment} />}
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <ErrorBanner err={err} shown={[]} />
          {!(ready && data) ? (
            !err && <Skeleton className="h-48" />
          ) : (
            b && (
              <>
                {b.entitlement === null && <Alert tone="warning">{t('leave.noHireDate')}</Alert>}
                <StatRow
                  layout="strip"
                  label={t('leave.balance')}
                  hero={{
                    label: t('leave.remaining'),
                    // ltr: in Arabic text a leading minus would otherwise land after the digits
                    value: b.remaining === null ? '—' : <bdi dir="ltr" className={cn(b.remaining < 0 && 'text-destructive')}>{b.remaining}</bdi>,
                    detail: data.adjustment?.reason && t('leave.adjusted', { reason: data.adjustment.reason }),
                  }}
                  stats={[
                    { label: t('leave.entitlement'), value: b.entitlement ?? '—' },
                    { label: t('leave.used'), value: b.used },
                    { label: t('leave.casualUsed'), value: b.casual_cap === null ? b.casual_used : <span className={cn(b.casual_used > b.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: b.casual_used, cap: b.casual_cap })}</span> },
                    { label: t('leave.sickDays'), value: b.sick_days },
                    { label: t('leave.unpaidDays'), value: b.unpaid_days },
                  ]}
                />
              </>
            )
          )}
        </CardContent>
      </Card>
      <Requests emp={emp} year={year} readOnly={readOnly} />
    </div>
  )
}

/** `GET /leave/requests?employee_id=&year=`: one employee's requests of the year, and «طلب إجازة» for this employee. */
function Requests({ emp, year, readOnly }: { emp: Row; year: number; readOnly: boolean }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [asking, setAsking] = useState(false)
  // ponytail: one page of 100; a year holds far fewer requests for one employee
  const { rows, err, loading } = useList(`/leave/requests?${new URLSearchParams({ employee_id: emp.id, year: String(year), per_page: '100' })}`)
  const { kind, fields, status } = useRequestColumns()
  return (
    <Card className="overflow-hidden">
      <CardHeader className="flex-wrap items-center">
        <CardTitle>{t('leave.requests.list')}</CardTitle>
        {can('leave.request') && !readOnly && (
          <Button type="button" variant="outline" onClick={() => setAsking(true)}>
            <CalendarPlus aria-hidden /> {t('leave.request.open')}
          </Button>
        )}
      </CardHeader>
      <RequestDialog open={asking} onOpenChange={setAsking} employee={emp} />
      <DataList<LeaveRequest> label={t('leave.requests.list')} rows={rows as LeaveRequest[]} rowKey={(r) => r.id} to={(r) => `/leave?request=${r.id}`} loading={loading} error={err} empty={{ icon: ClipboardList, title: t('leave.requests.empty') }} title={kind} fields={fields} status={status} />
    </Card>
  )
}

const FIELDS = ['entitlement', 'opening_used', 'opening_casual_used', 'reason']
const WHOLE = /^\d+$/
// a whole number goes as a number; anything else goes as typed, so the server's 422 names the field
const whole = (v: string) => (WHOLE.test(v.trim()) ? Number(v) : v.trim())

/** HR's adjustment of one year: an entitlement override (blank = computed), the days used before the system, and why. */
function AdjustDialog({ empId, year, path, adjustment: a }: { empId: string; year: number; path: string; adjustment: Adjustment | null }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const send = useSave()

  const submit = (f: Row) => {
    const opening = (v: string) => (v.trim() === '' ? 0 : whole(v))
    const body = { entitlement: f.entitlement.trim() === '' ? null : whole(f.entitlement), opening_used: opening(f.opening_used), opening_casual_used: opening(f.opening_casual_used), reason: f.reason.trim() || null, version: f.version }
    return send<EmployeeLeave>(`/employees/${empId}/leave/${year}`, { method: 'PUT', body }, [path, '/leave'])
  }
  // as the server counts it: a set entitlement, or an opening value that is a whole number above 0 (blank or not a number is 0)
  const changed = (f: Row) => f.entitlement.trim() !== '' || [f.opening_used, f.opening_casual_used].some((v: string) => WHOLE.test(v.trim()) && Number(v) > 0)

  const ns = 'adjust-'
  return (
    <FormDialog
      title={t('leave.adjust.title', { year })}
      description={t('leave.adjust.description')}
      trigger={<Button type="button" variant="outline">{t('leave.adjust.open')}</Button>}
      init={{
        entitlement: a?.entitlement == null ? '' : String(a.entitlement),
        opening_used: a?.opening_used ? String(a.opening_used) : '',
        opening_casual_used: a?.opening_casual_used ? String(a.opening_casual_used) : '',
        reason: a?.reason ?? '',
        version: a?.version ?? null,
      }}
      reloadKey={a?.version ?? 0}
      validate={(f): Errs => (changed(f) && !f.reason.trim() ? { reason: t('leave.adjust.reasonRequired') } : {})}
      submit={submit}
      // the PUT answers with the whole balance: show it now, so a reopen before the refetch lands starts from the saved version
      onDone={(saved) => qc.setQueryData(queryKey(path), (old: object | undefined) => old && { ...old, data: saved })}
      shown={FIELDS}
    >
      {({ values, set, errs }) => (
        <>
          <div className="grid gap-1.5">
            <TextField name="entitlement" ns={ns} label={t('leave.adjust.entitlement')} inputMode="numeric" enterKeyHint="next" values={values} set={set} errs={errs} />
            <p className="text-[13px] text-muted-foreground">{t('leave.adjust.entitlementHint')}</p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField name="opening_used" ns={ns} label={t('leave.adjust.openingUsed')} inputMode="numeric" enterKeyHint="next" placeholder="0" values={values} set={set} errs={errs} />
            <TextField name="opening_casual_used" ns={ns} label={t('leave.adjust.openingCasualUsed')} inputMode="numeric" enterKeyHint="next" placeholder="0" values={values} set={set} errs={errs} />
          </div>
          <Field name="reason" id={`${ns}reason`} label={t('leave.adjust.reason')} errs={errs}>
            {(p) => <Textarea {...p} value={values.reason} onChange={(e) => set('reason', e.target.value)} />}
          </Field>
        </>
      )}
    </FormDialog>
  )
}
