import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { StatusBadge } from '@/components/common/StatusBadge'
import { ErrorBanner, Field, localName, type Row, SelectField, TextField, toErrs, useForm } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/input'
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { DAY_VALUES as VALUES } from '@/lib/day-sheet'
import { SITES, useApi, useSave } from '@/lib/query'

type Day = Row & { fields: string[]; leave_day_fields: string[]; hints: Record<string, number>; period: { is_locked: boolean } | null }
type Options = [string, string][]

const TIMES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out']
const MINUTES = ['late_minutes', 'ot_minutes', 'outside_minutes']
const OUTSIDE = ['outside_minutes', 'outside_amount']
const TRANSPORT = ['full', 'half', 'none']
// lateness is computed from the in time, overtime from the out time, so each hint sits under that time
const HINT: Record<string, [hint: string, key: string]> = { in_time: ['late_minutes', 'attendance.day.hintLate'], out_time: ['ot_minutes', 'attendance.day.hintOt'] }

const AMOUNTS = ['outside_amount', 'advance_amount']
/** Amounts: "500.000000" → "500" by string surgery (money never goes through a float). Every other field shows as typed. */
const asText = (f: string, v: unknown) => (v == null ? '' : AMOUNTS.includes(f) && typeof v === 'string' && /^\d+\.\d+$/.test(v) ? v.replace(/\.?0+$/, '') : String(v))
/** Blank → null; minutes as integers (anything else goes as typed, so the server names the field). */
const toBody = (f: string, v: string) => (v === '' ? null : MINUTES.includes(f) && /^\d+$/.test(v) ? Number.parseInt(v, 10) : v)

/** One employee's day in a sheet. "Load latest" remounts the body, which refetches the day before the form starts again. */
export function DayEditor({ employee, date, onOpenChange }: { employee: Row; date: string; onOpenChange: (open: boolean) => void }) {
  const [gen, setGen] = useState(0)
  return (
    <Sheet open onOpenChange={onOpenChange}>
      <SheetContent side="right" aria-describedby={undefined}>
        <DayBody key={gen} employee={employee} date={date} onDone={() => onOpenChange(false)} onReload={() => setGen((g) => g + 1)} />
      </SheetContent>
    </Sheet>
  )
}

function DayBody({ employee, date, onDone, onReload }: { employee: Row; date: string; onDone: () => void; onReload: () => void }) {
  const { t, i18n } = useTranslation()
  const path = `/attendance/days/${employee.id}/${date}`
  const day = useApi<Day>(path, { fresh: true })
  const sites = useApi<Row[]>(SITES) // shared with the grid
  const err = day.err ?? sites.err
  const ready = day.ready && day.data && sites.data
  return (
    <>
      <div className="flex flex-wrap items-center gap-2 border-b p-5 pe-14">
        <SheetTitle>{t('attendance.day.title', { date, name: localName(employee, i18n.language) })}</SheetTitle>
        {ready && day.data?.source && <StatusBadge variant="neutral" label={t(`attendance.source.${day.data.source}`)} />}
      </div>
      {ready ? (
        <DayForm day={day.data!} sites={sites.data!} path={path} loadErr={err} onDone={onDone} onReload={onReload} />
      ) : (
        <div className="grid gap-4 p-5">
          <ErrorBanner err={err} shown={[]} />
          {!err && <TableSkeleton rows={4} />}
        </div>
      )}
    </>
  )
}

