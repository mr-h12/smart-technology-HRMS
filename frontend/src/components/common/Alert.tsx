import { CircleAlert, CircleCheck, Info, TriangleAlert, type LucideIcon } from 'lucide-react'
import type { ReactNode, Ref } from 'react'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

// box, then the colour of the icon and title; the body stays text-foreground
const TONE: Record<Tone, [string, string, LucideIcon]> = {
  neutral: ['border-border bg-muted', 'text-muted-foreground', Info],
  info: ['border-info-border bg-info-bg', 'text-info', Info],
  success: ['border-success-border bg-success-bg', 'text-success', CircleCheck],
  warning: ['border-warning-border bg-warning-bg', 'text-warning', TriangleAlert],
  danger: ['border-destructive-border bg-destructive-bg', 'text-destructive', CircleAlert],
}

export type AlertProps = {
  tone: Tone
  /** Default per tone; always aria-hidden, the text carries the meaning. */
  icon?: LucideIcon
  title?: string
  children: ReactNode
  /** For a caller that scrolls the alert into view (ErrorBanner); scroll-mt-20 keeps it clear of the sticky header, as `main` does. */
  ref?: Ref<HTMLDivElement>
}

/**
 * An inline note styled like content (rule 17), not a banner. Roles: danger → "alert"; success → "status" (it appears
 * after an action); neutral, info and warning → none (a static note must not interrupt a screen reader).
 */
export function Alert({ tone, icon, title, children, ref }: AlertProps) {
  const [box, ink, Default] = TONE[tone]
  const Icon = icon ?? Default
  return (
    <div
      ref={ref}
      role={tone === 'danger' ? 'alert' : tone === 'success' ? 'status' : undefined}
      className={`flex scroll-mt-20 gap-3 rounded-lg border px-4 py-3 text-sm text-foreground ${box}`}
    >
      <Icon aria-hidden className={`mt-0.5 size-4 shrink-0 ${ink}`} />
      <div className="min-w-0 space-y-1">
        {title && <p className={`font-semibold ${ink}`}>{title}</p>}
        {children}
      </div>
    </div>
  )
}
