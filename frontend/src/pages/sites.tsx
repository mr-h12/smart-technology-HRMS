import { Bus, CalendarOff, House, MapPin, Plus, type LucideIcon } from 'lucide-react'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/common/EmptyState'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cairoToday, type Controlled, ErrorBanner, FormDialog, type Row, SelectField, TextField, useList, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination, paginate } from '@/components/tables/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, apiList } from '@/lib/api'
import { useAuth } from '@/lib/auth'

const LEAVE_CODES = ['friday', 'official_holiday', 'sick', 'rest', 'absent', 'other']
const MODES = ['none', 'share_of_wage', 'fixed']
const LIST = '?per_page=100' // server max; these lists are small, the notice below covers overflow
const PAGE = 12

const Notice = ({ show, text }: { show: boolean; text: string }) =>
  show ? (
    <p role="status" className="rounded-md border p-3 text-sm">
      {text}
    </p>
  ) : null

/** The card every settings list shares: header with an optional add button, banners, skeleton, table or empty state, pagination. */
function ListCard({ title, description, action, err, loading, empty, icon, notice, page, children }: {
  title: string
  description: string
  action?: ReactNode
  err: unknown
  loading: boolean
  empty: boolean
  icon: LucideIcon
  notice?: ReactNode
  page?: { page: number; total: number; onPageChange: (p: number) => void }
  children: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <div>
          <CardTitle>{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </div>
        {action}
      </CardHeader>
      <div className="space-y-3 px-5 pb-3 empty:hidden">
        <ErrorBanner err={err} shown={[]} />
        {notice}
      </div>
      {loading ? <TableSkeleton /> : empty ? !err && <EmptyState icon={icon} title={t('common.noData')} /> : children}
      {page && page.total > PAGE && <Pagination page={page.page} pageSize={PAGE} total={page.total} onPageChange={page.onPageChange} />}
    </Card>
  )
}

function SiteDialog({ kind, site, reload, ...dialog }: { kind: 'work' | 'leave'; site?: Row; reload: () => void } & Controlled) {
  const { t } = useTranslation()
  const addKey = kind === 'work' ? 'site.add' : 'site.addLeaveType'
  const init = { name_ar: site?.name_ar ?? '', name_en: site?.name_en ?? '', leave_code: site?.leave_code ?? LEAVE_CODES[0], outside_alexandria: site?.outside_alexandria ? 'true' : '', active: site?.active === false ? '' : 'true' }
  const body = (form: Row) => ({
    name_ar: form.name_ar,
    name_en: form.name_en || null,
    kind,
    leave_code: kind === 'leave' ? form.leave_code || null : null,
    outside_alexandria: !!form.outside_alexandria,
    active: !!form.active,
  })
  return (
    <FormDialog
      {...dialog}
      title={t(site ? 'site.edit' : addKey)}
      trigger={site ? undefined : <Button type="button" variant="outline" size="sm"><Plus />{t(addKey)}</Button>}
      init={init}
      submit={(form) => (site ? api(`/sites/${site.id}`, { method: 'PATCH', body: body(form) }) : api('/sites', { method: 'POST', body: body(form) }))}
      onDone={reload}
      shown={['name_ar', 'name_en', 'leave_code']}
    >
      {(f) => (
        <>
          <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
          <TextField name="name_en" label={t('employee.name_en')} {...f} />
          {kind === 'leave' && <SelectField name="leave_code" label={t('site.leave_code')} options={LEAVE_CODES.map((v) => [v, t(`enum.leave_code.${v}`)])} {...f} />}
          {(kind === 'work' ? ['outside_alexandria', 'active'] : ['active']).map((k) => (
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
function RulesDialog({ site, ...dialog }: { site: Row } & Controlled) {
  const { t } = useTranslation()
  return (
    <FormDialog
      {...dialog}
      title={`${t('site.allowanceHistory')}: ${site.name_ar}`}
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

/** Ticks the managers of a site among the active accounts; the body mounts on open, so it loads then. */
function ManagersDialog({ site, open, onOpenChange }: { site: Row; open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{`${t('site.managers')}: ${site.name_ar}`}</DialogTitle>
        </DialogHeader>
        <ManagersBody siteId={site.id} onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function ManagersBody({ siteId, onDone }: { siteId: string; onDone: () => void }) {
  const { t } = useTranslation()
  const [users, setUsers] = useState<Row[]>()
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    Promise.all([api<Row>(`/sites/${siteId}`), apiList<Row>('/users?active=true&per_page=100')])
      .then(([s, u]) => {
        setUsers(u.data)
        setTicked(new Set(s.managers.map((m: Row) => m.id)))
      })
      .catch(setErr)
  }, [siteId])
  const toggle = (id: string, on: boolean) => setTicked((s) => { const n = new Set(s); if (on) n.add(id); else n.delete(id); return n })
  function save() {
    setBusy(true)
    setErr(undefined)
    api(`/sites/${siteId}/managers`, { method: 'PUT', body: { manager_ids: users!.filter((u) => ticked.has(u.id)).map((u) => u.id) } })
      .then(onDone, setErr)
      .finally(() => setBusy(false))
  }
  return (
    <>
      <DialogBody className="grid gap-1">
        <ErrorBanner err={err} shown={[]} />
        {users
          ? users.map((u) => (
              <label key={u.id} className="flex min-h-11 items-center gap-3 rounded-md px-2 text-sm hover:bg-muted">
                <input type="checkbox" checked={ticked.has(u.id)} onChange={(e) => toggle(u.id, e.target.checked)} />
                {u.name}
              </label>
            ))
          : !err && <TableSkeleton rows={3} />}
      </DialogBody>
      <DialogFooter>
        <Button type="button" disabled={busy || !users} onClick={save}>
          {t('common.save')}
        </Button>
      </DialogFooter>
    </>
  )
}

/** Work sites and leave types are both `sites` rows; `kind` picks the list, the columns and the row actions. */
function SiteList({ kind }: { kind: 'work' | 'leave' }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { rows, total, err, loading, reload } = useList(`/sites${LIST}&kind=${kind}`)
  const [page, setPage] = useState(1)
  const [dlg, setDlg] = useState<{ type: 'edit' | 'rules' | 'managers'; row: Row }>()
  const close = { open: true, onOpenChange: () => setDlg(undefined) }
  const work = kind === 'work'
  const actions = (row: Row) => [
    ...(can('site.manage') ? [{ label: t('site.edit'), onSelect: () => setDlg({ type: 'edit', row }) }] : []),
    ...(work && can('site.manage') ? [{ label: t('site.allowances'), onSelect: () => setDlg({ type: 'rules', row }) }] : []),
    ...(work && can('site.assign_manager') ? [{ label: t('site.managers'), onSelect: () => setDlg({ type: 'managers', row }) }] : []),
  ]
  return (
    <ListCard
      title={t(work ? 'settings.tab.sites' : 'settings.tab.leaveTypes')}
      description={t(work ? 'settings.desc.sites' : 'settings.desc.leaveTypes')}
      action={can('site.manage') && <SiteDialog kind={kind} reload={reload} />}
      err={err}
      loading={loading}
      empty={!rows.length}
      icon={work ? MapPin : CalendarOff}
      notice={<Notice show={total > rows.length} text={t('common.tooMany')} />}
      page={{ page, total: rows.length, onPageChange: setPage }}
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('common.name')}</TableHead>
            {work ? <TableHead className="hidden md:table-cell">{t('site.outside_alexandria')}</TableHead> : <TableHead className="hidden sm:table-cell">{t('site.leave_code')}</TableHead>}
            <TableHead>{t('common.status')}</TableHead>
            <TableHead>
              <span className="sr-only">{t('common.actions')}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {paginate(rows, page, PAGE).map((s) => (
            <TableRow key={s.id}>
              <TableCell>
                <div className="font-medium">{s.name_ar}</div>
                {s.name_en && <div className="text-xs text-muted-foreground">{s.name_en}</div>}
              </TableCell>
              {work ? (
                <TableCell className="hidden md:table-cell">{s.outside_alexandria ? <Badge variant="neutral">{t('site.yes')}</Badge> : '—'}</TableCell>
              ) : (
                <TableCell className="hidden sm:table-cell">{s.leave_code ? <Badge variant="neutral">{t(`enum.leave_code.${s.leave_code}`)}</Badge> : '—'}</TableCell>
              )}
              <TableCell>
                <StatusBadge variant={s.active ? 'success' : 'neutral'} label={t(s.active ? 'status.active' : 'status.inactive')} />
              </TableCell>
              <TableCell className="text-end">
                <RowActions items={actions(s)} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {dlg?.type === 'edit' && <SiteDialog kind={kind} site={dlg.row} reload={reload} {...close} />}
      {dlg?.type === 'rules' && <RulesDialog site={dlg.row} {...close} />}
      {dlg?.type === 'managers' && <ManagersDialog site={dlg.row} {...close} />}
    </ListCard>
  )
}

export const SitesTab = () => <SiteList kind="work" />
export const LeaveTypesTab = () => <SiteList kind="leave" />

function ResidenceDialog({ residence, reload, ...dialog }: { residence?: Row; reload: () => void } & Controlled) {
  const { t } = useTranslation()
  return (
    <FormDialog
      {...dialog}
      title={t(residence ? 'site.edit' : 'residence.add')}
      trigger={residence ? undefined : <Button type="button" variant="outline" size="sm"><Plus />{t('residence.add')}</Button>}
      init={{ name_ar: residence?.name_ar ?? '' }}
      submit={(form) => (residence ? api(`/residences/${residence.id}`, { method: 'PATCH', body: { name_ar: form.name_ar } }) : api('/residences', { method: 'POST', body: { name_ar: form.name_ar } }))}
      onDone={reload}
      shown={['name_ar']}
    >
      {(f) => <TextField name="name_ar" label={t('employee.name_ar')} {...f} />}
    </FormDialog>
  )
}

export function ResidencesTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { rows, total, err, loading, reload } = useList(`/residences${LIST}`)
  const [page, setPage] = useState(1)
  const [edit, setEdit] = useState<Row>()
  return (
    <ListCard
      title={t('settings.tab.residences')}
      description={t('settings.desc.residences')}
      action={can('site.manage') && <ResidenceDialog reload={reload} />}
      err={err}
      loading={loading}
      empty={!rows.length}
      icon={House}
      notice={<Notice show={total > rows.length} text={t('common.tooMany')} />}
      page={{ page, total: rows.length, onPageChange: setPage }}
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('common.name')}</TableHead>
            <TableHead>
              <span className="sr-only">{t('common.actions')}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {paginate(rows, page, PAGE).map((r) => (
            <TableRow key={r.id}>
              <TableCell className="font-medium">{r.name_ar}</TableCell>
              <TableCell className="text-end">
                <RowActions items={can('site.manage') ? [{ label: t('site.edit'), onSelect: () => setEdit(r) }] : []} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {edit && <ResidenceDialog residence={edit} reload={reload} open onOpenChange={() => setEdit(undefined)} />}
    </ListCard>
  )
}

/** Fares keyed by residence x site. `?date=` already makes the server send only the row in force per pair; the date check here is a redundant guard. */
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
      trigger={<Button type="button" variant="soft" size="sm" className="min-w-20 tabular-nums">{children}</Button>}
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

export function FaresTab() {
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
    <ListCard
      title={t('settings.tab.fares')}
      description={t('settings.desc.fares')}
      err={err}
      loading={!data && !err}
      empty={!!data && (!data.sites.length || !data.residences.length)}
      icon={Bus}
      // ponytail: one 100-row page of in-force fares; paginate if a site x residence grid ever exceeds it
      notice={<Notice show={!!data?.over} text={t('fare.tooMany')} />}
    >
      {data && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="sticky start-0 z-10 bg-muted">{t('fare.residence')}</TableHead>
              {data.sites.map((s) => <TableHead key={s.id}>{s.name_ar}</TableHead>)}
            </TableRow>
          </TableHeader>
          <TableBody>
            {data.residences.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="sticky start-0 z-10 bg-card font-medium">{r.name_ar}</TableCell>
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
      )}
    </ListCard>
  )
}
