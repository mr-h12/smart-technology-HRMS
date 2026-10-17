import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'

export function Pagination({ page, pageSize, total, onPageChange }: { page: number; pageSize: number; total: number; onPageChange: (p: number) => void }) {
  const { t } = useTranslation()
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1
  const to = Math.min(total, page * pageSize)
  return (
    <div className="flex flex-col items-center justify-between gap-3 border-t px-5 py-3 text-[13px] text-muted-foreground sm:flex-row">
      <span>{t('common.showing', { from, to, total })}</span>
      <div className="flex items-center gap-1.5">
        <Button variant="outline" size="icon-sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)} aria-label={t('common.prev')}>
          <ChevronLeft className="rtl:-scale-x-100" />
        </Button>
        <span className="px-2 tabular">{t('common.pageOf', { page, pages })}</span>
        <Button variant="outline" size="icon-sm" disabled={page >= pages} onClick={() => onPageChange(page + 1)} aria-label={t('common.next')}>
          <ChevronRight className="rtl:-scale-x-100" />
        </Button>
      </div>
    </div>
  )
}

export function paginate<T>(items: T[], page: number, pageSize: number) {
  return items.slice((page - 1) * pageSize, page * pageSize)
}
