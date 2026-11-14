import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import { useList, useSave } from '@/lib/query'
import { download } from './hr-forms'

type Export = { id: string; serial_number: number; stage: 'unsigned' | 'signed'; status: 'pending' | 'ready' | 'failed'; file_name: string; actions: string[] }
const fields = [
  ['job_title', 'jobTitle', 160],
  ['notification_method', 'notificationMethod', 160],
  ['manager_opinion', 'managerOpinion', 360],
  ['substitute', 'substitute', 160],
] as const

export function LeaveRequestPdf({ id }: { id: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const list = useList(`/hr-form-exports?type=leave_request&subject_id=${id}&per_page=100`)
  const exports = list.rows as Export[]
  const reload = list.reload
  const save = useSave()
  const [error, setError] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const requestKey = useRef<{ fields: string; id: string } | undefined>(undefined)
  const pending = exports.some((row) => row.status === 'pending')
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
    const body = Object.fromEntries(new FormData(event.currentTarget))
    const key = JSON.stringify(body)
    void run(async () => {
      if (requestKey.current?.fields !== key) requestKey.current = { fields: key, id: crypto.randomUUID() }
      await save('/hr-form-exports', { body: { type: 'leave_request', subject_id: id, idempotency_key: requestKey.current.id, fields: body } })
      requestKey.current = undefined
    })
  }

  const latest = exports.find((row) => row.stage === 'unsigned')
  return (
    <section className="space-y-3 border-t pt-3">
      <h3 className="font-semibold">{t('hrForms.leaveRequest')}</h3>
      <ErrorBanner err={error ?? list.err} shown={[]} />
      {can('hr_forms.generate') && (
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={generate}>
          {fields.map(([name, label, limit]) => (
            <label key={name} className="space-y-1">
              <span>{t(`hrForms.${label}`)}</span>
              <Input name={name} required={name === 'job_title'} maxLength={limit} disabled={busy} />
            </label>
          ))}
          <Button disabled={busy} className="sm:col-span-2">{t(latest ? 'hrForms.regenerate' : 'hrForms.generate')}</Button>
        </form>
      )}
      <ul className="space-y-2">
        {exports.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center gap-2 rounded border p-2">
            <span className="me-auto">{t('hrForms.serial', { serial: row.serial_number })} · {t(`hrForms.${row.stage}`)} · {t(`hrForms.${row.status}`)}</span>
            {row.actions.includes('download') && <Button type="button" variant="outline" onClick={() => void download(row.id, row.file_name).catch(setError)}>{t('hrForms.download')}</Button>}
            {row.actions.includes('approve') && (
              <Button disabled={busy} onClick={() => void run(() => save(`/hr-form-exports/${row.id}/approve`))}>{t('hrForms.approve')}</Button>
            )}
            {row.actions.includes('retry') && (
              <Button disabled={busy} onClick={() => void run(() => save(`/hr-form-exports/${row.id}/retry`))}>{t('hrForms.retry')}</Button>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
