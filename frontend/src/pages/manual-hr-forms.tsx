import { useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, localName, type Row } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input, Textarea } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'
import { download, ExportPages } from './hr-forms'

type Field = { name: string; input: 'text' | 'date' | 'time' | 'textarea' | 'checkbox'; required: boolean; max: number | null }
type Definition = { type: string; fields: Field[] }

export function ManualHrForms({ employee }: { employee: Row }) {
  const { t } = useTranslation()
  const [selected, setSelected] = useState<string>()
  const { data, err } = useApi<{ forms: Definition[] }>('/hr-manual-form-definitions')
  const form = data?.forms.find(({ type }) => type === selected) ?? data?.forms[0]

  return (
    <section className="space-y-3 border-t py-4">
      <h2 className="text-lg font-semibold">{t('hrForms.manual.title')}</h2>
      <ErrorBanner err={err} shown={[]} />
      {data && data.forms.length > 1 && (
        <select aria-label={t('hrForms.manual.title')} className="min-h-tap rounded-md border bg-background px-2"
          value={form?.type} onChange={(event) => setSelected(event.target.value)}>
          {data.forms.map(({ type }) => <option key={type} value={type}>{t(`hrForms.manual.${type}`)}</option>)}
        </select>
      )}
      {form && <ManualForm key={form.type} form={form} employee={employee} />}
    </section>
  )
}

function ManualForm({ form, employee }: { form: Definition; employee: Row }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const save = useSave()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>()
  const saving = useRef(false)
  const submission = useRef<{ payload: string; key: string } | undefined>(undefined)
  const history = `/employees/${employee.id}/hr-form-exports`

  async function run(action: () => Promise<unknown>) {
    if (saving.current) return
    saving.current = true
    setBusy(true)
    setError(undefined)
    try { await action() } catch (err) { setError(err) } finally {
      saving.current = false
      setBusy(false)
    }
  }

  function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const element = event.currentTarget
    const fields = Object.fromEntries(new FormData(element))
    const payload = JSON.stringify(fields)
    void run(async () => {
      if (submission.current?.payload !== payload) submission.current = { payload, key: crypto.randomUUID() }
      await save(`/employees/${employee.id}/hr-forms/manual/${form.type}`, {
        body: { ...fields, idempotency_key: submission.current.key },
      }, [history])
      submission.current = undefined
      element.reset()
    })
  }

  return (
    <section className="space-y-3">
      <h3 className="font-semibold">{t(`hrForms.manual.${form.type}`)}</h3>
      <p className="text-sm text-muted-foreground">{t('hrForms.manual.notice')}</p>
      <ErrorBanner err={error} shown={[]} />
      {can('hr_forms.generate') && (
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={generate}>
          <label className="grid gap-1">{t('common.name')}<Input readOnly value={localName(employee, i18n.language)} /></label>
          <label className="grid gap-1">{t('employee.code')}<Input readOnly value={employee.code ?? ''} /></label>
          <label className="grid gap-1">{t('employee.department')}<Input readOnly value={employee.department ?? ''} /></label>
          {form.fields.map(({ name, input, required, max }) => {
            const props = { name, required, maxLength: max ?? undefined, disabled: busy }
            if (input === 'checkbox') {
              return (
                <label key={name} className="flex items-start gap-2 sm:col-span-2">
                  <input type="checkbox" name={name} required={required} disabled={busy} className="mt-1 size-4 shrink-0" />
                  <span>{t(`hrForms.manual.fields.${name}`)}</span>
                </label>
              )
            }
            return (
              <label key={name} className={`grid gap-1${input === 'textarea' ? ' sm:col-span-2' : ''}`}>
                {t(`hrForms.manual.fields.${name}`)}
                {input === 'textarea' ? <Textarea {...props} /> : <Input type={input} {...props} />}
              </label>
            )
          })}
          <Button disabled={busy} className="sm:col-span-2">{t('hrForms.generate')}</Button>
        </form>
      )}
      {can('hr_forms.view') && <ManualExports form={form.type} history={history} busy={busy}
        retry={can('hr_forms.generate') ? (id) => void run(() => save(`/hr-form-exports/${id}/retry-manual/${form.type}`, {}, [history])) : undefined}
        reportError={setError} />}
    </section>
  )
}

function ManualExports({ form, history, busy, retry, reportError }: {
  form: string; history: string; busy: boolean; retry?: (id: string) => void; reportError: (err: unknown) => void
}) {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const list = useList(`${history}?page=${page}&form_type=${form}`, { refetchInterval: 2500 })
  return (
    <>
      <ErrorBanner err={list.err} shown={[]} />
      <ul className="space-y-2">
        {list.rows.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center gap-2 rounded border p-2">
            <span className="me-auto">{t('hrForms.serial', { serial: row.serial_number })} · {t(`hrForms.${row.status}`)}</span>
            {row.status === 'ready' && <Button variant="outline" onClick={() => void download(row.id, String(row.serial_number), 'unsigned', form.replaceAll('_', '-')).catch(reportError)}>{t('hrForms.download')}</Button>}
            {row.status === 'failed' && retry && <Button disabled={busy} onClick={() => retry(row.id)}>{t('hrForms.retry')}</Button>}
          </li>
        ))}
      </ul>
      <ExportPages page={page} total={list.total} setPage={setPage} />
    </>
  )
}
