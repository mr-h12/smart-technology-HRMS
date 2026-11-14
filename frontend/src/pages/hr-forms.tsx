import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ErrorBanner } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiBlob } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'

const exportsPath = '/hr-form-exports'
const signaturePath = '/me/hr-signature'
type ExportResult = { id: string }
type Signature = { signature: { version: number } | null }

export async function download(id: string, fileName: string) {
  const url = URL.createObjectURL(await apiBlob(`${exportsPath}/${id}/download`))
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.click()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function CertificateDocuments({ employee }: { employee: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [title, setTitle] = useState('')
  const [page, setPage] = useState(1)
  const [error, setError] = useState<Error>()
  const [busy, setBusy] = useState(false)
  const request = useRef<{ payload: string; key: string } | null>(null)
  const saving = useRef(false)
  const list = useList(`${exportsPath}?type=experience_certificate&subject_id=${employee}&page=${page}`, { refetchInterval: 2500 })
  const save = useSave()

  async function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (saving.current || !title.trim()) return
    saving.current = true
    setBusy(true)
    setError(undefined)
    const payload = JSON.stringify([employee, title.trim()])
    if (request.current?.payload !== payload) request.current = { payload, key: crypto.randomUUID() }
    try {
      await save(exportsPath, {
        body: { type: 'experience_certificate', subject_id: employee, idempotency_key: request.current.key, fields: { job_title: title.trim() } },
      })
      request.current = null
      setTitle('')
      setPage(1)
    } catch (err) {
      setError(err as Error)
    } finally {
      saving.current = false
      setBusy(false)
    }
  }

  return (
    <section className="space-y-4 py-4">
      <h2 className="text-lg font-semibold">{t('hrForms.certificate')}</h2>
      {can('hr_forms.generate') && (
        <form onSubmit={generate} className="max-w-2xl space-y-3">
          <label className="block space-y-1">
            <span>{t('hrForms.jobTitle')}</span>
            <Input value={title} onChange={(event) => setTitle(event.target.value)} maxLength={160} required />
          </label>
          <div className="flex flex-wrap gap-2">
            <Button disabled={busy}>{t('hrForms.generate')}</Button>
          </div>
        </form>
      )}
      <ErrorBanner shown={[]} err={error ?? list.err} />
      {!list.loading && !list.err && list.rows.length === 0 && <p>{t('hrForms.empty')}</p>}
      <ul className="space-y-2">
        {list.rows.map((row) => {
          const id = String(row.id)
          const serial = String(row.serial_number)
          const stage = String(row.stage)
          return (
            <li key={id} className="flex flex-wrap items-center gap-2 rounded border p-3">
              <span className="me-auto">{t('hrForms.serial', { serial })} · {t(`hrForms.${stage}`)} · {t(`hrForms.${row.status}`)}
                {row.impersonator_id ? ` · ${t('hrForms.approvedViaViewAs')}` : null}
              </span>
              {row.actions.includes('download') && <Button variant="outline" onClick={() => void download(id, row.file_name).catch(setError)}>{t('hrForms.download')}</Button>}
              {row.actions.includes('retry') && (
                <Button variant="outline" onClick={() => void save(`${exportsPath}/${id}/retry`).catch(setError)}>{t('hrForms.retry')}</Button>
              )}
            </li>
          )
        })}
      </ul>
      <ExportPages page={page} total={list.total} setPage={setPage} />
    </section>
  )
}

export function ExportPages({ page, total, setPage }: { page: number; total: number; setPage: (page: number) => void }) {
  const { t } = useTranslation()
  if (page === 1 && total <= 25) return null
  return (
    <div className="flex gap-2">
      <Button variant="outline" disabled={page === 1} onClick={() => setPage(page - 1)}>{t('hrForms.previous')}</Button>
      <Button variant="outline" disabled={page * 25 >= total} onClick={() => setPage(page + 1)}>{t('hrForms.next')}</Button>
    </div>
  )
}

export function FormApprovalsPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState<string>()
  const [error, setError] = useState<Error>()
  const [approved, setApproved] = useState<string>()
  const list = useList(`${exportsPath}?awaiting_approval=1&type=experience_certificate&page=${page}`)
  const save = useSave()
  async function approve(id: string) {
    setBusy(id)
    setError(undefined)
    try {
      const row = await save<ExportResult>(`${exportsPath}/${id}/approve`)
      setApproved(row.id)
    } catch (err) {
      setError(err as Error)
    } finally {
      setBusy(undefined)
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-semibold">{t('hrForms.approvals')}</h1>
      <ErrorBanner shown={[]} err={error ?? list.err} />
      {approved && <ApprovedExport key={approved} id={approved} />}
      {!list.loading && !list.err && list.rows.length === 0 && <p>{t('hrForms.empty')}</p>}
      <ul className="space-y-2">
        {list.rows.map((row) => {
          const id = String(row.id)
          return (
            <li key={id} className="flex flex-wrap items-center gap-2 rounded border p-3">
              <Link className="me-auto underline" to={`/employees/${row.employee_id}`}>{t('hrForms.serial', { serial: row.serial_number })} · {t('hrForms.certificate')}</Link>
              <Button variant="outline" onClick={() => void download(id, row.file_name).catch(setError)}>{t('hrForms.download')}</Button>
              <Button disabled={busy !== undefined} onClick={() => void approve(id)}>{t('hrForms.approve')}</Button>
            </li>
          )
        })}
      </ul>
      <ExportPages page={page} total={list.total} setPage={setPage} />
    </section>
  )
}

function ApprovedExport({ id }: { id: string }) {
  const { t } = useTranslation()
  const save = useSave()
  const [error, setError] = useState<Error>()
  const [retrying, setRetrying] = useState(false)
  const path = `${exportsPath}/${id}`
  const { data, err, reload } = useApi<{ status: string; serial_number: number; file_name: string }>(path)
  const pending = data?.status === 'pending'
  useEffect(() => {
    if (!pending) return
    const timer = window.setInterval(reload, 2500)
    return () => window.clearInterval(timer)
  }, [pending, reload])

  return (
    <div className="space-y-2 rounded border p-3">
      <ErrorBanner shown={[]} err={error ?? err} />
      {data && <p role="status">{t('hrForms.serial', { serial: data.serial_number })} · {t(`hrForms.${data.status}`)}</p>}
      {data?.status === 'ready' && <Button variant="outline" onClick={() => void download(id, data.file_name).catch(setError)}>{t('hrForms.download')}</Button>}
      {data?.status === 'failed' && (
        <Button disabled={retrying} onClick={async () => {
          setRetrying(true)
          setError(undefined)
          try { await save(`${path}/retry`) } catch (err) { setError(err as Error) } finally { setRetrying(false) }
        }}>{t('hrForms.retry')}</Button>
      )}
    </div>
  )
}

export function SignaturePage() {
  const { t } = useTranslation()
  const { me } = useAuth()
  const { data, err } = useApi<Signature>(me?.view_as ? null : signaturePath)
  const save = useSave()
  const [error, setError] = useState<Error>()
  const [busy, setBusy] = useState(false)
  const [image, setImage] = useState('')
  const version = data?.signature?.version
  useEffect(() => {
    if (!version || me?.view_as) return
    let active = true
    let url = ''
    void apiBlob(`${signaturePath}/file`).then((blob) => {
      if (!active) return
      url = URL.createObjectURL(blob)
      setImage(url)
    }).catch((err: Error) => { if (active) setError(err) })
    return () => { active = false; if (url) URL.revokeObjectURL(url) }
  }, [version, me?.view_as])

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    setBusy(true)
    setError(undefined)
    try {
      await save(signaturePath, { body: new FormData(form) })
      form.reset()
    } catch (err) {
      setError(err as Error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-semibold">{t('hrForms.signature')}</h1>
      {me?.view_as ? <p>{t('hrForms.viewAsBlocked')}</p> : (
        <>
          <ErrorBanner shown={[]} err={error ?? err} />
          {version && image && <img src={image} alt={t('hrForms.signature')} className="h-28 max-w-full rounded border bg-white p-2 object-contain" />}
          <form onSubmit={upload} className="flex flex-wrap items-end gap-3">
            <label className="space-y-1">
              <span>{t('hrForms.signatureFile')}</span>
              <Input type="file" name="file" accept="image/png,image/jpeg" required />
            </label>
            <Button disabled={busy}>{t('hrForms.upload')}</Button>
          </form>
        </>
      )}
    </section>
  )
}
