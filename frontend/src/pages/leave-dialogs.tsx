import { LeaveRequestPdf } from './leave-request-pdf'
import { useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { SearchInput } from '@/components/common/Filters'
import { StatusBadge } from '@/components/common/StatusBadge'
import { type Controlled, Dates, ErrorBanner, Field, type FormApi, FormDialog, localName, ltrDates, type Row, SelectField, TextField, useCairoTime } from '@/components/form'
import type { BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/misc'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'
import type { LeaveRequest } from './leave'

export const STATUS: Record<string, BadgeVariant> = { pending: 'warning', escalated: 'info', approved: 'success', refused: 'danger', cancelled: 'neutral' }

const KINDS = ['annual', 'casual', 'sick', 'unpaid'] as const

/** `GET /leave/requests/preview`: the server's count and split; the dialog shows these numbers and computes none. */
type Preview = { days: number; unpaid: number; remaining_after: number | null }

const FIELDS = ['employee_id', 'kind', 'from_date', 'to_date', 'note']

/** Filing a leave request. `employee` fixes and hides the employee (the employee page); without it a picker searches the employees in reach. */
export function RequestDialog({ open, onOpenChange, employee }: Required<Controlled> & { employee?: Row }) {
  const { t } = useTranslation()
  const send = useSave()
  const submit = (f: Row) => {
    const body = { employee_id: f.employee_id, kind: f.kind, from_date: f.from_date, to_date: f.to_date, note: f.note.trim() || null }
    return send('/leave/requests', { body }, ['/leave/requests', `/employees/${f.employee_id}/leave`])
  }
  return (
    <FormDialog
      title={t('leave.request.title')}
      open={open}
      onOpenChange={onOpenChange}
      init={{ employee_id: employee?.id ?? '', kind: 'annual', from_date: '', to_date: '', note: '' }}
      submit={submit}
      onDone={() => toast.success(t('leave.request.sent'))}
      shown={FIELDS}
      footer="leave.request.submit"
      clearErrOnEdit
    >
      {(f) => (
        <>
          {!employee && <EmployeePicker {...f} />}
          <SelectField name="kind" label={t('leave.request.kind')} options={KINDS.map((k) => [k, t(`attendance.leaveName.${k}`)])} {...f} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField name="from_date" label={t('leave.request.from')} type="date" {...f} />
            <TextField name="to_date" label={t('leave.request.to')} type="date" {...f} />
          </div>
          <PreviewLine values={f.values} />
          <Field name="note" id="note" label={t('leave.request.note')} errs={f.errs}>
            {(props) => <Textarea {...props} value={f.values.note} onChange={(e) => f.set('note', e.target.value)} />}
          </Field>
        </>
      )}
    </FormDialog>
  )
}

/** The server's count, balance after and unpaid days (or its refusal) for the values as they are now; nothing while the answer is for older values. */
function PreviewLine({ values: v }: { values: Row }) {
  const { t } = useTranslation()
  const live = v.employee_id && v.kind && v.from_date && v.to_date ? new URLSearchParams({ employee_id: v.employee_id, kind: v.kind, from_date: v.from_date, to_date: v.to_date }).toString() : null
  const query = useDebounced(live, 300)
  const preview = useApi<Preview>(query && `/leave/requests/preview?${query}`)
  if (!live || query !== live) return null
  if (preview.err) return <ErrorBanner err={preview.err} shown={[]} />
  const p = preview.data
  if (!p) return null
  return (
    <>
      <p role="status" className="text-sm">
        {t('leave.request.days', { count: p.days })} {t('leave.request.excluded')}
        {/* ltr: in Arabic text a leading minus would otherwise land after the digits */}
        {p.remaining_after !== null && <Trans i18nKey="leave.request.after" components={{ n: <bdi dir="ltr">{String(p.remaining_after)}</bdi> }} />}
      </p>
      {p.unpaid > 0 && <Alert tone="warning">{t('leave.request.unpaid', { count: p.unpaid })}</Alert>}
    </>
  )
}

/** A search box over `GET /employees` (the server keeps a site manager to the employees in reach) and a select of the matches. */
function EmployeePicker({ values, set, errs }: FormApi) {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDebounced(q, 250)
  const { rows } = useList(`/employees?${new URLSearchParams({ per_page: '20', ...(search && { search }) })}`)
  // the chosen employee stays an option when a later search no longer matches it
  const [picked, setPicked] = useState<Row>()
  const options = picked && !rows.some((r) => r.id === picked.id) ? [picked, ...rows] : rows
  const pick = (k: string, id: string) => {
    set(k, id)
    setPicked(options.find((r) => r.id === id))
  }
  return (
    <div className="grid gap-2">
      <SearchInput value={q} onChange={setQ} placeholder={t('leave.search')} className="sm:w-full" />
      <SelectField name="employee_id" label={t('employee.employee')} blank={t('leave.request.pick')} options={options.map((r) => [r.id, `${localName(r, i18n.language)} (${r.code})`])} values={values} set={pick} errs={errs} />
    </div>
  )
}

/** `GET /leave/requests/{id}`: the list row, the decision so far, and while pending or escalated the approval the server would make now. */
type Shown = LeaveRequest & {
  decision_reason: string | null
  decided_by: { id: string; name: string } | null
  decided_at: string | null
  approval: { days: number; balance: { remaining: number | null; casual_remaining: number | null } | null; keep: number; unpaid: number; remaining_after: number | null; blocked: string[]; replaced: { date: string; leave_code: string }[] } | null
}
type Action = 'approve' | 'refuse' | 'escalate' | 'cancel'

/** One request by id (a link may point past the loaded list page): its details, and the decisions (cancelling included) the reader may take on it now. */
export function DecideDialog({ id, open, onOpenChange }: { id: string } & Required<Controlled>) {
  const { t } = useTranslation()
  // a decision in flight keeps the dialog open, so its outcome (or its refusal) is seen
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open={open} onOpenChange={(o) => busy || onOpenChange(o)}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('leave.decide.title')}</DialogTitle>
        </DialogHeader>
        <DecideBody id={id} close={() => onOpenChange(false)} busy={busy} setBusy={setBusy} />
      </DialogContent>
    </Dialog>
  )
}

