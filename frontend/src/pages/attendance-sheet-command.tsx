import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { intlLocale, isHhmm, localName, type Row, SelectField, TimeInput } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { type BulkRow, hhmm, isOfficeRow, MAX_BATCH, parseBatch, parseSiteMessage, type SheetAction, type SheetRow, statusOf } from '@/lib/day-sheet'
import { cn } from '@/lib/utils'
import { QUICK, selectOnFocus } from './attendance-sheet-rows'

type Batch = ReturnType<typeof parseBatch>

/** The live badges under the paste box: how many rows it hits, the IDs not on the sheet, and the 500-ID cap. */
function BatchPreview({ batch, count }: { batch: Batch; count: number }) {
  const { t, i18n } = useTranslation()
  return (
    <>
      <Badge variant={count ? 'default' : 'neutral'}>{t('attendance.sheet.matched', { count })}</Badge>
      {batch.unknown.length > 0 && <Badge variant="warning" className="whitespace-normal">{t('attendance.sheet.unknown', { ids: new Intl.ListFormat(intlLocale(i18n.language)).format(batch.unknown) })}</Badge>}
      {batch.capped && <Badge variant="warning">{t('attendance.sheet.capped', { max: MAX_BATCH })}</Badge>}
    </>
  )
}

/**
 * Bulk paste (an Excel column, a WhatsApp list): newlines become spaces, then `parseBatch`. Only an ID
 * batch is written; a name never is. `onApply` is the sheet's `apply()` and resolves true once the rows are written.
 */
