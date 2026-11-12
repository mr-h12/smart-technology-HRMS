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
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { plainAmount } from '@/lib/day-sheet'
import { useList, useSave } from '@/lib/query'

/** A row of `GET /employees/wage-proposals`: a daily wage a site manager proposed for a day laborer they added, waiting for HR. */
export type WageProposal = {
  id: string
  version: number
  daily_wage: string
  created_at: string
  site: { id: string; name_ar: string; name_en: string | null } | null
  proposed_by: { id: string; name: string | null } | null
  employee: { id: string; code: number; name_ar: string; name_en: string | null; phone: string | null; hire_date: string | null }
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
 * One proposal: HR approves at the proposed wage or their own (the pay row starts on the hire date), or refuses it, which ends the
 * laborer that day and takes them off every crew (owner, 2026-11-05).
 */
function DecideDialog({ p, close }: { p: WageProposal; close: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const send = useSave()
  const [form, setForm] = useState({ daily_wage: plainAmount(p.daily_wage) })
  const [invalid, setInvalid] = useState<Errs>({})
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [refusing, setRefusing] = useState(false)
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
      setInvalid(bad)
      if (Object.keys(bad).length) return
    }
    setBusy(true)
    setErr(undefined)
    try {
      const wage = form.daily_wage.trim()
      const body = action === 'approve'
        ? { version: p.version, ...(wage !== plainAmount(p.daily_wage) && { daily_wage: wage }) }
        : { version: p.version }
      await send(`/employees/wage-proposals/${p.id}/${action}`, { body }, ['/employees'])
      toast.success(t(`wages.${action}.done`, { name: p.employee.name_ar }))
      close()
    } catch (e) {
      setErr(e)
      setRefusing(false)
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
          <ErrorBanner err={err} shown={['daily_wage']} />
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Item label={t('employee.employee')}>{localName(p.employee, i18n.language)} (<bdi dir="ltr">{p.employee.code}</bdi>)</Item>
            <Item label={t('wages.site')}>{p.site ? localName(p.site, i18n.language) : '—'}</Item>
            <Item label={t('wages.proposedBy')}>{p.proposed_by?.name ?? '—'}</Item>
            <Item label={t('wages.proposedAt')}>{time(p.created_at)}</Item>
            <Item label={t('wages.proposed')}>{money(p.daily_wage)}</Item>
            <Item label={t('wages.from')}>{p.employee.hire_date ? ltrDates(p.employee.hire_date) : '—'}</Item>
            {p.employee.phone && <Item label={t('employee.phone')}><bdi dir="ltr">{p.employee.phone}</bdi></Item>}
          </dl>
          <TextField name="daily_wage" label={t('wages.approved')} values={form} set={set} errs={errs} inputMode="decimal" dir="ltr" disabled={busy || refusing} />
          {refusing && <Alert tone="warning">{t('wages.refuse.confirm')}</Alert>}
        </DialogBody>
        <DialogFooter>
          {refusing ? (
            <>
              <Button type="button" variant="outline" disabled={busy} onClick={() => setRefusing(false)}>{t('wages.refuse.back')}</Button>
              <Button type="button" variant="destructive" disabled={busy} onClick={() => void decide('refuse')}>{t('wages.refuse.yes')}</Button>
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
