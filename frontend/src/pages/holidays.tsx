import { CalendarDays, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { RowActions } from '@/components/common/RowActions'
import { type Controlled, ErrorBanner, FormDialog, ltrDates, type Row, TextField, useList } from '@/components/form'
import { paginate } from '@/components/tables/Pagination'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { useSave } from '@/lib/query'
import { ListCard, Notice, PAGE } from './sites'

const PATH = '/attendance/holidays'

/** Calendar days from date to end_date, both included; ISO dates parse as UTC midnight, so no daylight-saving hour. */
const dayCount = (h: Row) => (Date.parse(h.end_date) - Date.parse(h.date)) / 86_400_000 + 1

/** Add or edit; a blank «إلى تاريخ» is the one day. Overlapping dates show under the field, a locked period (423) in the banner. */
function HolidayDialog({ holiday, ...dialog }: { holiday?: Row } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  const body = (form: Row) => ({ date: form.date, end_date: form.end_date || form.date, name: form.name })
  return (
    <FormDialog
      {...dialog}
      title={t(holiday ? 'holiday.edit' : 'holiday.add')}
      trigger={holiday ? undefined : <Button type="button" variant="outline" size="sm"><Plus />{t('holiday.add')}</Button>}
      init={{ date: holiday?.date ?? '', end_date: holiday?.end_date ?? '', name: holiday?.name ?? '' }}
      submit={(form) => (holiday ? send(`${PATH}/${holiday.id}`, { method: 'PATCH', body: body(form) }) : send(PATH, { body: body(form) }))}
      onDone={() => {}}
      shown={['date', 'end_date', 'name']}
    >
      {(f) => (
        <>
          <TextField name="date" label={t('holiday.from')} type="date" {...f} />
          <TextField name="end_date" label={t('holiday.to')} type="date" min={f.values.date || undefined} {...f} />
          <TextField name="name" label={t('holiday.name')} {...f} />
        </>
      )}
    </FormDialog>
  )
}

function DeleteDialog({ holiday, onClose }: { holiday: Row; onClose: () => void }) {
  const { t } = useTranslation()
  const send = useSave()
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  function remove() {
    setBusy(true)
    setErr(undefined)
    send(`${PATH}/${holiday.id}`, { method: 'DELETE' })
      .then(onClose, setErr)
      .finally(() => setBusy(false))
  }
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('holiday.deleteConfirm', { name: holiday.name })}</DialogTitle>
        </DialogHeader>
        <DialogBody className="empty:hidden">
          <ErrorBanner err={err} shown={[]} />
        </DialogBody>
        <DialogFooter>
          <Button type="button" variant="destructive" className="h-11" disabled={busy} onClick={remove}>
            {t('holiday.delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** Official holidays: the attendance grid derives these days as an official holiday for every employee. */
export function HolidaysTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const manage = can('holiday.manage')
  const { rows, total, err, loading } = useList(`${PATH}?per_page=100`)
  const [page, setPage] = useState(1)
  const [dlg, setDlg] = useState<{ type: 'edit' | 'delete'; row: Row }>()
  const close = () => setDlg(undefined)
  return (
    <ListCard
      title={t('settings.tab.holidays')}
      description={t('settings.desc.holidays')}
      action={manage && <HolidayDialog />}
      err={err}
      loading={loading}
      empty={!rows.length}
      icon={CalendarDays}
      notice={<Notice show={total > rows.length} text={t('common.tooMany')} />}
      page={{ page, total: rows.length, onPageChange: setPage }}
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('holiday.date')}</TableHead>
            <TableHead className="hidden sm:table-cell">{t('holiday.duration')}</TableHead>
            <TableHead>{t('holiday.name')}</TableHead>
            <TableHead>
              <span className="sr-only">{t('common.actions')}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {paginate(rows, page, PAGE).map((h) => (
            <TableRow key={h.id}>
              <TableCell className="whitespace-normal tabular-nums">
                {ltrDates(h.end_date === h.date ? h.date : t('holiday.range', { from: h.date, to: h.end_date }))}
                <div className="text-xs text-muted-foreground sm:hidden">{t('holiday.days', { count: dayCount(h) })}</div>
              </TableCell>
              <TableCell className="hidden tabular-nums sm:table-cell">{t('holiday.days', { count: dayCount(h) })}</TableCell>
              <TableCell className="font-medium whitespace-normal">{h.name}</TableCell>
              <TableCell className="text-end">
                <RowActions
                  items={manage ? [
                    { label: t('site.edit'), onSelect: () => setDlg({ type: 'edit', row: h }) },
                    { label: t('holiday.delete'), onSelect: () => setDlg({ type: 'delete', row: h }), destructive: true },
                  ] : []}
                />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {dlg?.type === 'edit' && <HolidayDialog holiday={dlg.row} open onOpenChange={close} />}
      {dlg?.type === 'delete' && <DeleteDialog holiday={dlg.row} onClose={close} />}
    </ListCard>
  )
}
