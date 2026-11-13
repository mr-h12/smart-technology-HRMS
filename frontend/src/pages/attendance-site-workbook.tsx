import { Download, FileSearch, Save, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { cairoToday, ErrorBanner, Field, formatTime, localName, ltrDates, type Row, SelectField, selectCls, TextField, useForm, useMoney } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { api, apiBlob, ApiError } from '@/lib/api'
import { useApi, useSave, WORK_SITES } from '@/lib/query'
import { cn } from '@/lib/utils'
import { errorKey, STEP } from './attendance-import'

const MAX_DAYS = 31 // the server's own limit (SiteWorkbookTemplate::MAX_DAYS): one sheet per day
const MAX_BYTES = 5 * 1024 * 1024 // the server's own limit (max:5120 KB)
const MAX_ROWS = 2000 // the server's own cap per sheet (SiteWorkbookImport::MAX_ROWS), for the too_many_rows message

/** A stored or written day as the server reads it back; `site` is the site or leave day-type, or the present word for office present. */
type Day = { site: string | null; in_time: string | null; out_time: string | null; late_minutes: number | null; ot_minutes: number | null; advance_amount: string | null; note: string | null }
type Sheet = { sheet: string; row: number }
/** A new laborer's row has no key, id or code: the preview rolls them back. `from` is null when no day is stored. */
type Change = Sheet & { key: string | null; date: string; code: number | null; name_ar: string; name_en: string | null; new_laborer: boolean; matched_by_national_id: boolean; conflict_site: string | null; from: Day | null; to: Day }
type RowError = Sheet & { date: string; name: string | null; error: string; message: string }
type NewLaborer = { name_ar: string; national_id: string; phone: string | null; residence: string | null; proposed_wage: string | null; gender: string; first_date: string; days: number }
type Summary = { rows: number; changes: number; unchanged: number; errors: number; conflicts: number; new_laborers: number; advances_total: string }
type Preview = { site: Row; from: string; to: string; token: string; summary: Summary; changes: Change[]; new_laborers: NewLaborer[]; errors: RowError[] }
type Added = { employee_id: string; code: number; name_ar: string; proposal: boolean }
type Result = { applied: number; unchanged: number; kept: number; advances_total: string; new_laborers: Added[]; errors: RowError[] }

/** Today's half-month in Cairo: the 1st to the 15th, else the 16th to the month's last day. Built from the date's text, so no time zone moves it. */
function halfMonth() {
  const today = cairoToday()
  const month = today.slice(0, 7)
  if (Number(today.slice(8)) <= 15) return { from: `${month}-01`, to: `${month}-15` }
  const last = new Date(Date.UTC(Number(today.slice(0, 4)), Number(today.slice(5, 7)), 0)).getUTCDate() // day 0 of the next month
  return { from: `${month}-16`, to: `${month}-${last}` }
}

/** The days from `from` to `to`, both counted. A YYYY-MM-DD string parses as UTC midnight, so the difference is whole days. */
const span = (from: string, to: string) => (Date.parse(to) - Date.parse(from)) / 86_400_000 + 1
/** A code's text in the UI language: the site workbook's own wording where the per-type one does not fit, else the shared one. */
const errKeys = (code: string) => [`attendance.import.site.error.${code}`, errorKey(code)]
const dash = (v: string | number | null) => v ?? '—'
/** The sheet HR looks for and the row on it; the sheet name is the file's own (an Arabic weekday and day-month). Whole tokens: DataList lets a cell break anywhere. */
const sheetRow = (r: Sheet) => (
  <span className="whitespace-normal wrap-normal">
    <bdi className="whitespace-nowrap">{r.sheet}</bdi> · <bdi dir="ltr" className="whitespace-nowrap">{r.row}</bdi>
  </span>
)

/**
 * HR's site workbook (ملف الموقع), the import tab's per-site side: pick an active work site and up to MAX_DAYS days (today's
 * half-month by default) and download the empty file; then pick the filled file, preview what it would change (nothing is
 * saved; the site and range come from the file), choose keep or replace for each conflict, and confirm with the same File.
 */
export function SiteWorkbookTab() {
  const { t, i18n } = useTranslation()
  const save = useSave()
  const money = useMoney()
  const { form, set } = useForm({ site_id: '', ...halfMonth() })
  const { site_id, from, to } = form as Record<'site_id' | 'from' | 'to', string>
  const { data: sites, err: sitesErr } = useApi<Row[]>(WORK_SITES)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<unknown>()
  const [file, setFile] = useState<File>()
  const [bad, setBad] = useState(false)
  const [preview, setPreview] = useState<Preview>()
  const [choices, setChoices] = useState<Record<string, string>>({}) // conflict key → keep | replace
  const [result, setResult] = useState<Result>()
  // the server refuses both; saying so here keeps HR from a download that cannot work
  const why = from && to && (to < from ? t('attendance.import.site.reversed') : span(from, to) > MAX_DAYS ? t('attendance.import.site.tooLong', { max: MAX_DAYS }) : '')
  // a conflict is a stored day, so it always has a key (only a new laborer's change has none)
  const conflicts = preview?.changes.filter((c): c is Change & { key: string } => !!c.conflict_site && c.key !== null) ?? []
  const chosen = new Set(conflicts.map((c) => choices[c.key] ?? ''))
  const open = chosen.has('')
  // a refusal belongs to the inputs it answered: editing them clears it, as a new file does
  const edit = (k: string, v: string) => {
    set(k, v)
    setErr(undefined)
  }

  function run<T>(work: () => Promise<T>, done: (r: T) => void) {
    setBusy(true)
    setErr(undefined)
    work()
      .then(done, setErr)
      .finally(() => setBusy(false))
  }
  const download = () =>
    run(() => apiBlob(`/attendance/site-workbook?${new URLSearchParams({ site_id, from, to })}`), (blob) => {
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `site-workbook-${from}-${to}.xlsx`
      a.click()
      setTimeout(() => URL.revokeObjectURL(a.href), 1000) // after the browser has started reading it
    })
  // a new file drops the old preview and its choices, so confirm can only ever send the file that was previewed
  function pick(f: File | undefined) {
    const ok = !!f && f.size <= MAX_BYTES && f.name.toLowerCase().endsWith('.xlsx')
    setFile(ok ? f : undefined)
    setBad(!!f && !ok)
    setPreview(undefined)
    setChoices({})
    setResult(undefined)
    setErr(undefined)
  }
  const body = () => {
    const f = new FormData()
    f.set('file', file!)
    return f
  }
  const check = () =>
    run(() => api<Preview>('/attendance/site-workbook/preview', { method: 'POST', body: body() }), (p) => {
      setPreview(p)
      setChoices({})
      setResult(undefined)
    })
  function confirm() {
    const f = body()
    f.set('token', preview!.token)
    for (const c of conflicts) f.set(`choices[${c.key}]`, choices[c.key])
    run(
      // confirm can create employees (new laborers) as well as days
      () => save<Result>('/attendance/site-workbook/confirm', { body: f }, ['/attendance', '/employees']).catch((e: unknown) => {
        // the stored days changed since the preview, so it no longer says what confirm would do: HR previews again
        if (e instanceof ApiError && e.code === 'preview_stale') {
          setPreview(undefined)
          setChoices({})
        }
        throw e
      }),
      (r) => {
        setResult(r)
        setPreview(undefined)
        setChoices({})
      },
    )
  }
  const errors = preview?.errors ?? result?.errors ?? []
  // a new laborer is hired even when every row of theirs is refused (days 0), so they alone are worth a confirm
  const saving = !!preview && (preview.changes.length > 0 || preview.new_laborers.length > 0)
  // a refusal this UI knows follows the UI language, as on the per-type side
  const shownErr = err instanceof ApiError && i18n.exists(errKeys(err.code)) ? new Error(t(errKeys(err.code), { max: MAX_ROWS })) : err
  const choiceOptions = (['keep', 'replace'] as const).map((v): [string, string] => [v, t(`attendance.import.site.${v}`)])

  return (
    <div className="space-y-4">
      <Card className="grid gap-4 p-5">
        <p className="text-sm text-muted-foreground">{t('attendance.import.site.desc', { max: MAX_DAYS })}</p>
        {/* the flow's steps, numbered in one list; role="list" because Safari drops it with list-style none */}
        <ol role="list" className="grid gap-4">
          <li className="flex items-start gap-3">
            <span aria-hidden className={STEP}>1</span>
            <div className="grid min-w-0 flex-1 gap-4">
              <div className="grid gap-4 sm:grid-cols-3 sm:items-start lg:max-w-3xl">
                <SelectField name="site_id" label={t('attendance.siteFilter')} values={form} set={edit} errs={{}} blank={t('attendance.import.site.choose')} options={(sites ?? []).map((s): [string, string] => [s.id, localName(s, i18n.language)])} />
                <TextField name="from" type="date" label={t('attendance.import.site.from')} values={form} set={edit} errs={{}} />
                <TextField name="to" type="date" label={t('attendance.import.site.to')} values={form} set={edit} errs={why ? { to: why } : {}} />
              </div>
              {sites?.length === 0 && <p className="text-sm text-muted-foreground">{t('attendance.import.site.noSites')}</p>}
              <Button type="button" variant="outline" className="justify-self-start" disabled={busy || !site_id || !from || !to || !!why} onClick={download}>
                <Download aria-hidden /> {t('attendance.import.site.download')}
              </Button>
            </div>
          </li>
          <li className="flex items-start gap-3">
            <span aria-hidden className={STEP}>2</span>
            <div className="min-w-0 flex-1">
              <Field name="file" id="workbook_file" label={t('attendance.import.file')} errs={bad ? { file: t('attendance.import.badFile') } : {}}>
                {(p) => (
                  // the real input stays (named by the field label) but hidden: its own "Choose File" text is in the browser's language, not the app's
                  <div className="flex min-w-0 items-center gap-3">
                    <input {...p} type="file" accept=".xlsx" className="peer sr-only" disabled={busy} onChange={(e) => pick(e.target.files?.[0])} />
                    <label htmlFor={p.id} aria-hidden className={cn(buttonVariants({ variant: 'outline' }), 'cursor-pointer peer-focus-visible:outline-2 peer-focus-visible:outline-solid peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring peer-disabled:pointer-events-none peer-disabled:opacity-50 peer-aria-invalid:border-destructive')}>
                      <Upload aria-hidden /> {t('attendance.import.choose')}
                    </label>
                    <span className="min-w-0 text-sm break-all text-muted-foreground">{file?.name ?? t('attendance.import.noFile')}</span>
                  </div>
                )}
              </Field>
            </div>
          </li>
          <li className="flex items-center gap-3">
            <span aria-hidden className={STEP}>3</span>
            <Button type="button" variant={preview ? 'outline' : 'default'} disabled={busy || !file} onClick={check}>
              <FileSearch aria-hidden /> {t('attendance.import.preview')}
            </Button>
          </li>
        </ol>
        <ErrorBanner err={shownErr ?? sitesErr} shown={[]} />
        {preview && (
          <div className="grid gap-3 border-t pt-4">
            <div className="grid gap-1">
              <h3 className="font-medium">{ltrDates(t('attendance.import.site.previewOf', { site: localName(preview.site, i18n.language), from: preview.from, to: preview.to }))}</h3>
              <p className="text-sm">{t('attendance.import.site.summary', preview.summary)}</p>
              {/* the server's total over the file's rows it accepts; the client never adds money */}
              <p className="text-sm">{t('attendance.import.site.advances', { total: money(preview.summary.advances_total) })}</p>
              <p className="text-sm text-muted-foreground">{t(saving ? 'attendance.import.notSaved' : preview.summary.rows ? 'attendance.import.site.nothing' : 'attendance.import.site.empty')}</p>
            </div>
            {saving && (
              <div className="flex flex-wrap items-center gap-3">
                <Button type="button" disabled={busy || open} aria-describedby={open ? 'workbook_open' : undefined} onClick={confirm}>
                  <Save aria-hidden /> {t('attendance.import.confirm')}
                </Button>
                {open && <p id="workbook_open" className="text-sm text-muted-foreground">{t('attendance.import.site.chooseAll')}</p>}
              </div>
            )}
          </div>
        )}
        {result && (
          <Alert tone="success">
            <p className="font-medium">{t('attendance.import.site.done', { saved: t('attendance.import.saved', { count: result.applied }), unchanged: result.unchanged, kept: result.kept, errors: result.errors.length })}</p>
            {/* the server's total over the rows saved or left unchanged: the same rows as the preview's total */}
            <p>{t('attendance.import.site.advances', { total: money(result.advances_total) })}</p>
          </Alert>
        )}
      </Card>
      {!!conflicts.length && (
        <Card className="overflow-hidden">
          <CardHeader className="grid gap-3 border-b">
            <CardTitle>{t('attendance.import.site.conflicts')}</CardTitle>
            <p className="text-sm text-muted-foreground">{t('attendance.import.site.conflictsDesc')}</p>
            <div className="sm:max-w-xs">
              <SelectField
                name="workbook_all"
                label={t('attendance.import.site.setAll')}
                values={{ workbook_all: chosen.size === 1 ? [...chosen][0] : '' }}
                set={(_, v) => setChoices(Object.fromEntries(conflicts.map((c) => [c.key, v])))}
                errs={{}}
                blank={t('attendance.import.site.pick')}
                options={choiceOptions}
                disabled={busy}
              />
            </div>
          </CardHeader>
          <DataList
            label={t('attendance.import.site.conflicts')}
            rows={conflicts}
            rowKey={(c) => c.key}
            // the person first, with the sheet under the name: four columns, so the choice fits beside both days from 640 px
            title={{
              header: t('attendance.employee'),
              cell: (c) => (
                <span className="grid gap-0.5">
                  <Who c={c} />
                  <span className="text-xs font-normal text-muted-foreground">{sheetRow(c)}</span>
                </span>
              ),
            }}
            fields={[
              { header: t('attendance.import.site.recorded'), cell: (c) => <DayText d={c.from} /> },
              { header: t('attendance.import.site.inFile'), cell: (c) => <DayText d={c.to} /> },
              {
                header: t('attendance.import.site.choice'),
                cell: (c) => (
                  <select aria-label={t('attendance.import.site.choiceFor', { name: localName(c, i18n.language), date: c.date })} className={cn(selectCls, 'w-auto')} value={choices[c.key] ?? ''} disabled={busy} onChange={(e) => setChoices((o) => ({ ...o, [c.key]: e.target.value }))}>
                    <option value="">{t('attendance.import.site.pick')}</option>
                    {choiceOptions.map(([v, l]) => (
                      <option key={v} value={v}>
                        {l}
                      </option>
                    ))}
                  </select>
                ),
              },
            ]}
          />
        </Card>
      )}
      {!!preview?.changes.length && <Changes rows={preview.changes} />}
      {!!preview?.new_laborers.length && <NewLaborers rows={preview.new_laborers} />}
      {!!result?.new_laborers.length && <AddedLaborers rows={result.new_laborers} />}
      {!!errors.length && <Errors rows={errors} />}
    </div>
  )
}

/** The employee's ID and name, then what HR must notice: new (no ID until confirm creates them), found by national ID, a conflict. */
function Who({ c, conflict = false }: { c: Change; conflict?: boolean }) {
  const { t, i18n } = useTranslation()
  return (
    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 whitespace-normal wrap-normal">
      {!c.new_laborer && <span className="whitespace-nowrap text-muted-foreground">{c.code}</span>}
      <bdi>{localName(c, i18n.language)}</bdi>
      {c.new_laborer && <Badge variant="info">{t('attendance.import.site.new')}</Badge>}
      {c.matched_by_national_id && <Badge variant="neutral" className="whitespace-normal">{t('attendance.import.site.matched')}</Badge>}
      {conflict && <Badge variant="warning">{t('attendance.import.site.conflict')}</Badge>}
    </span>
  )
}

/** A day in one line: its site or leave day-type, then its times when it has any. */
function DayText({ d }: { d: Day | null }) {
  const { i18n } = useTranslation()
  if (!d) return '—'
  return (
    <span className="whitespace-normal wrap-normal">
      <bdi>{dash(d.site)}</bdi>
      {d.in_time && (
        <>
          {' · '}
          <bdi>
            <span className="whitespace-nowrap">{formatTime(d.in_time, i18n.language)}</span>–<span className="whitespace-nowrap">{formatTime(d.out_time, i18n.language)}</span>
          </bdi>
        </>
      )}
    </span>
  )
}

// ponytail: every row renders on one page; fine for a site's few hundred rows, pass DataList a page if files grow past that
function Changes({ rows }: { rows: Change[] }) {
  const { t } = useTranslation()
  const money = useMoney()
  const title = t('attendance.import.changes')
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <DataList
        label={title}
        rows={rows}
        rowKey={(c) => `${c.sheet}#${c.row}`}
        title={{ header: t('attendance.import.site.sheetRow'), cell: sheetRow }}
        fields={[
          { header: t('attendance.employee'), cell: (c) => <Who c={c} conflict={!!c.conflict_site} /> },
          { header: t('attendance.import.from'), cell: (c) => <span className="text-muted-foreground"><DayText d={c.from} /></span> },
          {
            // five columns, so the table fits its card from 640 px: late, overtime and the note sit under the new value
            header: t('attendance.import.to'),
            cell: (c) => (
              <span className="grid gap-0.5 font-medium">
                <DayText d={c.to} />
                <span className="grid gap-0.5 text-xs font-normal whitespace-normal wrap-normal text-muted-foreground empty:hidden">
                  {/* no minutes for leave days or permanent workers: no line, and never a 0 for a missing figure */}
                  {(c.to.late_minutes !== null || c.to.ot_minutes !== null) && <span>{t('attendance.import.site.lateOt', { late: dash(c.to.late_minutes), ot: dash(c.to.ot_minutes) })}</span>}
                  {/* a day entered by hand that the file repeats: only its source changes */}
                  {JSON.stringify(c.from) === JSON.stringify(c.to) && <span>{t('attendance.import.site.same')}</span>}
                  {c.to.note && (
                    <span>
                      <bdi>{t('siteDay.noteShown', { note: c.to.note })}</bdi>
                    </span>
                  )}
                </span>
              </span>
            ),
          },
          { header: t('attendance.day.advance_amount'), numeric: true, cell: (c) => money(c.to.advance_amount) },
        ]}
      />
    </Card>
  )
}

