import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const DOT: Partial<Record<BadgeVariant, string>> = {
  success: 'bg-success', warning: 'bg-warning', danger: 'bg-destructive', info: 'bg-info', violet: 'bg-violet-500', neutral: 'bg-muted-foreground',
}

export function StatusBadge({ variant, label, className, dot = true }: { variant: BadgeVariant; label: string; className?: string; dot?: boolean }) {
  return (
    <Badge variant={variant} className={className}>
      {dot && <span className={cn('size-1.5 rounded-full', DOT[variant])} />}
      {label}
    </Badge>
  )
}
