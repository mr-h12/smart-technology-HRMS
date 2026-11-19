import { Bus, HandCoins } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { EmptyState } from '@/components/common/EmptyState'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { asciiDigits, ErrorBanner, type Errs, localName, TextField, toErrs, useCairoTime, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { ApiError } from '@/lib/api'
import { useApi, useList, useSave } from '@/lib/query'

type Ref = { id: string; name: string }
type Pending = { id: string; requested_amount: string; created_at: string | null; mine: boolean }
/** A row of `GET /team-fares/site`: `amount` null is a fare never set; "0.000000" is one set to zero. */
export type SiteFareRow = {
  employee_id: string
  code: number
  name: string
  type: 'engineer' | 'permanent_worker' | 'day_laborer'
  residence: Ref | null
  amount: string | null
  source: 'override' | 'default' | 'none' | 'no_residence'
  default_amount: string | null
  pending: Pending | null
}
export type SiteFares = { sites: Ref[]; site: Ref; rows: SiteFareRow[] }
type Named = { id: string; code: number; name_ar: string; name_en: string | null }
/** A transport request as `GET /fare-requests/mine` (and HR's list, with requested_by/decided_by) returns it. */
export type FareRequest = {
  id: string
  status: 'pending' | 'approved' | 'refused'
  outcome: 'pending' | 'approved' | 'revised' | 'refused'
  current_amount: string | null
  requested_amount: string
  approved_amount: string | null
  effective_from: string | null
  reason: string
  refusal_reason: string | null
  created_at: string
  decided_at: string | null
  version: number
  site: { id: string; name_ar: string; name_en: string | null }
  employee: Named
  requested_by?: { id: string; name: string } | null
}

export const OUTCOME: Record<FareRequest['outcome'], BadgeVariant> = { pending: 'warning', approved: 'success', revised: 'info', refused: 'danger' }
// the server's rule (SiteRules::MONEY): zero or more, up to 12 digits and 6 decimals
export const AMOUNT = /^\d{1,12}(\.\d{1,6})?$/
const chipCls = 'min-h-tap rounded-full border bg-card px-3 text-[13px] font-medium text-muted-foreground transition-colors outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-solid focus-visible:outline-ring aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary'
const newId = () => crypto.randomUUID()

/**
 * The site manager's «مواصلات الموقع» (owner, 2026-11-20): one of their sites at a time, everyone of their team there with the fare
 * that applies at it, a change request per row, and their requests with HR's decision. `?site=` keeps the site, `?request=` opens one.
 */
export function SiteFaresView() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const picked = params.get('site')
  // the first answer names the manager's sites; with no ?site= it is the team's first site
  const first = useApi<{ sites: Ref[] }>(picked ? null : '/team-fares')
  const siteId = picked ?? first.data?.sites[0]?.id ?? null
  const { data, err, loading } = useApi<SiteFares>(siteId ? `/team-fares/site?site_id=${siteId}` : null, { keep: true })
  const [asking, setAsking] = useState<SiteFareRow>()
  const sites = data?.sites ?? first.data?.sites ?? []
  const pick = (id: string) => setParams((p) => (p.set('site', id), p.delete('request'), p), { replace: true })
  return (
    <div className="space-y-4">
      <PageHeader title={t('fareRequest.siteTitle')} description={t('fareRequest.siteSubtitle')} />
      {sites.length > 0 && (
        <div role="group" aria-label={t('fareRequest.sites')} className="flex flex-wrap gap-2">
          {sites.map((s) => (
            <button key={s.id} type="button" aria-pressed={s.id === siteId} onClick={() => s.id !== siteId && pick(s.id)} className={chipCls}>
              {s.name}
            </button>
          ))}
        </div>
      )}
      <ErrorBanner err={first.err ?? err} shown={[]} />
      {siteId === null ? (
        first.loading ? <TableSkeleton /> : !first.err && <EmptyState icon={Bus} title={t('teamFare.empty')} />
      ) : loading || !data ? (
        !err && <TableSkeleton />
      ) : !data.rows.length ? (
        <EmptyState icon={Bus} title={t('fareRequest.empty')} />
      ) : (
        <SiteTable view={data} ask={setAsking} />
      )}
      {asking && data && <RequestDialog row={asking} site={data.site} close={() => setAsking(undefined)} />}
      <MyRequests />
    </div>
  )
}

/** The amount with unset and zero told apart: unset is a badge, zero is the amount 0.00 marked as set. */
export function FareAmount({ amount }: { amount: string | null }) {
  const { t } = useTranslation()
  const money = useMoney()
  if (amount === null) return <Badge variant="warning">{t('fareRequest.unset')}</Badge>
  return Number(amount) === 0 ? <span title={t('fareRequest.zero')}>{money(amount)} <span className="text-xs text-muted-foreground">({t('fareRequest.zero')})</span></span> : <>{money(amount)}</>
}

