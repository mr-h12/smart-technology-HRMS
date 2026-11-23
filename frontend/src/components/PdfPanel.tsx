import { ChevronDown, Download, Eye } from 'lucide-react'
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/common/StatusBadge'
import { FileViewer } from '@/components/FileViewer'
import { ErrorBanner, selectCls, useCairoTime } from '@/components/form'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input, Textarea } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/misc'
import { apiBlob } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'

export type PdfType = 'experience_certificate' | 'leave_request' | 'advance_request' | 'overtime_request'

/** One generated PDF as the API answers it ("The row" of the Step 1 spec); `actions` is computed for the caller. */
export type PdfExport = {
  id: string
  type: PdfType
  serial_number: number
  stage: 'unsigned' | 'signed'
  status: 'pending' | 'ready' | 'failed'
  employee_id: string | null
  created_at: string
  file_name: string
  actions: ('download' | 'approve' | 'retry')[]
  shows_pay?: boolean | null
  employee?: { id: string; code: number; name_ar: string; name_en: string | null } | null
  impersonator_id?: string | null
}

type Field = { name: string; label: string; kind: 'text' | 'textarea' | 'choice' | 'yesno'; required?: boolean; max?: number; options?: string[]; finance?: boolean }

const jobTitle: Field = { name: 'job_title', label: 'hrForms.jobTitle', kind: 'text', required: true, max: 160 }

/**
 * The four HR form PDFs. Field rules mirror each backend type's `rules()`; `finance` fields show only to a caller who sees pay.
 * `hint` shows once a file exists: the advance amount stays editable after its PDF froze it.
 */
export const PDF_TYPES: Record<PdfType, { title: string; ownScope: boolean; hint?: string; fields: Field[] }> = {
  experience_certificate: { title: 'hrForms.certificate', ownScope: false, fields: [jobTitle] },
  leave_request: {
    title: 'hrForms.leaveRequest',
    ownScope: false,
    fields: [
      // The leave form's printable limits: longer values cannot hold its one page.
      { ...jobTitle, max: 60 },
      { name: 'notification_method', label: 'hrForms.notificationMethod', kind: 'text', max: 60 },
      { name: 'manager_opinion', label: 'hrForms.managerOpinion', kind: 'textarea', max: 130 },
      { name: 'substitute', label: 'hrForms.substitute', kind: 'text', max: 60 },
    ],
  },
  advance_request: {
    title: 'hrForms.advanceRequest',
    ownScope: true,
    hint: 'hrForms.amountChanged',
    fields: [
      jobTitle,
      { name: 'reason', label: 'hrForms.advanceReason', kind: 'textarea', required: true, max: 300 },
      { name: 'repayment_method', label: 'hrForms.repaymentMethod', kind: 'choice', required: true, options: ['receipts', 'cash', 'salary_deduction'] },
      { name: 'previous_advance', label: 'hrForms.previousAdvance', kind: 'yesno', finance: true },
      { name: 'finance_remarks', label: 'hrForms.financeRemarks', kind: 'textarea', max: 300, finance: true },
    ],
  },
  overtime_request: {
    title: 'hrForms.overtimeRequest',
    ownScope: false,
    fields: [jobTitle, { name: 'reason', label: 'hrForms.overtimeReason', kind: 'textarea', required: true, max: 300 }],
  },
}

/** `can('x.y')` matches any scope, so a type closed to own-scope callers names `.all`. */
export function canUsePdf(can: (a: string) => boolean, action: 'view' | 'generate', type: PdfType): boolean {
  return can(`hr_forms.${action}.all`) || (PDF_TYPES[type].ownScope && can(`hr_forms.${action}`))
}

const EXPORTS = '/hr-form-exports'
export const STATUS: Record<PdfExport['status'], BadgeVariant> = { pending: 'info', ready: 'success', failed: 'danger' }
const summaryCls = cn('flex min-h-tap w-fit cursor-pointer list-none items-center gap-2 rounded-lg text-sm font-medium text-primary [&::-webkit-details-marker]:hidden', FOCUS)

