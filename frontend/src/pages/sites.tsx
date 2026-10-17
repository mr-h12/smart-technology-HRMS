import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, FormDialog, type Row, SelectField, selectCls, TextField, useList, useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, apiList } from '@/lib/api'

const LEAVE_CODES = ['friday', 'official_holiday', 'sick', 'rest', 'absent', 'other']
const MODES = ['none', 'share_of_wage', 'fixed']
const LIST = '?per_page=100' // server max; these lists are small, the notice below covers overflow

const cairoToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Cairo' })

const Notice = ({ show, text }: { show: boolean; text: string }) =>
  show ? (
    <p role="status" className="rounded-md border p-3 text-sm">
      {text}
    </p>
  ) : null

function SiteDialog({ site, reload }: { site?: Row; reload: () => void }) {
  const { t } = useTranslation()
  const init = { name_ar: site?.name_ar ?? '', name_en: site?.name_en ?? '', kind: site?.kind ?? 'work', leave_code: site?.leave_code ?? '', outside_alexandria: site?.outside_alexandria ? 'true' : '', active: site?.active === false ? '' : 'true' }
  const body = (form: Row) => ({
    name_ar: form.name_ar,
    name_en: form.name_en || null,
    kind: form.kind,
    leave_code: form.kind === 'leave' ? form.leave_code || null : null,
    outside_alexandria: !!form.outside_alexandria,
    active: !!form.active,
  })
  return (
    <FormDialog
      title={t(site ? 'site.edit' : 'site.add')}
      trigger={<Button type="button" variant={site ? 'outline' : 'default'}>{t(site ? 'site.edit' : 'site.add')}</Button>}
      init={init}
      submit={(form) => (site ? api(`/sites/${site.id}`, { method: 'PATCH', body: body(form) }) : api('/sites', { method: 'POST', body: body(form) }))}
      onDone={reload}
      shown={['name_ar', 'name_en', 'kind', 'leave_code']}
    >
      {(f) => (
        <>
          <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
          <TextField name="name_en" label={t('employee.name_en')} {...f} />
          <SelectField name="kind" label={t('site.kind')} options={['work', 'leave'].map((v) => [v, t(`enum.site_kind.${v}`)])} {...f} />
          {f.values.kind === 'leave' && <SelectField name="leave_code" label={t('site.leave_code')} blank options={LEAVE_CODES.map((v) => [v, t(`enum.leave_code.${v}`)])} {...f} />}
          {['outside_alexandria', 'active'].map((k) => (
            <label key={k} className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!f.values[k]} onChange={(e) => f.set(k, e.target.checked ? 'true' : '')} />
              {t(`site.${k}`)}
            </label>
          ))}
        </>
      )}
    </FormDialog>
  )
}