function SiteTable({ view, ask }: { view: SiteFares; ask: (r: SiteFareRow) => void }) {
  const { t } = useTranslation()
  const money = useMoney()
  const [, setParams] = useSearchParams()
  return (
    <Card className="overflow-hidden">
      <DataList<SiteFareRow>
        label={`${t('fareRequest.siteTitle')}: ${view.site.name}`}
        rows={view.rows}
        rowKey={(r) => r.employee_id}
        title={{
          header: t('teamFare.col.worker'),
          cell: (r) => (
            <>
              <div className="font-medium"><bdi>{r.name}</bdi> <bdi dir="ltr" className="text-xs text-muted-foreground">#{r.code}</bdi></div>
              <div className="text-xs text-muted-foreground">{t(`enum.type.${r.type}`)}</div>
            </>
          ),
        }}
        fields={[
          { header: t('teamFare.col.residence'), tableFrom: 'md', cell: (r) => r.residence?.name ?? '—' },
          { header: t('teamFare.col.fare'), numeric: true, cell: (r) => <FareAmount amount={r.amount} /> },
          {
            header: t('teamFare.col.source'),
            tableFrom: 'lg',
            cell: (r) => <span className="text-xs text-muted-foreground">{t(r.type === 'engineer' && r.source === 'none' ? 'teamFare.source.engineer' : `teamFare.source.${r.source}`)}</span>,
          },
        ]}
        status={{
          header: t('common.status'),
          cell: (r) => (r.pending ? <Badge variant="info">{r.pending.mine ? t('fareRequest.pendingBadge', { amount: money(r.pending.requested_amount) }) : t('fareRequest.pendingOther')}</Badge> : null),
        }}
        actions={(r) =>
          r.pending?.mine ? (
            <Button type="button" variant="outline" size="sm" onClick={() => setParams((p) => (p.set('request', r.pending!.id), p), { replace: true })}>
              {t('fareRequest.viewPending')}
            </Button>
          ) : r.pending ? null : (
            <Button type="button" variant="outline" size="sm" aria-label={t('fareRequest.requestFor', { name: r.name })} onClick={() => ask(r)}>
              {t('fareRequest.request')}
            </Button>
          )
        }
      />
    </Card>
  )
}