async function download(id: string, fileName: string) {
  const url = URL.createObjectURL(await apiBlob(`${EXPORTS}/${id}/download`))
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.click()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** `subject` is the employee, leave request or attendance request id, per `type`. Renders nothing without view permission. */
export function PdfPanel({ type, subject }: { type: PdfType; subject: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const titleId = useId()
  const statusId = useId()
  const visible = canUsePdf(can, 'view', type)
  // fresh: Approve depends on state that changes outside the panel (a leave decision), so a reopened panel asks again
  const { data: rows, err, reload } = useApi<PdfExport[]>(visible ? `${EXPORTS}?type=${type}&subject_id=${subject}&per_page=100` : null, { fresh: true })
  const polling = !!rows?.some((r) => r.status === 'pending')
  useEffect(() => {
    if (!polling) return
    const timer = window.setInterval(reload, 3000)
    return () => window.clearInterval(timer)
  }, [polling, reload])
  if (!visible) return null

  const seesPay = can('employee.view.all')
  // The server lists newest first. Copies with pay and without are separate chains (shows_pay; null for types without the
  // split), so the newest of each stays current: a manager's copy never hides HR's.
  const current: PdfExport[] = []
  const earlier: PdfExport[] = []
  const kinds = new Set<boolean | null>()
  for (const r of rows ?? []) {
    const kind = r.shows_pay ?? null
    ;(kinds.has(kind) ? earlier : current).push(r)
    kinds.add(kind)
  }
  current.sort((a, b) => Number(a.shows_pay === false) - Number(b.shows_pay === false))
  const ownPending = !!rows?.some((r) => r.status === 'pending' && (r.shows_pay ?? seesPay) === seesPay)
  const item = (r: PdfExport) => <PdfRow key={r.id} row={r} onChanged={reload} />
  const form = rows && canUsePdf(can, 'generate', type) && <GenerateForm type={type} subject={subject} rows={rows} blocked={ownPending} describedBy={statusId} />
  const hint = PDF_TYPES[type].hint

  return (
    <section aria-labelledby={titleId} className="space-y-3 rounded-xl border bg-card p-4">
      <h3 id={titleId} className="text-card-title">{t(PDF_TYPES[type].title)}</h3>
      <p id={statusId} role="status" className="sr-only">{ownPending ? t('hrForms.pending') : ''}</p>
      <ErrorBanner err={err} shown={[]} />
      {!rows ? (
        !err && <Skeleton aria-busy="true" aria-label={t('auth.loading')} className="h-16" />
      ) : rows.length ? (
        <ul className="space-y-2">{current.map(item)}</ul>
      ) : (
        <p className="text-sm text-muted-foreground">{t('hrForms.noFile')}</p>
      )}
      {hint && !!rows?.length && <p className="text-sm text-muted-foreground">{t(hint)}</p>}
      {earlier.length > 0 && <Disclosure label={t('hrForms.earlier', { n: earlier.length })}><ul className="space-y-2">{earlier.map(item)}</ul></Disclosure>}
      {form && (rows.length ? <Disclosure label={t('hrForms.regenerate')}>{form}</Disclosure> : form)}
    </section>
  )
}

function Disclosure({ label, children }: { label: string; children: ReactNode }) {
  return (
    <details className="group space-y-2 border-t pt-2">
      <summary className={summaryCls}>
        {label}
        <ChevronDown aria-hidden className="transition-transform group-open:rotate-180 motion-reduce:transition-none" />
      </summary>
      {children}
    </details>
  )
}

function PdfRow({ row, onChanged }: { row: PdfExport; onChanged: () => void }) {
  const { t } = useTranslation()
  const time = useCairoTime({ dateStyle: 'long', timeStyle: 'short' })
  return (
    <li className="grid gap-2 rounded-lg border p-3">
      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span className="font-semibold">{t('hrForms.serial', { serial: row.serial_number })}</span>
        {row.stage === 'signed' ? <Badge variant="success">{t('hrForms.signed')}</Badge> : <span className="text-muted-foreground">{t('hrForms.unsigned')}</span>}
        <StatusBadge variant={STATUS[row.status]} label={t(`hrForms.${row.status}`)} />
        <NoPayBadge row={row} />
        <ViewAsBadge row={row} />
        <span className="text-muted-foreground">{time(row.created_at)}</span>
      </p>
      <PdfRowActions row={row} onChanged={onChanged} />
    </li>
  )
}

/** «بدون الراتب» on an advance copy made without the salary, for a caller who also sees the copies with it. */
export function NoPayBadge({ row }: { row: PdfExport }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  return can('employee.view.all') && row.shows_pay === false ? <Badge variant="neutral">{t('hrForms.noPay')}</Badge> : null
}

/** «اعتُمدت بالعرض بحساب آخر» on a copy a developer approved while viewing as another account. */
export function ViewAsBadge({ row }: { row: PdfExport }) {
  const { t } = useTranslation()
  return row.impersonator_id ? <Badge variant="warning">{t('hrForms.approvedViaViewAs')}</Badge> : null
}

/** View, Download, Approve and Retry for one row, each only when the row's `actions` allows it; a refusal shows inline under them. `className` styles the row of buttons. */
export function PdfRowActions({ row, onChanged, className }: { row: PdfExport; onChanged: () => void; className?: string }) {
  const { t } = useTranslation()
  const save = useSave()
  const [viewing, setViewing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>()
  const path = `${EXPORTS}/${row.id}`
  const has = (action: PdfExport['actions'][number]) => row.actions.includes(action)
  const run = async (action: () => Promise<unknown>, changes = true) => {
    setBusy(true)
    setError(undefined)
    try {
      await action()
      if (changes) onChanged()
    } catch (e) {
      setError(e)
    } finally {
      setBusy(false)
    }
  }
  if (!row.actions.length) return null

  return (
    // flex, not grid: the global `.grid > * { min-width: 0 }` would let a table column shrink below its buttons
    <div className="flex flex-col gap-2">
      <div className={cn('flex flex-wrap gap-2', className)}>
        {has('download') && (
          <>
            <Button type="button" variant="outline" onClick={() => setViewing(true)}><Eye aria-hidden />{t('hrForms.view')}</Button>
            <Button type="button" variant="outline" disabled={busy} onClick={() => void run(() => download(row.id, row.file_name), false)}><Download aria-hidden />{t('hrForms.download')}</Button>
          </>
        )}
        {has('approve') && <Button type="button" disabled={busy} onClick={() => void run(() => save(`${path}/approve`))}>{t('hrForms.approve')}</Button>}
        {has('retry') && <Button type="button" variant="outline" disabled={busy} onClick={() => void run(() => save(`${path}/retry`))}>{t('hrForms.retry')}</Button>}
      </div>
      {/* no width of its own from 640 px: a refusal wraps under the buttons instead of widening a table column */}
      {!!error && <div className="whitespace-normal sm:contain-inline-size"><ErrorBanner err={error} shown={[]} /></div>}
      {viewing && (
        <FileViewer
          path={`${path}/download`}
          type="application/pdf"
          name={row.file_name}
          title={`${t(PDF_TYPES[row.type].title)} · ${t('hrForms.serial', { serial: row.serial_number })} · ${t(`hrForms.${row.stage}`)}`}
          onClose={() => setViewing(false)}
        />
      )}
    </div>
  )
}

/**
 * The form keeps its idempotency key while a request fails or goes unanswered, so a retry replays it; a created copy or a changed
 * value takes a new key. After a create it stays disabled until `rows` shows the copy: a click before that would make a second one.
 */
function GenerateForm({ type, subject, rows, blocked, describedBy }: { type: PdfType; subject: string; rows: PdfExport[]; blocked: boolean; describedBy: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const save = useSave()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>()
  const [created, setCreated] = useState<string>()
  const request = useRef<{ fields: string; key: string }>(undefined)
  const fields = PDF_TYPES[type].fields.filter((f) => !f.finance || can('employee.view.all'))
  const unlisted = !!created && !rows.some((r) => r.id === created)

  async function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const values = Object.fromEntries(fields.map(({ name, kind }) => {
      const value = String(data.get(name) ?? '')
      return [name, kind === 'yesno' ? (value ? value === 'yes' : null) : value]
    }))
    const json = JSON.stringify(values)
    if (request.current?.fields !== json) request.current = { fields: json, key: crypto.randomUUID() }
    setBusy(true)
    setError(undefined)
    try {
      const row = await save<PdfExport>(EXPORTS, { body: { type, subject_id: subject, idempotency_key: request.current.key, fields: values } })
      request.current = undefined
      setCreated(row.id)
    } catch (e) {
      setError(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <ErrorBanner err={error} shown={[]} />
      <form onSubmit={generate} className="grid gap-3 sm:grid-cols-2">
        {fields.map((f) => (
          <label key={f.name} className={cn('grid content-start gap-1.5 text-[13px] font-medium', f.kind === 'textarea' && 'sm:col-span-2')}>
            {t(f.label)}
            {f.kind === 'text' ? (
              <Input name={f.name} required={f.required} maxLength={f.max} disabled={busy} />
            ) : f.kind === 'textarea' ? (
              <Textarea name={f.name} required={f.required} maxLength={f.max} disabled={busy} />
            ) : (
              <select name={f.name} required={f.required} defaultValue="" disabled={busy} className={selectCls}>
                <option value="" disabled={f.required} />
                {(f.kind === 'yesno' ? ['yes', 'no'] : (f.options ?? [])).map((o) => <option key={o} value={o}>{t(`hrForms.${o}`)}</option>)}
              </select>
            )}
          </label>
        ))}
        <Button disabled={busy || blocked || unlisted} aria-describedby={blocked ? describedBy : undefined} className="sm:col-span-2 sm:justify-self-start">{t('hrForms.generate')}</Button>
      </form>
    </>
  )
}
