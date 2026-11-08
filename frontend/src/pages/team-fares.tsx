import { Bus, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { DataList } from '@/components/common/DataList'
import { EmptyState } from '@/components/common/EmptyState'
import { PageHeader } from '@/components/common/PageHeader'
import { cairoToday, type Controlled, ErrorBanner, FormDialog, SelectField, TextField, useList, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useAuth } from '@/lib/auth'
import { RESIDENCES, useApi, useSave } from '@/lib/query'
import { NotFoundPage } from './pages'
import { ResidenceDialog as AddResidence } from './sites'

export type Ref = { id: string; name: string }
export type TeamFareRow = { employee_id: string; code: number; name: string; type: 'engineer' | 'permanent_worker' | 'day_laborer'; residence: Ref | null; site: Ref | null; amount: string | null; source: 'override' | 'default' | 'none' | 'no_residence'; default_amount: string | null; overrides: { site_id: string; amount: string }[] }
export type TeamFares = { sites: Ref[]; rows: TeamFareRow[] }

const TONE: Partial<Record<TeamFareRow['source'], BadgeVariant>> = { default: 'neutral', override: 'info', none: 'warning' }
const isEngineer = (r: TeamFareRow) => r.type === 'engineer'
const missing = (r: TeamFareRow) => !isEngineer(r) && r.amount === null
/** A filter toggle: put it on a `<button aria-pressed>`. */
const chipCls = 'min-h-tap rounded-full border bg-card px-3 text-[13px] font-medium text-muted-foreground transition-colors outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-solid focus-visible:outline-ring aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary'
/** A stored decimal as a person types it: "35.500000" → "35.5". */
const typed = (v: string) => v.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')

/** «مواصلات التيم»: the team's fare today from residence × site, corrected per worker (mockup 1). HR picks a site manager and sets fares; the site manager reads. */
export function TeamFaresPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const edit = can('fare.manage')
  const fillResidence = can('employee.edit')
  const pick = can('employee.view.all')
  const allowed = edit || can('employee.view.own')
  const [picked, setPicked] = useState('')
  const leads = useApi<Ref[]>(allowed && pick ? '/sites/manager-candidates' : null)
  const lead = picked || leads.data?.[0]?.id
  const path = !allowed ? null : pick ? (lead ? `/team-fares?manager_id=${lead}` : null) : '/team-fares'
  const { data, err, loading } = useApi<TeamFares>(path)
  if (!allowed) return <NotFoundPage />
  return (
    <div className="space-y-4">
      <PageHeader title={t('teamFare.title')} description={t(edit ? 'teamFare.subtitle' : 'teamFare.subtitleView')} />
      {pick && (
        <div role="group" aria-label={t('fare.lead')} className="flex flex-wrap gap-2">
          {(leads.data ?? []).map((m) => (
            <button key={m.id} type="button" aria-pressed={lead === m.id} onClick={() => setPicked(m.id)} className={chipCls}>
              {m.name}
            </button>
          ))}
        </div>
      )}
      <ErrorBanner err={leads.err ?? err} shown={[]} />
      {path === null ? (
        leads.loading ? <TableSkeleton /> : leads.data && <EmptyState icon={Users} title={t('teamFare.noManagers')} />
      ) : loading || !data ? (
        !err && <TableSkeleton />
      ) : !data.rows.length ? (
        <EmptyState icon={Bus} title={t('teamFare.empty')} />
      ) : (
        <TeamTable team={data} edit={edit} fillResidence={fillResidence} />
      )}
    </div>
  )
}

function TeamTable({ team, edit, fillResidence }: { team: TeamFares; edit: boolean; fillResidence: boolean }) {
  const { t } = useTranslation()
  const money = useMoney()
  const [chip, setChip] = useState('all')
  const [editing, setEditing] = useState<TeamFareRow>()
  const [housing, setHousing] = useState<TeamFareRow>()
  const siteName = (id: string) => team.sites.find((s) => s.id === id)?.name ?? ''
  const shown = team.rows.filter((r) => chip === 'all' || (chip === 'missing' ? missing(r) : r.site?.id === chip || r.overrides.some((o) => o.site_id === chip)))
  const chips: [string, string][] = [['all', t('teamFare.all', { count: team.rows.length })], ...team.sites.map((s): [string, string] => [s.id, s.name]), ['missing', t('teamFare.missing', { count: team.rows.filter(missing).length })]]
  return (
    <>
      <div className="flex flex-wrap gap-2">
        {chips.map(([value, label]) => (
          <button key={value} type="button" aria-pressed={chip === value} onClick={() => setChip(value)} className={chipCls}>
            {label}
          </button>
        ))}
      </div>
      <Card className="overflow-hidden">
        <DataList
          label={t('teamFare.title')}
          rows={shown}
          rowKey={(r) => r.employee_id}
          title={{
            header: t('teamFare.col.worker'),
            cell: (r) => (
              <>
                <div className="font-medium"><bdi>{r.name}</bdi></div>
                <div className="text-xs text-muted-foreground">{t(`enum.type.${r.type}`)}</div>
              </>
            ),
          }}
          fields={[
            {
              header: t('teamFare.col.residence'),
              cell: (r) =>
                r.residence ? (
                  r.residence.name
                ) : fillResidence ? (
                  <Button type="button" variant="outline" size="sm" onClick={() => setHousing(r)}>
                    {t('teamFare.setResidence')}
                  </Button>
                ) : (
                  <Badge variant="danger">{t('teamFare.noResidence')}</Badge>
                ),
            },
            { header: t('teamFare.col.site'), cell: (r) => (isEngineer(r) ? t('teamFare.byDay') : r.site?.name) },
            {
              header: t('teamFare.col.fare'),
              numeric: true,
              cell: (r) => (isEngineer(r) ? (r.overrides.length ? r.overrides.map((o) => <div key={o.site_id}>{`${siteName(o.site_id)} ${money(o.amount)}`}</div>) : '—') : money(r.amount)),
            },
          ]}
          status={{
            header: t('teamFare.col.source'),
            cell: (r) =>
              isEngineer(r) ? (
                <span className="text-xs text-muted-foreground">{t('teamFare.source.engineer')}</span>
              ) : TONE[r.source] ? (
                <Badge variant={TONE[r.source]}>{t(`teamFare.source.${r.source}`)}</Badge>
              ) : (
                <span className="text-xs text-muted-foreground">{t(`teamFare.source.${r.source}`)}</span>
              ),
          }}
          actions={
            edit
              ? (r) => (
                  <Button type="button" variant="outline" size="sm" aria-label={t('teamFare.edit', { name: r.name })} onClick={() => setEditing(r)}>
                    {t('fare.edit')}
                  </Button>
                )
              : undefined
          }
        />
      </Card>
      {editing && <TeamFareDialog row={editing} team={team} open onOpenChange={() => setEditing(undefined)} />}
      {housing && <ResidenceDialog row={housing} open onOpenChange={() => setHousing(undefined)} />}
    </>
  )
}

