import { useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'
import { PenLine, TriangleAlert, Users } from 'lucide-react'
import { type FocusEvent, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { PageHeader } from '@/components/common/PageHeader'
import { PeriodArrows, type PeriodRange } from '@/components/common/PeriodArrows'
import { StatusBadge } from '@/components/common/StatusBadge'
import { asciiDigits, ErrorBanner, type Errs, Field, FormDialog, localName, type Row, SelectField, TextField, toErrs, useMoney, weekdayOf } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Card } from '@/components/ui/card'
import { Input, Textarea } from '@/components/ui/input'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { queryKey, SITES, useApi, useSave } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'

const GROUPS = ['office', 'engineer', 'permanent_worker', 'day_laborer']
/** HR's entry fields per type besides `note`, as the payroll API takes them (PayrollRules::ENTRY_FIELDS); it refuses any other. */
const FIELDS: Record<string, string[]> = {
  office: ['bonus', 'negligence', 'lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes', 'advances', 'insurance_employee', 'insurance_company', 'income_tax'],
  engineer: ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
  permanent_worker: ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
  day_laborer: ['bonus', 'deduction', 'deduction_reason'],
}
const COUNTS = ['lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes']
const TEXTS = ['deduction_reason', 'note']
/** An office count has no column of its own: it sits just before the component it feeds. */
const COUNT_OF: Record<string, string> = { travel: 'travel_days', tower: 'tower_days', overtime: 'ot_minutes', lateness: 'lateness_exemption_minutes' }

type Override = { id: string; amount: string; reason: string; inputs_changed: boolean }
type Amount = { computed: string; override: Override | null; final: string }
type Entry = Record<string, string | number | null>
type Line = { employee: Row; components: Record<string, Amount>; entry: Entry; flags: string[] }
type Sheet = { lines: Line[]; totals: Record<string, string>; locked: boolean }
type Day = { date: string; state: string; leave_code: string | null; site_id: string | null; parts: Record<string, string>; day_cost: string; flags: string[] }
/** A column: a component (HR's input when the type's entry carries it, else computed), or an entry field with no component. */
type Col = ['component' | 'field', string]

/** The sheet's columns in the API's component order, each count before its component, the deduction's reason after it, the note last. */
function columns(group: string, components: string[]): Col[] {
  const fields = FIELDS[group]
  return [
    ...components.flatMap((c): Col[] => [
      ...(fields.includes(COUNT_OF[c]) ? [['field', COUNT_OF[c]] as Col] : []),
      ['component', c],
      ...(c === 'deduction' && fields.includes('deduction_reason') ? [['field', 'deduction_reason'] as Col] : []),
    ]),
    ['field', 'note'],
  ]
}

/** An entry value as its cell shows it: an amount "150.000000" → "150" by string surgery (money never goes through a float). */
const asText = (f: string, v: string | number | null | undefined) => (v == null ? '' : !TEXTS.includes(f) && typeof v === 'string' && /^\d+\.\d+$/.test(v) ? v.replace(/\.?0+$/, '') : String(v))
/** Blank → null; counts as JSON integers (anything else goes as typed, so the API names the field). */
const toBody = (f: string, v: string) => (v === '' ? null : COUNTS.includes(f) && /^\d+$/.test(v) ? Number.parseInt(v, 10) : v)
const colLabel = (t: TFunction, [kind, key]: Col) => t(kind === 'component' ? `payroll.component.${key}` : `payroll.field.${key}`)
/** A line or day flag; a day flag may carry a value after `:` (minutes early, the leave code to check). */
function flagText(t: TFunction, flag: string) {
  const [name, value] = flag.split(':')
  const label = t(`payroll.flag.${name}`, { defaultValue: name })
  if (!value) return label
  return `${label}: ${name === 'early_departure' ? t('payroll.minutes', { minutes: value }) : t(`attendance.leaveName.${value}`, { defaultValue: value })}`
}
const Flags = ({ flags }: { flags: string[] }) => {
  const { t } = useTranslation()
  return <span className="flex flex-wrap gap-1">{flags.map((f) => <StatusBadge key={f} variant={f === 'negative_net' ? 'danger' : 'warning'} dot={false} label={flagText(t, f)} />)}</span>
}

/**
 * HR's and the CEO's payroll page, URL state `?group=&period=` (group defaults to engineers, the period to the current one): the
 * sheet in the shape of the Excel المجمع, one row per employee and a totals row. HR (payroll.manage) types the entry fields in their
 * cells and overrides or reverts a computed one; payroll.view alone reads. Every amount is the API's; the page computes nothing.
 */
export function PayrollPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const { can } = useAuth()
  const money = useMoney()
  const group = GROUPS.find((g) => g === params.get('group')) ?? 'engineer'
  const chosen = params.get('period')
  const go = (g: string, start?: string) => setParams(start ? { group: g, period: start } : { group: g }, { replace: true })
  const { data: period, err: periodErr } = useApi<PeriodRange>(`/attendance/period?${new URLSearchParams({ group, ...(chosen && { period_start: chosen }) })}`)
  const start = chosen ?? period?.start
  const path = start ? `/payroll/${group}/${start}` : null
  const { data: sheet, err } = useApi<Sheet>(path)
  const [panel, setPanel] = useState<Row>()
  const [overriding, setOverriding] = useState<{ line: Line; component: string }>()
  const editable = can('payroll.manage')
  const cols = sheet?.lines.length ? columns(group, Object.keys(sheet.lines[0].components)) : []

  return (
    <div className="space-y-6">
      <PageHeader title={t('nav.payroll')} description={t('payroll.subtitle')} />
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="sm:w-56">
            <SelectField name="group" label={t('attendance.groupLabel')} values={{ group }} set={(_, v) => go(v)} errs={{}} options={GROUPS.map((g) => [g, t(`attendance.group.${g}`)])} />
          </div>
          <PeriodArrows period={period} onMove={(s) => go(group, s)} />
        </div>
        {(err ?? periodErr) !== undefined && <div className="border-b p-4"><ErrorBanner err={err ?? periodErr} shown={[]} /></div>}
        {sheet && !sheet.locked && <div className="border-b p-4"><Alert tone="warning">{t('payroll.notLocked')}</Alert></div>}
        {!sheet ? (
          (err ?? periodErr) === undefined && <TableSkeleton rows={8} />
        ) : !sheet.lines.length ? (
          <EmptyState icon={Users} title={t('payroll.empty')} />
        ) : (
          // each cell owns its row line, as the attendance grid: collapsed borders leave a band under a sticky cell
          <Table className="border-separate border-spacing-0 [&_td]:border-b [&_th]:border-b">
            <TableHeader>
              <TableRow>
                <TableHead className="sticky start-0 z-10 bg-muted">{t('attendance.employee')}</TableHead>
                <TableHead>{t('payroll.flags')}</TableHead>
                {cols.map((col) => <TableHead key={col.join(':')} className={cn('min-w-24 whitespace-normal normal-case tracking-normal', !TEXTS.includes(col[1]) && 'text-end')}>{colLabel(t, col)}</TableHead>)}
              </TableRow>
            </TableHeader>
            <TableBody>
              {sheet.lines.map((line) => (
                <LineRow key={line.employee.id} line={line} cols={cols} group={group} path={path!} editable={editable}
                  onOpen={() => setPanel(line.employee)} onOverride={(component) => setOverriding({ line, component })} />
              ))}
            </TableBody>
            <tfoot className="bg-muted font-semibold">
              <tr>
                <TableCell className="sticky start-0 z-10 bg-muted">{t('payroll.total')}</TableCell>
                <TableCell />
                {cols.map(([kind, key]) => <TableCell key={`${kind}:${key}`} className="text-end">{kind === 'component' && money(sheet.totals[key])}</TableCell>)}
              </tr>
            </tfoot>
          </Table>
        )}
      </Card>
      {panel && path && <DaysPanel employee={panel} path={path} onClose={() => setPanel(undefined)} />}
      {overriding && path && <OverrideDialog {...overriding} path={path} onClose={() => setOverriding(undefined)} />}
    </div>
  )
}

