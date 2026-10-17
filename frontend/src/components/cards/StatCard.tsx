import type { LucideIcon } from 'lucide-react'
import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/misc'
import { cn } from '@/lib/utils'

const TONES = {
  indigo: 'bg-primary/10 text-primary',
  emerald: 'bg-success/10 text-success',
  amber: 'bg-warning/10 text-warning',
  sky: 'bg-info/10 text-info',
  rose: 'bg-destructive/10 text-destructive',
  violet: 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300',
  slate: 'bg-muted text-muted-foreground',
}

export type Tone = keyof typeof TONES

interface StatCardProps {
  label: string
  value: ReactNode
  icon: LucideIcon
  tone?: Tone
  hint?: ReactNode
  delta?: { value: string; positive: boolean }
  footer?: ReactNode
  loading?: boolean
  onClick?: () => void
}

export function StatCard({ label, value, icon: Icon, tone = 'indigo', hint, delta, footer, loading, onClick }: StatCardProps) {
  return (
    <Card
      className={cn('group relative p-5 transition-all', onClick && 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md')}
      onClick={onClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={onClick ? (e) => e.key === 'Enter' && onClick() : undefined}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-muted-foreground">{label}</p>
          {loading ? (
            <Skeleton className="mt-2 h-8 w-24" />
          ) : (
            <p className="mt-1.5 text-[26px] leading-tight font-semibold tracking-tight tabular">{value}</p>
          )}
        </div>
        <div className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', TONES[tone])}>
          <Icon className="size-5" />
        </div>
      </div>
      {(hint || delta) && (
        <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          {delta && (
            <span className={cn('inline-flex items-center gap-0.5 font-medium', delta.positive ? 'text-success' : 'text-destructive')}>
              {delta.positive ? <ArrowUpRight className="size-3.5 rtl:-scale-x-100" /> : <ArrowDownRight className="size-3.5 rtl:-scale-x-100" />}
              {delta.value}
            </span>
          )}
          {hint && <span>{hint}</span>}
        </div>
      )}
      {footer && <div className="mt-4">{footer}</div>}
    </Card>
  )
}

export function MiniStat({ label, value, tone = 'slate', icon: Icon }: { label: string; value: ReactNode; tone?: Tone; icon?: LucideIcon }) {
  return (
    <div role="group" aria-label={label} className="flex items-center gap-3 rounded-xl border bg-card p-3.5">
      {Icon && (
        <div className={cn('flex size-9 shrink-0 items-center justify-center rounded-lg', TONES[tone])}>
          <Icon className="size-4" />
        </div>
      )}
      <div className="min-w-0">
        <div className="text-lg leading-tight font-semibold tabular">{value}</div>
        <div className="truncate text-xs text-muted-foreground">{label}</div>
      </div>
    </div>
  )
}
