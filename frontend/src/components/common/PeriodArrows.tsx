import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ltrDates } from '@/components/form'
import { Button } from '@/components/ui/button'

/** A period header (`GET /attendance/period`) as the arrows read it. */
export type PeriodRange = { start: string; end: string; prev_start: string; next_start: string | null }

/** The period's range between its previous and next arrows (attendance and payroll); `children` follow the arrows. No period yet: a dash, arrows off. */
export function PeriodArrows({ period, onMove, children }: { period?: PeriodRange; onMove: (start: string) => void; children?: ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-center gap-1">
      <Button variant="outline" size="icon" disabled={!period} onClick={() => period && onMove(period.prev_start)} aria-label={t('attendance.prevPeriod')}>
        <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
      </Button>
      <span className="px-1 text-sm font-medium whitespace-nowrap">
        {period ? ltrDates(t('attendance.periodRange', { start: period.start, end: period.end })) : '—'}
      </span>
      <Button variant="outline" size="icon" disabled={!period?.next_start} onClick={() => period?.next_start && onMove(period.next_start)} aria-label={t('attendance.nextPeriod')}>
        <ChevronRight className="rtl:-scale-x-100" aria-hidden />
      </Button>
      {children}
    </div>
  )
}
