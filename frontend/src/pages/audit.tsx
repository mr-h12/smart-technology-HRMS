import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, type Row, SelectField, TextField, toErrs, useCairoTime, useForm, useList } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { apiList } from '@/lib/api'
import { useAuth } from '@/lib/auth'

const ENTITIES = [
  'employees', 'employee_type_changes', 'employee_pay_changes', 'employee_documents', 'employee_fare_overrides', 'employee_manager_assignments',
  'sites', 'site_allowance_rules', 'residences', 'fares', 'users', 'roles',
]
const PER_PAGE = 50

export function AuditPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const { form, set } = useForm()
  const [page, setPage] = useState(1)
  const [users, setUsers] = useState<Row[]>([])
  const [usersErr, setUsersErr] = useState<unknown>()
  const canUsers = can('user.view')
  useEffect(() => {
    if (canUsers) apiList<Row>('/users?per_page=100').then((r) => setUsers(r.data)).catch(setUsersErr)
  }, [canUsers])
  const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(form).filter(([, v]) => v)), page: String(page), per_page: String(PER_PAGE) })
  const { rows, total, err } = useList(`/audit-log?${qs}`)
  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const f = { values: form, set: (k: string, v: string) => { set(k, v); setPage(1) }, errs: toErrs(err) }
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-page-title">{t('page.audit')}</h1>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {canUsers && <SelectField name="user_id" label={t('audit.actor')} blank options={users.map((u) => [u.id, u.name])} {...f} />}
        <SelectField name="entity_type" label={t('audit.entityType')} blank options={ENTITIES.map((e) => [e, t(`audit.entity.${e}`)])} {...f} />
        <TextField name="from" label={t('audit.from')} type="date" {...f} />
        <TextField name="to" label={t('audit.to')} type="date" {...f} />
      </div>
      <ErrorBanner err={err} shown={['user_id', 'entity_type', 'from', 'to']} />
      <ErrorBanner err={usersErr} shown={[]} />
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('audit.time')}</TableHead>
            <TableHead>{t('audit.actor')}</TableHead>
            <TableHead>{t('audit.action')}</TableHead>
            <TableHead>{t('audit.entityType')}</TableHead>
            <TableHead>{t('audit.entityId')}</TableHead>
            <TableHead>{t('audit.changes')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell>{time(r.occurred_at)}</TableCell>
              <TableCell>{r.user?.name ?? '—'}</TableCell>
              <TableCell>{t(`audit.event.${r.event}`, { defaultValue: r.event })}</TableCell>
              <TableCell>{t(`audit.entity.${r.entity_type}`, { defaultValue: r.entity_type })}</TableCell>
              <TableCell dir="ltr" className="font-mono text-xs">{r.entity_id}</TableCell>
              <TableCell>
                <details>
                  <summary>{t('audit.changes')}</summary>
                  {(['old_values', 'new_values'] as const).map((k) => (
                    <div key={k}>
                      <p className="text-sm font-medium">{t(`audit.${k}`)}</p>
                      <pre dir="ltr" className="overflow-x-auto text-xs">{JSON.stringify(r[k], null, 2)}</pre>
                    </div>
                  ))}
                </details>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <div className="flex items-center gap-3">
        <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
          {t('common.prev')}
        </Button>
        <span>{t('common.pageOf', { page, total: pages })}</span>
        <Button variant="outline" disabled={page >= pages} onClick={() => setPage(page + 1)}>
          {t('common.next')}
        </Button>
      </div>
    </div>
  )
}