/** The request: the fare now, the amount asked (any digits) and why. The form id stays until a success, so a retry adds nothing. */
function RequestDialog({ row, site, close }: { row: SiteFareRow; site: Ref; close: () => void }) {
  const { t } = useTranslation()
  const money = useMoney()
  const send = useSave()
  const [, setParams] = useSearchParams()
  const [form, setForm] = useState({ amount: '', reason: '' })
  const [requestId] = useState(newId)
  const [invalid, setInvalid] = useState<Errs>({})
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: k === 'amount' ? asciiDigits(v) : v }))
    setInvalid({})
  }
  const pending = err instanceof ApiError && err.code === 'fare_request_pending' ? (err.body.pending as Pending | undefined) : undefined
  async function submit() {
    const amount = form.amount.trim()
    const bad: Errs = {}
    if (!AMOUNT.test(amount)) bad.amount = t('fareRequest.badAmount')
    if (!form.reason.trim()) bad.reason = t('fareRequest.reasonRequired')
    setInvalid(bad)
    if (Object.keys(bad).length) return
    setBusy(true)
    setErr(undefined)
    try {
      await send('/fare-requests', { body: { employee_id: row.employee_id, site_id: site.id, amount, reason: form.reason.trim(), client_request_id: requestId } }, ['/team-fares', '/fare-requests'])
      toast.success(t('fareRequest.sent', { name: row.name }))
      close()
    } catch (e) {
      setErr(e)
    } finally {
      setBusy(false)
    }
  }
  const errs = { ...toErrs(err), ...invalid }
  return (
    <Dialog open onOpenChange={(o) => o || busy || close()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('fareRequest.dialogTitle', { name: row.name, site: site.name })}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          {pending ? (
            <Alert tone="info">
              {t('fareRequest.alreadyPending', { amount: money(pending.requested_amount) })}{' '}
              {pending.mine && (
                <Button type="button" variant="link" className="h-auto p-0" onClick={() => { close(); setParams((p) => (p.set('request', pending.id), p), { replace: true }) }}>
                  {t('fareRequest.viewPending')}
                </Button>
              )}
            </Alert>
          ) : (
            <ErrorBanner err={err} shown={['amount', 'reason']} />
          )}
          <dl className="text-sm">
            <dt className="text-muted-foreground">{t('fareRequest.current')}</dt>
            <dd className="font-medium"><FareAmount amount={row.amount} /></dd>
          </dl>
          <TextField name="amount" label={t('fareRequest.amount')} values={form} set={set} errs={errs} inputMode="decimal" dir="ltr" disabled={busy} />
          <label className="grid gap-1.5 text-sm font-medium">
            {t('fareRequest.reason')}
            <Textarea value={form.reason} onChange={(e) => set('reason', e.target.value)} maxLength={500} disabled={busy} aria-invalid={!!errs.reason || undefined} />
            {errs.reason && <span className="text-[13px] text-destructive">{errs.reason}</span>}
          </label>
          <p className="text-[13px] text-muted-foreground">{t('fareRequest.keepsCurrent')}</p>
        </DialogBody>
        <DialogFooter>
          <Button type="button" disabled={busy || !!pending} onClick={() => void submit()}>{t('fareRequest.send')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** The manager's own requests, newest first, with HR's decision; `?request=` opens one (the row, the bell). */
function MyRequests() {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const [params, setParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const { rows, total, err, loading } = useList(`/fare-requests/mine?${new URLSearchParams({ page: String(page), per_page: '20' })}`)
  const list = rows as FareRequest[]
  const id = params.get('request')
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle className="text-base">{t('fareRequest.mine.title')}</CardTitle>
      </CardHeader>
      {id && <RequestDetail id={id} close={() => setParams((p) => (p.delete('request'), p), { replace: true })} />}
      <DataList<FareRequest>
        label={t('fareRequest.mine.list')}
        rows={list}
        rowKey={(r) => r.id}
        to={(r) => `/team-fares?${new URLSearchParams({ ...(params.get('site') && { site: params.get('site')! }), request: r.id })}`}
        current={(r) => r.id === id}
        loading={loading}
        error={err}
        empty={{ icon: HandCoins, title: t('fareRequest.mine.empty') }}
        page={{ page, pageSize: 20, total, onPageChange: setPage }}
        title={{ header: t('fareRequest.col.employee'), cell: (r) => <>{localName(r.employee, i18n.language)} <bdi dir="ltr" className="text-muted-foreground">#{r.employee.code}</bdi></> }}
        status={{ header: t('common.status'), cell: (r) => <StatusBadge variant={OUTCOME[r.outcome]} label={t(`fareRequest.outcome.${r.outcome}`)} /> }}
        fields={[
          { header: t('fareRequest.col.site'), tableFrom: 'md', cell: (r) => localName(r.site, i18n.language) },
          { header: t('fareRequest.col.askedAt'), tableFrom: 'lg', cell: (r) => time(r.created_at) },
          { header: t('fareRequest.col.requested'), numeric: true, cell: (r) => money(r.requested_amount) },
          { header: t('fareRequest.col.approved'), numeric: true, cell: (r) => (r.approved_amount === null ? '—' : money(r.approved_amount)) },
        ]}
      />
    </Card>
  )
}

/** One of the manager's requests: what was asked and HR's decision (the approved amount and its date, or HR's reason). */
function RequestDetail({ id, close }: { id: string; close: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const { data: r, err, loading } = useApi<FareRequest>(`/fare-requests/mine/${id}`)
  return (
    <Dialog open onOpenChange={(o) => o || close()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('fareRequest.detail')}</DialogTitle>
        </DialogHeader>
        <DialogBody>
          {loading && <p className="text-sm text-muted-foreground" role="status">{t('fareRequest.mine.loading')}</p>}
          {!loading && (err !== undefined || !r) && <p className="text-sm text-muted-foreground">{t('fareRequest.mine.gone')}</p>}
          {r && <RequestFacts r={r} lang={i18n.language} money={money} time={time} />}
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}

/** The facts of a request both sides read: employee, site, status, the amounts, the dates and both reasons. */
export function RequestFacts({ r, lang, money, time, hr = false }: { r: FareRequest; lang: string; money: (v: string) => string; time: (v: string) => string; hr?: boolean }) {
  const { t } = useTranslation()
  return (
    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      <Item label={t('fareRequest.col.employee')}>{localName(r.employee, lang)} (<bdi dir="ltr">{r.employee.code}</bdi>)</Item>
      <Item label={t('fareRequest.col.site')}>{localName(r.site, lang)}</Item>
      <Item label={t('common.status')}><StatusBadge variant={OUTCOME[r.outcome]} label={t(`fareRequest.outcome.${r.outcome}`)} /></Item>
      {hr && <Item label={t('fareRequest.col.requestedBy')}>{r.requested_by?.name ?? '—'}</Item>}
      <Item label={t('fareRequest.col.askedAt')}>{time(r.created_at)}</Item>
      <Item label={t('fareRequest.col.current')}><FareAmount amount={r.current_amount} /></Item>
      <Item label={t('fareRequest.col.requested')}>{money(r.requested_amount)}</Item>
      {r.approved_amount !== null && <Item label={t('fareRequest.col.approved')}>{money(r.approved_amount)}</Item>}
      {r.effective_from && <Item label={t('fareRequest.col.from')}><bdi dir="ltr">{r.effective_from}</bdi></Item>}
      {r.decided_at && <Item label={t('fareRequest.col.decidedAt')}>{time(r.decided_at)}</Item>}
      <div className="sm:col-span-2"><Item label={t('fareRequest.col.reason')}>{r.reason}</Item></div>
      {r.refusal_reason && <div className="sm:col-span-2"><Item label={t('fareRequest.col.response')}>{r.refusal_reason}</Item></div>}
    </dl>
  )
}

const Item = ({ label, children }: { label: string; children: React.ReactNode }) => (
  <div>
    <dt className="text-muted-foreground">{label}</dt>
    <dd className="font-medium">{children}</dd>
  </div>
)
