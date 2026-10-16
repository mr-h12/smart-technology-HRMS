import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input, Textarea } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, ApiError, apiList, type Pagination } from '@/lib/api'

const TYPES = ['office', 'engineer', 'permanent_worker', 'day_laborer']
const GENDERS = ['male', 'female']
const PAY_METHODS = ['cash', 'bank']
const DOC_KINDS = ['national_id_copy', 'work_stub', 'criminal_record', 'qualification', 'birth_certificate', 'insurance_form_6', 'photos', 'military_status', 'other']
const IDENTITY = ['name_ar', 'name_en', 'fingerprint_name', 'gender', 'national_id', 'insurance_no', 'phone', 'address', 'department', 'residence_id', 'pay_method', 'bank_account', 'hire_date', 'contract_end_date', 'termination_date', 'termination_reason', 'hr_notes']
const PAY = ['salary', 'raise', 'insured_basic', 'daily_wage', 'shift_start', 'shift_end']

type Row = Record<string, any> // eslint-disable-line @typescript-eslint/no-explicit-any
type Errs = Record<string, string>

const selectCls =
  'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-destructive'

const toErrs = (e: unknown): Errs =>
  e instanceof ApiError ? Object.fromEntries(e.details.filter((d) => d.field).map((d) => [d.field!, d.message])) : {}

/** Fields PayFields renders for a type; also what create/dialog submit. */
const payFields = (type: string) => [...(type === 'day_laborer' ? ['daily_wage'] : ['salary', 'raise', 'insured_basic']), 'shift_start', 'shift_end']

/** Shows every error message that no rendered field displays (non-422s, or fields hidden for this type). */
function ErrorBanner({ err, shown }: { err: unknown; shown: string[] }) {
  if (!err) return null
  const msgs =
    err instanceof ApiError && err.details.length
      ? err.details.filter((d) => !d.field || !shown.includes(d.field)).map((d) => d.message)
      : [err instanceof Error ? err.message : String(err)]
  return msgs.length ? (
    <div role="alert" className="rounded-md border border-destructive p-3 text-sm text-destructive">
      {msgs.map((m, i) => (
        <p key={i}>{m}</p>
      ))}
    </div>
  ) : null
}

function useMoney() {
  const { i18n } = useTranslation()
  const f = new Intl.NumberFormat(i18n.language, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return (v: string | null | undefined) => (v == null ? '—' : f.format(v as unknown as number)) // string input is formatted exactly by Intl
}

/** Label + control + server error wired with aria-describedby. */
function Field({ name, id = name, label, errs, children }: { name: string; id?: string; label: string; errs: Errs; children: (p: Row) => React.ReactNode }) {
  const err = errs[name]
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children({ id, name, 'aria-invalid': !!err || undefined, 'aria-describedby': err ? `${id}-err` : undefined })}
      {err && (
        <p id={`${id}-err`} className="text-[13px] text-destructive">
          {err}
        </p>
      )}
    </div>
  )
}

function TextField({ name, label, values, set, errs, ns = '', ...rest }: { name: string; label: string; values: Row; set: (k: string, v: string) => void; errs: Errs; ns?: string } & Omit<React.ComponentProps<'input'>, 'form'>) {
  return (
    <Field name={name} id={ns + name} label={label} errs={errs}>
      {(p) => <Input {...p} {...rest} value={values[name] ?? ''} onChange={(e) => set(name, e.target.value)} />}
    </Field>
  )
}

