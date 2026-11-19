import { HandCoins } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { localName, useCairoTime, useMoney } from '@/components/form'
import type { BadgeVariant } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useApi, useList } from '@/lib/query'

/** A row of `GET /employees/wage-proposals/mine`: a daily wage this site manager proposed, and once HR decided, its outcome. */
export type MyProposal = {
  id: string
  status: 'pending' | 'approved' | 'refused'
  proposed_wage: string
  approved_wage: string | null
  refusal_reason: string | null
  created_at: string
  decided_at: string | null
  site: { id: string; name_ar: string; name_en: string | null } | null
  employee: { id: string; code: number; name_ar: string; name_en: string | null }
}

const STATUS: Record<MyProposal['status'], BadgeVariant> = { pending: 'warning', approved: 'success', refused: 'danger' }

/**
 * `/my-wage-proposals`: the daily wages this site manager proposed for the day laborers they added, and HR's decision on each: the wage
 * HR approved (the one proposed or HR's revision) or HR's reason for refusing (owner, 2026-11-19). Their own proposals only; the API
 * answers 404 for anyone else's. `?proposal=` opens one (the bell).
 */
export function MyWageProposalsPage() {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const time = useCairoTime()
  const [params, setParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const { rows, total, err, loading } = useList(`/employees/wage-proposals/mine?${new URLSearchParams({ page: String(page), per_page: '20' })}`)
  const list = rows as MyProposal[]
  const id = params.get('proposal')

  return (
    <div className="space-y-6">
      <PageHeader title={t('wages.mine.page')} description={t('wages.mine.description')} />
      {id && <ProposalDialog id={id} close={() => setParams({}, { replace: true })} />}
      <Card className="overflow-hidden">
        <DataList<MyProposal>
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
            { header: t('wages.site'), tableFrom: 'md', cell: (r) => (r.site ? localName(r.site, i18n.language) : '—') },
            { header: t('wages.proposedAt'), tableFrom: 'lg', cell: (r) => time(r.created_at) },
            { header: t('wages.proposed'), cell: (r) => money(r.proposed_wage) },
            { header: t('wages.mine.approvedWage'), cell: (r) => (r.approved_wage ? money(r.approved_wage) : '—') },
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
  const { data: p, err, loading } = useApi<MyProposal>(`/employees/wage-proposals/mine/${id}`)

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
              <Item label={t('wages.site')}>{p.site ? localName(p.site, i18n.language) : '—'}</Item>
              <Item label={t('common.status')}><StatusBadge variant={STATUS[p.status]} label={t(`wages.mine.status.${p.status}`)} /></Item>
              <Item label={t('wages.proposedAt')}>{time(p.created_at)}</Item>
              <Item label={t('wages.proposed')}>{money(p.proposed_wage)}</Item>
              {p.status === 'approved' && <Item label={t('wages.mine.approvedWage')}>{p.approved_wage ? money(p.approved_wage) : '—'}</Item>}
              {p.decided_at && <Item label={t('wages.mine.decidedAt')}>{time(p.decided_at)}</Item>}
              {p.status === 'refused' && <div className="sm:col-span-2"><Item label={t('wages.mine.reason')}>{p.refusal_reason ?? '—'}</Item></div>}
            </dl>
          )}
        </DialogBody>
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
