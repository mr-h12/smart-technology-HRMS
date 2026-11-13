import { useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'
import { Bell } from 'lucide-react'
import { type ReactNode, useId } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { formatTime, localName, ltrDates } from '@/components/form'
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/misc'
import { queryKey, useList, useSave } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'

type Named = { id: string; code: number; name_ar: string; name_en: string | null } | null
type Base = { id: string; date: string; ref: string | null; read_at: string | null }
// open and crew_changes since 2026-11-05: an older alert has missing days only
type MissingDays = Base & { type: 'attendance_missing_days'; data: { date: string; groups: { group: string; period_start: string; period_end: string; missing: number; open?: number; crew_changes?: number }[] } }
type LeaveAnniversary = Base & { type: 'leave_anniversary'; data: { employee_id: string; hire_date: string }; employee: Named }
type RequestData = { employee_id: string; request_id: string; kind: string; from_date: string; to_date: string; days: number }
type LeaveRequested = Base & { type: 'leave_requested'; data: RequestData; employee: Named }
type LeaveEscalated = Base & { type: 'leave_escalated'; data: RequestData; employee: Named }
type LeaveDecided = Base & { type: 'leave_decided'; data: RequestData & { status: 'approved' | 'refused' }; employee: Named }
type LeaveCancelled = Base & { type: 'leave_cancelled'; data: RequestData; employee: Named }
type WageProposed = Base & { type: 'wage_proposed'; data: { employee_id: string; daily_wage: string; site_name_ar: string; site_name_en: string | null }; employee: Named }
type PastEdit = Base & { type: 'attendance_past_edit'; data: { employee_id: string; date: string; out_time: string; site_name_ar: string; site_name_en: string | null; group: string; period_start: string }; employee: Named }
type AttendanceRequestCreated = Base & { type: 'attendance_request_created'; data: { request_id: string; kind: string; status: string; site_id: string } }
type AttendanceRequestDecided = Base & { type: 'attendance_request_decided'; data: AttendanceRequestCreated['data'] }
type Notification = AttendanceRequestCreated | AttendanceRequestDecided | MissingDays | LeaveAnniversary | LeaveRequested | LeaveEscalated | LeaveDecided | LeaveCancelled | WageProposed | PastEdit

/** One clickable line: what it says, the muted line under it, and where it opens (null: it only marks the notification read). */
type Line = { key: string; text: ReactNode; detail: string; to: string | null }
/** A notification with a `title` is a group of lines under that title; without one it is a single line. */
type View = { title?: string; lines: Line[] }
type Ctx = { t: TFunction; lang: string }

/** A line about one leave request: `key` names the text, the detail is its kind and dates, and it opens the request. */
const requestLine = (key: string, n: LeaveRequested | LeaveEscalated | LeaveDecided | LeaveCancelled, ctx: Ctx): View => ({
  lines: [{
    key: n.id,
    text: named(key, n.employee, ctx),
    detail: ctx.t('notifications.leaveRequested.detail', { kind: ctx.t(`attendance.leaveName.${n.data.kind}`), from: n.data.from_date, to: n.data.to_date }),
    to: `/leave?request=${n.data.request_id}`,
  }],
})

const named = (key: string, employee: Named, { t, lang }: Ctx) =>
  employee ? <Trans i18nKey={`${key}.line`} components={{ name: <bdi>{localName(employee, lang)}</bdi>, code: <bdi dir="ltr">{employee.code}</bdi> }} /> : t(`${key}.unknown`)

/** Every type the bell shows, keyed by type; a type missing here is neither counted nor shown. */
const VIEWS: { [K in Notification['type']]: (n: Extract<Notification, { type: K }>, ctx: Ctx) => View } = {
  attendance_missing_days: (n, { t }) => ({
    title: t('notifications.missingDays.title', { date: n.data.date }),
    lines: n.data.groups.map((g) => ({
      key: `${g.group}-${g.period_start}`,
      // the unrecorded days, then the open days and the day's crew changes when there are any (owner, 2026-11-05)
      text: [
        g.missing > 0 ? t('notifications.missingDays.line', { group: t(`attendance.group.${g.group}`), count: g.missing }) : t('notifications.missingDays.group', { group: t(`attendance.group.${g.group}`) }),
        ...(g.open ? [t('notifications.missingDays.open', { count: g.open })] : []),
        ...(g.crew_changes ? [t('notifications.missingDays.crew', { count: g.crew_changes })] : []),
      ].join(' · '),
      detail: t('notifications.missingDays.period', { start: g.period_start, end: g.period_end }),
      to: `/attendance?${new URLSearchParams({ group: g.group, period: g.period_start, tab: 'grid' })}`,
    })),
  }),
  leave_anniversary: (n, ctx) => ({
    lines: [{
      key: n.id,
      text: named('notifications.leaveAnniversary', n.employee, ctx),
      detail: ctx.t('notifications.leaveAnniversary.hired', { date: n.data.hire_date }),
      // a deleted employee (employee null) has no page to open; the year is the anniversary's, not today's
      to: n.employee && `/employees/${n.employee.id}?tab=leave&year=${n.date.slice(0, 4)}`,
    }],
  }),
  leave_requested: (n, ctx) => requestLine('notifications.leaveRequested', n, ctx),
  leave_escalated: (n, ctx) => requestLine('notifications.leaveEscalated', n, ctx),
  leave_decided: (n, ctx) => requestLine(`notifications.leaveDecided.${n.data.status}`, n, ctx),
  leave_cancelled: (n, ctx) => requestLine('notifications.leaveCancelled', n, ctx),
  // a site manager's new day laborer: the ref is the proposal, which HR approves or refuses on /wage-proposals
  wage_proposed: (n, ctx) => ({
    lines: [{
      key: n.id,
      text: named('notifications.wageProposed', n.employee, ctx),
      detail: ctx.t('notifications.wageProposed.detail', { amount: n.data.daily_wage, site: ctx.lang === 'en' ? n.data.site_name_en || n.data.site_name_ar : n.data.site_name_ar }),
      to: n.ref ? `/wage-proposals?proposal=${n.ref}` : '/wage-proposals',
    }],
  }),
  attendance_request_created: (n, { t }) => ({
    lines: [{
      key: n.id,
      text: t('attendanceRequests.newNotification'),
      detail: t(`attendanceRequests.type.${n.data.kind}`),
      to: `/attendance-requests?${new URLSearchParams({ request: n.data.request_id })}`,
    }],
  }),
  attendance_request_decided: (n, { t }) => ({
    lines: [{
      key: n.id,
      text: t(`attendanceRequests.type.${n.data.kind}`),
      detail: t(n.data.kind === 'advance' && n.data.status === 'approved' ? 'attendanceRequests.paid' : `attendanceRequests.status.${n.data.status}`),
      to: `/site-day?${new URLSearchParams({ site: n.data.site_id, date: n.date })}`,
    }],
  }),
  // a departure the site manager entered after the yesterday window: an edit to a past day, which HR can correct (owner, 2026-11-05)
  attendance_past_edit: (n, ctx) => ({
    lines: [{
      key: n.id,
      text: n.employee
        ? <Trans i18nKey="notifications.pastEdit.line" values={{ date: n.data.date }} components={{ name: <bdi>{localName(n.employee, ctx.lang)}</bdi>, code: <bdi dir="ltr">{n.employee.code}</bdi> }} />
        : ctx.t('notifications.pastEdit.unknown', { date: n.data.date }),
      detail: ctx.t('notifications.pastEdit.detail', { time: formatTime(n.data.out_time, ctx.lang), site: ctx.lang === 'en' ? n.data.site_name_en || n.data.site_name_ar : n.data.site_name_ar }),
      to: `/attendance?${new URLSearchParams({ group: n.data.group, period: n.data.period_start, tab: 'grid' })}`,
    }],
  }),
}
const view = (n: Notification, ctx: Ctx): View => {
  const v = (VIEWS[n.type] as (n: Notification, ctx: Ctx) => View)(n, ctx)
  // about an employee the reader cannot name (deleted, archived, out of reach): their page or request would be a 404, so the line only marks it read
  return 'employee' in n && n.employee === null && 'employee_id' in n.data ? { ...v, lines: v.lines.map((l) => ({ ...l, to: null })) } : v
}

const PATH = '/notifications?per_page=10'

/** The header bell: the latest 10 notifications, the badge counting the unread among them; a line opens what it is about (a group's grid for its period, an employee's Leave tab, a leave request). */
export function NotificationBell() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const save = useSave()
  const id = useId()
  const { rows, err, loading } = useList(PATH, { refetchInterval: 5 * 60_000 }) // and on window focus, the query default
  // useList drops its rows on an error; a failed refetch keeps the last good answer in the cache, so the bell keeps showing it
  const kept = useQueryClient().getQueryData<{ data: Notification[] }>(queryKey(PATH))?.data
  const list = ((err ? kept : rows) ?? []) as { type: string }[]
  const known = list.filter((n): n is Notification => Object.hasOwn(VIEWS, n.type)) // an unknown type is neither counted nor shown
  const unread = known.filter((n) => !n.read_at).length
  const open = (n: Notification, to: string | null) => {
    if (!n.read_at) save(`/notifications/${n.id}/read`, { method: 'PATCH' }).catch((e: Error) => toast.error(e.message))
    if (to) navigate(to)
  }
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label={t('notifications.open', { count: unread })}
          className={cn('relative grid size-10 min-h-tap min-w-tap shrink-0 place-items-center rounded-xl border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground', FOCUS)}
        >
          <Bell className="size-5" aria-hidden />
          {unread > 0 && (
            <span aria-hidden className="absolute -end-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="max-h-[min(32rem,var(--radix-dropdown-menu-content-available-height))] w-80 max-w-[calc(100vw-1.5rem)] overflow-y-auto overscroll-contain">
        <DropdownMenuLabel className="text-sm text-foreground">{t('notifications.title')}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {err && <p className="px-2.5 py-2 text-sm text-destructive">{t('notifications.loadFailed')}</p>}
        {loading && (
          <div className="space-y-2 px-2.5 py-2">
            <span className="sr-only">{t('auth.loading')}</span>
            <Skeleton aria-hidden className="h-3.5 w-48" />
            <Skeleton aria-hidden className="h-3 w-32" />
          </div>
        )}
        {!loading && !err && known.length === 0 && <p className="px-2.5 py-3 text-sm text-muted-foreground">{t('notifications.empty')}</p>}
        {known.map((n) => {
          const { title, lines } = view(n, { t, lang: i18n.language })
          const items = lines.map((l) => <Item key={l.key} line={l} unread={!n.read_at} dot={!title} onSelect={() => open(n, l.to)} />)
          return title ? (
            <DropdownMenuGroup key={n.id} aria-labelledby={`${id}-${n.id}`} className="py-1">
              <p id={`${id}-${n.id}`} className={cn('flex items-center gap-2 px-2.5 py-1.5 text-xs', n.read_at ? 'text-muted-foreground' : 'text-foreground')}>
                {!n.read_at && <span aria-hidden className="size-2 shrink-0 rounded-full bg-primary" />}
                <span className={cn(!n.read_at && 'font-semibold')}>{ltrDates(title)}</span>
              </p>
              {items}
            </DropdownMenuGroup>
          ) : (
            items
          )
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** One line of the menu. Unread: bold, "unread" first for screen readers, and the dot here when no group title carries it. */
function Item({ line, unread, dot, onSelect }: { line: Line; unread: boolean; dot: boolean; onSelect: () => void }) {
  const { t } = useTranslation()
  return (
    <DropdownMenuItem onSelect={onSelect} className={cn('flex-col items-start gap-0.5', unread && 'font-semibold')}>
      <span className="flex items-baseline gap-2">
        {dot && unread && <span aria-hidden className="size-2 shrink-0 self-center rounded-full bg-primary" />}
        <span>
          {unread && <><span className="sr-only">{t('notifications.unread')}</span> </>}
          {line.text}
          {/* without it, screen readers other than Chromium's run the line into the date that follows */}
          <span className="sr-only">{t('notifications.pause')}</span>{' '}
        </span>
      </span>
      <span className="text-xs font-normal text-muted-foreground">{ltrDates(line.detail)}</span>
    </DropdownMenuItem>
  )
}
