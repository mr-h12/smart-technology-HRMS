import { ScrollText } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/common/EmptyState'
import { PageHeader } from '@/components/common/PageHeader'
import { UserAvatar } from '@/components/common/UserAvatar'
import { ErrorBanner, type Row, SelectField, TextField, toErrs, useCairoTime, useForm, useList } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { apiList } from '@/lib/api'
import { useAuth } from '@/lib/auth'

const ENTITIES = [
  'employees', 'employee_type_changes', 'employee_pay_changes', 'employee_documents', 'employee_fare_overrides', 'employee_manager_assignments',
  'sites', 'site_allowance_rules', 'residences', 'fares', 'users', 'roles', 'settings',
]
const PER_PAGE = 20
const EVENT_VARIANT: Record<string, BadgeVariant> = { CREATED: 'success', UPDATED: 'info', DELETED: 'danger' }

/** A string shows as is, a missing side or null as a dash, anything else (objects, arrays, numbers) as JSON text. */
const show = (v: unknown) => (v == null ? '—' : typeof v === 'string' ? v : JSON.stringify(v))

function useLabels() {
  const { t } = useTranslation()
  return {
    event: (e: Row) => t(`audit.event.${e.event}`, { defaultValue: e.event }),
    entity: (e: Row) => t(`audit.entity.${e.entity_type}`, { defaultValue: e.entity_type }),
  }
}

function Details({ entry, onClose }: { entry: Row; onClose: () => void }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  const label = useLabels()
  const sides = (['old_values', 'new_values'] as const).map((k) => [k, (entry[k] ?? {}) as Row] as const)
  const keys = [...new Set(sides.flatMap(([, v]) => Object.keys(v)))]
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" className="sm:max-w-[440px]">
        <div className="space-y-1 border-b p-5 pe-12">
          <SheetTitle>
            {label.event(entry)} · {label.entity(entry)}
          </SheetTitle>
          <SheetDescription>
            {time(entry.occurred_at)} · {entry.user?.name ?? '—'}
          </SheetDescription>
        </div>
        <div className="grid flex-1 content-start gap-4 overflow-y-auto p-5 sm:grid-cols-2">
          {sides.map(([k, values]) => (
            <section key={k} aria-label={t(`audit.${k}`)} className="min-w-0 rounded-lg border p-3">
              <h3 className="mb-2 text-xs font-semibold text-muted-foreground">{t(`audit.${k}`)}</h3>
              {keys.length ? (
                <dl className="space-y-3">
                  {keys.map((key) => (
                    <div key={key}>
                      <dt dir="ltr" className="text-start font-mono text-xs text-muted-foreground">{key}</dt>
                      <dd dir="ltr" className="text-start text-sm break-all whitespace-pre-wrap">{show(values[key])}</dd>
                    </div>
                  ))}
                </dl>
              ) : (
                <p className="text-sm">—</p>
              )}
            </section>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

export function AuditPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const label = useLabels()
  const { form, setForm, set } = useForm()
  const [page, setPage] = useState(1)
  const [users, setUsers] = useState<Row[]>([])
  const [usersErr, setUsersErr] = useState<unknown>()
  const [open, setOpen] = useState<Row>()
  const canUsers = can('user.view')
  useEffect(() => {
    if (canUsers) apiList<Row>('/users?per_page=100').then((r) => setUsers(r.data)).catch(setUsersErr)
  }, [canUsers])
  const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(form).filter(([, v]) => v)), page: String(page), per_page: String(PER_PAGE) })
  const { rows, total, err, loading } = useList(`/audit-log?${qs}`)
  const f = { values: form, set: (k: string, v: string) => { set(k, v); setPage(1) }, errs: toErrs(err) }
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.audit')} description={t('audit.subtitle')} />
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {canUsers && <SelectField name="user_id" label={t('audit.actor')} blank options={users.map((u) => [u.id, u.name])} {...f} />}
            <SelectField name="entity_type" label={t('audit.entityType')} blank options={ENTITIES.map((e) => [e, t(`audit.entity.${e}`)])} {...f} />
            <TextField name="from" label={t('audit.from')} type="date" {...f} />
            <TextField name="to" label={t('audit.to')} type="date" {...f} />
          </div>
          {Object.values(form).some(Boolean) && (
            <Button variant="ghost" size="sm" onClick={() => { setForm({}); setPage(1) }}>
              {t('common.clearFilters')}
            </Button>
          )}
          <ErrorBanner err={err} shown={['user_id', 'entity_type', 'from', 'to']} />
          <ErrorBanner err={usersErr} shown={[]} />
        </div>
        {loading ? (
          <TableSkeleton rows={8} />
        ) : !rows.length ? (
          <EmptyState icon={ScrollText} title={t('common.noResults')} />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('audit.time')}</TableHead>
                <TableHead>{t('audit.actor')}</TableHead>
                <TableHead>{t('audit.action')}</TableHead>
                <TableHead className="hidden md:table-cell">{t('audit.entityType')}</TableHead>
                <TableHead className="hidden xl:table-cell">{t('audit.entityId')}</TableHead>
                <TableHead>
                  <span className="sr-only">{t('common.actions')}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="whitespace-normal text-muted-foreground sm:whitespace-nowrap">{time(r.occurred_at)}</TableCell>
                  <TableCell className="whitespace-normal">
                    {r.user ? (
                      <div className="flex items-center gap-2">
                        <UserAvatar name={r.user.name} size="sm" />
                        <span className="min-w-0 font-medium">{r.user.name}</span>
                      </div>
                    ) : (
                      '—'
                    )}
                  </TableCell>
                  <TableCell>
                    <Badge variant={EVENT_VARIANT[r.event] ?? 'neutral'}>{label.event(r)}</Badge>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">{label.entity(r)}</TableCell>
                  <TableCell dir="ltr" className="hidden text-start font-mono text-xs text-muted-foreground xl:table-cell">{r.entity_id}</TableCell>
                  <TableCell className="text-end">
                    <Button variant="ghost" size="sm" onClick={() => setOpen(r)}>
                      {t('audit.details')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        {total > 0 && <Pagination page={page} pageSize={PER_PAGE} total={total} onPageChange={setPage} />}
      </Card>
      {open && <Details entry={open} onClose={() => setOpen(undefined)} />}
    </div>
  )
}
