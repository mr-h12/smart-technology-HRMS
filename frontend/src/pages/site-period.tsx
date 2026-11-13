import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/common/StatusBadge'
import { ErrorBanner, localName, type Row } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { useApi, useSave } from '@/lib/query'

export type Signoff = { id: string; period_start: string; period_end: string; version: number; status: 'signed' | 'returned'; return_note: string | null }
type PeriodRow = {
  employee: Row & { type: string }
  period: { start: string; end: string }
  marked: number
  missing: number
  open: number
  pending: number
  signoff: Signoff | null
  can_sign: boolean
  locked: boolean
  correction_days: { date: string; site_id: string }[]
}

export function SitePeriod({ site, which, at, dirty, changePeriod, openDay }: {
  site: string; which: 'current' | 'previous'; at: string | null; dirty: boolean
  changePeriod: (which: string) => void; openDay: (date: string, site: string) => void
}) {
  const { t, i18n } = useTranslation()
  const { data: rows, err, loading } = useApi<PeriodRow[]>(`/attendance/site-period?${new URLSearchParams({ site_id: site, which, ...(at && { at }) })}`)
  const save = useSave()
  const [busy, setBusy] = useState<string>()
  const [error, setError] = useState<unknown>()

  async function sign(row: PeriodRow) {
    setBusy(row.employee.id)
    setError(undefined)
    try {
      await save('/attendance/signoffs', { body: {
        site_id: site, employee_id: row.employee.id, employee_type: row.employee.type, period_start: row.period.start,
        version: row.signoff?.version ?? null,
      } }, ['/attendance'])
    } catch (error) {
      setError(error)
    } finally {
      setBusy(undefined)
    }
  }

  return (
    <section className="grid gap-3" aria-label={t('sitePeriod.tab')}>
      <SegmentedControl className="w-fit" label={t('sitePeriod.tab')} value={at ? '' : which} options={['current', 'previous'].map((value) => ({
        value, label: t(`sitePeriod.${value}`), content: <span aria-hidden>{t(`sitePeriod.${value}`)}</span>,
      }))} onChange={changePeriod} />
      <ErrorBanner err={err ?? error} shown={[]} />
      {dirty && <p className="text-sm">{t('sitePeriod.saveFirst')}</p>}
      {loading && <TableSkeleton rows={4} />}
      {!loading && !err && !rows?.length && <p className="text-sm text-muted-foreground">{t('sitePeriod.empty')}</p>}
      {rows?.map((row) => (
        <Card key={`${row.employee.id}/${row.employee.type}/${row.period.start}`} className="grid gap-2 p-4">
          <div className="flex flex-wrap items-center gap-2">
            <bdi className="font-semibold">{localName(row.employee, i18n.language)}</bdi>
            <span className="text-sm text-muted-foreground">{t(`attendance.group.${row.employee.type}`)}</span>
            {row.signoff && <StatusBadge variant={row.signoff.status === 'signed' ? 'success' : 'neutral'} label={t(`sitePeriod.${row.signoff.status}`)} />}
          </div>
          <p className="text-sm"><bdi dir="ltr">{row.period.start} – {row.period.end}</bdi></p>
          <p className="flex flex-wrap gap-x-3 text-sm">
            <span>{t('sitePeriod.marked', { count: row.marked })}</span>
            <span>{t('sitePeriod.missing', { count: row.missing })}</span>
            <span>{t('sitePeriod.open', { count: row.open })}</span>
            <span>{t('sitePeriod.pending', { count: row.pending })}</span>
          </p>
          {row.signoff?.return_note && <p className="break-words text-sm">{t('sitePeriod.note')} <bdi>{row.signoff.return_note}</bdi></p>}
          {!!row.correction_days.length && (
            <details>
              <summary className="cursor-pointer text-sm">{t('sitePeriod.correctDays')}</summary>
              <div className="flex flex-wrap gap-1 pt-2">
                {row.correction_days.map((day) => <Button type="button" size="sm" variant="outline" key={day.date} onClick={() => openDay(day.date, day.site_id)}><bdi>{day.date}</bdi></Button>)}
              </div>
            </details>
          )}
          {row.locked ? <p className="text-sm">{t('sitePeriod.locked')}</p> : row.can_sign ? (
            <Button type="button" className="w-fit" disabled={busy !== undefined || dirty} onClick={() => void sign(row)}>
              {t(row.signoff ? 'sitePeriod.resubmit' : 'sitePeriod.submit')}
            </Button>
          ) : !row.signoff && <p className="text-sm text-muted-foreground">{t('sitePeriod.notDue')}</p>}
        </Card>
      ))}
    </section>
  )
}
