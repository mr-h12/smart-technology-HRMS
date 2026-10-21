import { useQueryClient } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { ltrDates } from '@/components/form'
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/misc'
import { queryKey, useList, useSave } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'

type MissingDays = { date: string; groups: { group: string; period_start: string; period_end: string; missing: number }[] }
type Notification = { id: string; type: string; date: string; data: MissingDays; read_at: string | null }

const PATH = '/notifications?per_page=10'

/** The header bell: the latest 10 notifications, the badge counting the unread among them; a line opens that group's grid for its period. */
export function NotificationBell() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const save = useSave()
  const id = useId()
  const { rows, err, loading } = useList(PATH, { refetchInterval: 5 * 60_000 }) // and on window focus, the query default
  // useList drops its rows on an error; a failed refetch keeps the last good answer in the cache, so the bell keeps showing it
  const kept = useQueryClient().getQueryData<{ data: Notification[] }>(queryKey(PATH))?.data
  const list = ((err ? kept : rows) ?? []) as Notification[]
  const known = list.filter((n) => n.type === 'attendance_missing_days') // the only type so far; an unknown one is neither counted nor shown
  const unread = known.filter((n) => !n.read_at).length
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
          className={cn('relative grid size-10 shrink-0 place-items-center rounded-xl border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground', FOCUS)}
        >
          <Bell className="size-5" aria-hidden />
          {unread > 0 && (
            <span aria-hidden className="absolute -end-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground tabular-nums">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="max-h-[min(32rem,var(--radix-dropdown-menu-content-available-height))] w-80 max-w-[calc(100vw-1.5rem)] overflow-y-auto">
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
        {known.map((n) => (
          <DropdownMenuGroup key={n.id} aria-labelledby={`${id}-${n.id}`} className="py-1">
            <p id={`${id}-${n.id}`} className={cn('flex items-center gap-2 px-2.5 py-1.5 text-xs', n.read_at ? 'text-muted-foreground' : 'text-foreground')}>
              {!n.read_at && <span aria-hidden className="size-2 shrink-0 rounded-full bg-primary" />}
              <span className={cn(!n.read_at && 'font-semibold')}>{ltrDates(t('notifications.missingDays.title', { date: n.data.date }))}</span>
            </p>
            {n.data.groups.map((g) => (
              <DropdownMenuItem key={`${g.group}-${g.period_start}`} onSelect={() => open(n, g.group, g.period_start)} className={cn('flex-col items-start gap-0.5', !n.read_at && 'font-semibold')}>
                <span>
                  {!n.read_at && <><span className="sr-only">{t('notifications.unread')}</span> </>}
                  {t('notifications.missingDays.line', { group: t(`attendance.group.${g.group}`), count: g.missing })}
                </span>
                <span className="text-xs font-normal text-muted-foreground tabular-nums">{ltrDates(t('notifications.missingDays.period', { start: g.period_start, end: g.period_end }))}</span>
              </DropdownMenuItem>
            ))}
          </DropdownMenuGroup>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
