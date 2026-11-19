import { Bus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { asciiDigits, cairoToday, ErrorBanner, type Errs, localName, TextField, toErrs, useCairoTime, useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { ApiError } from '@/lib/api'
import { plainAmount } from '@/lib/day-sheet'
import { useList, useSave } from '@/lib/query'
import { AMOUNT, FareAmount, type FareRequest, RequestFacts } from './site-fares'

// Pending requests are few (one per employee and site until HR decides), so one page holds them; more shows a notice.
const LIST = '/fare-requests?per_page=100'

/**
 * `/fare-requests`: HR approves each site manager's transport request at the amount asked or a revised one, from a date (today by
 * default), or rejects it with a reason the manager reads (`fare.manage`; owner, 2026-11-20). `?request=` opens one (the bell).
 */
export function FareRequestsPage() {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const [params, setParams] = useSearchParams()
  const { key } = useLocation()
  const [closed, setClosed] = useState<string>()
  const { rows, total, err, loading } = useList(LIST)
  const list = rows as FareRequest[]
  const id = params.get('request')
  const open = id ? list.find((r) => r.id === id) : undefined
  const close = () => {
    setClosed(key)
    setParams({}, { replace: true })
  }
  return (
    <div className="space-y-6">
      <PageHeader title={t('fareRequest.review.page')} description={t('fareRequest.review.description')} />
      {total > list.length && <Alert tone="warning">{t('fareRequest.review.more', { shown: list.length, total })}</Alert>}
      {id && !loading && !err && !open && <Alert tone="info">{t('fareRequest.review.gone')}</Alert>}
      {open && closed !== key && <DecideDialog r={open} close={close} />}
      <Card className="overflow-hidden">
        <DataList<FareRequest>
          label={t('fareRequest.review.list')}
          rows={list}
          rowKey={(r) => r.id}
          to={(r) => `/fare-requests?request=${r.id}`}
          current={(r) => r.id === id}
          loading={loading}
          error={err}
          empty={{ icon: Bus, title: t('fareRequest.review.empty') }}
          title={{ header: t('fareRequest.col.employee'), cell: (r) => <>{localName(r.employee, i18n.language)} <bdi dir="ltr" className="text-muted-foreground">#{r.employee.code}</bdi></> }}
          fields={[
            { header: t('fareRequest.col.site'), cell: (r) => localName(r.site, i18n.language) },
            { header: t('fareRequest.col.requestedBy'), tableFrom: 'lg', cell: (r) => r.requested_by?.name ?? '—' },
            { header: t('fareRequest.col.askedAt'), tableFrom: 'md', cell: (r) => time(r.created_at) },
            { header: t('fareRequest.col.current'), numeric: true, tableFrom: 'md', cell: (r) => <FareAmount amount={r.current_amount} /> },
          ]}
          hero={{ header: t('fareRequest.col.requested'), cell: (r) => money(r.requested_amount) }}
        />
      </Card>
    </div>
  )
}

function DecideDialog({ r, close }: { r: FareRequest; close: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const send = useSave()
  const [form, setForm] = useState({ amount: plainAmount(r.requested_amount), effective_from: cairoToday() })
  const [invalid, setInvalid] = useState<Errs>({})
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [refusing, setRefusing] = useState(false)
  const [reason, setReason] = useState('')
  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: asciiDigits(v) }))
    setInvalid({})
  }
  // a 409 (decided meanwhile) also reloads the list, so the stale row goes away
  const decide = async (action: 'approve' | 'refuse') => {
    const amount = form.amount.trim()
    if (action === 'approve' && !AMOUNT.test(amount)) return setInvalid({ amount: t('fareRequest.badAmount') })
    setBusy(true)
    setErr(undefined)
    try {
      const body = action === 'approve'
        ? { version: r.version, effective_from: form.effective_from, ...(amount !== plainAmount(r.requested_amount) && { amount }) }
        : { version: r.version, reason: reason.trim() }
      await send(`/fare-requests/${r.id}/${action}`, { body }, ['/fare-requests', '/team-fares', '/fares'])
      toast.success(t(`fareRequest.review.${action === 'approve' ? 'approved' : 'refused'}`, { name: r.employee.name_ar }))
      close()
    } catch (e) {
      setErr(e)
      if (!(e instanceof ApiError && e.status === 422)) setRefusing(false)
    } finally {
      setBusy(false)
    }
  }
  const errs = { ...toErrs(err), ...invalid }
  return (
    <Dialog open onOpenChange={(o) => o || busy || close()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('fareRequest.review.title')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={['amount', 'effective_from', 'reason']} />
          <RequestFacts r={r} lang={i18n.language} money={money} time={time} hr />
          {!refusing && (
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField name="amount" label={t('fareRequest.review.approvedAmount')} values={form} set={set} errs={errs} inputMode="decimal" dir="ltr" disabled={busy} />
              <TextField name="effective_from" label={t('fareRequest.review.from')} type="date" values={form} set={set} errs={errs} disabled={busy} />
            </div>
          )}
          {refusing && (
            <label className="grid gap-1.5 text-sm font-medium">
              {t('fareRequest.review.refuseReason')}
              <Textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} disabled={busy} aria-invalid={!!errs.reason || undefined} />
              {errs.reason && <span className="text-[13px] text-destructive">{errs.reason}</span>}
            </label>
          )}
        </DialogBody>
        <DialogFooter>
          {refusing ? (
            <>
              <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(false)}>{t('fareRequest.review.back')}</Button>
              <Button type="button" variant="destructive" disabled={busy || !reason.trim()} onClick={() => void decide('refuse')}>{t('fareRequest.review.refuseYes')}</Button>
            </>
          ) : (
            <>
              <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(true)}>{t('fareRequest.review.refuse')}</Button>
              <Button type="button" disabled={busy} onClick={() => void decide('approve')}>{t('fareRequest.review.approve')}</Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