/** Starts from the day as the server sent it (its defaults included) and sends only the fields the chosen site allows. */
function DayForm({ day, sites, path, loadErr, onDone, onReload }: { day: Day; sites: Row[]; path: string; loadErr?: unknown; onDone: () => void; onReload: () => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const send = useSave()
  const { form, set } = useForm({ site_id: day.site_id ?? '', ...Object.fromEntries(VALUES.map((f) => [f, asText(f, day[f])])) })
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [version] = useState(day.version) // the version the form started from; only "Load latest" (a remount) moves it, so a refetch after a 409 cannot turn the next Save into an overwrite
  const locked = !!day.period?.is_locked
  const readOnly = locked || !can('attendance.edit')
  const site = sites.find((s) => s.id === form.site_id)
  const shown = (site?.kind === 'leave' ? day.leave_day_fields : day.fields).filter((f) => !OUTSIDE.includes(f) || site?.outside_alexandria)
  const errs = toErrs(err)
  const conflict = err instanceof ApiError && err.status === 409
  // the seeded leave types have no name_en: in English they fall back to their code's name
  const siteName = (s: Row) => localName(s, i18n.language, s.leave_code ? t(`attendance.leaveName.${s.leave_code}`, { defaultValue: s.name_ar }) : undefined)
  // a stored day keeps its site in the list even after the site is deactivated
  const usable = (kind: string): Options => sites.filter((s) => s.kind === kind && (s.active || s.id === day.site_id)).map((s) => [s.id, siteName(s)])
  const daySite = sites.find((s) => s.id === day.site_id)
  const leaveName = daySite ? siteName(daySite) : t(`attendance.short.${day.leave_code}`, { defaultValue: day.leave_code })
  const leaveGroup: [string, Options] = [t('attendance.day.leaveTypes'), usable('leave')]
  const siteChoices = day.type === 'office'
    ? { options: [['', t('attendance.present')]] as Options, groups: [leaveGroup] }
    : { options: [] as Options, blank: t('attendance.day.chooseSite'), groups: [[t('attendance.day.workSites'), usable('work')] as [string, Options], leaveGroup] }

  function run(write: () => Promise<unknown>) {
    setBusy(true)
    setErr(undefined)
    write()
      .then(() => { toast.success(t('common.saved')); onDone() }, setErr)
      .finally(() => setBusy(false))
  }
  const save = () => run(() => send(path, { method: 'PUT', body: { version, site_id: form.site_id || null, ...Object.fromEntries(shown.map((f) => [f, toBody(f, form[f])])) } }))
  const clear = () => run(() => send(`${path}?version=${version}`, { method: 'DELETE' }))

  function field(f: string) {
    const label = t(`attendance.day.${f}`)
    if (f === 'transport')
      return <SelectField key={f} name={f} label={label} values={form} set={set} errs={errs} blank disabled={readOnly} options={TRANSPORT.map((v) => [v, t(`attendance.transport.${v}`)])} />
    if (f === 'note')
      return (
        <Field key={f} name={f} label={label} errs={errs}>
          {(p) => <Textarea {...p} value={form.note} maxLength={500} disabled={readOnly} onChange={(e) => set(f, e.target.value)} />}
        </Field>
      )
    const hint = HINT[f] && day.hints[HINT[f][0]]
    return (
      <div key={f} className="grid gap-1.5">
        <TextField name={f} label={label} values={form} set={set} errs={errs} disabled={readOnly} {...(TIMES.includes(f) ? { type: 'time' } : { inputMode: MINUTES.includes(f) ? 'numeric' : 'decimal' })} />
        {hint != null && <p className="text-[13px] text-muted-foreground">{t(HINT[f][1], { minutes: hint })}</p>}
      </div>
    )
  }

  return (
    <>
      {/* read-only: the controls' own disabled:opacity-50 would wash out the muted fill and the values */}
      <div className={`grid flex-1 content-start gap-4 overflow-y-auto p-5 [&_input]:h-11 [&_select]:h-11 [&_input:disabled]:bg-muted [&_textarea:disabled]:bg-muted [&_select:disabled]:bg-muted ${readOnly ? '[&_:disabled]:opacity-100' : ''}`}>
        {locked && <p className="rounded-md bg-muted p-3 text-sm">{t('attendance.day.lockedNote')}</p>}
        {readOnly && !locked && <p className="rounded-md bg-muted p-3 text-sm">{t('attendance.day.readOnlyNote')}</p>}
        {day.state === 'derived' && <p className="rounded-md bg-muted p-3 text-sm">{t('attendance.day.derivedNote', { type: leaveName })}</p>}
        {day.state === 'missing' && <p className="rounded-md bg-muted p-3 text-sm">{t('attendance.day.missingNote')}</p>}
        <ErrorBanner err={loadErr} shown={[]} />
        {conflict ? (
          <div role="alert" className="flex flex-wrap items-center gap-3 rounded-md border border-destructive p-3 text-sm text-destructive">
            <p className="flex-1">{t('attendance.day.conflict')}</p>
            <Button type="button" variant="outline" className="h-11" onClick={onReload}>
              {t('attendance.day.loadLatest')}
            </Button>
          </div>
        ) : (
          <ErrorBanner err={err} shown={[...shown, 'site_id']} />
        )}
        <SelectField name="site_id" label={t('attendance.day.siteOrType')} values={form} set={set} errs={errs} disabled={readOnly} {...siteChoices} />
        {shown.map(field)}
      </div>
      {!readOnly && (
        <div className="flex flex-wrap gap-2 border-t p-4">
          <Button type="button" className="h-11" disabled={busy} onClick={save}>
            {t('common.save')}
          </Button>
          {day.state === 'stored' && (
            <Button type="button" variant="outline" className="h-11 text-destructive" disabled={busy} onClick={clear}>
              {t('attendance.day.clear')}
            </Button>
          )}
        </div>
      )}
    </>
  )
}
