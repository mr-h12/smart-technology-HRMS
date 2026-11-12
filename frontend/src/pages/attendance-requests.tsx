import { HandCoins } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { AdvancePrint } from '@/components/AdvancePrint'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { cairoToday, ErrorBanner, localName, useCairoTime, useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input, Textarea } from '@/components/ui/input'
import { useApi, useList, useSave } from '@/lib/query'
import { useAuth } from '@/lib/auth'
import { AttendanceRequestPdf } from './attendance-request-pdf'

type RequestRow = {
  id: string
  type: 'overtime' | 'advance'
  status: 'pending' | 'approved' | 'refused'
  date: string
  minutes: number | null
  amount: string | null
  version: number
  employee: { id: string; code: number; name_ar: string; name_en: string | null } | null
  site: { id: string; name_ar: string; name_en: string | null } | null
  reason: string | null
  decided_at?: string | null
  paper_decision_date?: string | null
}

const PAGE_SIZE = 20
const STATUSES = ['pending', 'approved', 'refused', 'all']
const TYPES = ['overtime', 'advance']

export function AttendanceRequestsPage() {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const [params, setParams] = useSearchParams()
  const wantedStatus = params.get('status') ?? 'pending'
  const status = STATUSES.includes(wantedStatus) ? wantedStatus : 'pending'
  const wantedType = params.get('type') ?? ''
  const type = TYPES.includes(wantedType) ? wantedType : ''
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const query = new URLSearchParams({ status, page: String(page), per_page: String(PAGE_SIZE) })
  if (type) query.set('type', type)
  const { rows, total, loading, err } = useList(`/attendance/requests?${query}`)
  const id = params.get('request')
  const { data: selected, err: selectionError } = useApi<RequestRow>(id ? `/attendance/requests/${encodeURIComponent(id)}` : null)
  const set = (key: string, value: string) => setParams((previous) => {
    const next = new URLSearchParams(previous)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key === 'status' || key === 'type') next.delete('page')
    return next
  })
  const amount = (row: RequestRow) => row.type === 'overtime'
    ? t('attendanceRequests.minutes', { count: row.minutes ?? 0 })
    : row.amount && money(row.amount)

  return (
    <div className="space-y-5">
      <PageHeader title={t('attendanceRequests.title')} description={t('attendanceRequests.description')} />
      <div className="flex flex-wrap gap-3">
        <label className="grid gap-1 text-sm">
          {t('attendanceRequests.statusLabel')}
          <select className="min-h-tap rounded-md border bg-background px-2" value={status} onChange={(e) => set('status', e.target.value)}>
            {STATUSES.map((value) => <option value={value} key={value}>{t(`attendanceRequests.status.${value}`)}</option>)}
          </select>
        </label>
        <label className="grid gap-1 text-sm">
          {t('attendanceRequests.typeLabel')}
          <select className="min-h-tap rounded-md border bg-background px-2" value={type} onChange={(e) => set('type', e.target.value)}>
            <option value="">{t('attendanceRequests.status.all')}</option>
            {TYPES.map((value) => <option value={value} key={value}>{t(`attendanceRequests.type.${value}`)}</option>)}
          </select>
        </label>
      </div>
      <Card className="overflow-hidden">
        <DataList<RequestRow>
          label={t('attendanceRequests.title')} rows={rows as RequestRow[]} rowKey={(row) => row.id}
          to={(row) => `?${new URLSearchParams({ ...Object.fromEntries(params), request: row.id })}`}
          loading={loading} error={err}
          empty={{ icon: HandCoins, title: t('attendanceRequests.empty') }}
          title={{ header: t('employee.employee'), cell: (row) => row.employee ? localName(row.employee, i18n.language) : '—' }}
          fields={[
            { header: t('attendanceRequests.typeLabel'), cell: (row) => t(`attendanceRequests.type.${row.type}`) },
            { header: t('wages.site'), cell: (row) => row.site ? localName(row.site, i18n.language) : '—' },
            { header: t('attendanceRequests.date'), cell: (row) => <bdi>{row.date}</bdi> },
            { header: t('attendanceRequests.statusLabel'), cell: (row) => t(row.type === 'advance' && row.status === 'approved' ? 'attendanceRequests.paid' : `attendanceRequests.status.${row.status}`) },
          ]}
          hero={{ header: t('attendanceRequests.value'), cell: amount }}
        />
      </Card>
      {(page > 1 || total > page * PAGE_SIZE) && (
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={page === 1} onClick={() => set('page', String(page - 1))}>{t('attendanceRequests.previous')}</Button>
          <Button variant="outline" disabled={total <= page * PAGE_SIZE} onClick={() => set('page', String(page + 1))}>{t('attendanceRequests.next')}</Button>
        </div>
      )}
      {selectionError != null && <ErrorBanner err={selectionError} shown={[]} />}
      {selected && <RequestDialog key={selected.id} row={selected} value={amount(selected)} close={() => set('request', '')} />}
    </div>
  )
}

