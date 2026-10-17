import { cn } from '@/lib/utils'

const AVATAR_COLORS = [
  'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
  'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
  'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
  'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
  'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
  'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
  'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
  'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
]

function avatarColor(seed: string) {
  let hash = 0
  for (let i = 0; i < seed.length; i++) hash = (hash * 31 + seed.charCodeAt(i)) | 0
  return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length]
}

function initials(name: string) {
  const parts = name.replace(/-/g, ' ').split(' ').filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}

const SIZES = { xs: 'size-6 text-[10px]', sm: 'size-8 text-xs', md: 'size-9 text-[13px]', lg: 'size-12 text-base', xl: 'size-20 text-2xl' }

export function UserAvatar({ name, size = 'md', className, ring }: { name: string; size?: keyof typeof SIZES; className?: string; ring?: boolean }) {
  return (
    <span
      className={cn('inline-flex shrink-0 items-center justify-center rounded-full font-semibold select-none', SIZES[size], avatarColor(name), ring && 'ring-2 ring-card', className)}
      aria-hidden
    >
      {initials(name)}
    </span>
  )
}

export function PersonCell({ name, subtitle, size = 'md', onClick }: { name: string; subtitle?: string; size?: keyof typeof SIZES; onClick?: () => void }) {
  const content = (
    <>
      <UserAvatar name={name} size={size} />
      <div className="min-w-0 text-start">
        <div className={cn('truncate text-sm font-medium', onClick && 'group-hover:text-primary')}>{name}</div>
        {subtitle && <div className="truncate text-xs text-muted-foreground">{subtitle}</div>}
      </div>
    </>
  )
  return onClick ? (
    <button type="button" onClick={onClick} className="group flex min-w-0 items-center gap-3 outline-none">
      {content}
    </button>
  ) : (
    <div className="flex min-w-0 items-center gap-3">{content}</div>
  )
}
