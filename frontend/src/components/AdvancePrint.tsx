import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { useMoney } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'

/** The saved request supplies the identifiers and amount. The paper carries the original form's handwritten fields. */
export function AdvancePrint({ id, version, name, code, site, date, amount }: {
  id: string; version: number; name: string; code: number; site: string; date: string; amount: string
}) {
  const { t, i18n } = useTranslation()
  const money = useMoney()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button type="button" variant="outline" onClick={() => setOpen(true)}>{t('attendanceRequests.print')}</Button>
      {open && (
        <>
          <Dialog open onOpenChange={setOpen}>
            <DialogContent aria-describedby={undefined}>
              <DialogHeader><DialogTitle>{t('attendanceRequests.advanceForm')}</DialogTitle></DialogHeader>
              <DialogBody><bdi>{name}</bdi> · {money(amount)}</DialogBody>
              <DialogFooter><Button type="button" onClick={() => window.print()}>{t('attendanceRequests.print')}</Button></DialogFooter>
            </DialogContent>
          </Dialog>
          {createPortal(
            <article className="advance-print hidden bg-white p-8 text-black print:block" dir={i18n.dir()} lang={i18n.language}>
              <style>{'@media print { body > :not(.advance-print) { display: none !important; } body { overflow: visible !important; } }'}</style>
              <h1 className="mb-5 text-center text-2xl font-bold">{t('attendanceRequests.advanceForm')}</h1>
              <dl className="grid grid-cols-2 gap-3 border p-4 text-sm">
                <div>{t('employee.employee')} <bdi>{name}</bdi></div>
                <div>{t('employee.code')} <bdi>{code}</bdi></div>
                <div>{t('wages.site')} <bdi>{site}</bdi></div>
                <div>{t('attendanceRequests.date')} <bdi>{date}</bdi></div>
                <div>{t('attendanceRequests.amount')} {money(amount)}</div>
                <div>{t('attendanceRequests.reference')} <bdi className="text-xs">{id} / {version}</bdi></div>
              </dl>
              <div className="mt-5 space-y-3 text-sm">
                {['department', 'job', 'reason', 'repayment', 'employeeSignature', 'departmentSignature', 'finance', 'director'].map((key) => (
                  <section key={key} className="border p-3">
                    <h2 className="font-semibold">{t(`attendanceRequests.paper.${key}`)}</h2>
                    <p className="mt-2">..................................................................................................................</p>
                  </section>
                ))}
              </div>
            </article>,
            document.body,
          )}
        </>
      )}
    </>
  )
}