/**
 * One employee's line. HR's typed values stay on the row until it is written: the row saves when the focus leaves it or on Enter,
 * so a deduction and its reason go in one `PUT …/entry` (PUT semantics: every field of the type, from the entry the typing started
 * on, with that entry's version, so a refetch meanwhile cannot turn the write into an overwrite). A 409 drops the typing and reloads.
 */
function LineRow({ line, cols, group, path, editable, onOpen, onOverride }: { line: Line; cols: Col[]; group: string; path: string; editable: boolean; onOpen: () => void; onOverride: (component: string) => void }) {
  const { t, i18n } = useTranslation()
  const qc = useQueryClient()
  const save = useSave()
  const money = useMoney()
  const ids = useId()
  const name = localName(line.employee, i18n.language)
  const [edit, setEdit] = useState<{ base: Entry; drafts: Record<string, string> }>()
  const [errs, setErrs] = useState<Errs>({})
  const [busy, setBusy] = useState(false)
  const flight = useRef(false)
  const fields = [...FIELDS[group], 'note']
  const value = (f: string) => edit?.drafts[f] ?? asText(f, (edit?.base ?? line.entry)[f])
  const change = (f: string, v: string) => setEdit((e) => ({ base: e?.base ?? line.entry, drafts: { ...e?.drafts, [f]: TEXTS.includes(f) ? v : asciiDigits(v) } }))

  async function commit() {
    if (!edit || flight.current) return
    if (Object.entries(edit.drafts).every(([f, v]) => v === asText(f, edit.base[f]))) return setEdit(undefined) // typed back to what it was
    flight.current = true
    setBusy(true)
    let done = false
    try {
      await save(`${path}/employees/${line.employee.id}/entry`, { method: 'PUT', body: { version: edit.base.version, ...Object.fromEntries(fields.map((f) => [f, toBody(f, value(f))])) } })
      toast.success(t('common.saved'))
      done = true
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        toast.error(t('payroll.conflict', { name }))
        done = true
      } else if (e instanceof ApiError && e.details.some((d) => d.field && fields.includes(d.field))) setErrs(toErrs(e))
      else toast.error(e instanceof Error ? e.message : String(e))
    } finally {
      // useSave has started the refetch; the typing stays on screen until it lands, so the row never flashes the old values
      await qc.invalidateQueries({ queryKey: queryKey(path) }, { cancelRefetch: false })
      if (done) {
        setEdit(undefined)
        setErrs({})
      }
      flight.current = false
      setBusy(false)
    }
  }
  const leave = (e: FocusEvent<HTMLInputElement>) => !e.currentTarget.closest('tr')?.contains(e.relatedTarget as Node | null) && void commit()

  function cell(col: Col) {
    const [kind, key] = col
    const label = t('payroll.cellLabel', { label: colLabel(t, col), name })
    const text = TEXTS.includes(key)
    if (kind === 'field' || FIELDS[group].includes(key)) {
      if (!editable) {
        const shown = kind === 'component' ? money(line.components[key].final) : asText(key, line.entry[key])
        return <TableCell key={key} className={cn(text ? 'max-w-48 truncate' : 'text-end')} title={text ? shown : undefined}>{shown}</TableCell>
      }
      const errId = `${ids}-${key}`
      return (
        <TableCell key={key} className="px-1.5 py-1.5 align-top first:ps-1.5 last:pe-1.5">
          <Input aria-label={label} value={value(key)} readOnly={busy} dir={text ? undefined : 'ltr'} inputMode={text ? undefined : COUNTS.includes(key) ? 'numeric' : 'decimal'} maxLength={text ? 500 : undefined}
            aria-invalid={!!errs[key] || undefined} aria-describedby={errs[key] ? errId : undefined} className={cn(text ? 'w-44' : 'w-28 text-end', edit && key in edit.drafts && 'border-primary')}
            onChange={(e) => change(key, e.target.value)} onBlur={leave} onKeyDown={(e) => e.key === 'Enter' && void commit()} />
          {errs[key] && <p id={errId} className="mt-1 max-w-44 text-xs whitespace-normal text-destructive">{errs[key]}</p>}
        </TableCell>
      )
    }
    const a = line.components[key]
    const o = a.override
    const shown = money(a.final)
    // the override's reason: the title on hover, the accessible name for screen readers; a click shows it in full (the dialog or the side panel)
    const note = o && [t('payroll.overridden', { reason: o.reason }), ...(o.inputs_changed ? [t('payroll.inputsChanged')] : [])].join(' — ')
    const look = cn('inline-flex min-h-tap items-center gap-1 rounded-md px-1.5', o && 'bg-info-bg text-info ring-1 ring-info-border', key === 'net' && 'font-semibold', a.final.startsWith('-') && 'text-destructive')
    const body = (
      <>
        {o && <PenLine aria-hidden className="size-3.5 shrink-0" />}
        {o?.inputs_changed && <TriangleAlert aria-hidden className="size-3.5 shrink-0 text-warning" />}
        {shown}
      </>
    )
    return (
      <TableCell key={key} className="px-2 py-1 text-end">
        {editable || o ? (
          <button type="button" className={cn(FOCUS, look, 'hover:bg-muted')} title={note || undefined} aria-label={`${label}: ${shown}${note ? ` — ${note}` : ''}`}
            onClick={() => (editable ? onOverride(key) : onOpen())}>
            {body}
          </button>
        ) : (
          <span className={look}>{body}</span>
        )}
      </TableCell>
    )
  }

  return (
    <TableRow>
      <TableCell className="sticky start-0 z-10 bg-card p-0 first:ps-0">
        <button type="button" title={name} aria-label={t('payroll.openDays', { name })} onClick={onOpen}
          className={cn(FOCUS, 'flex min-h-tap w-full max-w-36 items-center gap-2 px-3 text-start sm:max-w-60')}>
          <span className="text-muted-foreground">{line.employee.code}</span>
          <bdi className="truncate font-medium text-primary underline-offset-4 hover:underline">{name}</bdi>
        </button>
      </TableCell>
      <TableCell className="min-w-32 whitespace-normal"><Flags flags={line.flags} /></TableCell>
      {cols.map(cell)}
    </TableRow>
  )
}

