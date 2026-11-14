import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input, Textarea } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { download } from './hr-forms'

type Export = { id: string; serial_number: number; status: 'pending' | 'ready' | 'failed'; file_name: string; actions: string[] }

export function AttendanceRequestPdf({ id, type }: { id: string; type: 'advance' | 'overtime' }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const advance = type === 'advance'
  const path = `/hr-form-exports?type=${type}_request&subject_id=${id}&per_page=100`
  const { data, err, reload } = useApi<Export[]>(can('hr_forms.view') ? path : null, { fresh: true })
  const save = useSave()
  const [error, setError] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const request = useRef<{ body: string; key: string } | undefined>(undefined)
  const pending = data?.some((row) => row.status === 'pending')
  useEffect(() => {
    if (!pending) return
    const timer = window.setInterval(reload, 2500)
    return () => window.clearInterval(timer)
  }, [pending, reload])

  const run = async (action: () => Promise<unknown>) => {
    setBusy(true)
    setError(undefined)
    try { await action() } catch (e) { setError(e) } finally { setBusy(false) }
  }

  const generate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(event.currentTarget))
    const body = advance ? { ...data, previous_advance: data.previous_advance === 'yes' } : data
    const key = JSON.stringify(body)
    void run(async () => {
      if (request.current?.body !== key) request.current = { body: key, key: crypto.randomUUID() }
      await save('/hr-form-exports', { body: { type: `${type}_request`, subject_id: id, idempotency_key: request.current.key, fields: body } })
      request.current = undefined
    })
  }

  return (
    <section className="space-y-3 border-t pt-3">
      <h3 className="font-semibold">{t(advance ? 'hrForms.advanceRequest' : 'hrForms.overtimeRequest')}</h3>
      {!advance && <p className="text-sm text-muted-foreground">{t('hrForms.printOnly')}</p>}
      <ErrorBanner err={error ?? err} shown={[]} />
      {can('hr_forms.generate') && (
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={generate}>
          <label className="grid gap-1">{t('hrForms.jobTitle')}<Input name="job_title" maxLength={160} required disabled={busy} /></label>
          {advance && <>
          <label className="grid gap-1">{t('hrForms.repaymentMethod')}
            <select className="min-h-tap rounded-md border bg-background px-2" name="repayment_method" required disabled={busy} defaultValue="">
              <option value="" disabled>{t('hrForms.repaymentMethod')}</option>
              <option value="receipts">{t('hrForms.receipts')}</option>
              <option value="cash">{t('hrForms.cash')}</option>
              <option value="salary_deduction">{t('hrForms.salaryDeduction')}</option>
            </select>
          </label>
          <label className="grid gap-1">{t('hrForms.previousAdvance')}
            <select className="min-h-tap rounded-md border bg-background px-2" name="previous_advance" required disabled={busy} defaultValue="">
              <option value="" disabled>{t('hrForms.previousAdvance')}</option>
              <option value="yes">{t('hrForms.yes')}</option>
              <option value="no">{t('hrForms.no')}</option>
            </select>
          </label>
          </>}
          <label className="grid gap-1">{t(advance ? 'hrForms.advanceReason' : 'hrForms.overtimeReason')}<Textarea name="reason" maxLength={300} required disabled={busy} /></label>
          {advance && <label className="grid gap-1 sm:col-span-2">{t('hrForms.financeRemarks')}<Textarea name="finance_remarks" maxLength={300} disabled={busy} /></label>}
          <Button disabled={busy} className="sm:col-span-2">{t(data?.length ? 'hrForms.regenerate' : 'hrForms.generate')}</Button>
        </form>
      )}
      <ul className="space-y-2">
        {data?.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center gap-2 rounded border p-2">
            <span className="me-auto">{t('hrForms.serial', { serial: row.serial_number })} · {t(`hrForms.${row.status}`)}</span>
            {row.actions.includes('download') && <Button type="button" variant="outline" onClick={() => void download(row.id, row.file_name).catch(setError)}>{t('hrForms.download')}</Button>}
            {row.actions.includes('retry') && <Button disabled={busy} onClick={() => void run(() => save(`/hr-form-exports/${row.id}/retry`))}>{t('hrForms.retry')}</Button>}
          </li>
        ))}
      </ul>
    </section>
  )
}
