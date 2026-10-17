import { Briefcase, CalendarDays, HardHat, type LucideIcon, UserPlus, Users, Wrench } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
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
import { Card } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cairoToday, ErrorBanner, Field, type Errs, type Row, SelectField, TextField, toErrs, useForm, useMoney } from '@/components/form'
import { api, apiList } from '@/lib/api'
import { useAuth } from '@/lib/auth'

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
  const [rows, setRows] = useState<Row[]>()
  const [total, setTotal] = useState(0)
  const [counts, setCounts] = useState<Record<string, number>>({})
  const [err, setErr] = useState<unknown>()

  useEffect(() => {
    let live = true // drop answers to queries the user has already typed past
    const qs = new URLSearchParams({ page: String(page), per_page: String(PAGE), ...(q && { search: q }), ...(type && { type }) })
    apiList<Row>(`/employees?${qs}`)
      .then((r) => {
        if (!live) return
        setErr(undefined)
        setRows(r.data)
        setTotal(r.meta.pagination.total)
      })
      .catch((e) => live && setErr(e))
    return () => {
      live = false
    }
  }, [q, type, page])

  // ponytail: one count call per tile; replace them with a counts endpoint if the list grows slow.
  useEffect(() => {
    for (const [k] of STATS) {
      apiList(`/employees?per_page=1${k === 'total' ? '' : `&type=${k}`}`).then(
        (r) => setCounts((c) => ({ ...c, [k]: r.meta.pagination.total })),
        () => {}, // a failed tile stays at —; the list request shows the banner
      )
    }
  }, [])

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

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {STATS.map(([k, icon, tone]) => (
          <MiniStat key={k} label={t(`employee.stat.${k}`)} value={counts[k] ?? '—'} icon={icon} tone={tone} />
        ))}
      </div>

      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="flex flex-col gap-3 xl:flex-row xl:items-center">
            <SearchInput value={q} onChange={filter(setQ)} placeholder={t('employee.search')} className="xl:w-80" />
            <FilterSelect value={type} onChange={filter(setType)} label={t('employee.type')} allLabel={t('employee.allTypes')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} />
            {(q || type) && (
              <Button variant="ghost" size="sm" onClick={clear} className="xl:ms-auto">
                {t('common.clearFilters')}
              </Button>
            )}
          </div>
          <ErrorBanner err={err} shown={[]} />
        </div>

        {!rows ? (
          !err && <TableSkeleton rows={8} />
        ) : !rows.length ? (
          <EmptyState icon={Users} title={t('common.noResults')} />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('employee.employee')}</TableHead>
                <TableHead>{t('employee.code')}</TableHead>
                <TableHead>{t('employee.type')}</TableHead>
                <TableHead className="hidden md:table-cell">{t('employee.department')}</TableHead>
                <TableHead className="hidden lg:table-cell">{t('employee.hire_date')}</TableHead>
                <TableHead>{t('employee.status')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((e) => {
                const left = e.termination_date && e.termination_date <= today
                return (
                  <TableRow
                    key={e.id}
                    tabIndex={0}
                    className="cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    onClick={() => open(e.id)}
                    onKeyDown={(ev) => ev.key === 'Enter' && open(e.id)}
                  >
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <UserAvatar name={e.name_ar} />
                        <div className="min-w-0">
                          <div className="font-medium">{e.name_ar}</div>
                          {e.name_en && <div className="text-xs text-muted-foreground">{e.name_en}</div>}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">{e.code}</TableCell>
                    <TableCell>{e.current_type ? <Badge variant="info">{t(`enum.type.${e.current_type}`)}</Badge> : '—'}</TableCell>
                    <TableCell className="hidden md:table-cell">{e.department ?? '—'}</TableCell>
                    <TableCell className="hidden text-muted-foreground lg:table-cell">{e.hire_date ?? '—'}</TableCell>
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

export function EmployeeFormPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const navigate = useNavigate()
  const { form, setForm, set } = useForm({ gender: 'male', pay_method: 'cash', type: 'office' })
  const [emp, setEmp] = useState<Row>()
  const [residences, setResidences] = useState<Row[]>([])
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)

  const load = useCallback(() => {
    if (!id) return
    api<Row>(`/employees/${id}`)
      .then((e) => {
        setEmp(e)
        setForm((f) => ({ ...f, ...Object.fromEntries(IDENTITY.map((k) => [k, e[k] ?? ''])) }))
      })
      .catch(setErr)
  }, [id, setForm])
  useEffect(load, [load])
  useEffect(() => {
    apiList<Row>('/residences?per_page=100').then((r) => setResidences(r.data)).catch(setErr)
  }, [])

  async function save(ev: React.FormEvent) {
    ev.preventDefault()
    setErr(undefined)
    try {
      if (id) {
        // PATCH: blank clears a nullable field; name_ar/gender/pay_method must stay set
        const body = Object.fromEntries(IDENTITY.map((k) => [k, form[k] === '' && !['name_ar', 'gender', 'pay_method'].includes(k) ? null : form[k]]))
        await api(`/employees/${id}`, { method: 'PATCH', body })
        load()
      } else {
        const e = await api<Row>('/employees', { method: 'POST', body: clean(form, [...IDENTITY, ...payFields(form.type), 'type', 'effective_from']) })
        navigate(`/employees/${e.id}`)
      }
    } catch (e) {
      setErr(e)
    }
  }

  const f = { values: form, set, errs }
  const shown = [...IDENTITY, ...(id ? [] : [...payFields(form.type), 'type', 'effective_from'])]
  return (
    <div className="flex max-w-3xl flex-col gap-6">
    <form onSubmit={save} className="flex flex-col gap-6">
      <h1 className="text-page-title">{id ? emp?.name_ar : t('employee.add')}</h1>
      <ErrorBanner err={err} shown={shown} />
      <fieldset className="grid gap-4 sm:grid-cols-2">
        <legend className="mb-2 font-semibold">{t('employee.identity')}</legend>
        <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
        <TextField name="name_en" label={t('employee.name_en')} {...f} />
        <TextField name="fingerprint_name" label={t('employee.fingerprint_name')} {...f} />
        <SelectField name="gender" label={t('employee.gender')} options={GENDERS.map((v) => [v, t(`enum.gender.${v}`)])} {...f} />
        <TextField name="national_id" label={t('employee.national_id')} inputMode="numeric" {...f} />
        <TextField name="insurance_no" label={t('employee.insurance_no')} {...f} />
        <TextField name="phone" label={t('employee.phone')} {...f} />
        <TextField name="address" label={t('employee.address')} {...f} />
      </fieldset>
      <fieldset className="grid gap-4 sm:grid-cols-2">
        <legend className="mb-2 font-semibold">{t('employee.placement')}</legend>
        <TextField name="department" label={t('employee.department')} {...f} />
        <SelectField name="residence_id" label={t('employee.residence')} blank options={residences.map((r) => [r.id, r.name_ar])} {...f} />
        <TextField name="hire_date" label={t('employee.hire_date')} type="date" {...f} />
        <TextField name="contract_end_date" label={t('employee.contract_end_date')} type="date" {...f} />
        <TextField name="termination_date" label={t('employee.termination_date')} type="date" {...f} />
        <TextField name="termination_reason" label={t('employee.termination_reason')} {...f} />
        <Field name="hr_notes" label={t('employee.hr_notes')} errs={errs}>
          {(p) => <Textarea {...p} value={form.hr_notes ?? ''} onChange={(e) => set('hr_notes', e.target.value)} />}
        </Field>
      </fieldset>
      <fieldset className="grid gap-4 sm:grid-cols-2">
        <legend className="mb-2 font-semibold">{t('employee.pay')}</legend>
        <SelectField name="pay_method" label={t('employee.pay_method')} options={PAY_METHODS.map((v) => [v, t(`enum.pay_method.${v}`)])} {...f} />
        <TextField name="bank_account" label={t('employee.bank_account')} {...f} />
        {!id && (
          <>
            <SelectField name="type" label={t('employee.type')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} {...f} />
            <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
            <PayFields type={form.type} {...f} />
          </>
        )}
      </fieldset>
      <div>
        <Button type="submit">{t('common.save')}</Button>
      </div>
    </form>
    {emp && <History emp={emp} reload={load} />}
    </div>
  )
}

function History({ emp, reload }: { emp: Row; reload: () => void }) {
  const { t } = useTranslation()
  const money = useMoney()
  const cell = (v: string | null) => (v == null ? '—' : v)
  return (
    <>
      <section className="flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">{t('history.types')}</h2>
          <ChangeDialog kind="type" emp={emp} reload={reload} />
        </div>
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
      </section>
      <section className="flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">{t('history.pay')}</h2>
          <ChangeDialog kind="pay" emp={emp} reload={reload} />
        </div>
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
                <TableCell>{cell(c.shift_start)}</TableCell>
                <TableCell>{cell(c.shift_end)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
      <section className="flex flex-col gap-2">
        <h2 className="font-semibold">{t('history.documents')}</h2>
        {DOC_KINDS.map((k) => (
          <DocRow key={k} kind={k} empId={emp.id} doc={emp.documents.find((d: Row) => d.kind === k)} reload={reload} />
        ))}
      </section>
    </>
  )
}

/** Type in force on a date: greatest effective_from <= date, else the first change, else the current type. */
const typeOn = (emp: Row, date: string): string => {
  const cs: Row[] = [...(emp.type_changes ?? [])].sort((x, y) => x.effective_from.localeCompare(y.effective_from))
  return (date && cs.filter((c) => c.effective_from <= date).at(-1)?.type) || cs[0]?.type || emp.current_type || ''
}

/** "Add change" dialog: a type change, or a pay change shaped by the type in force on the chosen date. */
function ChangeDialog({ kind, emp, reload }: { kind: 'type' | 'pay'; emp: Row; reload: () => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const { form, set, setForm } = useForm({})
  const [err, setErr] = useState<unknown>()
  const [sameType, setSameType] = useState(false)
  const errs = { ...toErrs(err), ...(sameType ? { type: t('change.sameType') } : {}) }
  const f = { values: form, set: (k: string, v: string) => { setSameType(false); set(k, v) }, errs }
  const inForce = typeOn(emp, form.effective_from ?? '')
  const shown = ['effective_from', ...(kind === 'type' ? ['type'] : payFields(inForce))]

  async function submit() {
    setErr(undefined)
    if (kind === 'type' && form.type === inForce) return setSameType(true)
    try {
      await api(`/employees/${emp.id}/${kind}-changes`, { method: 'POST', body: clean(form, shown) })
      setOpen(false)
      setForm({})
      reload()
    } catch (e) {
      setErr(e)
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button type="button" variant="outline" onClick={() => setOpen(true)}>
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

function DocRow({ kind, empId, doc, reload }: { kind: string; empId: string; doc?: Row; reload: () => void }) {
  const { t } = useTranslation()
  const { form, set } = useForm({ received_at: doc?.received_at ?? '', expires_at: doc?.expires_at ?? '', note: doc?.note ?? '' })
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)
  const save = async () => {
    setErr(undefined)
    try {
      await api(`/employees/${empId}/documents/${kind}`, { method: 'PUT', body: Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v || null])) })
      reload()
    } catch (e) {
      setErr(e)
    }
  }
  const f = { values: form, set, errs, ns: `${kind}-` }
  return (
    <div className="flex flex-col gap-2">
    <ErrorBanner err={err} shown={['received_at', 'expires_at', 'note']} />
    <div className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]">
      <span className="text-sm font-medium">{t(`enum.document.${kind}`)}</span>
      <TextField name="received_at" label={t('document.received_at')} type="date" {...f} />
      <TextField name="expires_at" label={t('document.expires_at')} type="date" {...f} />
      <TextField name="note" label={t('document.note')} {...f} />
      <Button type="button" variant="outline" onClick={save}>
        {t('common.save')}
      </Button>
    </div>
    </div>
  )
}
