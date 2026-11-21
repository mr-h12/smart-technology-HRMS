import { HandCoins } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { asciiDigits, ErrorBanner, type Errs, localName, ltrDates, TextField, toErrs, useCairoTime, useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Textarea } from '@/components/ui/input'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ApiError } from '@/lib/api'
import { plainAmount } from '@/lib/day-sheet'
import { useList, useSave } from '@/lib/query'

/**
 * A row of `GET /employees/wage-proposals`: a daily wage a site manager proposed, waiting for HR: for a day laborer they added
 * (`new_laborer`), or a new wage for one who already works (`wage_change`, #79) with the wage in force today and the manager's reason.
 */
export type WageProposal = {
  id: string
  version: number
  kind?: 'new_laborer' | 'wage_change'
  daily_wage: string
  current_wage?: string | null
  reason?: string | null
  created_at: string
  site: { id: string; name_ar: string; name_en: string | null } | null
  proposed_by: { id: string; name: string | null } | null
  employee: { id: string; code: number; name_ar: string; name_en: string | null; phone: string | null; national_id?: string | null; hire_date: string | null }
}

// Pending proposals are few (one per new laborer until HR decides), so one page holds them all; more than this shows a notice.
const LIST = '/employees/wage-proposals?per_page=100'
// the server's rule: a positive amount with up to 6 decimals
const WAGE = /^\d{1,12}(\.\d{1,6})?$/

/** `/wage-proposals`: HR approves or refuses each wage a site manager proposed (`employee.approve_wage.all`). `?proposal=` opens one (the bell). */
export function WageProposalsPage() {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const [params, setParams] = useSearchParams()
  const { key } = useLocation()
  // the navigation whose dialog was closed, so every new link to a proposal (the same one again included) opens it
  const [closed, setClosed] = useState<string>()
  const { rows, total, err, loading } = useList(LIST)
  const list = rows as WageProposal[]
  const id = params.get('proposal')
  const open = id ? list.find((r) => r.id === id) : undefined
  const close = () => {
    setClosed(key)
    setParams({}, { replace: true })
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('wages.page')} description={t('wages.description')} />
      {total > list.length && <Alert tone="warning">{t('wages.more', { shown: list.length, total })}</Alert>}
      {/* a link to a proposal that is no longer pending (decided by someone else, or from an old notification) */}
      {id && !loading && !err && !open && <Alert tone="info">{t('wages.gone')}</Alert>}
      {open && closed !== key && <DecideDialog p={open} close={close} />}
      <Card className="overflow-hidden">
        <DataList<WageProposal>
          label={t('wages.list')}
          rows={list}
          rowKey={(r) => r.id}
          to={(r) => `/wage-proposals?proposal=${r.id}`}
          current={(r) => r.id === id}
          loading={loading}
          error={err}
          empty={{ icon: HandCoins, title: t('wages.empty') }}
          title={{ header: t('employee.employee'), cell: (r) => localName(r.employee, i18n.language) }}
          fields={[
            { header: t('wages.kind.label'), cell: (r) => t(`wages.kind.${r.kind ?? 'new_laborer'}`) },
            { header: t('employee.code'), tableFrom: 'xl', cell: (r) => <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.employee.code}</bdi> },
            { header: t('wages.site'), cell: (r) => (r.site ? localName(r.site, i18n.language) : '—') },
            { header: t('wages.proposedBy'), tableFrom: 'lg', cell: (r) => r.proposed_by?.name ?? '—' },
            { header: t('wages.proposedAt'), tableFrom: 'md', cell: (r) => time(r.created_at) },
          ]}
          hero={{ header: t('wages.proposed'), cell: (r) => money(r.daily_wage) }}
        />
      </Card>
    </div>
  )
}

/**
 * One proposal: HR approves at the proposed wage or their own, or refuses it with a reason the proposing site manager reads. A new
 * laborer's pay row starts on the hire date, and refusing ends the laborer that day and takes them off every crew (owner, 2026-11-05;
 * reason 2026-11-19). A wage change (#79) starts on the date HR picks, which the server refuses inside a locked period or on a date
 * that already has a pay row; refusing it leaves the laborer and their wage as they are.
 */
