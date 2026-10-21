import { ArrowRight, Download, FileSearch, Save, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, Field, localName, ltrDates } from '@/components/form'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, apiBlob, ApiError } from '@/lib/api'
import { useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

const MAX_BYTES = 5 * 1024 * 1024 // the server's own limit (max:5120 KB)
const MAX_ROWS = 2000 // the server's own cap (AttendanceImport::MAX_ROWS), for the too_many_rows message

type Cell = { row: number; column: string | null }
type Change = Cell & { employee_id: string; code: number; name_ar: string; name_en: string | null; date: string; from: string | null; to: string }
type CellError = Cell & { code: number | null; date: string | null; error: string; message: string }
type Preview = { summary: { cells: number; changes: number; unchanged: number; errors: number }; changes: Change[]; errors: CellError[] }
type Result = { applied: number; unchanged: number; errors: CellError[] }

/** The Excel cell HR looks for, e.g. G3, drawn left to right; a row error has no column, so it is just the row number. */
const cellLabel = (c: Cell) => <bdi dir="ltr" className="font-medium tabular">{`${c.column ?? ''}${c.row}`}</bdi>
const dash = (v: string | number | null) => v ?? '—'
/** The server's error codes in the UI language (the server's message is in the request's); its own words only for a code this UI does not know. */
const errorKey = (code: string) => `attendance.import.error.${code}`

/**
 * The period's Excel grid: download the template, pick the filled file, preview what it would change (nothing is saved), then
 * confirm, which sends the same File again. Keyed by group and period in the page, so a new period starts empty.
 */
export function ImportTab({ group, periodStart }: { group: string; periodStart: string }) {
  const { t, i18n } = useTranslation()
  const save = useSave()
  const [file, setFile] = useState<File>()
  const [bad, setBad] = useState(false)
  const [preview, setPreview] = useState<Preview>()
  const [result, setResult] = useState<Result>()
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)

  function run<T>(work: () => Promise<T>, done: (r: T) => void) {
    setBusy(true)
    setErr(undefined)
    work()
      .then(done, setErr)
      .finally(() => setBusy(false))
  }
  const body = () => {
    const f = new FormData()
    f.set('group', group)
    f.set('period_start', periodStart)
    f.set('file', file!)
    return f
  }
  const download = () =>
    run(() => apiBlob(`/attendance/template?${new URLSearchParams({ group, period_start: periodStart })}`), (blob) => {
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `attendance-${group}-${periodStart}.xlsx`
      a.click()
      setTimeout(() => URL.revokeObjectURL(a.href), 1000) // after the browser has started reading it
    })
  // a new file drops the old preview, so confirm can only ever send the file that was previewed
  function pick(f: File | undefined) {
    const ok = !!f && f.size <= MAX_BYTES && f.name.toLowerCase().endsWith('.xlsx')
    setFile(ok ? f : undefined)
    setBad(!!f && !ok)
    setPreview(undefined)
    setResult(undefined)
    setErr(undefined)
  }
  const check = () => run(() => api<Preview>('/attendance/import/preview', { method: 'POST', body: body() }), (p) => { setPreview(p); setResult(undefined) })
  const confirm = () => run(() => save<Result>('/attendance/import/confirm', { body: body() }), (r) => { setResult(r); setPreview(undefined) })
  const errors = preview?.errors ?? result?.errors ?? []
  // a whole-file refusal (template_mismatch, unreadable_file, too_many_rows) follows the UI language like the cells' errors
  const fileErr = err instanceof ApiError && i18n.exists(errorKey(err.code)) ? new Error(t(errorKey(err.code), { max: MAX_ROWS })) : err

  return (
    <div className="space-y-4">
      <Card className="grid gap-4 p-5">
        <p className="text-sm text-muted-foreground">{t('attendance.import.desc')}</p>
        <div className="grid gap-4 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-end">
          <Button type="button" variant="outline" className="h-11" disabled={busy} onClick={download}>
            <Download aria-hidden /> {t('attendance.import.download')}
          </Button>
          <Field name="file" label={t('attendance.import.file')} errs={bad ? { file: t('attendance.import.badFile') } : {}}>
            {(p) => (
              // the real input stays (named by the field label) but hidden: its own "Choose File" text is in the browser's language, not the app's
              <div className="flex min-w-0 items-center gap-3">
                <input {...p} type="file" accept=".xlsx" className="peer sr-only" disabled={busy} onChange={(e) => pick(e.target.files?.[0])} />
                <label htmlFor={p.id} aria-hidden className={cn(buttonVariants({ variant: 'outline' }), 'h-11 cursor-pointer peer-focus-visible:outline-2 peer-focus-visible:outline-solid peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring peer-disabled:pointer-events-none peer-disabled:opacity-50 peer-aria-invalid:border-destructive')}>
                  <Upload aria-hidden /> {t('attendance.import.choose')}
                </label>
                <span className="min-w-0 text-sm break-all text-muted-foreground">{file?.name ?? t('attendance.import.noFile')}</span>
              </div>
            )}
          </Field>
          <Button type="button" variant={preview ? 'outline' : 'default'} className="h-11" disabled={busy || !file} onClick={check}>
            <FileSearch aria-hidden /> {t('attendance.import.preview')}
          </Button>
        </div>
        <ErrorBanner err={fileErr} shown={[]} />
        {preview && (
          <div className="grid gap-3 border-t pt-4 sm:flex sm:flex-wrap sm:items-center">
            <div className="grid flex-1 gap-1">
              <p className="font-medium tabular">{t('attendance.import.summary', preview.summary)}</p>
              <p className="text-sm text-muted-foreground">{t('attendance.import.notSaved')}</p>
            </div>
            <Button type="button" className="h-11" disabled={busy || preview.summary.changes === 0} onClick={confirm}>
              <Save aria-hidden /> {t('attendance.import.confirm')}
            </Button>
          </div>
        )}
        {result && (
          <p role="status" className="rounded-md border border-success-border bg-success-bg p-3 text-sm font-medium text-success">
            {t('attendance.import.done', { saved: t('attendance.import.saved', { count: result.applied }), skipped: t('attendance.import.skipped', { count: result.errors.length }) })}
          </p>
        )}
      </Card>
      {!!preview?.changes.length && <Changes rows={preview.changes} />}
      {!!errors.length && <Errors rows={errors} />}
    </div>
  )
}