function DecideBody({ id, close, busy, setBusy }: { id: string; close: () => void; busy: boolean; setBusy: (b: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const { me, can } = useAuth()
  const time = useCairoTime()
  const send = useSave()
  const path = `/leave/requests/${id}`
  const { data: r, err: loadErr } = useApi<Shown>(path, { fresh: true })
  const [err, setErr] = useState<unknown>()
  const [asking, setAsking] = useState<Action>()
  // the dates HR ticked to replace: a reload listing other dates leaves the tick off
  const [ticked, setTicked] = useState<string>()
  if (!r) return <DialogBody>{loadErr ? <ErrorBanner err={loadErr} shown={[]} /> : <Skeleton className="h-40" />}</DialogBody>

  const a = r.approval
  const actions: Action[] = r.status === 'pending' && can('leave.decide') ? ['escalate', 'refuse', 'approve'] : r.status === 'escalated' && can('leave.decide_escalated') ? ['refuse', 'approve'] : []
  const undecided = r.status === 'pending' || r.status === 'escalated'
  const cancellable = undecided ? (!!me && r.requested_by?.id === me.id) || can('leave.request.all') : r.status === 'approved' && can('leave.decide.all')
  const replaceDates = a?.replaced.map((d) => d.date) ?? []
  const replace = !!replaceDates.length && ticked === replaceDates.join()
  const asks = (['cancel', 'refuse', 'escalate'] as const).filter((x) => (x === 'cancel' ? cancellable : actions.includes(x)))
  // a refusal (409 stale or blocked days) also marks the request stale, so it reloads with the server's current status and blocked dates
  const decide = (action: Action, reason?: string) =>
    send(`${path}/${action}`, { body: { version: r.version, ...(reason && { reason }), ...(action === 'approve' && replace && { replace_dates: replaceDates }) } }, ['/leave', '/attendance', ...(r.employee ? [`/employees/${r.employee.id}/leave`] : [])])
  // every decision lands here, so a refusal shows in the one banner above the request (the reason form has closed by then)
  const run = async (action: Action, reason?: string) => {
    setBusy(true)
    setErr(undefined)
    try {
      await decide(action, reason)
      toast.success(t(`leave.decide.${action}.done`))
      close()
    } catch (e) {
      setErr(e)
    } finally {
      setBusy(false)
    }
  }
  const kind = t(`attendance.leaveName.${r.kind}`)
  const label = LABELS[r.status] ?? LABELS.decided

  return (
    <>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={err} shown={[]} />
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <Item label={t('employee.employee')}>{r.employee ? <>{localName(r.employee, i18n.language)} (<bdi dir="ltr">{r.employee.code}</bdi>)</> : '—'}</Item>
          <Item label={t('leave.requests.kind')}>{kind}</Item>
          <Item label={t('leave.requests.dates')}>{ltrDates(t('leave.requests.range', { from: r.from_date, to: r.to_date }))}</Item>
          <Item label={t('leave.requests.days')}>{a?.days ?? r.days}</Item>
          <Item label={t('leave.decide.requestedBy')}>{r.requested_by?.name ?? '—'}</Item>
          <Item label={t('common.status')}><StatusBadge variant={STATUS[r.status] ?? 'neutral'} label={t(`leave.status.${r.status}`)} /></Item>
          {a?.balance && a.remaining_after !== null && (
            <>
              <Item label={t('leave.decide.before')}><Count n={a.balance.remaining} /></Item>
              {r.kind === 'casual' && <Item label={t('leave.decide.casualBefore')}><Count n={a.balance.casual_remaining} /></Item>}
              <Item label={t('leave.decide.after')}><Count n={a.remaining_after} /></Item>
            </>
          )}
          {r.decided_by && <Item label={t(label.by)}>{r.decided_by.name}</Item>}
          {r.decided_at && <Item label={t(label.at)}>{time(r.decided_at)}</Item>}
          {r.note && <Item label={t('leave.request.note')} wide>{r.note}</Item>}
          {r.decision_reason && <Item label={t(label.reason)} wide>{r.decision_reason}</Item>}
        </dl>
        {a && a.unpaid > 0 && (
          <Alert tone="warning">
            <p className="font-semibold">{t('leave.decide.split', { keep: a.keep, kind, unpaid: a.unpaid })}</p>
            <p>{t('leave.decide.short')}</p>
          </Alert>
        )}
        {a && a.remaining_after === null && (r.kind === 'annual' || r.kind === 'casual') && <Alert tone="warning">{t('leave.noHireDate')}</Alert>}
        {!!a?.blocked.length && (
          <Alert tone="danger">
            <p>{t('leave.decide.blocked')}</p>
            <Dates dates={a.blocked} />
          </Alert>
        )}
        {!!replaceDates.length && (
          <Alert tone="warning">
            <p>{t('leave.decide.replaced')}</p>
            <ul className="flex flex-wrap gap-x-4">{a?.replaced.map((d) => <li key={d.date}><bdi dir="ltr">{d.date}</bdi> {t(`attendance.leaveName.${d.leave_code}`)}</li>)}</ul>
          </Alert>
        )}
        {!!replaceDates.length && actions.includes('approve') && (
          <label className="flex min-h-tap items-center gap-3 text-sm font-medium">
            <input type="checkbox" className="size-4" checked={replace} onChange={(e) => setTicked(e.target.checked ? replaceDates.join() : undefined)} />
            {t('leave.decide.replace')}
          </label>
        )}
        {r.employee && can('hr_forms.view') && <LeaveRequestPdf key={r.id} id={r.id} />}
      </DialogBody>
      {(!!actions.length || cancellable) && (
        <DialogFooter>
          {/* cancelling is not a decision: set apart at the start, and red so it is not taken for the dialog's own close */}
          {cancellable && <Button type="button" variant="outline" className="text-destructive hover:text-destructive sm:me-auto" disabled={busy} onClick={() => setAsking('cancel')}>{t('leave.decide.cancel.action')}</Button>}
          {actions.map((action) =>
            action === 'approve' ? (
              <Button key={action} type="button" disabled={busy || (!!replaceDates.length && !replace)} onClick={() => run('approve')}>{t('leave.decide.approve.action')}</Button>
            ) : (
              <Button key={action} type="button" variant="outline" disabled={busy} onClick={() => setAsking(action)}>{t(`leave.decide.${action}.action`)}</Button>
            ),
          )}
        </DialogFooter>
      )}
      {asks.map((action) => (
        <FormDialog
          key={action}
          title={t(`leave.decide.${action}.title`)}
          description={action === 'cancel' ? t(`leave.decide.cancel.${undecided ? 'withdraw' : 'clear'}`) : undefined}
          open={asking === action}
          onOpenChange={(o) => setAsking(o ? action : undefined)}
          init={{ reason: '' }}
          reloadKey={r.version}
          validate={(f): Record<string, string> => (action === 'refuse' && !f.reason.trim() ? { reason: t('leave.decide.refuse.required') } : {})}
          submit={async (f) => f.reason.trim()}
          onDone={(reason) => run(action, reason)}
          shown={['reason']}
          footer={`leave.decide.${action}.submit`}
          destructive={action !== 'escalate'}
          clearErrOnEdit
        >
          {(f) => (
            <Field name="reason" id={`${action}-reason`} label={t(`leave.decide.${action}.reason`)} errs={f.errs}>
              {(props) => <Textarea {...props} maxLength={1000} value={f.values.reason} onChange={(e) => f.set('reason', e.target.value)} />}
            </Field>
          )}
        </FormDialog>
      ))}
    </>
  )
}

const decided = { by: 'leave.decide.decidedBy', at: 'leave.decide.decidedAt', reason: 'leave.decide.escalationNote' }
/** The decider, date and reason row labels by status; any other status's reason is HR's escalation note. */
const LABELS: Record<string, typeof decided> = {
  decided,
  refused: { ...decided, reason: 'leave.decide.refuse.reason' },
  cancelled: { by: 'leave.decide.cancelledBy', at: 'leave.decide.cancelledAt', reason: 'leave.decide.cancelReason' },
}

function Item({ label, wide, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return (
    <div className={cn('min-w-0', wide && 'sm:col-span-2')}>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium [overflow-wrap:anywhere]">{children}</dd>
    </div>
  )
}

/** ltr: in Arabic text a leading minus would otherwise land after the digits. */
const Count = ({ n }: { n: number | null }) => <bdi dir="ltr" className={cn(n !== null && n < 0 && 'text-destructive')}>{n ?? '—'}</bdi>
