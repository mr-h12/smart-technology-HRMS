import { Bell } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { ErrorBanner, ltrDates } from '@/components/form'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/misc'
import { useList, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

type MissingDays = { date: string; groups: { group: string; period_start: string; period_end: string; missing: number }[] }
type Notification = { id: string; type: string; date: string; data: MissingDays; read_at: string | null }

/** The header bell: the latest 10 notifications, the badge counting the unread among them; a line opens that group's grid for its period. */
export function NotificationBell() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const save = useSave()
  const { rows, err, loading } = useList('/notifications?per_page=10', { refetchInterval: 5 * 60_000 }) // and on window focus, the query default
  const list = rows as unknown as Notification[]
  const unread = list.filter((n) => !n.read_at).length
  const open = (n: Notification, group: string, period: string) => {
    if (!n.read_at) save(`/notifications/${n.id}/read`, { method: 'PATCH' }).catch((e: Error) => toast.error(e.message))
    navigate(`/attendance?${new URLSearchParams({ group, period, tab: 'grid' })}`)
  }
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label={t('notifications.open', { count: unread })}
          className="relative grid size-10 shrink-0 place-items-center rounded-xl border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          <Bell className="size-5" aria-hidden />
          {unread > 0 && (
            <span aria-hidden className="absolute -end-1.5 -top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground tabular-nums">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="max-h-[min(32rem,var(--radix-dropdown-menu-content-available-height))] w-80 max-w-[calc(100vw-1.5rem)] overflow-y-auto">
        <DropdownMenuLabel className="text-sm text-foreground">{t('notifications.title')}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <ErrorBanner err={err} shown={[]} />
        {loading && (
          <div aria-hidden className="space-y-2 px-2.5 py-2">
            <Skeleton className="h-3.5 w-48" />
            <Skeleton className="h-3 w-32" />
          </div>
        )}
        {!loading && !err && list.length === 0 && <p className="px-2.5 py-3 text-sm text-muted-foreground">{t('notifications.empty')}</p>}
        {list.filter((n) => n.type === 'attendance_missing_days').map((n) => (
          <div key={n.id} className="py-1">
            <p className={cn('flex items-center gap-2 px-2.5 py-1.5 text-xs', n.read_at ? 'text-muted-foreground' : 'text-foreground')}>
              {!n.read_at && <span aria-hidden className="size-2 shrink-0 rounded-full bg-primary" />}
              <span className={cn(!n.read_at && 'font-semibold')}>{ltrDates(t('notifications.missingDays.title', { date: n.data.date }))}</span>
            </p>
            {n.data.groups.map((g) => (
              <DropdownMenuItem key={`${g.group}-${g.period_start}`} onSelect={() => open(n, g.group, g.period_start)} className={cn('flex-col items-start gap-0.5', !n.read_at && 'font-semibold')}>
                <span>{t('notifications.missingDays.line', { group: t(`attendance.group.${g.group}`), count: g.missing })}</span>
                <span className="text-xs font-normal text-muted-foreground tabular-nums">{ltrDates(t('notifications.missingDays.period', { start: g.period_start, end: g.period_end }))}</span>
              </DropdownMenuItem>
            ))}
          </div>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
