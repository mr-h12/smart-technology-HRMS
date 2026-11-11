import { Briefcase, CalendarCheck, CalendarDays, ChevronLeft, ChevronRight, FileText, HardHat, History as HistoryIcon, IdCard, type LucideIcon, MapPin, RotateCcw, UserCog, UserPlus, Users, Wrench } from 'lucide-react'
import { useEffect, useEffectEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { MiniStat, type Tone } from '@/components/cards/StatCard'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { UserAvatar } from '@/components/common/UserAvatar'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Pagination } from '@/components/tables/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cairoToday, ErrorBanner, Field, type Errs, FormDialog, type FormApi, formatTime, ltrDates, type Row, SelectField, stopAtMissingPeriod, TextField, toErrs, useForm, useMoney } from '@/components/form'
import { useDebounced } from '@/hooks/useDebounced'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { cn } from '@/lib/utils'
import { RESIDENCES, USERS, useApi, useList, useSave, WORK_SITES } from '@/lib/query'
import { EmployeeAttendance } from './employee-attendance'
import { Documents } from './employee-documents'
import { Leave } from './employee-leave'

const TYPES = ['office', 'engineer', 'permanent_worker', 'day_laborer']
const WORKERS = ['permanent_worker', 'day_laborer'] // a worker belongs to a home site; its manager follows the site
const SITED = [...WORKERS, 'office'] // an office employee's site only lists them at it in HR's filter; they keep a manager too
const GENDERS = ['male', 'female']
const PAY_METHODS = ['cash', 'bank']
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
  // the filters and page live in the URL, so going back from an employee shows the same list; a default ('') leaves its key out
  const [params, setParams] = useSearchParams()
  const set = (patch: Record<string, string>) =>
    setParams((p) => {
      for (const [k, v] of Object.entries(patch)) {
        if (v) p.set(k, v)
        else p.delete(k)
      }
      return p
    }, { replace: true })
  const [q, setQ] = useState(params.get('q') ?? '') // the router writes the URL in a transition, which would lag a box bound to it
  const type = params.get('type') ?? ''
  const page = Number(params.get('page')) || 1
  const view = params.get('view') === 'archived' ? 'archived' : 'active'
  const archive = view === 'archived'
  const search = useDebounced(q, 250) // the box stays instant; the request waits for a pause in typing
  const filters = { ...(search && { search }), ...(type && { type }), ...(archive && { archived: 'true' }) }
  const qs = new URLSearchParams({ page: String(page), per_page: String(PAGE), ...filters })
  const { rows, total, err, loading } = useList(`/employees?${qs}`)
  const { data: counts } = useApi<Record<string, number>>('/employees/summary') // a failed tile stays at —; the list shows the banner

  const filter = (patch: Record<string, string>) => set({ ...patch, page: '' })
  const clear = () => {
    setQ('')
    filter({ q: '', type: '' })
  }
  const today = cairoToday()
  // the employee screen steps through this same filtered list: its request without the paging, the row's place in the whole result, its size
  const open = (id: string, i: number) => navigate(`/employees/${id}`, { state: { list: `/employees?${new URLSearchParams(filters)}`, pos: (page - 1) * PAGE + i, total } })
  const notes = rows.some((r) => 'hr_notes' in r) // the server sends notes only to the viewers allowed to read them
  const office = can('employee.view.all') // a site manager never reaches office staff
  const types = TYPES.filter((v) => office || v !== 'office')
  const stats = STATS.filter(([k]) => office || k !== 'office')

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

      <div className={cn('grid grid-cols-2 gap-3', stats.length === 5 ? 'sm:grid-cols-3 xl:grid-cols-5' : 'xl:grid-cols-4')}>
        {stats.map(([k, icon, tone]) => (
          <MiniStat key={k} label={t(`employee.stat.${k}`)} value={counts?.[k] ?? '—'} icon={icon} tone={tone} />
        ))}
      </div>

      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="flex flex-col gap-3 xl:flex-row xl:items-center">
            <SearchInput value={q} onChange={(v) => { setQ(v); filter({ q: v }) }} placeholder={t('employee.search')} className="sm:w-full sm:max-w-md" />
            <FilterSelect value={type} onChange={(v) => filter({ type: v })} label={t('employee.type')} allLabel={t('employee.allTypes')} options={types.map((v) => [v, t(`enum.type.${v}`)])} />
            <SegmentedControl
              label={t('employee.archive.view')}
              value={view}
              onChange={(v) => filter({ view: v === 'archived' ? v : '' })}
              className="w-max"
              // the visible text is aria-hidden: the option's sr-only label already names it
              options={(['active', 'archived'] as const).map((v) => ({ value: v, label: t(`employee.archive.${v}`), content: <span aria-hidden className="px-1.5 whitespace-nowrap">{t(`employee.archive.${v}`)}</span> }))}
            />
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
                {notes && <TableHead className="hidden md:table-cell">{t('employee.hr_notes')}</TableHead>}
                <TableHead>{t(archive ? 'employee.termination_date' : 'employee.status')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((e, i) => {
                const leaving = e.termination_date && e.termination_date > today // the active list never holds a past date: the termination day is still worked
                const badge = e.current_type && <Badge variant="info">{t(`enum.type.${e.current_type}`)}</Badge>
                return (
                  <TableRow
                    key={e.id}
                    tabIndex={0}
                    className="cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    onClick={() => open(e.id, i)}
                    onKeyDown={(ev) => ev.key === 'Enter' && open(e.id, i)}
                  >
                    <TableCell className="whitespace-normal">
                      <div className="flex items-center gap-3">
                        <UserAvatar name={e.name_ar} />
                        <div className="min-w-0">
                          <div className="font-medium">{e.name_ar}</div>
                          {e.name_en && <div className="text-xs text-muted-foreground">{e.name_en}</div>}
                          {badge && <div className="mt-1 sm:hidden">{badge}</div>}
                          {notes && e.hr_notes && <div className="mt-1 md:hidden"><NoteCell note={e.hr_notes} /></div>}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="hidden font-mono text-xs text-muted-foreground sm:table-cell">{e.code}</TableCell>
                    <TableCell className="hidden sm:table-cell">{badge || '—'}</TableCell>
                    <TableCell className="hidden md:table-cell">{e.department ?? '—'}</TableCell>
                    <TableCell className="hidden text-muted-foreground xl:table-cell">{e.hire_date ?? '—'}</TableCell>
                    {notes && <TableCell className="hidden md:table-cell">{e.hr_notes ? <NoteCell note={e.hr_notes} /> : '—'}</TableCell>}
                    <TableCell>
                      {archive ? e.termination_date : <StatusBadge variant={leaving ? 'neutral' : 'success'} label={leaving ? ltrDates(t('employee.leavesOn', { date: e.termination_date })) : t('status.active')} />}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
        {total > 0 && <Pagination page={page} pageSize={PAGE} total={total} onPageChange={(p) => set({ page: p > 1 ? String(p) : '' })} />}
      </Card>
    </div>
  )
}

/** An HR note in the list: one line, the whole note on hover (title) and on a tap or Enter, which never opens the employee. */
function NoteCell({ note }: { note: string }) {
  const [full, setFull] = useState(false)
  return (
    <button
      type="button"
      title={note}
      aria-expanded={full}
      className={cn('block max-w-56 text-start text-sm text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring', full ? 'whitespace-pre-wrap' : 'truncate')}
      onClick={(ev) => { ev.stopPropagation(); setFull(!full) }}
      onKeyDown={(ev) => ev.stopPropagation()}
    >
      {note}
    </button>
  )
}

const TABS: [string, LucideIcon][] = [
  ['profile', IdCard],
  ['manager', UserCog],
  ['site', MapPin],
  ['history', HistoryIcon],
  ['documents', FileText],
  ['leave', CalendarDays],
  ['attendance', CalendarCheck],
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
  if (id && !(ready && emp)) return <div className="mx-auto max-w-5xl space-y-6"><Pager />{err ? <ErrorBanner err={err} shown={[]} /> : <TableSkeleton rows={6} />}</div>
  return <EmployeeForm emp={emp} loadErr={err} />
}

/**
 * Previous / next employee of the filtered list this one was opened from, by the arrows or a horizontal swipe; nothing when opened
 * from anywhere else. The opening screen puts `{ list, pos, total }` in the router state: `list` is its list request with its filters
 * and no paging (`/employees?type=engineer`, `/leave/balances?year=2026`), `pos` the row's place in the whole result, `total` its size.
 * Each neighbour is one row of that same request, so it keeps the screen's order and permission scope. A list the server does not page
 * passes `ids` (its shown rows, in order) instead of `list`. A move replaces the history entry, so Back returns straight to the list.
 */
function Pager() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { state, search } = useLocation()
  const ctx: { list?: string; ids?: string[]; pos: number; total: number } | null =
    (typeof state?.list === 'string' || Array.isArray(state?.ids)) && Number.isInteger(state.pos) && Number.isInteger(state.total) ? state : null
  const { list, ids, pos, total } = ctx ?? { pos: 0, total: 0 }
  const [path, query = ''] = list?.split('?') ?? []
  const at = (p: number) => (list && p >= 0 && p < total ? `${path}?${new URLSearchParams(`${query}&page=${p + 1}&per_page=1`)}` : null)
  const prev: string | undefined = useApi<Row[]>(at(pos - 1)).data?.[0]?.id ?? ids?.[pos - 1]
  const next: string | undefined = useApi<Row[]>(at(pos + 1)).data?.[0]?.id ?? ids?.[pos + 1]
  const go = (id: string | undefined, p: number) => id && navigate(`/employees/${id}${search}`, { replace: true, state: { ...ctx, pos: p } }) // `search` keeps the tab
  // content follows the finger: in Arabic the next employee comes in from the left, so a swipe to the right brings it
  const swipe = useEffectEvent((dx: number) => (dx > 0 === (i18n.dir() === 'rtl') ? go(next, pos + 1) : go(prev, pos - 1)))
  useEffect(() => {
    if (!ctx) return
    // not a swipe when the touch belongs to something else: the focused field (moving the caret; on a phone most swipes start on an
    // unfocused field, which stays a swipe) or a box that scrolls sideways
    const owned = (n: EventTarget | null) => {
      for (let el = n instanceof Element ? n : null; el; el = el.parentElement)
        if (el.matches('input:focus, textarea:focus, select:focus') || (el.scrollWidth > el.clientWidth && /auto|scroll/.test(getComputedStyle(el).overflowX))) return true
      return false
    }
    let start: { x: number; y: number } | undefined
    const down = (e: TouchEvent) => {
      const f = e.touches[0]
      // one finger (a pinch is not a swipe), away from the screen edges where Safari's back gesture starts, and none while a dialog,
      // sheet or menu is open: a swipe on its backdrop would leave what it holds (and what was typed in it) behind
      const open = document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]')
      start = e.touches.length === 1 && f.clientX >= 24 && f.clientX <= window.innerWidth - 24 && !open && !owned(e.target) ? { x: f.clientX, y: f.clientY } : undefined
    }
    const up = (e: TouchEvent) => {
      const f = e.changedTouches[0]
      if (!start || !f) return
      const dx = f.clientX - start.x
      const dy = f.clientY - start.y
      start = undefined
      if (Math.abs(dx) >= 60 && Math.abs(dx) > 2 * Math.abs(dy)) swipe(dx)
    }
    window.addEventListener('touchstart', down, { passive: true })
    window.addEventListener('touchend', up, { passive: true })
    return () => {
      window.removeEventListener('touchstart', down)
      window.removeEventListener('touchend', up)
    }
  }, [ctx])
  if (!ctx) return null
  return (
    <div className="flex items-center justify-end gap-1">
      <Button variant="outline" size="icon-sm" disabled={!prev} onClick={() => go(prev, pos - 1)} aria-label={t('employee.prev')} title={t('employee.prev')}>
        <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
      </Button>
      <span className="px-1 text-sm font-medium whitespace-nowrap">{t('employee.position', { pos: pos + 1, total })}</span>
      <Button variant="outline" size="icon-sm" disabled={!next} onClick={() => go(next, pos + 1)} aria-label={t('employee.next')} title={t('employee.next')}>
        <ChevronRight className="rtl:-scale-x-100" aria-hidden />
      </Button>
    </div>
  )
}

