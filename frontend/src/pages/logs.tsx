import { Activity, Bug, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { ErrorBanner, type Row, SelectField, TextField, toErrs, useCairoTime, useForm, useList } from '@/components/form'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { USERS, useApi } from '@/lib/query'
import { Actor, show } from './audit'
import { NotFoundPage } from './pages'

const PER_PAGE = 20
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
const tone = (status: number): BadgeVariant => (status >= 500 ? 'danger' : status >= 400 ? 'warning' : 'success')
/** The other tab, filtered by one request: a link inside a details sheet. */
const linkTo = (tab: string, requestId: string) => `?${new URLSearchParams({ tab, request_id: requestId })}`

/** `request_id` lives in the URL so a link (from the other tab or another page) can filter by one request; a new one starts on page 1, as a filter change does. */
function useLinkedRequest() {
  const [params, setParams] = useSearchParams()
  const requestId = params.get('request_id') ?? ''
  const [page, setPage] = useState(1)
  const [seenId, setSeenId] = useState(requestId)
  if (seenId !== requestId) {
    setSeenId(requestId)
    setPage(1)
  }
  const clearRequestId = () => setParams((p) => { p.delete('request_id'); return p }, { replace: true })
  return { requestId, page, setPage, clearRequestId }
}

function RequestDetails({ entry, onClose }: { entry: Row; onClose: () => void }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  // IP reuses the sessions list's label
  const meta = [['logs.requestId', entry.request_id], ['console.ip', entry.ip_address], ['logs.route', entry.route], ['logs.userAgent', entry.user_agent]] as const
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" className="sm:max-w-[720px]">
        <SheetHeader>
          <SheetTitle dir="ltr" className="text-start font-mono text-sm [overflow-wrap:anywhere]">
            {entry.method} {entry.path}
          </SheetTitle>
          <SheetDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Badge variant={tone(entry.status)}>{entry.status}</Badge>
            {/* each part isolated: an Arabic name in an English line (or the reverse) must not pull the numbers into its run */}
            <span>
              <bdi>{time(entry.occurred_at)}</bdi> · <bdi>{entry.user?.name ?? '—'}</bdi> · <bdi>{t('logs.ms', { n: entry.duration_ms })}</bdi>
            </span>
          </SheetDescription>
        </SheetHeader>
        <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain p-5">
          <dl className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
            {meta.map(([k, v]) => (
              <div key={k} className={k === 'logs.route' || k === 'logs.userAgent' ? 'min-w-0 sm:col-span-2' : 'min-w-0'}>
                <dt className="text-xs text-muted-foreground">{t(k)}</dt>
                <dd dir="ltr" className="text-start font-mono text-xs [overflow-wrap:anywhere]">{show(v)}</dd>
              </div>
            ))}
          </dl>
          <Button asChild variant="outline" size="sm">
            <Link to={linkTo('errors', entry.request_id)}>
              <Bug aria-hidden /> {t('logs.viewErrors')}
            </Link>
          </Button>
          {(['query', 'request_body', 'response_body'] as const).map((k) => (
            <section key={k} aria-label={t(`logs.${k}`)} className="min-w-0 rounded-lg border p-3">
              <h3 className="mb-2 text-xs font-semibold text-muted-foreground">{t(`logs.${k}`)}</h3>
              <pre dir="ltr" className="text-start font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]">{show(entry[k])}</pre>
            </section>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

function RequestsTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const { form, setForm, set } = useForm()
  const { requestId, page, setPage, clearRequestId } = useLinkedRequest()
  const [open, setOpen] = useState<Row>()
  const canUsers = can('user.view')
  const { data: users = [], err: usersErr } = useApi<Row[]>(canUsers ? USERS : null) // shared with the accounts list
  const path = useDebounced(form.path ?? '', 250) // the box stays instant; the request waits for a pause in typing
  const filters = { ...form, path, request_id: requestId }
  const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page: String(page), per_page: String(PER_PAGE) })
  const { rows, total, err, loading } = useList(`/request-log?${qs}`)
  const f = { values: form, set: (k: string, v: string) => { set(k, v); setPage(1) }, errs: toErrs(err) }
  const clear = () => {
    setForm({})
    setPage(1)
    clearRequestId()
  }
  return (
    <>
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <TextField name="from" label={t('logs.from')} type="date" {...f} />
            <TextField name="to" label={t('logs.to')} type="date" {...f} />
            <SelectField name="status" label={t('logs.status')} blank={t('common.all')} options={[['failed', t('logs.statusFilter.failed')], ['server', t('logs.statusFilter.server')]]} {...f} />
            <SelectField name="method" label={t('logs.method')} blank={t('common.all')} options={METHODS.map((m) => [m, m])} {...f} />
            {canUsers && <SelectField name="user_id" label={t('logs.account')} blank={t('common.all')} options={users.map((u) => [u.id, u.name])} {...f} />}
            <TextField name="path" label={t('logs.path')} type="search" dir="ltr" className="font-mono" {...f} />
          </div>
          <FilterActions requestId={requestId} onClearRequestId={clearRequestId} dirty={Object.values(form).some(Boolean)} onClear={clear} />
          <ErrorBanner err={err} shown={['from', 'to', 'status', 'method', 'user_id', 'path']} />
          <ErrorBanner err={usersErr} shown={[]} />
        </div>
        <DataList
          label={t('page.logs')}
          rows={rows}
          rowKey={(r) => r.id}
          title={{ header: t('logs.time'), cell: (r) => time(r.occurred_at) }}
          status={{ header: t('logs.status'), cell: (r) => <Badge variant={tone(r.status)}>{r.status}</Badge> }}
          // the table must fit its card with the details button: the request line and the account are capped per width and the duration (also in the details) joins from xl, as on the errors tab
          fields={[
            {
              header: t('logs.request'),
              cell: (r) => (
                <span dir="ltr" title={`${r.method} ${r.path}`} className="inline-block max-w-full truncate align-bottom font-mono text-xs sm:max-w-48 md:max-w-40 xl:max-w-64 2xl:max-w-md">
                  <span className="font-semibold">{r.method}</span> <span>{r.path}</span>
                </span>
              ),
            },
            { header: t('logs.duration'), cell: (r) => r.duration_ms, numeric: true, tableFrom: 'xl' },
            { header: t('logs.account'), cell: (r) => <Actor user={r.user} className="md:max-w-32 xl:max-w-40 2xl:max-w-48" />, tableFrom: 'md' },
          ]}
          actions={(r) => (
            <Button variant="ghost" size="sm" onClick={() => setOpen(r)}>
              {t('logs.details')}
            </Button>
          )}
          loading={loading}
          empty={{ icon: Activity, title: t('common.noResults') }}
          page={{ page, pageSize: PER_PAGE, total, onPageChange: setPage }}
        />
      </Card>
      {open && <RequestDetails entry={open} onClose={() => setOpen(undefined)} />}
    </>
  )
}

/** Clear filters, after the request-ID filter a link set (a chip with its own clear button). */
function FilterActions({ requestId, onClearRequestId, dirty, onClear }: { requestId: string; onClearRequestId: () => void; dirty: boolean; onClear: () => void }) {
  const { t } = useTranslation()
  if (!requestId && !dirty) return null
  return (
    <div className="flex flex-wrap items-center gap-2">
      {requestId && (
        <span className="inline-flex max-w-full items-center gap-1 rounded-md border bg-muted ps-2 text-xs text-muted-foreground">
          <span className="min-w-0 py-1 [overflow-wrap:anywhere]">
            {t('logs.requestId')}: <bdi dir="ltr" className="font-mono text-foreground">{requestId}</bdi>
          </span>
          <button type="button" onClick={onClearRequestId} aria-label={t('logs.clearRequestId')} className="grid min-h-tap min-w-tap shrink-0 place-items-center rounded hover:bg-background hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring">
            <X className="size-3.5" aria-hidden />
          </button>
        </span>
      )}
      <Button variant="ghost" size="sm" onClick={onClear}>
        {t('common.clearFilters')}
      </Button>
    </div>
  )
}

function ErrorDetails({ entry, onClose }: { entry: Row; onClose: () => void }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  const meta = [['logs.file', [entry.file, entry.line].filter((x) => x != null).join(':') || null], ['logs.sqlstate', entry.sqlstate], ['logs.requestId', entry.request_id]] as const
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" className="sm:max-w-[720px]">
        <SheetHeader>
          <SheetTitle dir="ltr" className="text-start font-mono text-sm [overflow-wrap:anywhere]">
            {entry.class}
          </SheetTitle>
          <SheetDescription>
            <bdi>{time(entry.occurred_at)}</bdi>
          </SheetDescription>
        </SheetHeader>
        <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain p-5">
          <dl className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
            {meta.map(([k, v]) => (
              <div key={k} className={k === 'logs.file' ? 'min-w-0 sm:col-span-2' : 'min-w-0'}>
                <dt className="text-xs text-muted-foreground">{t(k)}</dt>
                <dd dir="ltr" className="text-start font-mono text-xs [overflow-wrap:anywhere]">{show(v)}</dd>
              </div>
            ))}
          </dl>
          {entry.request_id && (
            <Button asChild variant="outline" size="sm">
              <Link to={linkTo('requests', entry.request_id)}>
                <Activity aria-hidden /> {t('logs.viewRequest')}
              </Link>
            </Button>
          )}
          <section aria-label={t('logs.message')} className="min-w-0 rounded-lg border p-3">
            <h3 className="mb-2 text-xs font-semibold text-muted-foreground">{t('logs.message')}</h3>
            <pre dir="ltr" className="text-start font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]">{show(entry.message)}</pre>
          </section>
          <section aria-label={t('logs.trace')} className="min-w-0 rounded-lg border p-3">
            <h3 className="mb-2 text-xs font-semibold text-muted-foreground">{t('logs.trace')}</h3>
            {/* one frame per line: the long ones scroll sideways inside the box, never the page */}
            <pre dir="ltr" tabIndex={0} className="max-h-[60vh] overflow-auto text-start font-mono text-xs leading-relaxed whitespace-pre focus-visible:outline-2 focus-visible:outline-ring">{show(entry.trace)}</pre>
          </section>
        </div>
      </SheetContent>
    </Sheet>
  )
}

function ErrorsTab() {
  const { t } = useTranslation()
  const time = useCairoTime()
  const { form, setForm, set } = useForm()
  const { requestId, page, setPage, clearRequestId } = useLinkedRequest()
  const [open, setOpen] = useState<Row>()
  const filters = { ...form, request_id: requestId }
  const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page: String(page), per_page: String(PER_PAGE) })
  const { rows, total, err, loading } = useList(`/error-log?${qs}`)
  const f = { values: form, set: (k: string, v: string) => { set(k, v); setPage(1) }, errs: toErrs(err) }
  const clear = () => {
    setForm({})
    setPage(1)
    clearRequestId()
  }
  return (
    <>
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <TextField name="from" label={t('logs.from')} type="date" {...f} />
            <TextField name="to" label={t('logs.to')} type="date" {...f} />
          </div>
          <FilterActions requestId={requestId} onClearRequestId={clearRequestId} dirty={Object.values(form).some(Boolean)} onClear={clear} />
          <ErrorBanner err={err} shown={['from', 'to']} />
        </div>
        <DataList
          label={t('logs.tab.errors')}
          rows={rows}
          rowKey={(r) => r.id}
          title={{ header: t('logs.time'), cell: (r) => time(r.occurred_at) }}
          // the table must fit its card with the request ID whole: class and message are capped per width, narrower from xl where the request ID column joins
          fields={[
            {
              header: t('logs.class'),
              cell: (r) => <span dir="ltr" title={r.class} className="inline-block max-w-full truncate align-bottom font-mono text-xs sm:max-w-28 md:max-w-40 xl:max-w-36 2xl:max-w-56">{r.class}</span>,
            },
            {
              header: t('logs.message'),
              cell: (r) => <span dir="auto" title={r.message} className="block max-w-full truncate sm:max-w-36 md:max-w-52 xl:max-w-44 2xl:max-w-80">{r.message}</span>,
            },
            { header: t('logs.requestId'), cell: (r) => (r.request_id ? <span dir="ltr" className="font-mono text-xs">{r.request_id}</span> : '—'), tableFrom: 'xl' },
          ]}
          actions={(r) => (
            <Button variant="ghost" size="sm" onClick={() => setOpen(r)}>
              {t('logs.details')}
            </Button>
          )}
          loading={loading}
          empty={{ icon: Bug, title: t('common.noResults') }}
          page={{ page, pageSize: PER_PAGE, total, onPageChange: setPage }}
        />
      </Card>
      {open && <ErrorDetails entry={open} onClose={() => setOpen(undefined)} />}
    </>
  )
}

const TABS = [
  { value: 'requests', icon: Activity, Body: RequestsTab },
  { value: 'errors', icon: Bug, Body: ErrorsTab },
]

/** The tab lives in `?tab=`; a missing or unknown tab shows Requests. Switching tabs from the bar drops the other tab's request-ID filter. */
export function LogsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = TABS.find((x) => x.value === params.get('tab'))?.value ?? 'requests'
  if (!can('system_log.view')) return <NotFoundPage />
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.logs')} description={t('logs.subtitle')} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList>
          {TABS.map(({ value, icon: Icon }) => (
            <TabsTrigger key={value} value={value}>
              <Icon className="size-4" aria-hidden /> {t(`logs.tab.${value}`)}
            </TabsTrigger>
          ))}
        </TabsList>
        {TABS.map(({ value, Body }) => (
          <TabsContent key={value} value={value}>
            <Body />
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}
