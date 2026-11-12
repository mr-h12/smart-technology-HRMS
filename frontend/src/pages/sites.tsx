import { Bus, CalendarOff, HardHat, House, MapPin, Plus, type LucideIcon } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { DataList } from '@/components/common/DataList'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect } from '@/components/common/Filters'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { accountLabel, cairoToday, type Controlled, ErrorBanner, FormDialog, ltrDates, type Row, SelectField, TextField, useList, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination, paginate } from '@/components/tables/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { RESIDENCES, SITES, useApi, useSave } from '@/lib/query'

const LEAVE_CODES = ['friday', 'saturday', 'official_holiday', 'annual', 'casual', 'sick', 'unpaid', 'rest', 'absent', 'other']
const MODES = ['none', 'share_of_wage', 'fixed']
const LIST = '?per_page=100' // server max; these lists are small, the notice below covers overflow
export const PAGE = 12

export const Notice = ({ show, text }: { show: boolean; text: string }) =>
  show ? (
    <p role="status" className="rounded-md border p-3 text-sm">
      {text}
    </p>
  ) : null

/** The card every settings list shares: header with an optional add button, banners, skeleton, table or empty state, pagination. */
export function ListCard({ title, description, action, err, loading, empty, icon, notice, page, children }: {
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

function SiteDialog({ kind, site, ...dialog }: { kind: 'work' | 'leave'; site?: Row } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
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
      submit={(form) => (site ? send(`/sites/${site.id}`, { method: 'PATCH', body: body(form) }) : send('/sites', { body: body(form) }))}
      onDone={() => {}}
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
  const { data: site, err } = useApi<Row>(`/sites/${siteId}`)
  const rules: Row[] = site?.allowance_rules ?? []
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
              <TableCell>{ltrDates(r.effective_from)}</TableCell>
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
  const send = useSave()
  return (
    <FormDialog
      {...dialog}
      title={`${t('site.allowanceHistory')}: ${site.name_ar}`}
      init={{ mode: 'fixed' }}
      submit={(form) => send(`/sites/${site.id}/allowance-rules`, { body: { effective_from: form.effective_from, mode: form.mode, ...(form.mode !== 'none' && { value: form.value }) } })}
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
  const site = useApi<Row>(`/sites/${siteId}`, { fresh: true }) // the ticks start from the site as it is now, never from the cache
  const users = useApi<Row[]>('/sites/manager-candidates') // only the active accounts whose role sees its own sites
  const err = site.err ?? users.err
  return site.ready && site.data && users.data ? (
    <ManagersForm siteId={siteId} managerIds={site.data.managers.map((m: Row) => m.id)} users={users.data} loadErr={err} onDone={onDone} />
  ) : (
    <>
      <DialogBody className="grid gap-1">
        <ErrorBanner err={err} shown={[]} />
        {!err && <TableSkeleton rows={3} />}
      </DialogBody>
      <DialogFooter>
        <Button type="button" disabled>
          {t('common.save')}
        </Button>
      </DialogFooter>
    </>
  )
}

/** The ticks start from the site's current managers, then belong to the user. */
function ManagersForm({ siteId, managerIds, users, loadErr, onDone }: { siteId: string; managerIds: string[]; users: Row[]; loadErr?: unknown; onDone: () => void }) {
  const { t } = useTranslation()
  const send = useSave()
  const [ticked, setTicked] = useState(() => new Set(managerIds))
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const toggle = (id: string, on: boolean) => setTicked((s) => { const n = new Set(s); if (on) n.add(id); else n.delete(id); return n })
  function save() {
    setBusy(true)
    setErr(undefined)
    send(`/sites/${siteId}/managers`, { method: 'PUT', body: { manager_ids: users.filter((u) => ticked.has(u.id)).map((u) => u.id) } })
      .then(onDone, setErr)
      .finally(() => setBusy(false))
  }
  return (
    <>
      <DialogBody className="grid gap-1">
        <ErrorBanner err={loadErr} shown={[]} />
        <ErrorBanner err={err} shown={[]} />
        {users.map((u) => (
          <label key={u.id} className="flex min-h-11 items-center gap-3 rounded-md px-2 text-sm hover:bg-muted">
            <input type="checkbox" checked={ticked.has(u.id)} onChange={(e) => toggle(u.id, e.target.checked)} />
            <span className="flex min-w-0 flex-col items-start">
              {u.name}{' '}
              <span dir="ltr" className="text-[13px] break-all text-muted-foreground">{u.email}</span>
            </span>
          </label>
        ))}
      </DialogBody>
      <DialogFooter>
        <Button type="button" disabled={busy} onClick={save}>
          {t('common.save')}
        </Button>
      </DialogFooter>
    </>
  )
}

/** The workers whose home site is this site today; mounted only while open, so the list loads on open. */
function WorkersDialog({ site, open, onOpenChange }: { site: Row; open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation()
  const { rows, total, err, loading } = useList(`/employees?site_id=${site.id}&per_page=100`)
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{`${t('site.workers')}: ${site.name_ar}`}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-3">
          <ErrorBanner err={err} shown={[]} />
          <Notice show={total > rows.length} text={t('common.tooMany')} />
          {loading ? (
            <TableSkeleton rows={3} />
          ) : !rows.length ? (
            !err && <EmptyState icon={HardHat} title={t('site.workersNone')} />
          ) : (
            <ul className="divide-y">
              {rows.map((e) => (
                <li key={e.id} className="flex min-h-11 items-center justify-between gap-3 py-2 text-sm">
                  <span className="font-medium">{e.name_ar}</span>
                  {e.current_type && <Badge variant="neutral">{t(`enum.type.${e.current_type}`)}</Badge>}
                </li>
              ))}
            </ul>
          )}
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}

/** Work sites and leave types are both `sites` rows; `kind` picks the list, the columns and the row actions. */
function SiteList({ kind }: { kind: 'work' | 'leave' }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { rows, total, err, loading } = useList(`/sites${LIST}&kind=${kind}`)
  const [page, setPage] = useState(1)
  const [dlg, setDlg] = useState<{ type: 'edit' | 'rules' | 'managers' | 'workers'; row: Row }>()
  const close = { open: true, onOpenChange: () => setDlg(undefined) }
  const work = kind === 'work'
  const actions = (row: Row) => [
    ...(can('site.manage') ? [{ label: t('site.edit'), onSelect: () => setDlg({ type: 'edit', row }) }] : []),
    ...(work && can('site.manage') ? [{ label: t('site.allowances'), onSelect: () => setDlg({ type: 'rules', row }) }] : []),
    ...(work && can('site.assign_manager') ? [{ label: t('site.managers'), onSelect: () => setDlg({ type: 'managers', row }) }] : []),
    ...(work && can('employee.view') ? [{ label: t('site.workers'), onSelect: () => setDlg({ type: 'workers', row }) }] : []),
  ]
  return (
    <ListCard
      title={t(work ? 'settings.tab.sites' : 'settings.tab.leaveTypes')}
      description={t(work ? 'settings.desc.sites' : 'settings.desc.leaveTypes')}
      action={can('site.manage') && <SiteDialog kind={kind} />}
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
      {dlg?.type === 'edit' && <SiteDialog kind={kind} site={dlg.row} {...close} />}
      {dlg?.type === 'rules' && <RulesDialog site={dlg.row} {...close} />}
      {dlg?.type === 'managers' && <ManagersDialog site={dlg.row} {...close} />}
      {dlg?.type === 'workers' && <WorkersDialog site={dlg.row} {...close} />}
    </ListCard>
  )
}

export const SitesTab = () => <SiteList kind="work" />
export const LeaveTypesTab = () => <SiteList kind="leave" />

export function ResidenceDialog({ residence, onDone, ...dialog }: { residence?: Row; onDone?: (saved: Row) => void } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  return (
    <FormDialog
      {...dialog}
      title={t(residence ? 'site.edit' : 'residence.add')}
      trigger={residence ? undefined : <Button type="button" variant="outline" size="sm"><Plus />{t('residence.add')}</Button>}
      init={{ name_ar: residence?.name_ar ?? '' }}
      submit={(form) => (residence ? send(`/residences/${residence.id}`, { method: 'PATCH', body: { name_ar: form.name_ar } }) : send('/residences', { body: { name_ar: form.name_ar } }))}
      onDone={onDone}
      shown={['name_ar']}
    >
      {(f) => <TextField name="name_ar" label={t('employee.name_ar')} {...f} />}
    </FormDialog>
  )
}

export function ResidencesTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { rows, total, err, loading } = useList(RESIDENCES)
  const [page, setPage] = useState(1)
  const [edit, setEdit] = useState<Row>()
  return (
    <ListCard
      title={t('settings.tab.residences')}
      description={t('settings.desc.residences')}
      action={can('site.manage') && <ResidenceDialog />}
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
      {edit && <ResidenceDialog residence={edit} open onOpenChange={() => setEdit(undefined)} />}
    </ListCard>
  )
}

function FareDialog({ residence, site, ...dialog }: { residence?: Row; site?: Row } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  const { rows: sites } = useList(SITES)
  const { rows: residences } = useList(RESIDENCES)
  const pick = !residence || !site
  return (
    <FormDialog
      {...dialog}
      title={pick ? t('fare.add') : t('fare.pairTitle', { residence: residence.name_ar, site: site.name_ar })}
      trigger={pick ? <Button type="button" variant="outline" size="sm"><Plus />{t('fare.add')}</Button> : undefined}
      init={{ effective_from: cairoToday(), residence_id: residence?.id ?? '', site_id: site?.id ?? '' }}
      submit={(form) => send('/fares', { body: form }, ['/fares', '/team-fares'])}
      shown={['residence_id', 'site_id', 'effective_from', 'amount']}
    >
      {(f) => (
        <>
          {pick && (
            <>
              <SelectField name="residence_id" label={t('teamFare.col.residence')} blank options={residences.map((r) => [r.id, r.name_ar])} {...f} />
              <SelectField name="site_id" label={t('fare.site')} blank options={sites.filter((x) => x.kind === 'work' && x.active).map((x) => [x.id, x.name_ar])} {...f} />
            </>
          )}
          <TextField name="amount" label={t('teamFare.amount')} inputMode="decimal" {...f} />
          <TextField name="effective_from" label={t('teamFare.from')} type="date" {...f} />
        </>
      )}
    </FormDialog>
  )
}

/** Every residence × site fare in force today, with who set it (mockup 3a). Editing needs fare.manage. */
export function FaresTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const money = useMoney()
  const today = cairoToday()
  const manage = can('fare.manage')
  const [lead, setLead] = useState('')
  const [page, setPage] = useState(1)
  const [edit, setEdit] = useState<Row>()
  const query = new URLSearchParams({ per_page: '100', date: today, ...(lead && { manager_id: lead }) })
  const fares = useList(`/fares?${query}`)
  const sites = useList(SITES)
  const residences = useList(RESIDENCES)
  const leads = useApi<Row[]>(can('site.assign_manager') ? '/sites/manager-candidates' : null)
  const err = fares.err ?? sites.err ?? residences.err ?? leads.err
  const name = (list: Row[], id: string) => list.find((x) => x.id === id)?.name_ar ?? ''
  return (
    <ListCard
      title={t('fare.combinedTitle')}
      description={t(manage ? 'fare.combinedDesc' : 'fare.combinedDescView')}
      action={manage && <FareDialog />}
      err={err}
      loading={fares.loading}
      empty={!fares.rows.length}
      icon={Bus}
      notice={
        <>
          <div className="flex flex-wrap items-center gap-2">
            {/* as wide as its longest name and email: a closed native select clips its label, and namesakes differ only by the email */}
            {leads.data && <FilterSelect value={lead} onChange={(v) => { setLead(v); setPage(1) }} label={t('fare.lead')} allLabel={t('fare.allLeads')} options={leads.data.map((m) => [m.id, accountLabel(m)])} className="sm:w-auto sm:max-w-md" />}
          </div>
          <Notice show={fares.total > fares.rows.length} text={t('fare.tooMany')} />
        </>
      }
      page={{ page, total: fares.rows.length, onPageChange: setPage }}
    >
      <DataList
        label={t('fare.combinedTitle')}
        rows={paginate(fares.rows, page, PAGE)}
        rowKey={(f) => f.id}
        title={{ header: t('fare.site'), cell: (f) => <span className="font-medium">{name(sites.rows, f.site_id)}</span> }}
        fields={[
          { header: t('teamFare.col.residence'), cell: (f) => name(residences.rows, f.residence_id) },
          {
            header: t('teamFare.amount'),
            numeric: true,
            cell: (f) => money(f.amount),
          },
          { header: t('teamFare.from'), cell: (f) => ltrDates(f.effective_from) },
          { header: t('fare.setBy'), cell: (f) => (f.set_by ? <bdi>{f.set_by.name}</bdi> : <Badge variant="neutral">{t('fare.fromExcel')}</Badge>) },
        ]}
        actions={
          manage
            ? (f) => (
                <Button type="button" variant="outline" size="sm" onClick={() => setEdit(f)}>
                  {t('fare.edit')}
                </Button>
              )
            : undefined
        }
      />
      {edit && <FareDialog residence={{ id: edit.residence_id, name_ar: name(residences.rows, edit.residence_id) }} site={{ id: edit.site_id, name_ar: name(sites.rows, edit.site_id) }} open onOpenChange={() => setEdit(undefined)} />}
    </ListCard>
  )
}