/** Rule history table; mounted only while the dialog is open, so it loads on open. */
function RuleHistory({ siteId }: { siteId: string }) {
  const { t } = useTranslation()
  const money = useMoney()
  const [rules, setRules] = useState<Row[]>([])
  const [err, setErr] = useState<unknown>()
  useEffect(() => {
    api<Row>(`/sites/${siteId}`).then((s) => { setErr(undefined); setRules(s.allowance_rules) }).catch(setErr)
  }, [siteId])
  return (
    <>
      <ErrorBanner err={err} shown={[]} />
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('change.effective_from')}</TableHead>
            <TableHead>{t('site.mode')}</TableHead>
            <TableHead>{t('site.value')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rules.map((r) => (
            <TableRow key={r.id}>
              <TableCell>{r.effective_from}</TableCell>
              <TableCell>{t(`enum.allowance_mode.${r.mode}`)}</TableCell>
              <TableCell>{r.mode === 'none' ? '—' : r.mode === 'share_of_wage' ? `×${r.value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')}` : money(r.value)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </>
  )
}

/** Allowance-rule history for a site, with an add form (a rule applies from its date until the next). */
function RulesDialog({ site }: { site: Row }) {
  const { t } = useTranslation()
  return (
    <FormDialog
      title={`${t('site.allowanceHistory')}: ${site.name_ar}`}
      trigger={<Button type="button" variant="outline">{t('site.allowances')}</Button>}
      init={{ mode: 'fixed' }}
      submit={(form) => api(`/sites/${site.id}/allowance-rules`, { method: 'POST', body: { effective_from: form.effective_from, mode: form.mode, ...(form.mode !== 'none' && { value: form.value }) } })}
      onDone={() => {}}
      shown={['effective_from', 'mode', 'value']}
      footer="site.addRule"
    >
      {(f) => (
        <>
          <RuleHistory siteId={site.id} />
          <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
          <SelectField name="mode" label={t('site.mode')} options={MODES.map((v) => [v, t(`enum.allowance_mode.${v}`)])} {...f} />
          {f.values.mode !== 'none' && <TextField name="value" label={t('site.value')} inputMode="decimal" {...f} />}
        </>
      )}
    </FormDialog>
  )
}

export function SitesPage() {
  const { t } = useTranslation()
  const [kind, setKind] = useState('')
  const [active, setActive] = useState('')
  const { rows, total, err, reload } = useList(`/sites${LIST}${kind && `&kind=${kind}`}${active && `&active=${active}`}`)
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-page-title">{t('page.sites')}</h1>
        <SiteDialog reload={reload} />
      </div>
      <div className="flex flex-wrap gap-3">
        <select aria-label={t('site.kind')} className={`${selectCls} max-w-xs`} value={kind} onChange={(e) => setKind(e.target.value)}>
          <option value="">{t('site.allKinds')}</option>
          {['work', 'leave'].map((v) => <option key={v} value={v}>{t(`enum.site_kind.${v}`)}</option>)}
        </select>
        <select aria-label={t('site.active')} className={`${selectCls} max-w-xs`} value={active} onChange={(e) => setActive(e.target.value)}>
          <option value="">{t('site.allActive')}</option>
          <option value="true">{t('site.active')}</option>
          <option value="false">{t('site.inactive')}</option>
        </select>
      </div>
      <ErrorBanner err={err} shown={[]} />
      <Notice show={total > rows.length} text={t('common.tooMany')} />
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('employee.name_ar')}</TableHead>
            <TableHead>{t('site.kind')}</TableHead>
            <TableHead>{t('site.leave_code')}</TableHead>
            <TableHead>{t('site.active')}</TableHead>
            <TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((s) => (
            <TableRow key={s.id}>
              <TableCell>{s.name_ar}</TableCell>
              <TableCell>{t(`enum.site_kind.${s.kind}`)}</TableCell>
              <TableCell>{s.leave_code ? t(`enum.leave_code.${s.leave_code}`) : '—'}</TableCell>
              <TableCell>{t(s.active ? 'site.yes' : 'site.no')}</TableCell>
              <TableCell>
                <div className="flex gap-2">
                  <SiteDialog site={s} reload={reload} />
                  <RulesDialog site={s} />
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function ResidenceDialog({ residence, reload }: { residence?: Row; reload: () => void }) {
  const { t } = useTranslation()
  return (
    <FormDialog
      title={t(residence ? 'site.edit' : 'residence.add')}
      trigger={<Button type="button" variant={residence ? 'outline' : 'default'}>{t(residence ? 'site.edit' : 'residence.add')}</Button>}
      init={{ name_ar: residence?.name_ar ?? '' }}
      submit={(form) => (residence ? api(`/residences/${residence.id}`, { method: 'PATCH', body: { name_ar: form.name_ar } }) : api('/residences', { method: 'POST', body: { name_ar: form.name_ar } }))}
      onDone={reload}
      shown={['name_ar']}
    >
      {(f) => <TextField name="name_ar" label={t('employee.name_ar')} {...f} />}
    </FormDialog>
  )
}

export function ResidencesPage() {
  const { t } = useTranslation()
  const { rows, total, err, reload } = useList(`/residences${LIST}`)
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-page-title">{t('page.residences')}</h1>
        <ResidenceDialog reload={reload} />
      </div>
      <ErrorBanner err={err} shown={[]} />
      <Notice show={total > rows.length} text={t('common.tooMany')} />
      <Table>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell>{r.name_ar}</TableCell>
              <TableCell>
                <ResidenceDialog residence={r} reload={reload} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

/** Greatest effective_from <= today (Cairo) per residence x site; the list is not date-filtered server-side. */
function inForce(fares: Row[], today: string) {
  const best = new Map<string, Row>()
  for (const f of fares) {
    const k = `${f.residence_id}|${f.site_id}`
    if (f.effective_from <= today && (!best.has(k) || f.effective_from > best.get(k)!.effective_from)) best.set(k, f)
  }
  return best
}

function FareDialog({ residence, site, reload, children }: { residence: Row; site: Row; reload: () => void; children: React.ReactNode }) {
  const { t } = useTranslation()
  return (
    <FormDialog
      title={`${residence.name_ar} / ${site.name_ar}`}
      trigger={<button type="button" className="min-h-9 w-full rounded px-2 text-start hover:bg-muted">{children}</button>}
      init={{}}
      submit={(form) => api('/fares', { method: 'POST', body: { residence_id: residence.id, site_id: site.id, effective_from: form.effective_from, amount: form.amount } })}
      onDone={reload}
      shown={['effective_from', 'amount']}
    >
      {(f) => (
        <>
          <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
          <TextField name="amount" label={t('fare.amount')} inputMode="decimal" {...f} />
        </>
      )}
    </FormDialog>
  )
}

export function FaresPage() {
  const { t } = useTranslation()
  const money = useMoney()
  const [data, setData] = useState<{ sites: Row[]; residences: Row[]; fares: Row[]; over: boolean }>()
  const [err, setErr] = useState<unknown>()
  const load = useCallback(() => {
    Promise.all([apiList<Row>(`/sites${LIST}&kind=work&active=true`), apiList<Row>(`/residences${LIST}`), apiList<Row>(`/fares${LIST}&date=${cairoToday()}`)])
      .then(([s, r, f]) => {
        setErr(undefined)
        setData({ sites: s.data, residences: r.data, fares: f.data, over: [s, r, f].some((x) => x.meta.pagination.total > x.data.length) })
      })
      .catch(setErr)
  }, [])
  useEffect(load, [load])
  const best = inForce(data?.fares ?? [], cairoToday())
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-page-title">{t('page.fares')}</h1>
      <ErrorBanner err={err} shown={[]} />
      {data && (
        <>
          {/* ponytail: one 100-row page of in-force fares; paginate if a site x residence grid ever exceeds it */}
          <Notice show={data.over} text={t('fare.tooMany')} />
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('fare.residence')}</TableHead>
                {data.sites.map((s) => <TableHead key={s.id}>{s.name_ar}</TableHead>)}
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.residences.map((r) => (
                <TableRow key={r.id}>
                  <TableCell>{r.name_ar}</TableCell>
                  {data.sites.map((s) => (
                    <TableCell key={s.id}>
                      <FareDialog residence={r} site={s} reload={load}>
                        {best.has(`${r.id}|${s.id}`) ? money(best.get(`${r.id}|${s.id}`)!.amount) : '—'}
                      </FareDialog>
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </>
      )}
    </div>
  )
}