function RequestDialog({ row, value, close }: { row: RequestRow; value: string | null; close: () => void }) {
  const { t, i18n } = useTranslation()
  const save = useSave()
  const [error, setError] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [refusing, setRefusing] = useState(false)
  const [reason, setReason] = useState('')
  const [paperDate, setPaperDate] = useState('')
  const time = useCairoTime()
  const { can } = useAuth()
  const name = row.employee ? localName(row.employee, i18n.language) : '—'
  const site = row.site ? localName(row.site, i18n.language) : '—'
  const decide = async (action: 'approve' | 'refuse') => {
    setBusy(true)
    setError(undefined)
    try {
      await save(`/attendance/requests/${row.id}/${action}`, { body: { version: row.version, ...(action === 'refuse' && { reason: reason.trim() }), ...(row.type === 'advance' && paperDate && { paper_decision_date: paperDate }) } }, ['/attendance', '/notifications'])
      close()
    } catch (e) {
      setError(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Dialog open onOpenChange={(open) => { if (!open && !busy) close() }}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader><DialogTitle>{t(`attendanceRequests.type.${row.type}`)}</DialogTitle></DialogHeader>
        <DialogBody className="grid gap-3">
          <ErrorBanner err={error} shown={[]} />
          <p><bdi>{name}</bdi> · <bdi>{site}</bdi> · <bdi>{row.date}</bdi></p>
          <p>{value} · {t(row.type === 'advance' && row.status === 'approved' ? 'attendanceRequests.paid' : `attendanceRequests.status.${row.status}`)}</p>
          {row.reason && <p>{row.reason}</p>}
          {row.type === 'advance' && row.status === 'pending' && <p>{t('attendanceRequests.paperApproval', { version: row.version })}</p>}
          {row.type === 'advance' && row.amount && row.employee && (
            <div><AdvancePrint id={row.id} version={row.version} name={name} code={row.employee.code} site={site} date={row.date} amount={row.amount} /></div>
          )}
          {row.type === 'advance' && row.status === 'pending' && (
            <label className="grid gap-1">
              {t('attendanceRequests.paperDateOptional')}
              <Input type="date" min={row.date} max={cairoToday()} value={paperDate} disabled={busy} onChange={(event) => setPaperDate(event.target.value)} />
            </label>
          )}
          {row.type === 'advance' && row.decided_at && (
            <dl className="grid gap-1 text-sm sm:grid-cols-2">
              <div><dt>{t('attendanceRequests.systemDecisionTime')}</dt><dd>{time(row.decided_at)}</dd></div>
              <div><dt>{t('attendanceRequests.paperDate')}</dt><dd><bdi>{row.paper_decision_date ?? '—'}</bdi></dd></div>
            </dl>
          )}
          {row.employee && (row.type === 'overtime' || can('employee.view.all')) && (can('hr_forms.generate') || can('hr_forms.view')) && <AttendanceRequestPdf id={row.id} type={row.type} />}
          {refusing && (
            <label className="grid gap-1">
              {t('attendanceRequests.refusalReason')}
              <Textarea maxLength={500} value={reason} disabled={busy} onChange={(event) => setReason(event.target.value)} />
            </label>
          )}
        </DialogBody>
        {row.status === 'pending' && (
          <DialogFooter>
            {refusing ? (
              <>
                <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(false)}>{t('attendanceRequests.back')}</Button>
                <Button type="button" variant="destructive" disabled={busy || !reason.trim()} onClick={() => void decide('refuse')}>{t('attendanceRequests.refuse')}</Button>
              </>
            ) : (
              <>
                <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(true)}>{t('attendanceRequests.refuse')}</Button>
                <Button type="button" disabled={busy} onClick={() => void decide('approve')}>{t(row.type === 'advance' ? 'attendanceRequests.recordPaper' : 'attendanceRequests.approve')}</Button>
              </>
            )}
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}