export function PasteDialog({ open, onOpenChange, rows, leaveTypeId, onApply }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  rows: SheetRow[]
  leaveTypeId: (code: string) => string | undefined
  onApply: (action: SheetAction, rows: SheetRow[], label: string) => Promise<boolean>
}) {
  const { t, i18n } = useTranslation()
  const [text, setText] = useState('')
  const id = useId()
  const batch = text.trim() ? parseBatch(text.replace(/\r?\n/g, ' '), rows) : null
  const ids = batch && !batch.byName ? batch : null

  async function run(action: SheetAction, label: string) {
    if (!ids || !(await onApply(action, ids.matches, t('attendance.sheet.actionCount', { action: label, count: ids.matches.length })))) return
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
          <Textarea id={id} dir="auto" rows={8} value={text} onChange={(e) => setText(e.target.value)} className="rtl:text-right" />
          <div role="status" className="flex min-h-6 flex-wrap items-center gap-1.5">
            {batch?.byName && <span className="text-xs text-warning">{t('attendance.sheet.paste.idsOnly')}</span>}
            {ids && <BatchPreview batch={ids} count={ids.matches.length} />}
          </div>
          {/* who each ID names today (HR can renumber IDs); outside the live region so it is not re-read per keystroke */}
          {!!ids?.matches.length && (
            <ul aria-label={t('attendance.sheet.paste.matches')} className="max-h-40 overflow-y-auto rounded-md border px-2 py-1 text-start text-xs">
              {ids.matches.map((r) => (
                <li key={r.employee.id}><span dir="ltr" className="text-muted-foreground">{r.employee.code}</span> {localName(r.employee, i18n.language)}</li>
              ))}
            </ul>
          )}
        </DialogBody>
        {/* Present is the dialog's one solid action; Absent and Sick are ghost */}
        <DialogFooter>
          {QUICK.map(([action, label, Icon, tone]) => (
            // a ghost has no border, so the tone's hover:border-* is dropped
            <Button key={label} type="button" variant={action.kind === 'present' ? 'default' : 'ghost'} className={cn(action.kind !== 'present' && tone.replace(/ hover:border-\S+/, ''))} disabled={!ids?.matches.length || (action.kind === 'leave' && !leaveTypeId(action.code))}
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

export function SiteMessageDialog({ rows, sites, date, absentId, allowOvernight, pending, onClose, onApply }: {
  rows: SheetRow[]
  sites: Row[]
  date: string
  absentId?: string
  allowOvernight: boolean
  pending: boolean
  onClose: () => void
  onApply: (out: BulkRow[], before: SheetRow[]) => Promise<boolean>
}) {
  const { t, i18n } = useTranslation()
  const id = useId()
  const [input, setInput] = useState('')
  const [siteId, setSiteId] = useState('')
  const selectedSite = sites.find((site) => site.id === siteId)
  const { entries, invalid, capped } = parseSiteMessage(input, rows, allowOvernight)
  const ready = !pending && !capped && !invalid.length && entries.length > 0
    && entries.every(({ inTime }) => inTime ? !!selectedSite : !!absentId)

  async function confirm() {
    if (!ready) return
    const saved = await onApply(entries.map(({ row, inTime, outTime }) => ({
      employee_id: row.employee.id,
      version: row.day.version,
      site_id: inTime ? siteId : absentId,
      ...(inTime && { in_time: inTime, out_time: outTime }),
    })), entries.map(({ row }) => row))
    if (saved) onClose()
  }

  return (
    <Dialog open onOpenChange={(open) => { if (!open && !pending) onClose() }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('attendance.sheet.message.title')}</DialogTitle>
          <DialogDescription>{t('attendance.sheet.message.hint')} <bdi>{date}</bdi></DialogDescription>
        </DialogHeader>
        <DialogBody className="grid gap-3">
          <SelectField name="site" label={t('attendance.sheet.message.site')} values={{ site: siteId }} set={(_, value) => setSiteId(value)}
            errs={{}} blank disabled={pending} options={sites.map((site) => [site.id, localName(site, i18n.language)])} />
          <Label htmlFor={id}>{t('attendance.sheet.message.label')}</Label>
          <Textarea id={id} dir="auto" rows={5} disabled={pending} value={input} onChange={(e) => setInput(e.target.value)} />
          {!!invalid.length && <p role="alert" className="text-sm text-destructive">{t('attendance.sheet.message.invalid', { lines: invalid.join(', ') })}</p>}
          {capped && <p role="alert" className="text-sm text-destructive">{t('attendance.sheet.toast.tooMany', { max: MAX_BATCH })}</p>}
          {!!entries.length && (
            <ul aria-label={t('attendance.sheet.message.preview')} className="max-h-40 overflow-y-auto rounded-md border p-2 text-sm">
              {entries.map(({ row, inTime, outTime }) => (
                <li key={row.employee.id} className="flex flex-wrap items-center gap-x-2 py-0.5">
                  <bdi>{row.employee.code}</bdi>
                  <span>{localName(row.employee, i18n.language)}</span>
                  {inTime ? <><bdi>{inTime} – {outTime}</bdi>{selectedSite && <span>{localName(selectedSite, i18n.language)}</span>}</> : <span>{t('attendance.state.absent')}</span>}
                  {row.day.state !== 'missing' && <Badge variant="warning">{t('attendance.sheet.message.replaces')}</Badge>}
                </li>
              ))}
            </ul>
          )}
        </DialogBody>
        <DialogFooter>
          <Button type="button" disabled={!ready} onClick={() => void confirm()}>{t('attendance.sheet.message.save', { count: entries.length })}</Button>
          <Button type="button" variant="ghost" disabled={pending} onClick={onClose}>{t('common.cancel')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const TIMES = ['in_time', 'out_time'] as const

/**
 * Bulk arrival/departure for a selection. A box starts with the time every present row shares, else blank («mixed values»); a blank box
 * changes nothing, and so does a shared value left as it is. Apply waits for a present row in the selection, a box with a new time and
 * none still needing AM/PM. Only present (or late) rows are written; the rest are skipped with a count toast. `onApply` is the sheet's
 * `apply()` and resolves true once the rows are written.
 */
export function TimesDialog({ rows, onOpenChange, onApply }: {
  rows: SheetRow[]
  onOpenChange: (open: boolean) => void
  onApply: (action: SheetAction, rows: SheetRow[], label: string) => Promise<boolean>
}) {
  const { t } = useTranslation()
  const ids = { in_time: useId(), out_time: useId() }
  const here = rows.filter((r) => ['present', 'late'].includes(statusOf(r, isOfficeRow(r))))
  const shared = (f: (typeof TIMES)[number]) => {
    const all = new Set(here.map((r) => hhmm(r.day[f]) ?? ''))
    return all.size === 1 ? [...all][0] : null // null: the rows differ
  }
  const [start] = useState(() => Object.fromEntries(TIMES.map((f) => [f, shared(f)])))
  const [box, setBox] = useState(() => Object.fromEntries(TIMES.map((f) => [f, { v: start[f] ?? '', needs: false }])))
  const mixed = TIMES.filter((f) => start[f] === null)
  // a box counts once it holds a time other than the one it opened with (a shared value left alone changes nothing)
  const given = TIMES.filter((f) => box[f].v && box[f].v !== start[f])
  const ready = here.length > 0 && given.length > 0 && TIMES.every((f) => !box[f].v || (!box[f].needs && isHhmm(box[f].v)))

  async function run() {
    const skipped = rows.length - here.length
    if (skipped) toast.warning(t('attendance.sheet.toast.skippedNotPresent', { count: skipped }))
    const action: SheetAction = { kind: 'time', ...Object.fromEntries(given.map((f) => [f, box[f].v])) }
    if (here.length && !(await onApply(action, here, t('attendance.sheet.actionCount', { action: t('attendance.sheet.bulk.times'), count: here.length })))) return
    onOpenChange(false)
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('attendance.sheet.times.title')}</DialogTitle>
          <DialogDescription>{t('attendance.sheet.times.selected', { count: rows.length })}</DialogDescription>
        </DialogHeader>
        <DialogBody className="grid gap-4">
          {TIMES.map((f) => (
            <div key={f} className="grid gap-1.5">
              <Label>{t(`attendance.day.${f}`)}</Label>
              <TimeInput value={box[f].v} label={t(`attendance.day.${f}`)} aria-label={t(`attendance.day.${f}`)} aria-describedby={mixed.includes(f) ? ids[f] : undefined} {...selectOnFocus}
                onChange={(v, needs) => setBox((b) => ({ ...b, [f]: { v, needs } }))} />
              {mixed.includes(f) && <p id={ids[f]} className="text-xs text-muted-foreground">{t('attendance.sheet.times.mixed')}</p>}
            </div>
          ))}
        </DialogBody>
        <DialogFooter>
          <Button type="button" disabled={!ready} onClick={() => void run()}>{t('attendance.sheet.bulk.apply')}</Button>
          <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>{t('common.cancel')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
