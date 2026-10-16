import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, type Row, SelectField, selectCls, TextField, toErrs, useForm, useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, apiList } from '@/lib/api'

const LEAVE_CODES = ['friday', 'official_holiday', 'sick', 'rest', 'absent', 'other']
const MODES = ['none', 'share_of_wage', 'fixed']
const LIST = '?per_page=100' // server max; these lists are small, the notice below covers overflow

/** Loads a list endpoint; `total` lets pages warn when more rows exist than were fetched. */
function useList(path: string) {
  const [rows, setRows] = useState<Row[]>([])
  const [total, setTotal] = useState(0)
  const [err, setErr] = useState<unknown>()
  const reload = useCallback(() => {
    apiList<Row>(path)
      .then((r) => {
        setErr(undefined)
        setRows(r.data)
        setTotal(r.meta.pagination.total)
      })
      .catch(setErr)
  }, [path])
  useEffect(reload, [reload])
  return { rows, total, err, reload }
}

const Notice = ({ show, text }: { show: boolean; text: string }) =>
  show ? (
    <p role="status" className="rounded-md border p-3 text-sm">
      {text}
    </p>
  ) : null

/** One-form dialog: shows the banner + `children`, saves through `submit`, closes on success. */
function FormDialog({ title, trigger, submit, onDone, err, setErr, shown, children, footer = 'common.save' }: {
  title: string
  trigger: React.ReactNode
  submit: () => Promise<unknown>
  onDone: () => void
  err: unknown
  setErr: (e: unknown) => void
  shown: string[]
  children: React.ReactNode
  footer?: string
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  async function go() {
    setErr(undefined)
    try {
      await submit()
      setOpen(false)
      onDone()
    } catch (e) {
      setErr(e)
    }
  }
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <span onClick={() => setOpen(true)}>{trigger}</span>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={shown} />
          {children}
        </DialogBody>
        <DialogFooter>
          <Button type="button" onClick={go}>
            {t(footer)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function SiteDialog({ site, reload }: { site?: Row; reload: () => void }) {
  const { t } = useTranslation()
  const init = { name_ar: site?.name_ar ?? '', name_en: site?.name_en ?? '', kind: site?.kind ?? 'work', leave_code: site?.leave_code ?? '', outside_alexandria: site?.outside_alexandria ? 'true' : '', active: site?.active === false ? '' : 'true' }
  const { form, set } = useForm(init)
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)
  const f = { values: form, set, errs }
  const shown = ['name_ar', 'name_en', 'kind', 'leave_code']
  const body = () => ({
    name_ar: form.name_ar,
    name_en: form.name_en || null,
    kind: form.kind,
    leave_code: form.kind === 'leave' ? form.leave_code || null : null,
    outside_alexandria: !!form.outside_alexandria,
    active: !!form.active,
  })
  const check = (k: string) => (
    <label className="flex items-center gap-2 text-sm">
      <input type="checkbox" checked={!!form[k]} onChange={(e) => set(k, e.target.checked ? 'true' : '')} />
      {t(`site.${k}`)}
    </label>
  )
  return (
    <FormDialog
      title={t(site ? 'site.edit' : 'site.add')}
      trigger={<Button type="button" variant={site ? 'outline' : 'default'}>{t(site ? 'site.edit' : 'site.add')}</Button>}
      submit={() => (site ? api(`/sites/${site.id}`, { method: 'PATCH', body: body() }) : api('/sites', { method: 'POST', body: body() }))}
      onDone={reload}
      err={err}
      setErr={setErr}
      shown={shown}
    >
      <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
      <TextField name="name_en" label={t('employee.name_en')} {...f} />
      <SelectField name="kind" label={t('site.kind')} options={['work', 'leave'].map((v) => [v, t(`enum.site_kind.${v}`)])} {...f} />
      {form.kind === 'leave' && <SelectField name="leave_code" label={t('site.leave_code')} blank options={LEAVE_CODES.map((v) => [v, t(`enum.leave_code.${v}`)])} {...f} />}
      {check('outside_alexandria')}
      {check('active')}
    </FormDialog>
  )
}

/** Allowance-rule history for a site, with an add form (a rule applies from its date until the next). */
function RulesDialog({ site }: { site: Row }) {
  const { t } = useTranslation()
  const money = useMoney()
  const [rules, setRules] = useState<Row[]>([])
  const [loadErr, setLoadErr] = useState<unknown>()
  const { form, set, setForm } = useForm({ mode: 'fixed' })
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)
  const f = { values: form, set, errs }
  const load = useCallback(() => {
    api<Row>(`/sites/${site.id}`).then((s) => { setLoadErr(undefined); setRules(s.allowance_rules) }).catch(setLoadErr)
  }, [site.id])
  useEffect(load, [load])
  const shown = ['effective_from', 'mode', 'value']
  return (
    <FormDialog
      title={`${t('site.allowanceHistory')}: ${site.name_ar}`}
      trigger={<Button type="button" variant="outline">{t('site.allowances')}</Button>}
      submit={() => api(`/sites/${site.id}/allowance-rules`, { method: 'POST', body: { effective_from: form.effective_from, mode: form.mode, ...(form.mode !== 'none' && { value: form.value }) } })}
      onDone={() => { setForm({ mode: 'fixed' }); load() }}
      err={err}
      setErr={setErr}
      shown={shown}
      footer="site.addRule"
    >
      <ErrorBanner err={loadErr} shown={[]} />
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
              <TableCell>{r.mode === 'none' ? '—' : money(r.value)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
      <SelectField name="mode" label={t('site.mode')} options={MODES.map((v) => [v, t(`enum.allowance_mode.${v}`)])} {...f} />
      {form.mode !== 'none' && <TextField name="value" label={t('site.value')} inputMode="decimal" {...f} />}
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
      <Notice show={total > rows.length} text={t('fare.tooMany')} />
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
  const { form, set } = useForm({ name_ar: residence?.name_ar ?? '' })
  const [err, setErr] = useState<unknown>()
  return (
    <FormDialog
      title={t(residence ? 'site.edit' : 'residence.add')}
      trigger={<Button type="button" variant={residence ? 'outline' : 'default'}>{t(residence ? 'site.edit' : 'residence.add')}</Button>}
      submit={() => (residence ? api(`/residences/${residence.id}`, { method: 'PATCH', body: { name_ar: form.name_ar } }) : api('/residences', { method: 'POST', body: { name_ar: form.name_ar } }))}
      onDone={reload}
      err={err}
      setErr={setErr}
      shown={['name_ar']}
    >
      <TextField name="name_ar" label={t('employee.name_ar')} values={form} set={set} errs={toErrs(err)} />
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
      <Notice show={total > rows.length} text={t('fare.tooMany')} />
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
  const { form, set, setForm } = useForm({})
  const [err, setErr] = useState<unknown>()
  return (
    <FormDialog
      title={`${residence.name_ar} / ${site.name_ar}`}
      trigger={<button type="button" className="min-h-9 w-full rounded px-2 text-start hover:bg-muted">{children}</button>}
      submit={() => api('/fares', { method: 'POST', body: { residence_id: residence.id, site_id: site.id, effective_from: form.effective_from, amount: form.amount } })}
      onDone={() => { setForm({}); reload() }}
      err={err}
      setErr={setErr}
      shown={['effective_from', 'amount']}
    >
      <TextField name="effective_from" label={t('change.effective_from')} type="date" values={form} set={set} errs={toErrs(err)} />
      <TextField name="amount" label={t('fare.amount')} inputMode="decimal" values={form} set={set} errs={toErrs(err)} />
    </FormDialog>
  )
}

export function FaresPage() {
  const { t } = useTranslation()
  const money = useMoney()
  const [data, setData] = useState<{ sites: Row[]; residences: Row[]; fares: Row[]; total: number }>()
  const [err, setErr] = useState<unknown>()
  const load = useCallback(() => {
    Promise.all([apiList<Row>(`/sites${LIST}&kind=work&active=true`), apiList<Row>(`/residences${LIST}`), apiList<Row>(`/fares${LIST}`)])
      .then(([s, r, f]) => {
        setErr(undefined)
        setData({ sites: s.data, residences: r.data, fares: f.data, total: f.meta.pagination.total })
      })
      .catch(setErr)
  }, [])
  useEffect(load, [load])
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Cairo' })
  const best = inForce(data?.fares ?? [], today)
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-page-title">{t('page.fares')}</h1>
      <ErrorBanner err={err} shown={[]} />
      {data && (
        <>
          {/* ponytail: one 100-row page; paginating a grid needs a server-side latest-fare endpoint */}
          <Notice show={data.total > data.fares.length} text={t('fare.tooMany')} />
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