/** Both render the same profile form; detail adds a header card and tabs synced to `?tab=`. The form starts from `emp` and is the user's after that. */
function EmployeeForm({ emp, loadErr }: { emp?: Row; loadErr?: unknown }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const id: string | undefined = emp?.id
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const { state } = useLocation() // a tab change keeps the list context the Pager reads
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
            <TextField name="phone" label={t('employee.phone')} inputMode="numeric" autoComplete="tel" maxLength={11} {...f} />
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

  const worker = WORKERS.includes(emp.current_type)
  const sited = SITED.includes(emp.current_type)
  const tabs = TABS.filter(([v]) => (v !== 'manager' || !worker) && (v !== 'site' || sited) && (v !== 'leave' || (can('leave.view') && emp.current_type !== 'day_laborer')) && (v !== 'documents' || can('employee_document.view')) && (v !== 'attendance' || can('attendance.view')))
  const tab = tabs.some(([v]) => v === params.get('tab')) ? params.get('tab')! : 'profile'
  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <Pager />
      <ErrorBanner err={loadErr} shown={[]} />
      <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
        <UserAvatar name={emp.name_ar} size="xl" />
        <div className="min-w-0 flex-1 space-y-1">
          <h1 className="text-page-title">{emp.name_ar}</h1>
          {emp.name_en && <p className="text-sm text-muted-foreground">{emp.name_en}</p>}
          {emp.code != null && (
            <div className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
              <p>
                {t('employee.code')}: <span className="font-mono">{emp.code}</span>
              </p>
              {can('employee.edit_code') && <ChangeCodeDialog emp={emp} />}
            </div>
          )}
        </div>
        {emp.current_type && <Badge variant="info" className="self-start sm:self-center">{t(`enum.type.${emp.current_type}`)}</Badge>}
        <dl className="grid gap-1 text-sm">
          {sited && (
            <div className="flex gap-2">
              <dt className="text-muted-foreground">{t('site.home')}</dt>
              <dd>{emp.current_site?.name_ar ?? t('site.homeNone')}</dd>
            </div>
          )}
          {!worker && (
            <div className="flex gap-2">
              <dt className="text-muted-foreground">{t('manager.title')}</dt>
              <dd>{emp.current_manager?.name ?? t('manager.none')}</dd>
            </div>
          )}
        </dl>
        {sited && can('employee.assign_site') && <MoveSiteDialog empId={emp.id} />}
        {!worker && can('employee.assign_manager') && <AssignManagerDialog empId={emp.id} />}
        {can('employee.edit') && emp.termination_date && emp.termination_date < cairoToday() && (
          // the open form drops the cleared values too, so a later Save cannot send them back
          <ReactivateDialog emp={emp} onDone={() => ['termination_date', 'termination_reason'].forEach((k) => set(k, ''))} />
        )}
      </Card>

      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true, state })}>
        <TabsList>
          {tabs.map(([v, Icon]) => (
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
            site: <SiteHistory emp={emp} />,
            history: <History emp={emp} />,
            documents: <Documents emp={emp} />,
            leave: <Leave emp={emp} initialYear={params.get('year')} />,
            attendance: <EmployeeAttendance emp={emp} />,
          }[tab]}
        </TabsContent>
      </Tabs>
    </div>
  )
}

