import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { download } from './hr-forms'

type Export = { id: string; serial_number: number; stage: 'unsigned' | 'signed'; status: 'pending' | 'ready' | 'failed'; approved_by: string | null; unsigned_export_id: string | null }
type History = { exports: Export[]; is_decider: boolean }
const fields = [
  ['job_title', 'jobTitle', 160],
  ['notification_method', 'notificationMethod', 160],
  ['manager_opinion', 'managerOpinion', 360],
  ['substitute', 'substitute', 160],
] as const

export function LeaveRequestPdf({ id }: { id: string }) {
  const { t } = useTranslation()
  const { can, me } = useAuth()
  const path = `/leave/requests/${id}/hr-form-exports`
  const { data, err, reload } = useApi<History>(path, { fresh: true })
  const save = useSave()
  const [error, setError] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const requestKey = useRef<{ fields: string; id: string } | undefined>(undefined)
  const pending = data?.exports.some((row) => row.status === 'pending')
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
      await save(`/leave/requests/${id}/hr-forms/leave-request`, { body: { ...body, idempotency_key: requestKey.current.id } }, [path])
      requestKey.current = undefined
    })
  }

  const latest = data?.exports.find((row) => row.stage === 'unsigned')
  const canApprove = can('hr_forms.approve_leave_request')
  return (
    <section className="space-y-3 border-t pt-3">
      <h3 className="font-semibold">{t('hrForms.leaveRequest')}</h3>
      <ErrorBanner err={error ?? err} shown={[]} />
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
        {data?.exports.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center gap-2 rounded border p-2">
            <span className="me-auto">{t('hrForms.serial', { serial: row.serial_number })} · {t(`hrForms.${row.stage}`)} · {t(`hrForms.${row.status}`)}</span>
            {row.status === 'ready' && <Button type="button" variant="outline" onClick={() => void download(row.id, String(row.serial_number), row.stage, 'leave-request').catch(setError)}>{t('hrForms.download')}</Button>}
            {row.id === latest?.id && row.status === 'ready' && data.is_decider && canApprove && !data.exports.some((signed) => signed.unsigned_export_id === row.id) && (
              <Button disabled={busy} onClick={() => void run(() => save(`/hr-form-exports/${row.id}/approve-leave-request`, {}, [path]))}>{t('hrForms.approve')}</Button>
            )}
            {row.stage === 'signed' && row.status === 'failed' && canApprove && row.approved_by === me?.id && (
              <Button disabled={busy} onClick={() => void run(() => save(`/hr-form-exports/${row.id}/retry-leave-request`, {}, [path]))}>{t('hrForms.retry')}</Button>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