/** One worker's fare from a date: an override for this worker (default), or the residence × site fare for everyone in that pair. Engineers only get an override, at a site they pick. */
function TeamFareDialog({ row, team, ...dialog }: { row: TeamFareRow; team: TeamFares } & Controlled) {
  const { t } = useTranslation()
  const money = useMoney()
  const send = useSave()
  const today = cairoToday()
  const engineer = isEngineer(row)
  const pair = !engineer && row.residence && row.site ? { residence: row.residence, site: row.site } : null
  const pairCount = pair ? team.rows.filter((r) => !isEngineer(r) && r.residence?.id === pair.residence.id && r.site?.id === pair.site.id).length : 0
  const title = row.site && !engineer
    ? row.residence ? t('teamFare.editAt', { name: row.name, residence: row.residence.name, site: row.site.name }) : t('teamFare.editAtSite', { name: row.name, site: row.site.name })
    : t('teamFare.edit', { name: row.name })
  const site = engineer ? team.sites[0]?.id ?? '' : row.site?.id ?? ''
  const overrideAt = (id: string) => row.overrides.find((o) => o.site_id === id)?.amount
  const current = engineer ? overrideAt(site) : row.amount
  const invalidate = ['/team-fares', '/fares']
  const submit = (form: Record<string, string>) =>
    form.scope === 'pair' && pair
      ? send('/fares', { body: { residence_id: pair.residence.id, site_id: pair.site.id, effective_from: form.effective_from, amount: form.amount } }, invalidate)
      : send(`/employees/${row.employee_id}/fare-overrides`, { body: { site_id: form.site_id, effective_from: form.effective_from, amount: form.amount } }, invalidate)
  return (
    <FormDialog
      {...dialog}
      title={title}
      init={{ effective_from: today, amount: typed(current ?? row.default_amount ?? ''), scope: 'worker', site_id: site }}
      submit={submit}
      shown={['effective_from', 'amount', ...(engineer ? ['site_id'] : [])]}
    >
      {(f) => (
        <>
          {row.default_amount !== null && <Badge variant="neutral" className="justify-self-start">{t('teamFare.auto', { amount: money(row.default_amount) })}</Badge>}
          {engineer && <SelectField name="site_id" label={t('teamFare.col.site')} options={team.sites.map((s) => [s.id, s.name])} {...f} set={(k, v) => { f.set(k, v); f.set('amount', typed(overrideAt(v) ?? '')) }} />}
          <TextField name="amount" label={t('teamFare.amount')} inputMode="decimal" {...f} />
          <TextField name="effective_from" label={t('teamFare.from')} type="date" {...f} />
          {pair && (
            <div className="grid gap-2">
              {(['worker', 'pair'] as const).map((scope) => (
                <label key={scope} className="flex min-h-tap items-center gap-2 text-sm">
                  <input type="radio" name="scope" checked={f.values.scope === scope} onChange={() => f.set('scope', scope)} />
                  {scope === 'worker' ? t('teamFare.scope.worker') : t('teamFare.scope.pair', { residence: pair.residence.name, site: pair.site.name, count: pairCount })}
                </label>
              ))}
            </div>
          )}
        </>
      )}
    </FormDialog>
  )
}

/** HR records a missing residence, for an engineer too; with site.manage a residence not in the list can be added here and comes back selected. */
function ResidenceDialog({ row, ...dialog }: { row: TeamFareRow } & Controlled) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const send = useSave()
  const { rows: residences } = useList(RESIDENCES)
  return (
    <FormDialog
      {...dialog}
      title={t('teamFare.residenceTitle', { name: row.name })}
      init={{ residence_id: '' }}
      submit={(form) => send(`/employees/${row.employee_id}`, { method: 'PATCH', body: { residence_id: form.residence_id } }, ['/team-fares', '/employees'])}
      shown={['residence_id']}
    >
      {(f) => (
        <>
          <SelectField name="residence_id" label={t('teamFare.col.residence')} blank options={residences.map((r) => [r.id, r.name_ar])} {...f} />
          {can('site.manage') && <AddResidence onDone={(saved) => f.set('residence_id', saved.id)} />}
        </>
      )}
    </FormDialog>
  )
}