/** Undoes the termination of an archived employee: the API clears the date and reason, so the days since count as employed again. */
function ReactivateDialog({ emp, onDone }: { emp: Row; onDone: () => void }) {
  const { t } = useTranslation()
  const send = useSave()
  return (
    <FormDialog
      title={t('employee.reactivate.title')}
      trigger={
        <Button type="button" variant="outline">
          <RotateCcw />
          {t('employee.reactivate.button')}
        </Button>
      }
      init={{}}
      submit={() => send(`/employees/${emp.id}`, { method: 'PATCH', body: { termination_date: null, termination_reason: null } })}
      onDone={onDone}
      shown={[]}
      footer="employee.reactivate.button"
      description={<span className="text-foreground">{ltrDates(t('employee.reactivate.confirm', { date: emp.termination_date }))}</span>}
    >
      {() => null}
    </FormDialog>
  )
}

/** Sets the employee ID. When a live employee holds it the API answers 409 code_taken naming the holder; the dialog then offers to swap the two IDs. */
function ChangeCodeDialog({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const send = useSave()
  const [open, setOpen] = useState(false)
  const [taken, setTaken] = useState(false)
  async function submit(form: Row) {
    try {
      const code = String(form.code ?? '').trim() // as typed: the server's integer rule rejects the rest
      return await send(`/employees/${emp.id}/code`, { method: 'PATCH', body: { code: code === '' ? null : code, ...(taken && { swap: true }) } })
    } catch (e) {
      setTaken(e instanceof ApiError && e.code === 'code_taken')
      throw e
    }
  }
  return (
    <FormDialog
      title={t('employee.changeCode.button')}
      description={t('employee.changeCode.warning')}
      trigger={
        <Button type="button" variant="link" size="xs">
          {t('employee.changeCode.button')}
        </Button>
      }
      open={open}
      onOpenChange={(o) => { setOpen(o); setTaken(false) }}
      init={{ code: String(emp.code ?? '') }}
      submit={submit}
      onDone={() => {}}
      shown={['code']}
      footer={taken ? 'employee.changeCode.swap' : 'common.save'}
      clearErrOnEdit // the holder banner names the ID that was tried, not the one now typed
    >
      {(f) => <TextField name="code" label={t('employee.changeCode.new')} inputMode="numeric" dir="ltr" {...f} set={(k, v) => { setTaken(false); f.set(k, v) }} />}
    </FormDialog>
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

/**
 * Moves a worker or office employee to a home site from a date; with a return date the API also puts them back on the site they had before.
 * With `from` it is the day sheet's «set the site» for someone with none: dated that day, with no previous site to return to.
 */
export function MoveSiteDialog({ empId, from, ariaLabel }: { empId: string; from?: string; ariaLabel?: string }) {
  const { t } = useTranslation()
  const send = useSave()
  const setting = from !== undefined
  const title = t(setting ? 'site.set' : 'site.move')
  const shown = ['site_id', 'effective_from', ...(setting ? [] : ['returns_on'])]
  return (
    <FormDialog
      title={title}
      trigger={
        <Button type="button" variant="outline" size={setting ? 'sm' : undefined} aria-label={ariaLabel}>
          <MapPin />
          {title}
        </Button>
      }
      init={{ site_id: '', effective_from: from ?? '', returns_on: '' }}
      submit={(form) => send(`/employees/${empId}/site-assignments`, { body: clean(form, shown) }, ['employees', 'attendance'])}
      onDone={() => {}}
      shown={shown}
    >
      {(f) => (
        <>
          <SiteSelect {...f} />
          <TextField name="effective_from" label={t('change.effective_from')} type="date" {...f} />
          {!setting && <TextField name="returns_on" label={t('site.returnsOn')} type="date" {...f} />}
        </>
      )}
    </FormDialog>
  )
}

/** Mounted with the dialog body, so the active work sites load when it opens. */
function SiteSelect(f: FormApi) {
  const { t } = useTranslation()
  const { rows, err } = useList(WORK_SITES)
  return (
    <>
      <ErrorBanner err={err} shown={[]} />
      <SelectField name="site_id" label={t('site.home')} blank options={rows.map((s) => [s.id, s.name_ar])} {...f} />
    </>
  )
}

function SiteHistory({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const rows: Row[] = [...emp.site_assignments].reverse() // the API sends oldest first
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>{t('site.homeHistory')}</CardTitle>
      </CardHeader>
      {rows.length ? (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('site.home')}</TableHead>
              <TableHead>{t('change.effective_from')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((s) => (
              <TableRow key={s.id}>
                <TableCell className="font-medium">{s.site_name_ar ?? '—'}</TableCell>
                <TableCell>{s.effective_from}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      ) : (
        <EmptyState icon={MapPin} title={t('site.homeNone')} />
      )}
    </Card>
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