function NewLaborers({ rows }: { rows: NewLaborer[] }) {
  const { t } = useTranslation()
  const money = useMoney()
  const title = t('attendance.import.site.newLaborers')
  return (
    <Card className="overflow-hidden">
      <CardHeader className="grid gap-1 border-b">
        <CardTitle>{title}</CardTitle>
        <p className="text-sm text-muted-foreground">{t('attendance.import.site.newLaborersDesc')}</p>
      </CardHeader>
      <DataList
        label={title}
        rows={rows}
        rowKey={(l) => l.national_id}
        // four columns, so the table fits its card from 640 px: gender, residence and mobile under the name, the days under the first day
        title={{
          header: t('common.name'),
          cell: (l) => (
            <span className="grid gap-0.5 wrap-normal">
              {/* bdi inside a span: a bdi grid item would align an Arabic name to the right in the English UI */}
              <span>
                <bdi>{l.name_ar}</bdi>
              </span>
              <span className="grid text-xs font-normal text-muted-foreground">
                <span>{t(`enum.gender.${l.gender}`)}</span>
                <span>{t('employee.residence')}: {dash(l.residence)}</span>
                {l.phone && (
                  <span>
                    {t('employee.phone')}: <bdi dir="ltr" className="whitespace-nowrap">{l.phone}</bdi>
                  </span>
                )}
              </span>
            </span>
          ),
        }}
        fields={[
          { header: t('employee.national_id'), cell: (l) => <bdi dir="ltr">{l.national_id}</bdi> },
          { header: t('wages.proposed'), numeric: true, cell: (l) => (l.proposed_wage ? money(l.proposed_wage) : <span className="whitespace-normal wrap-normal">{t('attendance.import.site.noWage')}</span>) },
          {
            header: t('attendance.import.site.firstDate'),
            cell: (l) => (
              <span className="grid">
                {/* a span, not the date's own bdi, is the grid item: a dir="ltr" grid item would sit at the column's end in Arabic */}
                <span>{ltrDates(l.first_date)}</span>
                <span className="text-xs text-muted-foreground">{t('attendance.import.site.daysLine', { days: l.days })}</span>
              </span>
            ),
          },
        ]}
      />
    </Card>
  )
}

