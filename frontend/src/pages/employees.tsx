import { Briefcase, CalendarDays, FileText, HardHat, History as HistoryIcon, IdCard, type LucideIcon, UserCog, UserPlus, Users, Wrench } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { MiniStat, type Tone } from '@/components/cards/StatCard'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { UserAvatar } from '@/components/common/UserAvatar'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cairoToday, ErrorBanner, Field, type Errs, FormDialog, type FormApi, formatTime, type Row, SelectField, stopAtMissingPeriod, TextField, toErrs, useForm, useMoney } from '@/components/form'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { RESIDENCES, USERS, useApi, useList, useSave } from '@/lib/query'

const TYPES = ['office', 'engineer', 'permanent_worker', 'day_laborer']
const GENDERS = ['male', 'female']
const PAY_METHODS = ['cash', 'bank']
const DOC_KINDS = ['national_id_copy', 'work_stub', 'criminal_record', 'qualification', 'birth_certificate', 'insurance_form_6', 'photos', 'military_status', 'other']
const IDENTITY = ['name_ar', 'name_en', 'fingerprint_name', 'gender', 'national_id', 'insurance_no', 'phone', 'address', 'department', 'residence_id', 'pay_method', 'bank_account', 'hire_date', 'contract_end_date', 'termination_date', 'termination_reason', 'hr_notes']
const PAY = ['salary', 'raise', 'insured_basic', 'daily_wage', 'shift_start', 'shift_end']


/** Fields PayFields renders for a type; also what create/dialog submit. */
const payFields = (type: string) => [...(type === 'day_laborer' ? ['daily_wage'] : ['salary', 'raise', 'insured_basic']), 'shift_start', 'shift_end']


/** Pay inputs; day laborers are paid a daily wage, everyone else a monthly salary. */
function PayFields({ type, values, set, errs }: { type: string; values: Row; set: (k: string, v: string) => void; errs: Errs }) {
  const { t } = useTranslation()
  const money = (name: string) => (
    <TextField key={name} name={name} label={t(`pay.${name}`)} values={values} set={set} errs={errs} inputMode="decimal" />
  )
  return (
    <>
      {payFields(type).filter((n) => !n.startsWith('shift')).map(money)}
      <TextField name="shift_start" label={t('pay.shift_start')} type="time" values={values} set={set} errs={errs} />
      <TextField name="shift_end" label={t('pay.shift_end')} type="time" values={values} set={set} errs={errs} />
    </>
  )
}

const clean = (o: Row, keys: string[]) => Object.fromEntries(keys.filter((k) => o[k] !== undefined && o[k] !== '').map((k) => [k, o[k]]))

const PAGE = 12
const STATS: [string, LucideIcon, Tone][] = [
  ['total', Users, 'indigo'],
  ['office', Briefcase, 'sky'],
  ['engineer', HardHat, 'amber'],
  ['permanent_worker', Wrench, 'emerald'],
  ['day_laborer', CalendarDays, 'slate'],
]

