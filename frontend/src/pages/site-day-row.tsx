import { MoreHorizontal, StickyNote, UserMinus } from 'lucide-react'
import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AdvancePrint } from '@/components/AdvancePrint'
import { asciiDigits, ErrorBanner, isHhmm, localName, type Row, TimeInput } from '@/components/form'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input, Textarea } from '@/components/ui/input'
import { useSave } from '@/lib/query'
import { chargeableLate, changed, departureDiff, type Draft, type LeaveDayTypes, type Mark, MARKS, MORE, type SiteDayRow } from '@/lib/site-day'
import { cn } from '@/lib/utils'

const PRESSED: Record<Mark, string> = {
  present: 'bg-success-bg border-success-border text-success',
  absent: 'bg-destructive-bg border-destructive-border text-destructive',
  sick: 'bg-info-bg border-info-border text-info',
}

/** The leave code a draft's site stands for, among the site manager's leave day-types. */
const leaveOf = (d: Draft | null, leave: LeaveDayTypes) => (d ? (Object.entries(leave).find(([, id]) => id === d.site_id)?.[0] ?? null) : null)

/** One person: status buttons, then (when present) the arrival and the departure with its difference from the shift end. */
export function PersonRow({ r, draft, siteId, date, siteName, grace, leave, sites, set, removable, onRemoved, locked, onOpenDay }: {
  r: SiteDayRow; draft: Draft | null; siteId: string; date: string; siteName: string; grace: number; leave: LeaveDayTypes; sites: Row[]; set: (d: Draft | null) => void; removable: boolean; onRemoved: () => void; locked: boolean
  onOpenDay: (date: string, site: string) => void
}) {
  const { t, i18n } = useTranslation()
  const save = useSave()
  const [removeErr, setRemoveErr] = useState<unknown>()
  const lang = i18n.language
  const name = localName(r.employee as Row, lang)
  const present = draft?.site_id === siteId
  const code = leaveOf(draft, leave)
  const mark: Mark | null = present ? 'present' : code === 'absent' || code === 'sick' ? code : null
  const off = !r.can_edit || locked
  // past the window, an open day takes its departure only: the status and the arrival stay as they are
  const fixed = off || r.departure_only
  const leaveName = (c: string) => t(`attendance.leaveName.${c}`, { defaultValue: c })
  let hint: string | null = null
  if (r.why_not) hint = t(`siteDay.why.${r.why_not === 'open_day' && !r.open_site_id ? 'open_day_hr' : r.why_not}`, { date: r.open_date ?? '' })
  else if (r.departure_only) hint = t('siteDay.departureOnly')
  else if (code && mark === null) hint = leaveName(code)                       // rest or other
  else if (!draft && r.day.leave_code) hint = leaveName(r.day.leave_code)       // a weekly off or holiday
  else if (draft && !present && !code) {
    // stored elsewhere: a leave type the site manager cannot choose, or another work site
    const other = sites.find((s) => s.id === draft.site_id)
    hint = r.day.leave_code ? leaveName(r.day.leave_code) : other ? t('siteDay.atSite', { site: localName(other, lang) }) : t('siteDay.otherSite')
  }

  const press = (m: Mark | (typeof MORE)[number]) => {
    if (m === 'present') {
      if (!present) set({ site_id: siteId, in_time: r.shift.start, out_time: null })
      return
    }
    const id = leave[m]
    if (id) set({ site_id: id, in_time: null, out_time: null })
  }
  // TimeInput hands back every keystroke (a half-typed "9:" too) and expects it back as its value; the save checks the result
  const time = (field: 'in_time' | 'out_time') => (v: string) => set({ ...(draft ?? { site_id: siteId, in_time: null, out_time: null }), [field]: v === '' ? null : v })
  const out = draft?.out_time ?? null
  const diff = present && isHhmm(out) ? departureDiff(out, r.shift.end) : null
  const arrival = draft?.in_time ?? null
  const late = present && isHhmm(arrival) ? chargeableLate(arrival, r.shift.start, grace) : null
  const missingOut = present && isHhmm(draft?.in_time ?? null) && !out
  const [advanceOpen, setAdvanceOpen] = useState(false)
  const advance = r.requests?.advance
  const overtime = r.requests?.overtime
  const editableAdvance = !!draft && present && !fixed && (!advance || advance.status === 'pending')
  const [noteOpen, setNoteOpen] = useState(false)
  const storedNote = r.day.state === 'stored' ? r.day.note : null
  const noteText = draft?.note !== undefined ? (draft.note ?? '') : (storedNote ?? '')
  const noteId = useId()
  // a note goes with a day this site saves: present here or one of the site manager's leave day-types, never a day stored at another
  // site (its save would be refused; PR #24 review), and not on a departure-only day, where only the departure may change
  const notable = draft !== null && (present || code !== null) && !r.departure_only
  const remove = async () => {
    setRemoveErr(undefined)
    try {
      await save('/attendance/site-day/crew', { body: { site_id: siteId, employee_id: r.employee.id, action: 'remove' } }, ['/attendance'])
      onRemoved()
    } catch (e) {
      setRemoveErr(e)
    }
  }

  return (
    <li className={cn('rounded-xl border bg-card p-3 shadow-1', draft !== null && changed(r, draft) && 'border-s-4 border-s-warning')}>
      <div role="group" aria-label={name} className="grid gap-2 xl:grid-cols-[minmax(10rem,1fr)_minmax(16rem,20rem)_minmax(10rem,13rem)_minmax(10rem,13rem)] xl:items-center xl:gap-3">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="font-semibold"><bdi>{name}</bdi></div>
            <div className="text-[13px] text-muted-foreground">
              {t(`enum.type.${r.employee.type}`)}
              {hint && <> · <span>{hint}</span></>}
            </div>
            {r.why_not === 'open_day' && r.open_date && r.open_site_id && (
              <Button type="button" variant="link" className="h-auto min-h-11 px-0 text-[13px]" onClick={() => onOpenDay(r.open_date!, r.open_site_id!)}>
                {t('siteDay.openDay', { date: r.open_date })}
              </Button>
            )}
          </div>
          {removable && (
            <Button type="button" variant="ghost" size="icon" className="size-11 shrink-0" aria-label={t('siteDay.removeFromToday', { name })} title={t('siteDay.removeFromToday', { name })} onClick={() => void remove()}>
              <UserMinus aria-hidden />
            </Button>
          )}
        </div>
        <div className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
          {MARKS.map((m) => (
            <Button key={m} type="button" variant="outline" disabled={fixed || (m !== 'present' && !leave[m])} aria-pressed={mark === m} onClick={() => press(m)}
              className={cn('h-11 min-w-0 px-2', mark === m ? cn(PRESSED[m], 'disabled:opacity-100') : 'text-muted-foreground')}>
              {t(`siteDay.${m}`)}
            </Button>
          ))}
          <DropdownMenu dir={lang === 'ar' ? 'rtl' : 'ltr'}>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" size="icon" disabled={fixed} className={cn('size-11', code && mark === null && 'border-primary text-primary')} aria-label={t('siteDay.more')}>
                <MoreHorizontal aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {MORE.map((m) => (
                <DropdownMenuItem key={m} disabled={!leave[m]} onSelect={() => press(m)}>{leaveName(m)}</DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        {present ? (
          <div className="grid grid-cols-2 gap-2 xl:col-span-2 xl:gap-3">
            <div className="grid content-start gap-1">
              <span aria-hidden className="text-[12px] text-muted-foreground">{t('siteDay.arrival')}</span>
              <TimeInput label={t('siteDay.arrival')} aria-label={t('siteDay.arrivalOf', { name })} value={draft?.in_time ?? ''} disabled={fixed} onChange={time('in_time')} quietHint />
              {late !== null && late > 0 && <p className="text-[13px] text-warning">{t('siteDay.late', { count: late })}</p>}
            </div>
            <div className="grid content-start gap-1">
              <span aria-hidden className="text-[12px] text-muted-foreground">{t('siteDay.departure')}</span>
              <TimeInput label={t('siteDay.departure')} aria-label={t('siteDay.departureOf', { name })} value={draft?.out_time ?? ''} disabled={off} onChange={time('out_time')} quietHint />
              {missingOut && <p className="text-[13px] text-warning">{t('siteDay.missingCheckout')}</p>}
              {diff !== null && diff !== 0 && (
                <p className={cn('text-[13px]', diff < 0 ? 'text-warning' : 'text-info')}>
                  {diff < 0 ? t('siteDay.early', { count: -diff }) : t('siteDay.later', { count: diff })}
                </p>
              )}
            </div>
          </div>
        ) : <div className="hidden xl:col-span-2 xl:block" />}
      </div>
      {notable && (
        <div className="mt-2 min-w-0">
          <Button type="button" variant="ghost" className="h-auto min-h-11 max-w-full justify-start gap-1.5 px-1 text-[13px] text-muted-foreground" aria-expanded={noteOpen} aria-controls={noteId} onClick={() => setNoteOpen((o) => !o)}>
            <StickyNote aria-hidden className="shrink-0" />
            {/* a 500-character note must not push the card wider than a 375 px phone: one line, cut with an ellipsis */}
            <span className="min-w-0 truncate">{noteText ? t('siteDay.noteShown', { note: noteText }) : t('siteDay.addNote')}</span>
          </Button>
          {noteOpen && (
            <Textarea id={noteId} aria-label={t('siteDay.noteOf', { name })} maxLength={500} rows={2} disabled={off} value={noteText}
              onChange={(e) => set({ ...draft, note: e.target.value })} className="mt-1" />
          )}
        </div>
      )}
      {(overtime || advance || editableAdvance) && (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          {overtime && <span className="rounded-md bg-muted px-2 py-1 text-xs">{t('attendanceRequests.type.overtime')} · {t('attendanceRequests.minutes', { count: overtime.minutes ?? 0 })} · {t(`attendanceRequests.status.${overtime.status}`)}</span>}
          {advance && <span className="rounded-md bg-muted px-2 py-1 text-xs">{t('attendanceRequests.type.advance')} · {t(advance.status === 'approved' ? 'attendanceRequests.paid' : `attendanceRequests.status.${advance.status}`)}</span>}
          {editableAdvance && <Button type="button" variant="ghost" className="min-h-tap" aria-expanded={advanceOpen} onClick={() => setAdvanceOpen((open) => !open)}>{t('attendanceRequests.requestAdvance')}</Button>}
          {advance?.amount && draft?.advance_request === undefined && <AdvancePrint id={advance.id} version={advance.version} name={name} code={r.employee.code} site={siteName} date={date} amount={advance.amount} />}
        </div>
      )}
      {advanceOpen && editableAdvance && draft && (
        <label className="mt-2 grid max-w-64 gap-1 text-sm">
          {t('attendanceRequests.amount')}
          <Input dir="ltr" inputMode="decimal" maxLength={19} value={draft.advance_request === undefined ? advance?.amount ?? '' : draft.advance_request ?? ''}
            onChange={(e) => set({ ...draft, advance_request: asciiDigits(e.target.value).trim() || null })} />
          <span className="text-xs text-muted-foreground">{t('attendanceRequests.saveBeforePrint')}</span>
        </label>
      )}
      {removeErr !== undefined && <div className="mt-2"><ErrorBanner err={removeErr} shown={[]} /></div>}
    </li>
  )
}