/** The new laborers confirm created, each with the Employee ID they were given; their page is where HR sets a missing wage or the gender. */
function AddedLaborers({ rows }: { rows: Added[] }) {
  const { t } = useTranslation()
  const title = t('attendance.import.site.added')
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <DataList
        label={title}
        rows={rows}
        rowKey={(l) => l.employee_id}
        to={(l) => `/employees/${l.employee_id}`}
        title={{ header: t('common.name'), cell: (l) => <bdi>{l.name_ar}</bdi> }}
        fields={[
          { header: t('employee.code'), numeric: true, cell: (l) => l.code },
          { header: t('wages.proposed'), cell: (l) => <span className="whitespace-normal wrap-normal">{t(l.proposal ? 'attendance.import.site.proposalSent' : 'attendance.import.site.noProposal')}</span> },
        ]}
      />
    </Card>
  )
}

function Errors({ rows }: { rows: RowError[] }) {
  const { t } = useTranslation()
  const title = t('attendance.import.errors')
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle className="text-destructive">{title}</CardTitle>
      </CardHeader>
      <DataList
        label={title}
        rows={rows}
        rowKey={(e) => `${e.sheet}#${e.row}`}
        title={{ header: t('attendance.import.site.sheetRow'), cell: sheetRow }}
        fields={[
          { header: t('common.name'), cell: (e) => <bdi className="whitespace-normal wrap-normal">{dash(e.name)}</bdi> },
          { header: t('attendance.import.date'), cell: (e) => ltrDates(e.date) },
          { header: t('attendance.import.message'), cell: (e) => <bdi className="whitespace-normal wrap-normal text-destructive">{t(errKeys(e.error), { defaultValue: e.message })}</bdi> },
        ]}
      />
    </Card>
  )
}