function SelectField({ name, label, values, set, errs, options, blank }: { name: string; label: string; values: Row; set: (k: string, v: string) => void; errs: Errs; options: [string, string][]; blank?: boolean }) {
  return (
    <Field name={name} label={label} errs={errs}>
      {(p) => (
        <select {...p} className={selectCls} value={values[name] ?? ''} onChange={(e) => set(name, e.target.value)}>
          {blank && <option value="" />}
          {options.map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </select>
      )}
    </Field>
  )
}

function useForm(initial: Row = {}) {
  const [form, setForm] = useState<Row>(initial)
  return { form, setForm, set: (k: string, v: string) => setForm((f) => ({ ...f, [k]: v })) }
}

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

export function EmployeesPage() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<Row[]>([])
  const [pg, setPg] = useState<Pagination>()
  const [err, setErr] = useState<unknown>()

  useEffect(() => {
    const qs = new URLSearchParams({ page: String(page), ...(q && { search: q }), ...(type && { type }) })
    apiList<Row>(`/employees?${qs}`)
      .then((r) => {
        setErr(undefined)
        setRows(r.data)
        setPg(r.meta.pagination)
      })
      .catch(setErr)
  }, [q, type, page])

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-page-title">{t('page.employees')}</h1>
        <Button asChild>
          <Link to="/employees/new">{t('employee.add')}</Link>
        </Button>
      </div>
      <div className="flex flex-wrap gap-3">
        <Input className="max-w-xs" aria-label={t('employee.search')} placeholder={t('employee.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
        <select aria-label={t('employee.type')} className={`${selectCls} max-w-xs`} value={type} onChange={(e) => { setType(e.target.value); setPage(1) }}>
          <option value="">{t('employee.allTypes')}</option>
          {TYPES.map((v) => (
            <option key={v} value={v}>
              {t(`enum.type.${v}`)}
            </option>
          ))}
        </select>
      </div>
      <ErrorBanner err={err} shown={[]} />
      <Table>
        <TableHeader>
          <TableRow>
            {['code', 'name_ar', 'type', 'department', 'pay_method'].map((c) => (
              <TableHead key={c}>{t(`employee.${c}`)}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((e) => (
            <TableRow key={e.id}>
              <TableCell>{e.code}</TableCell>
              <TableCell>
                <Link className="underline" to={`/employees/${e.id}`}>
                  {e.name_ar}
                </Link>
              </TableCell>
              <TableCell>{e.current_type ? t(`enum.type.${e.current_type}`) : '—'}</TableCell>
              <TableCell>{e.department ?? '—'}</TableCell>
              <TableCell>{t(`enum.pay_method.${e.pay_method}`)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {pg && (
        <div className="flex items-center gap-3 text-sm">
          <Button variant="outline" disabled={!pg.has_previous_page} onClick={() => setPage(page - 1)}>
            {t('common.prev')}
          </Button>
          <span>{t('common.pageOf', { page: pg.page, total: pg.total_pages })}</span>
          <Button variant="outline" disabled={!pg.has_next_page} onClick={() => setPage(page + 1)}>
            {t('common.next')}
          </Button>
        </div>
      )}
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
    apiList<Row>('/residences?per_page=100').then((r) => setResidences(r.data))
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
          <ChangeDialog kind="type" empId={emp.id} current={emp.current_type} reload={reload} />
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
          <ChangeDialog kind="pay" empId={emp.id} current={emp.current_type} reload={reload} />
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

/** "Add change" dialog: a type change, or a pay change shaped by the current type. */
function ChangeDialog({ kind, empId, current, reload }: { kind: 'type' | 'pay'; empId: string; current: string | null; reload: () => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const { form, set, setForm } = useForm({ type: current ?? 'office' })
  const [err, setErr] = useState<unknown>()
  const errs = toErrs(err)
  const f = { values: form, set, errs }
  const shown = ['effective_from', ...(kind === 'type' ? ['type'] : payFields(current ?? ''))]

  async function submit() {
    setErr(undefined)
    try {
      await api(`/employees/${empId}/${kind}-changes`, { method: 'POST', body: clean(form, shown) })
      setOpen(false)
      setForm({ type: current ?? 'office' })
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
            <SelectField name="type" label={t('employee.type')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} {...f} />
          ) : (
            <PayFields type={current ?? ''} {...f} />
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
