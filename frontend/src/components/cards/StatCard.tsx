import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
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