function DecideDialog({ p, close }: { p: WageProposal; close: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const send = useSave()
  const change = p.kind === 'wage_change'
  const [form, setForm] = useState({ daily_wage: plainAmount(p.daily_wage), effective_from: '' })
  const [invalid, setInvalid] = useState<Errs>({})
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [refusing, setRefusing] = useState(false)
  const [reason, setReason] = useState('')
  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: asciiDigits(v) }))
    setInvalid({})
  }
  // a refusal (409: decided meanwhile) also reloads the list, so the stale row goes away
  const decide = async (action: 'approve' | 'refuse') => {
    if (action === 'approve') {
      const bad: Errs = {}
      const wage = form.daily_wage.trim()
      if (!WAGE.test(wage) || Number(wage) <= 0) bad.daily_wage = t('wages.badWage')
      if (change && !form.effective_from) bad.effective_from = t('wages.change.pickDate')
      setInvalid(bad)
      if (Object.keys(bad).length) return
    }
    setBusy(true)
    setErr(undefined)
    try {
      const wage = form.daily_wage.trim()
      const body = action === 'approve'
        ? { version: p.version, ...(wage !== plainAmount(p.daily_wage) && { daily_wage: wage }), ...(change && { effective_from: form.effective_from }) }
        : { version: p.version, reason: reason.trim() }
      await send(`/employees/wage-proposals/${p.id}/${action}`, { body }, ['/employees'])
      toast.success(t(`wages.${action}.done`, { name: p.employee.name_ar }))
      close()
    } catch (e) {
      setErr(e)
      // a 422 (no reason) keeps the refusal open; anything else goes back to the choice
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
          <DialogTitle>{t('wages.decide.title')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={['daily_wage', 'effective_from', 'reason']} />
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Item label={t('employee.employee')}>{localName(p.employee, i18n.language)} (<bdi dir="ltr">{p.employee.code}</bdi>)</Item>
            <Item label={t('wages.kind.label')}>{t(`wages.kind.${p.kind ?? 'new_laborer'}`)}</Item>
            <Item label={t('wages.site')}>{p.site ? localName(p.site, i18n.language) : '—'}</Item>
            <Item label={t('wages.proposedBy')}>{p.proposed_by?.name ?? '—'}</Item>
            <Item label={t('wages.proposedAt')}>{time(p.created_at)}</Item>
            {change && <Item label={t('wages.change.current')}>{p.current_wage ? money(p.current_wage) : '—'}</Item>}
            <Item label={t('wages.proposed')}>{money(p.daily_wage)}</Item>
            {!change && <Item label={t('wages.from')}>{p.employee.hire_date ? ltrDates(p.employee.hire_date) : '—'}</Item>}
            {change && p.reason && <div className="sm:col-span-2"><Item label={t('wages.change.managerReason')}>{p.reason}</Item></div>}
            {p.employee.phone && <Item label={t('employee.phone')}><bdi dir="ltr">{p.employee.phone}</bdi></Item>}
            {p.employee.national_id && <Item label={t('employee.national_id')}><bdi dir="ltr">{p.employee.national_id}</bdi></Item>}
          </dl>
          <TextField name="daily_wage" label={t('wages.approved')} values={form} set={set} errs={errs} inputMode="decimal" dir="ltr" disabled={busy || refusing} />
          {change && <TextField name="effective_from" label={t('wages.from')} type="date" values={form} set={set} errs={errs} disabled={busy || refusing} />}
          {refusing && <Alert tone="warning">{t(change ? 'wages.refuse.confirmChange' : 'wages.refuse.confirm')}</Alert>}
          {refusing && (
            <label className="grid gap-1.5 text-sm font-medium">
              {t('wages.refuse.reason')}
              <Textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} disabled={busy} aria-invalid={!!errs.reason || undefined} />
              {errs.reason && <span className="text-[13px] text-destructive">{errs.reason}</span>}
            </label>
          )}
        </DialogBody>
        <DialogFooter>
          {refusing ? (
            <>
              <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(false)}>{t('wages.refuse.back')}</Button>
              <Button type="button" variant="destructive" disabled={busy || !reason.trim()} onClick={() => void decide('refuse')}>{t('wages.refuse.yes')}</Button>
            </>
          ) : (
            <>
              <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(true)}>{t('wages.refuse.open')}</Button>
              <Button type="button" disabled={busy} onClick={() => void decide('approve')}>{t('wages.approve.open')}</Button>
            </>
          )}
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