// ponytail: every row renders (a table from xl, cards below it); fine for a period's few thousand cells, paginate if files grow past that
function Changes({ rows }: { rows: Change[] }) {
  const { t, i18n } = useTranslation()
  const title = t('attendance.import.changes')
  const employee = (c: Change) => (
    <span className="flex min-w-0 items-center gap-2">
      <span className="tabular text-muted-foreground">{c.code}</span>
      <bdi className="min-w-0 break-words">{localName(c, i18n.language)}</bdi>
    </span>
  )
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <div className="hidden xl:block">
        <Table aria-label={title}>
          <TableHeader>
            <TableRow>
              <TableHead>{t('attendance.import.cell')}</TableHead>
              <TableHead>{t('attendance.employee')}</TableHead>
              <TableHead>{t('attendance.import.date')}</TableHead>
              <TableHead>{t('attendance.import.from')}</TableHead>
              <TableHead>{t('attendance.import.to')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((c) => (
              <TableRow key={`${c.column}${c.row}`}>
                <TableCell>{cellLabel(c)}</TableCell>
                <TableCell className="whitespace-normal">{employee(c)}</TableCell>
                <TableCell className="tabular">{ltrDates(c.date)}</TableCell>
                <TableCell className="whitespace-normal text-muted-foreground">{dash(c.from)}</TableCell>
                <TableCell className="font-medium whitespace-normal">{c.to}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <ul aria-label={title} className="divide-y xl:hidden">
        {rows.map((c) => (
          <li key={`${c.column}${c.row}`} className="grid gap-2 p-4 text-sm">
            <div className="flex items-center justify-between gap-3">
              {cellLabel(c)}
              <span className="text-muted-foreground tabular">{ltrDates(c.date)}</span>
            </div>
            {employee(c)}
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 break-words">
              <span className="sr-only">{t('attendance.import.from')}</span>
              <span className="text-muted-foreground">{dash(c.from)}</span>
              <ArrowRight className="size-4 shrink-0 text-muted-foreground rtl:-scale-x-100" aria-hidden />
              <span className="sr-only">{t('attendance.import.to')}</span>
              <span className="font-medium">{c.to}</span>
            </p>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Errors({ rows }: { rows: CellError[] }) {
  const { t } = useTranslation()
  const title = t('attendance.import.errors')
  const message = (e: CellError) => <bdi>{t(errorKey(e.error), { defaultValue: e.message })}</bdi>
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle className="text-destructive">{title}</CardTitle>
      </CardHeader>
      <div className="hidden xl:block">
        <Table aria-label={title}>
          <TableHeader>
            <TableRow>
              <TableHead>{t('attendance.import.cell')}</TableHead>
              <TableHead>{t('employee.code')}</TableHead>
              <TableHead>{t('attendance.import.date')}</TableHead>
              <TableHead>{t('attendance.import.message')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((e) => (
              <TableRow key={`${e.column}${e.row}`}>
                <TableCell>{cellLabel(e)}</TableCell>
                <TableCell className="tabular">{dash(e.code)}</TableCell>
                <TableCell className="tabular">{e.date ? ltrDates(e.date) : '—'}</TableCell>
                <TableCell className="whitespace-normal text-destructive">{message(e)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <ul aria-label={title} className="divide-y xl:hidden">
        {rows.map((e) => (
          <li key={`${e.column}${e.row}`} className="grid gap-2 p-4 text-sm">
            <div className="flex items-center justify-between gap-3">
              <span className="flex items-center gap-2">
                {cellLabel(e)}
                <span className="text-muted-foreground tabular">{dash(e.code)}</span>
              </span>
              {e.date && <span className="text-muted-foreground tabular">{ltrDates(e.date)}</span>}
            </div>
            <p className="break-words text-destructive">{message(e)}</p>
          </li>
        ))}
      </ul>
    </Card>
  )
}