/** HR's override of one computed component (amount and reason), or, on an overridden one, its revert (reason): one live override per component. */
function OverrideDialog({ line, component, path, onClose }: { line: Line; component: string; path: string; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const send = useSave()
  const money = useMoney()
  const a = line.components[component]
  const o = a.override
  const names = { label: t(`payroll.component.${component}`), name: localName(line.employee, i18n.language) }
  const base = `${path}/employees/${line.employee.id}/overrides`
  return (
    <FormDialog open onOpenChange={(v) => !v && onClose()} title={t(o ? 'payroll.override.revertTitle' : 'payroll.override.title', names)} description={t(o ? 'payroll.override.revertBody' : 'payroll.override.body')}
      init={{ amount: '', reason: '' }} shown={['amount', 'reason']} footer={o ? 'payroll.override.revert' : 'payroll.override.save'}
      validate={(f) => ({ ...(!o && !f.amount.trim() && { amount: t('payroll.override.amountRequired') }), ...(!f.reason.trim() && { reason: t(o ? 'payroll.override.revertReasonRequired' : 'payroll.override.reasonRequired') }) })}
      submit={(f) => (o ? send(`${base}/${o.id}`, { method: 'DELETE', body: { reason: f.reason } }) : send(base, { body: { component, amount: f.amount, reason: f.reason } }))}
      onDone={() => toast.success(t('common.saved'))}>
      {({ values, set, errs }) => (
        <>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt className="text-muted-foreground">{t('payroll.override.computed')}</dt>
            <dd>{money(a.computed)}</dd>
            {o && (
              <>
                <dt className="text-muted-foreground">{t('payroll.override.current')}</dt>
                <dd>{money(o.amount)}</dd>
                <dt className="text-muted-foreground">{t('payroll.override.reasonWas')}</dt>
                <dd className="break-words">{o.reason}</dd>
              </>
            )}
          </dl>
          {o?.inputs_changed && <Alert tone="warning">{t('payroll.inputsChanged')}</Alert>}
          {!o && <TextField name="amount" label={t('payroll.override.amount')} inputMode="decimal" dir="ltr" values={values} set={set} errs={errs} />}
          <Field name="reason" label={t('payroll.override.reason')} errs={errs}>
            {(p) => <Textarea {...p} required maxLength={500} value={values.reason} onChange={(e) => set('reason', e.target.value)} />}
          </Field>
        </>
      )}
    </FormDialog>
  )
}

/** One employee's line opened beside the sheet: its flags, its overrides with their reasons, and every day of the period with what it earned. */
function DaysPanel({ employee, path, onClose }: { employee: Row; path: string; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const lang = i18n.language
  const { data: line, err } = useApi<Line & { days: Day[] }>(`${path}/employees/${employee.id}`)
  const { data: sites = [] } = useApi<Row[]>(SITES)
  const daysId = useId()
  const overridesId = useId()
  const place = (d: Day) => {
    if (d.state === 'leave') return t(`attendance.leaveName.${d.leave_code}`, { defaultValue: d.leave_code ?? '—' })
    if (d.state !== 'work' && d.state !== 'open') return t(`attendance.state.${d.state === 'not_employed' ? 'notEmployed' : d.state}`)
    const s = sites.find((x) => x.id === d.site_id)
    return s ? localName(s, lang) : '—'
  }
  const overrides = line ? Object.entries(line.components).filter(([, a]) => a.override) : []
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" aria-describedby={undefined}>
        <SheetHeader>
          <SheetTitle>{t('payroll.daysOf', { name: localName(employee, lang) })}</SheetTitle>
        </SheetHeader>
        <div className="grid flex-1 content-start gap-4 overflow-y-auto overscroll-contain p-5">
          <ErrorBanner err={err} shown={[]} />
          {!line ? (
            !err && <TableSkeleton rows={6} />
          ) : (
            <>
              {line.flags.length > 0 && <Flags flags={line.flags} />}
              {overrides.length > 0 && (
                <section aria-labelledby={overridesId} className="grid gap-2">
                  <h3 id={overridesId} className="text-sm font-semibold">{t('payroll.overrides')}</h3>
                  <ul className="grid gap-2 text-sm">
                    {overrides.map(([c, a]) => (
                      <li key={c} className="rounded-lg border border-info-border bg-info-bg p-3">
                        <p className="font-medium">{t(`payroll.component.${c}`)}: {money(a.final)} <span className="text-muted-foreground">({t('payroll.override.computed')}: {money(a.computed)})</span></p>
                        <p className="break-words">{t('payroll.overridden', { reason: a.override!.reason })}</p>
                        {a.override!.inputs_changed && <p className="text-warning">{t('payroll.inputsChanged')}</p>}
                      </li>
                    ))}
                  </ul>
                </section>
              )}
              <h3 id={daysId} className="text-sm font-semibold">{t('payroll.days')}</h3>
              <ul aria-labelledby={daysId} className="divide-y rounded-lg border text-sm">
                {line.days.map((d) => {
                  const parts = Object.entries(d.parts).filter(([, v]) => !/^-?0(\.0*)?$/.test(v)) // string test: money never goes through a float
                  const flags = d.flags.filter((f) => f !== 'missing') // the day's state already says it
                  return (
                    <li key={d.date} className="grid gap-1 px-3 py-2">
                      <div className="flex flex-wrap items-center gap-x-3">
                        <bdi dir="ltr" className="font-medium">{d.date}</bdi>
                        <span className="text-muted-foreground">{t(`weekdayShort.${weekdayOf(d.date)}`)}</span>
                        <span className="min-w-0 flex-1 truncate">{place(d)}</span>
                        <span title={t('payroll.dayCost')}><span className="sr-only">{t('payroll.dayCost')} </span>{money(d.day_cost)}</span>
                      </div>
                      {(parts.length > 0 || flags.length > 0) && (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                          {parts.map(([c, v]) => <span key={c}>{t(`payroll.component.${c}`)} <span className="text-foreground">{money(v)}</span></span>)}
                          {flags.length > 0 && <Flags flags={flags} />}
                        </div>
                      )}
                    </li>
                  )
                })}
              </ul>
            </>
          )}
        </div>
      </SheetContent>
    </Sheet>
  )
}
