import { Download, FileSearch, Save, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { DataList } from '@/components/common/DataList'
import { ErrorBanner, Field, localName, ltrDates } from '@/components/form'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
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
const cellLabel = (c: Cell) => <bdi dir="ltr" className="font-medium">{`${c.column ?? ''}${c.row}`}</bdi>
/** A step's number beside it; the list itself carries the order for a screen reader. */
export const STEP = 'grid size-6 shrink-0 place-items-center rounded-full border text-xs font-semibold text-muted-foreground'
const dash = (v: string | number | null) => v ?? '—'
/** The server's error codes in the UI language (the server's message is in the request's); its own words only for a code this UI does not know. */
export const errorKey = (code: string) => `attendance.import.error.${code}`

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
        {/* rule 3: the flow's three steps, numbered in one list; role="list" because Safari drops it with list-style none */}
        <ol role="list" className="grid gap-4">
          <li className="flex items-center gap-3">
            <span aria-hidden className={STEP}>1</span>
            <Button type="button" variant="outline" disabled={busy} onClick={download}>
              <Download aria-hidden /> {t('attendance.import.download')}
            </Button>
          </li>
          <li className="flex items-start gap-3">
            <span aria-hidden className={STEP}>2</span>
            <div className="min-w-0 flex-1">
              <Field name="file" label={t('attendance.import.file')} errs={bad ? { file: t('attendance.import.badFile') } : {}}>
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
        <ErrorBanner err={fileErr} shown={[]} />
        {preview && (
          <div className="grid gap-3 border-t pt-4 sm:flex sm:flex-wrap sm:items-center">
            <div className="grid flex-1 gap-1">
              <p className="font-medium">{t('attendance.import.summary', preview.summary)}</p>
              <p className="text-sm text-muted-foreground">{t('attendance.import.notSaved')}</p>
            </div>
            <Button type="button" disabled={busy || preview.summary.changes === 0} onClick={confirm}>
              <Save aria-hidden /> {t('attendance.import.confirm')}
            </Button>
          </div>
        )}
        {result && (
          <Alert tone="success">
            <p className="font-medium">{t('attendance.import.done', { saved: t('attendance.import.saved', { count: result.applied }), skipped: t('attendance.import.skipped', { count: result.errors.length }) })}</p>
          </Alert>
        )}
      </Card>
      {!!preview?.changes.length && <Changes rows={preview.changes} />}
      {!!errors.length && <Errors rows={errors} />}
    </div>
  )
}

// ponytail: every row renders on one page; fine for a period's few thousand cells, pass DataList a page if files grow past that
function Changes({ rows }: { rows: Change[] }) {
  const { t, i18n } = useTranslation()
  const title = t('attendance.import.changes')
  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <DataList
        label={title}
        rows={rows}
        rowKey={(c) => `${c.column}${c.row}`}
        title={{ header: t('attendance.import.cell'), cell: cellLabel }}
        fields={[
          {
            header: t('attendance.employee'),
            cell: (c) => (
              <span className="inline-flex items-center gap-2 whitespace-normal">
                <span className="text-muted-foreground">{c.code}</span>
                <bdi>{localName(c, i18n.language)}</bdi>
              </span>
            ),
          },
          { header: t('attendance.import.date'), cell: (c) => ltrDates(c.date) },
          { header: t('attendance.import.from'), cell: (c) => <span className="whitespace-normal text-muted-foreground">{dash(c.from)}</span> },
          { header: t('attendance.import.to'), cell: (c) => <span className="font-medium whitespace-normal">{c.to}</span> },
        ]}
      />
    </Card>
  )
}

function Errors({ rows }: { rows: CellError[] }) {
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
        rowKey={(e) => `${e.column}${e.row}`}
        title={{ header: t('attendance.import.cell'), cell: cellLabel }}
        fields={[
          { header: t('employee.code'), cell: (e) => dash(e.code) },
          { header: t('attendance.import.date'), cell: (e) => (e.date ? ltrDates(e.date) : '—') },
          { header: t('attendance.import.message'), cell: (e) => <bdi className="whitespace-normal text-destructive">{t(errorKey(e.error), { defaultValue: e.message })}</bdi> },
        ]}
      />
    </Card>
  )
}
