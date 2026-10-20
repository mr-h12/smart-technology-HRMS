import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { intlLocale } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { MAX_BATCH, parseBatch, type SheetAction, type SheetRow } from '@/lib/day-sheet'
import { cn } from '@/lib/utils'
import { FOCUS, QUICK } from './attendance-sheet-rows'

type Batch = ReturnType<typeof parseBatch>
const CHIP = 'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold'

/** The live chips under a batch input: how many rows it hits, the IDs not on the sheet, and the 500-ID cap. */
export function BatchPreview({ batch, count }: { batch: Batch; count: number }) {
  const { t, i18n } = useTranslation()
  return (
    <>
      <span className={cn(CHIP, 'tabular', count ? 'border-primary/30 bg-accent text-accent-foreground' : 'bg-muted text-muted-foreground')}>{t('attendance.sheet.matched', { count })}</span>
      {batch.unknown.length > 0 && (
        <span className={cn(CHIP, 'border-warning-border bg-warning-bg text-warning')}>{t('attendance.sheet.unknown', { ids: new Intl.ListFormat(intlLocale(i18n.language)).format(batch.unknown) })}</span>
      )}
      {batch.capped && <span className={cn(CHIP, 'border-warning-border bg-warning-bg text-warning')}>{t('attendance.sheet.capped', { max: MAX_BATCH })}</span>}
    </>
  )
}

/**
 * Bulk paste (an Excel column, a WhatsApp list): newlines become spaces, then the same parser as the command bar. Only an ID
 * batch is written; a name never is. `onApply` is the sheet's `apply()` and resolves true once the rows are written.
 */
export function PasteDialog({ open, onOpenChange, rows, leaveSite, onApply }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  rows: SheetRow[]
  leaveSite: (code: string) => string | undefined
  onApply: (action: SheetAction, rows: SheetRow[], label: string) => Promise<boolean>
}) {
  const { t, i18n } = useTranslation()
  const [text, setText] = useState('')
  const id = useId()
  const batch = text.trim() ? parseBatch(text.replace(/\r?\n/g, ' '), rows) : null
  const ids = batch && !batch.byName ? batch : null

  async function run(action: SheetAction, label: string) {
    if (!ids || !(await onApply(action, ids.matches, `${label} (${ids.matches.length})`))) return
    if (ids.unknown.length) toast.warning(t('attendance.sheet.unknown', { ids: new Intl.ListFormat(intlLocale(i18n.language)).format(ids.unknown) }))
    setText('')
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('attendance.sheet.paste.title')}</DialogTitle>
          <DialogDescription>{t('attendance.sheet.paste.hint')}</DialogDescription>
        </DialogHeader>
        <DialogBody className="grid gap-2">
          <Label htmlFor={id}>{t('attendance.sheet.paste.label')}</Label>
          <Textarea id={id} dir="auto" rows={8} value={text} onChange={(e) => setText(e.target.value)} className="tabular rtl:text-right" />
          <div role="status" className="flex min-h-6 flex-wrap items-center gap-1.5">
            {batch?.byName && <span className="text-xs text-warning">{t('attendance.sheet.paste.idsOnly')}</span>}
            {ids && <BatchPreview batch={ids} count={ids.matches.length} />}
          </div>
        </DialogBody>
        <DialogFooter>
          {QUICK.map(([action, label, Icon, tone]) => (
            <Button key={label} type="button" variant="outline" className={cn(FOCUS, 'h-11 md:h-9', tone)} disabled={!ids?.matches.length || (action.kind === 'leave' && !leaveSite(action.code))}
              onClick={() => void run(action, t(label))}>
              <Icon aria-hidden />
              {t(label)}
            </Button>
          ))}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
