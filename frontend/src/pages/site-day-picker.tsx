import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { localName, type Row } from '@/components/form'
import { Button } from '@/components/ui/button'
import { useApi } from '@/lib/query'
import { counts, dayPath, type SiteDay } from '@/lib/site-day'
import { cn } from '@/lib/utils'

/**
 * The entry step: which of the site manager's assigned sites they work on now. A working list only; it never changes an assignment.
 * A site left out keeps its unsaved changes, which come back when it is chosen again.
 */
export function SitePicker({ sites, initial, unsavedAt, onContinue }: { sites: Row[]; initial: string[]; unsavedAt: (id: string) => boolean; onContinue: (ids: string[]) => void }) {
  const { t, i18n } = useTranslation()
  const [picked, setPicked] = useState<string[]>(initial)
  const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  const ordered = sites.map((s) => s.id).filter((id) => picked.includes(id))
  const leftOut = sites.filter((s) => !picked.includes(s.id) && unsavedAt(s.id)).map((s) => localName(s, i18n.language))
  return (
    <div className="mx-auto flex w-full max-w-xl flex-col gap-4">
      <div>
        <h1 className="text-page-title text-balance">{t('siteDay.pickTitle')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('siteDay.pickHint')}</p>
      </div>
      <ul aria-label={t('siteDay.pickTitle')} className="grid gap-2">
        {sites.map((s) => {
          const on = picked.includes(s.id)
          return (
            <li key={s.id}>
              <button type="button" role="checkbox" aria-checked={on} onClick={() => toggle(s.id)}
                className={cn('flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border bg-card px-4 text-start shadow-1 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring', on && 'border-primary bg-primary/10')}>
                <span className="font-semibold">{localName(s, i18n.language)}</span>
                <span className="flex items-center gap-2">
                  {unsavedAt(s.id) && <span className="text-[12px] text-warning">{t('siteDay.unsavedShort')}</span>}
                  <span aria-hidden className={cn('grid size-6 place-items-center rounded-md border', on && 'border-primary bg-primary text-primary-foreground')}>{on ? '✓' : ''}</span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>
      {leftOut.length > 0 && <Alert tone="warning">{t('siteDay.leftOutUnsaved', { sites: leftOut.join('، ') })}</Alert>}
      <p className="text-[13px] text-muted-foreground">{t('siteDay.hrOwnsSites')}</p>
      <Button type="button" className="min-h-11" disabled={!ordered.length} onClick={() => onContinue(ordered)}>{t('siteDay.continue')}</Button>
    </div>
  )
}

/** A site in the switcher, with what is still open there (its day is the same cached query the page opens). */
export function SiteTab({ s, active, date, unsaved, onPick }: { s: Row; active: boolean; date: string | null; unsaved: boolean; onPick: () => void }) {
  const { t, i18n } = useTranslation()
  const { data } = useApi<SiteDay>(dayPath(s.id, date))
  const open = data && data.site.id === s.id ? counts(data.rows, {}, s.id, data.late_grace_minutes) : null
  const todo = open ? open.unrecorded + open.noCheckout : 0
  let state = null
  if (unsaved) state = <span className="text-[12px] font-normal text-warning">{t('siteDay.unsavedShort')}</span>
  else if (open && todo > 0) state = <span className="rounded-full bg-warning-bg px-2 text-[12px] font-normal text-warning">{t('siteDay.todo', { count: todo })}</span>
  else if (open) state = <span className="text-[12px] font-normal text-success">{t('siteDay.done')}</span>
  return (
    <Button type="button" variant="outline" aria-pressed={active} onClick={onPick} className={cn('min-h-11 gap-2', active && 'border-primary bg-primary/10 text-primary')}>
      <span>{localName(s, i18n.language)}</span>
      {state}
    </Button>
  )
}

