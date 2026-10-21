import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui/misc'

export function TableSkeleton({ rows = 6 }: { rows?: number }) {
  const { t } = useTranslation()
  return (
    <div className="divide-y" aria-busy="true" aria-label={t('auth.loading')}>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-5 py-3.5">
          <Skeleton className="size-9 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3.5 w-40" />
            <Skeleton className="h-3 w-28" />
          </div>
          <Skeleton className="hidden h-3.5 w-24 sm:block" />
          <Skeleton className="h-6 w-20 rounded-full" />
        </div>
      ))}
    </div>
  )
}