export function EmployeesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250) // the box stays instant; the request waits for a pause in typing
  const qs = new URLSearchParams({ page: String(page), per_page: String(PAGE), ...(search && { search }), ...(type && { type }) })
  const { rows, total, err, loading } = useList(`/employees?${qs}`)
  const { data: counts } = useApi<Record<string, number>>('/employees/summary') // a failed tile stays at —; the list shows the banner

  const filter = (set: (v: string) => void) => (v: string) => {
    set(v)
    setPage(1)
  }
  const clear = () => {
    setQ('')
    setType('')
    setPage(1)
  }
  const today = cairoToday()
  const open = (id: string) => navigate(`/employees/${id}`)

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('page.employees')}
        description={t('employee.subtitle')}
        actions={
          can('employee.create') && (
            <Button asChild>
              <Link to="/employees/new">
                <UserPlus />
                {t('employee.add')}
              </Link>
            </Button>
          )
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        {STATS.map(([k, icon, tone]) => (
          <MiniStat key={k} label={t(`employee.stat.${k}`)} value={counts?.[k] ?? '—'} icon={icon} tone={tone} />
        ))}
      </div>

      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="flex flex-col gap-3 xl:flex-row xl:items-center">
            <SearchInput value={q} onChange={filter(setQ)} placeholder={t('employee.search')} className="sm:w-full sm:max-w-md" />
            <FilterSelect value={type} onChange={filter(setType)} label={t('employee.type')} allLabel={t('employee.allTypes')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} />
            {(q || type) && (
              <Button variant="ghost" size="sm" onClick={clear} className="xl:ms-auto">
                {t('common.clearFilters')}
              </Button>
            )}
          </div>
          <ErrorBanner err={err} shown={[]} />
        </div>

        {loading ? (
          <TableSkeleton rows={8} />
        ) : !rows.length ? (
          !err && <EmptyState icon={Users} title={t('common.noResults')} />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('employee.employee')}</TableHead>
                <TableHead className="hidden sm:table-cell">{t('employee.code')}</TableHead>
                <TableHead className="hidden sm:table-cell">{t('employee.type')}</TableHead>
                <TableHead className="hidden md:table-cell">{t('employee.department')}</TableHead>
                <TableHead className="hidden xl:table-cell">{t('employee.hire_date')}</TableHead>
                <TableHead>{t('employee.status')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((e) => {
                const left = e.termination_date && e.termination_date <= today
                const badge = e.current_type && <Badge variant="info">{t(`enum.type.${e.current_type}`)}</Badge>
                return (
                  <TableRow
                    key={e.id}
                    tabIndex={0}
                    className="cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    onClick={() => open(e.id)}
                    onKeyDown={(ev) => ev.key === 'Enter' && open(e.id)}
                  >
                    <TableCell className="whitespace-normal">
                      <div className="flex items-center gap-3">
                        <UserAvatar name={e.name_ar} />
                        <div className="min-w-0">
                          <div className="font-medium">{e.name_ar}</div>
                          {e.name_en && <div className="text-xs text-muted-foreground">{e.name_en}</div>}
                          {badge && <div className="mt-1 sm:hidden">{badge}</div>}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="hidden font-mono text-xs text-muted-foreground sm:table-cell">{e.code}</TableCell>
                    <TableCell className="hidden sm:table-cell">{badge || '—'}</TableCell>
                    <TableCell className="hidden md:table-cell">{e.department ?? '—'}</TableCell>
                    <TableCell className="hidden text-muted-foreground xl:table-cell">{e.hire_date ?? '—'}</TableCell>
                    <TableCell>
                      <StatusBadge variant={left ? 'neutral' : 'success'} label={t(left ? 'status.inactive' : 'status.active')} />
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
        {total > 0 && <Pagination page={page} pageSize={PAGE} total={total} onPageChange={setPage} />}
      </Card>
    </div>
  )
}

const TABS: [string, LucideIcon][] = [
  ['profile', IdCard],
  ['manager', UserCog],
  ['history', HistoryIcon],
  ['documents', FileText],
]

/** One element for `employees/new` and `employees/:id`: keyed by the id so opening another employee starts a fresh form. */
export function EmployeeRoute() {
  const { id } = useParams()
  return <EmployeeFormPage key={id ?? 'new'} />
}

/**
 * Create (no id) or detail: loads the record, then shows the form. The form starts only from a record fetched since this page
 * opened (never the cache), and once open it stays open: a failed refetch shows the banner above it and keeps what was typed.
 */
export function EmployeeFormPage() {
  const { id } = useParams()
  const { data: emp, err, ready } = useApi<Row>(id ? `/employees/${id}` : null, { fresh: true })
  if (id && !(ready && emp)) return <div className="mx-auto max-w-5xl space-y-6">{err ? <ErrorBanner err={err} shown={[]} /> : <TableSkeleton rows={6} />}</div>
  return <EmployeeForm emp={emp} loadErr={err} />
}

/** Both render the same profile form; detail adds a header card and tabs synced to `?tab=`. The form starts from `emp` and is the user's after that. */
function EmployeeForm({ emp, loadErr }: { emp?: Row; loadErr?: unknown }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const id: string | undefined = emp?.id
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const { form, set } = useForm({ gender: 'male', pay_method: 'cash', type: 'office', ...(emp && Object.fromEntries(IDENTITY.map((k) => [k, emp[k] ?? '']))) })
  const { data: residences = [], err: residencesErr } = useApi<Row[]>(RESIDENCES)
  const send = useSave() // refreshes every employees list and summary, and the detail
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)
  // An own-scoped (manager) record comes without the sensitive identity and pay fields; never render or send what was not received.
  const has = (k: string) => !emp || k in emp
  const editable = can(id ? 'employee.edit' : 'employee.create')

  async function save(ev: React.FormEvent<HTMLFormElement>) {
    ev.preventDefault()
    if (stopAtMissingPeriod(ev.currentTarget)) return
    setErr(undefined)
    try {
      if (id) {
        // PATCH: blank clears a nullable field; name_ar/gender/pay_method must stay set
        const body = Object.fromEntries(IDENTITY.filter(has).map((k) => [k, form[k] === '' && !['name_ar', 'gender', 'pay_method'].includes(k) ? null : form[k]]))
        await send(`/employees/${id}`, { method: 'PATCH', body })
      } else {
        const e = await send<Row>('/employees', { body: clean(form, [...IDENTITY, ...payFields(form.type), 'type', 'effective_from']) })
        navigate(`/employees/${e.id}`)
      }
    } catch (e) {
      setErr(e)
    }
  }

  const f = { values: form, set, errs }
  const shown = [...IDENTITY.filter(has), ...(id ? [] : [...payFields(form.type), 'type', 'effective_from'])]
  const section = (title: string, fields: React.ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t(title)}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">{fields}</CardContent>
    </Card>
  )
  const profile = (
    <form onSubmit={save} className="space-y-6">
      <ErrorBanner err={err ?? residencesErr} shown={shown} />
      <fieldset disabled={!editable} className="min-w-0 space-y-6">
        {section('employee.identity', (
          <>
            <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
            <TextField name="name_en" label={t('employee.name_en')} {...f} />
            <TextField name="fingerprint_name" label={t('employee.fingerprint_name')} {...f} />
            <SelectField name="gender" label={t('employee.gender')} options={GENDERS.map((v) => [v, t(`enum.gender.${v}`)])} {...f} />
            {has('national_id') && <TextField name="national_id" label={t('employee.national_id')} inputMode="numeric" {...f} />}
            {has('insurance_no') && <TextField name="insurance_no" label={t('employee.insurance_no')} {...f} />}
            <TextField name="phone" label={t('employee.phone')} {...f} />
            <TextField name="address" label={t('employee.address')} {...f} />
          </>
        ))}
        {section('employee.placement', (
          <>
            <TextField name="department" label={t('employee.department')} {...f} />
            <SelectField name="residence_id" label={t('employee.residence')} blank options={residences.map((r) => [r.id, r.name_ar])} {...f} />
            <TextField name="hire_date" label={t('employee.hire_date')} type="date" {...f} />
            <TextField name="contract_end_date" label={t('employee.contract_end_date')} type="date" {...f} />
            <TextField name="termination_date" label={t('employee.termination_date')} type="date" {...f} />
            <TextField name="termination_reason" label={t('employee.termination_reason')} {...f} />
            {has('hr_notes') && (
              <Field name="hr_notes" label={t('employee.hr_notes')} errs={errs}>
                {(p) => <Textarea {...p} value={form.hr_notes ?? ''} onChange={(e) => set('hr_notes', e.target.value)} />}
              </Field>
            )}
          </>
        ))}
        {has('bank_account') && section('employee.pay', (
          <>
            <SelectField name="pay_method" label={t('employee.pay_method')} options={PAY_METHODS.map((v) => [v, t(`enum.pay_method.${v}`)])} {...f} />
            <TextField name="bank_account" label={t('employee.bank_account')} {...f} />
            {!id && (
              <>
                <SelectField name="type" label={t('employee.type')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} {...f} />
                <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
                <PayFields type={form.type} {...f} />
              </>
            )}
          </>
        ))}
      </fieldset>
      {editable && (
        <div className="flex justify-end">
          <Button type="submit">{t('common.save')}</Button>
        </div>
      )}
    </form>
  )

  if (!emp)
    return (
      <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader title={t('employee.add')} />
        {profile}
      </div>
    )

  const tab = TABS.some(([v]) => v === params.get('tab')) ? params.get('tab')! : 'profile'
  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <ErrorBanner err={loadErr} shown={[]} />
      <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
        <UserAvatar name={emp.name_ar} size="xl" />
        <div className="min-w-0 flex-1 space-y-1">
          <h1 className="text-page-title">{emp.name_ar}</h1>
          {emp.name_en && <p className="text-sm text-muted-foreground">{emp.name_en}</p>}
          {emp.code != null && (
            <p className="text-xs text-muted-foreground">
              {t('employee.code')}: <span className="font-mono">{emp.code}</span>
            </p>
          )}
        </div>
        {emp.current_type && <Badge variant="info" className="self-start sm:self-center">{t(`enum.type.${emp.current_type}`)}</Badge>}
        <dl className="flex gap-2 text-sm">
          <dt className="text-muted-foreground">{t('manager.title')}</dt>
          <dd>{emp.current_manager?.name ?? t('manager.none')}</dd>
        </dl>
        {can('employee.assign_manager') && <AssignManagerDialog empId={emp.id} />}
      </Card>

      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList>
          {TABS.map(([v, Icon]) => (
            <TabsTrigger key={v} value={v}>
              <Icon className="hidden size-4 sm:block" aria-hidden /> {t(`employee.tab.${v}`)}
            </TabsTrigger>
          ))}
        </TabsList>
        {/* Only the active panel is mounted: a hidden panel stays labelled by its trigger, so «المدير» would also name the manager panel. */}
        <TabsContent key={tab} value={tab}>
          {{
            profile,
            manager: <ManagerHistory emp={emp} />,
            history: <History emp={emp} />,
            documents: <Documents emp={emp} />,
          }[tab]}
        </TabsContent>
      </Tabs>
    </div>
  )
}

/** Posts a dated manager assignment; the users list loads when the dialog opens (its body mounts then). */
function AssignManagerDialog({ empId }: { empId: string }) {
  const { t } = useTranslation()
  const send = useSave()
  return (
    <FormDialog
      title={t('manager.assign')}
      trigger={
        <Button type="button" variant="outline">
          <UserCog />
          {t('manager.assign')}
        </Button>
      }
      init={{ manager_id: '', effective_from: '' }}
      submit={(form) => send(`/employees/${empId}/manager-assignments`, { body: { manager_id: form.manager_id, effective_from: form.effective_from } })}
      onDone={() => {}}
      shown={['manager_id', 'effective_from']}
    >
      {(f) => (
        <>
          <ManagerSelect {...f} />
          <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
        </>
      )}
    </FormDialog>
  )
}

function ManagerSelect(f: FormApi) {
  const { t } = useTranslation()
  const { data: users = [], err } = useApi<Row[]>(USERS) // shared with the accounts list and the audit filter
  return (
    <>
      <ErrorBanner err={err} shown={[]} />
      <SelectField name="manager_id" label={t('manager.title')} blank options={users.filter((u) => u.is_active).map((u) => [u.id, u.name])} {...f} />
    </>
  )
}

function ManagerHistory({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const rows: Row[] = [...emp.manager_assignments].reverse() // the API sends oldest first
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>{t('manager.history')}</CardTitle>
      </CardHeader>
      {rows.length ? (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('manager.title')}</TableHead>
              <TableHead>{t('change.effective_from')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((m) => (
              <TableRow key={m.id}>
                <TableCell className="font-medium">{m.manager_name ?? '—'}</TableCell>
                <TableCell>{m.effective_from}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      ) : (
        <EmptyState icon={UserCog} title={t('manager.none')} />
      )}
    </Card>
  )
}

function History({ emp }: { emp: Row }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const money = useMoney()
  return (
    <div className="space-y-6">
      <Card className="overflow-hidden">
        <CardHeader>
          <CardTitle>{t('history.types')}</CardTitle>
          {can('employee.edit') && <ChangeDialog kind="type" emp={emp} />}
        </CardHeader>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('change.effective_from')}</TableHead>
              <TableHead>{t('employee.type')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {emp.type_changes.map((c: Row) => (
              <TableRow key={c.id}>
                <TableCell>{c.effective_from}</TableCell>
                <TableCell>{t(`enum.type.${c.type}`)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
      {emp.pay_changes && (
        <Card className="overflow-hidden">
          <CardHeader>
            <CardTitle>{t('history.pay')}</CardTitle>
            {can('employee.edit') && <ChangeDialog kind="pay" emp={emp} />}
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                {['effective_from', ...PAY].map((c) => (
                  <TableHead key={c}>{t(c === 'effective_from' ? 'change.effective_from' : `pay.${c}`)}</TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {emp.pay_changes.map((c: Row) => (
                <TableRow key={c.id}>
                  <TableCell>{c.effective_from}</TableCell>
                  {['salary', 'raise', 'insured_basic', 'daily_wage'].map((k) => (
                    <TableCell key={k}>{money(c[k])}</TableCell>
                  ))}
                  <TableCell>{formatTime(c.shift_start, i18n.language)}</TableCell>
                  <TableCell>{formatTime(c.shift_end, i18n.language)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  )
}

/** Type in force on a date: greatest effective_from <= date, else the first change, else the current type. */
const typeOn = (emp: Row, date: string): string => {
  const cs: Row[] = [...(emp.type_changes ?? [])].sort((x, y) => x.effective_from.localeCompare(y.effective_from))
  return (date && cs.filter((c) => c.effective_from <= date).at(-1)?.type) || cs[0]?.type || emp.current_type || ''
}

/** "Add change" dialog: a type change, or a pay change shaped by the type in force on the chosen date. */
function ChangeDialog({ kind, emp }: { kind: 'type' | 'pay'; emp: Row }) {
  const { t } = useTranslation()
  const send = useSave()
  const [open, setOpen] = useState(false)
  const { form, set, setForm } = useForm({})
  const [err, setErr] = useState<unknown>()
  const [sameType, setSameType] = useState(false)
  const errs = { ...toErrs(err), ...(sameType ? { type: t('change.sameType') } : {}) }
  const f = { values: form, set: (k: string, v: string) => { setSameType(false); set(k, v) }, errs }
  const inForce = typeOn(emp, form.effective_from ?? '')
  const shown = ['effective_from', ...(kind === 'type' ? ['type'] : payFields(inForce))]

  async function submit(e: React.MouseEvent<HTMLElement>) {
    if (stopAtMissingPeriod(e.currentTarget)) return
    setErr(undefined)
    if (kind === 'type' && form.type === inForce) return setSameType(true)
    try {
      await send(`/employees/${emp.id}/${kind}-changes`, { body: clean(form, shown) })
      setOpen(false)
      setForm({})
    } catch (e) {
      setErr(e)
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)}>
        {t('change.add')}
      </Button>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t(kind === 'type' ? 'history.types' : 'history.pay')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          <ErrorBanner err={err} shown={shown} />
          <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
          {kind === 'type' ? (
            <SelectField name="type" label={t('employee.type')} blank options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} {...f} />
          ) : (
            <PayFields type={inForce} {...f} />
          )}
        </DialogBody>
        <DialogFooter>
          <Button type="button" onClick={submit}>
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** One row per document kind; its dialog PUTs and the page shows the record the API returns. */
function Documents({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const send = useSave()
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>{t('history.documents')}</CardTitle>
      </CardHeader>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('document.kind')}</TableHead>
            <TableHead>{t('document.received_at')}</TableHead>
            <TableHead className="hidden md:table-cell">{t('document.expires_at')}</TableHead>
            <TableHead className="hidden lg:table-cell">{t('document.note')}</TableHead>
            <TableHead>
              <span className="sr-only">{t('common.actions')}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {DOC_KINDS.map((kind) => {
            const doc: Row | undefined = emp.documents.find((d: Row) => d.kind === kind)
            return (
              <TableRow key={kind}>
                <TableCell className="font-medium">{t(`enum.document.${kind}`)}</TableCell>
                <TableCell>{doc?.received_at ?? '—'}</TableCell>
                <TableCell className="hidden md:table-cell">{doc?.expires_at ?? '—'}</TableCell>
                <TableCell className="hidden text-muted-foreground lg:table-cell">{doc?.note ?? '—'}</TableCell>
                <TableCell className="text-end">
                  {can('employee.edit') && (
                    <FormDialog
                      title={t(`enum.document.${kind}`)}
                      trigger={
                        <Button type="button" variant="outline" size="sm">
                          {t('common.edit')}
                        </Button>
                      }
                      init={{ received_at: doc?.received_at ?? '', expires_at: doc?.expires_at ?? '', note: doc?.note ?? '' }}
                      submit={(form) =>
                        send(`/employees/${emp.id}/documents/${kind}`, { method: 'PUT', body: Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v || null])) })
                      }
                      onDone={() => {}}
                      shown={['received_at', 'expires_at', 'note']}
                    >
                      {(f) => (
                        <>
                          <TextField name="received_at" label={t('document.received_at')} type="date" {...f} />
                          <TextField name="expires_at" label={t('document.expires_at')} type="date" {...f} />
                          <TextField name="note" label={t('document.note')} {...f} />
                        </>
                      )}
                    </FormDialog>
                  )}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </Card>
  )
}
