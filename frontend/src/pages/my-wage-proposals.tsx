import { HandCoins, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { asciiDigits, ErrorBanner, type Errs, localName, ltrDates, TextField, toErrs, useCairoTime, useMoney } from '@/components/form'
import type { BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input, Textarea } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'
import { newRequestId } from './site-day-add'

/** A row of `GET /employees/wage-proposals/mine`: no figure in the list (Youssef, 2026-11-19); the amounts come with the opened request. */
export type MyProposalRow = {
  id: string
  /** a new laborer's first wage, or a new wage for a laborer who already works (#79) */
  kind: 'new_laborer' | 'wage_change'
  status: 'pending' | 'approved' | 'refused'
  created_at: string
  decided_at: string | null
  site: { id: string; name_ar: string; name_en: string | null } | null
  employee: { id: string; code: number; name_ar: string; name_en: string | null }
}
/** `GET /employees/wage-proposals/mine/{id}`: one proposal with the wage proposed and, once HR decided, the approved wage or the reason. */
export type MyProposal = MyProposalRow & {
  proposed_wage: string
  approved_wage: string | null
  refusal_reason: string | null
  /** the site manager's own reason for a wage change */
  reason: string | null
  /** the date HR approved a wage change from */
  effective_from: string | null
}

const STATUS: Record<MyProposal['status'], BadgeVariant> = { pending: 'warning', approved: 'success', refused: 'danger' }
const MINE = '/employees/wage-proposals/mine'
// the server's rule: a positive amount with up to 6 decimals
const WAGE = /^\d{1,12}(\.\d{1,6})?$/

/**
 * `/my-wage-proposals`: the daily wages this site manager proposed, for the day laborers they added and since #79 for laborers who
 * already work («اقتراح أجر لعامل موجود», `employee.propose_wage`), and HR's decision on each: the wage HR approved (the one proposed
 * or HR's revision) or HR's reason for refusing (owner, 2026-11-19). Their own proposals only; the API answers 404 for anyone else's.
 * `?proposal=` opens one (the bell).
 */
export function MyWageProposalsPage() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const [proposing, setProposing] = useState(false)
  const [params, setParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const { rows, total, err, loading } = useList(`${MINE}?${new URLSearchParams({ page: String(page), per_page: '20' })}`)
  const list = rows as MyProposalRow[]
  const id = params.get('proposal')

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('wages.mine.page')}
        description={t('wages.mine.description')}
        {...(can('employee.propose_wage') && { action: { label: t('wages.change.open'), icon: Plus, onClick: () => setProposing(true) } })}
      />
      {proposing && <ProposeWageDialog close={() => setProposing(false)} />}
      {id && <ProposalDialog id={id} close={() => setParams({}, { replace: true })} />}
      <Card className="overflow-hidden">
        <DataList<MyProposalRow>
          label={t('wages.mine.list')}
          rows={list}
          rowKey={(r) => r.id}
          to={(r) => `/my-wage-proposals?proposal=${r.id}`}
          current={(r) => r.id === id}
          loading={loading}
          error={err}
          empty={{ icon: HandCoins, title: t('wages.mine.empty') }}
          page={{ page, pageSize: 20, total, onPageChange: setPage }}
          title={{ header: t('employee.employee'), cell: (r) => <>{localName(r.employee, i18n.language)} <bdi dir="ltr" className="text-muted-foreground">#{r.employee.code}</bdi></> }}
          status={{ header: t('common.status'), cell: (r) => <StatusBadge variant={STATUS[r.status]} label={t(`wages.mine.status.${r.status}`)} /> }}
          fields={[
            { header: t('wages.kind.label'), cell: (r) => t(`wages.kind.${r.kind}`) },
            { header: t('wages.site'), tableFrom: 'md', cell: (r) => (r.site ? localName(r.site, i18n.language) : '—') },
            { header: t('wages.proposedAt'), cell: (r) => time(r.created_at) },
          ]}
        />
      </Card>
    </div>
  )
}

/** One request: what was proposed, and HR's decision with the approved wage or the reason. */
function ProposalDialog({ id, close }: { id: string; close: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const { data: p, err, loading } = useApi<MyProposal>(`${MINE}/${id}`)

  return (
    <Dialog open onOpenChange={(o) => o || close()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('wages.mine.request')}</DialogTitle>
        </DialogHeader>
        <DialogBody>
          {loading && <p className="text-sm text-muted-foreground" role="status">{t('wages.mine.loading')}</p>}
          {!loading && (err !== undefined || !p) && <p className="text-sm text-muted-foreground">{t('wages.mine.gone')}</p>}
          {p && (
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <Item label={t('employee.employee')}>{localName(p.employee, i18n.language)} (<bdi dir="ltr">{p.employee.code}</bdi>)</Item>
              <Item label={t('wages.kind.label')}>{t(`wages.kind.${p.kind}`)}</Item>
              <Item label={t('wages.site')}>{p.site ? localName(p.site, i18n.language) : '—'}</Item>
              <Item label={t('common.status')}><StatusBadge variant={STATUS[p.status]} label={t(`wages.mine.status.${p.status}`)} /></Item>
              <Item label={t('wages.proposedAt')}>{time(p.created_at)}</Item>
              <Item label={t('wages.proposed')}>{money(p.proposed_wage)}</Item>
              {p.status === 'approved' && <Item label={t('wages.mine.approvedWage')}>{p.approved_wage ? money(p.approved_wage) : '—'}</Item>}
              {p.status === 'approved' && p.effective_from && <Item label={t('wages.from')}>{ltrDates(p.effective_from)}</Item>}
              {p.decided_at && <Item label={t('wages.mine.decidedAt')}>{time(p.decided_at)}</Item>}
              {p.reason && <div className="sm:col-span-2"><Item label={t('wages.change.yourReason')}>{p.reason}</Item></div>}
              {p.status === 'refused' && <div className="sm:col-span-2"><Item label={t('wages.mine.reason')}>{p.refusal_reason ?? '—'}</Item></div>}
            </dl>
          )}
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}

/** A person a site manager reaches, as `GET /employees` returns them to a site manager: never a wage. */
type Found = { id: string; code: number; name_ar: string; name_en: string | null }

/**
 * A new daily wage for a day laborer the site manager reaches today (#79): find them by code or name among the people they reach, type
 * the wage and an optional reason. The current wage is never shown (CLAUDE.md: a site manager never sees a wage); the server refuses
 * someone who is not a day laborer or who already has a proposal waiting.
 */
function ProposeWageDialog({ close }: { close: () => void }) {
  const { t, i18n } = useTranslation()
  const send = useSave()
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<Found>()
  const [form, setForm] = useState({ daily_wage: '' })
  const [reason, setReason] = useState('')
  const [requestId] = useState(newRequestId)
  const [invalid, setInvalid] = useState<Errs>({})
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const q = asciiDigits(search).trim()
  const found = useApi<Found[]>(picked || q.length < 2 ? null : `/employees?${new URLSearchParams({ search: q, per_page: '10' })}`, { keep: true })
  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: asciiDigits(v) }))
    setInvalid({})
  }
  const submit = async () => {
    const wage = form.daily_wage.trim()
    const bad: Errs = {}
    if (!picked) bad.employee = t('wages.change.pick')
    if (!WAGE.test(wage) || Number(wage) <= 0) bad.daily_wage = t('wages.badWage')
    setInvalid(bad)
    if (!picked || Object.keys(bad).length) return
    setBusy(true)
    setErr(undefined)
    try {
      await send(`/employees/${picked.id}/wage-proposals`, { body: { daily_wage: wage, reason: reason.trim() || null, client_request_id: requestId } }, [MINE])
      toast.success(t('wages.change.done', { name: localName(picked, i18n.language) }))
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
          <DialogTitle>{t('wages.change.title')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={['employee', 'daily_wage', 'reason']} />
          {picked ? (
            <div className="flex items-center justify-between gap-2 rounded-md border p-3 text-sm">
              <span className="font-medium">{localName(picked, i18n.language)} (<bdi dir="ltr">{picked.code}</bdi>)</span>
              <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setPicked(undefined)}>{t('wages.change.other')}</Button>
            </div>
          ) : (
            <div className="grid gap-1.5 text-sm font-medium">
              <label htmlFor="wage-change-search">{t('wages.change.search')}</label>
              <Input id="wage-change-search" value={search} onChange={(e) => setSearch(e.target.value)} aria-invalid={!!errs.employee || undefined} autoFocus />
              {errs.employee && <span className="text-[13px] text-destructive">{errs.employee}</span>}
            </div>
          )}
          {!picked && q.length >= 2 && (
            <ul className="grid gap-1" aria-label={t('wages.change.results')}>
              {(found.data ?? []).map((e) => (
                <li key={e.id}>
                  <Button type="button" variant="ghost" className="w-full justify-start" onClick={() => setPicked(e)}>
                    {localName(e, i18n.language)} <bdi dir="ltr" className="text-muted-foreground">#{e.code}</bdi>
                  </Button>
                </li>
              ))}
              {!found.loading && (found.data ?? []).length === 0 && <li className="text-sm text-muted-foreground">{t('wages.change.none')}</li>}
            </ul>
          )}
          {picked && errs.employee && <span className="text-[13px] text-destructive">{errs.employee}</span>}
          <TextField name="daily_wage" label={t('wages.proposed')} values={form} set={set} errs={errs} inputMode="decimal" dir="ltr" disabled={busy} />
          <div className="grid gap-1.5 text-sm font-medium">
            <label htmlFor="wage-change-reason">{t('wages.change.reason')}</label>
            <Textarea id="wage-change-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} disabled={busy} aria-invalid={!!errs.reason || undefined} />
            {errs.reason && <span className="text-[13px] text-destructive">{errs.reason}</span>}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={busy} onClick={close}>{t('wages.refuse.back')}</Button>
          <Button type="button" disabled={busy} onClick={() => void submit()}>{t('wages.change.send')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const Item = ({ label, children }: { label: string; children: React.ReactNode }) => (
  <div>
    <dt className="text-muted-foreground">{label}</dt>
    <dd className="font-medium">{children}</dd>
  </div>
)
