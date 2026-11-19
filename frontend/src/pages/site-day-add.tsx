import { UserPlus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, localName, type Row, toErrs } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useDebounced } from '@/hooks/useDebounced'
import { ApiError } from '@/lib/api'
import { useApi, useSave } from '@/lib/query'

type Match = { id: string; code: number; name_ar: string; name_en: string | null; site: { id: string; name_ar: string; name_en: string | null } | null; here: boolean; can_add: boolean }
/** `GET /attendance/site-day/laborers`: the one laborer with that code or full name; `ambiguous` when two share the name. */
type Found = { match: Match | null; ambiguous: boolean }

const GENDERS = ['male', 'female'] as const

/** A new form id per form: the API returns the first answer for a repeat, so a double tap or a retry adds one worker (owner, 2026-11-19). */
const newRequestId = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}`)

/**
 * Find a day laborer by their code or full name to add to today's crew, or add a new one with a proposed daily wage for HR to review.
 * A site manager adds someone they know: the search never lists the company's laborers (owner, 2026-11-05). A new laborer needs a
 * three-part name and may carry a 14-digit national ID; a name someone already has shows that person first, and the manager adds
 * them or confirms it is someone else (owner, 2026-11-19).
 */
export function AddLaborer({ siteId, siteName, canAdd, canPropose, onDone }: { siteId: string; siteName: string; canAdd: boolean; canPropose: boolean; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const save = useSave()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [gender, setGender] = useState('')
  const [wage, setWage] = useState('')
  const [phone, setPhone] = useState('')
  const [nid, setNid] = useState('')
  const [different, setDifferent] = useState(false)
  const [requestId, setRequestId] = useState(newRequestId)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const search = useDebounced(q.trim().replace(/\s+/g, ' '), 300)
  const byCode = /^\d+$/.test(search)
  const { data: found, loading } = useApi<Found>(open && search ? `/attendance/site-day/laborers?${new URLSearchParams({ site_id: siteId, search })}` : null)
  const m = found?.match ?? null
  const reset = () => {
    setQ('')
    setGender('')
    setWage('')
    setPhone('')
    setNid('')
    setDifferent(false)
    setRequestId(newRequestId())
    setErr(undefined)
  }
  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setErr(undefined)
    try {
      await fn()
      setOpen(false)
      reset()
      onDone()
    } catch (e) {
      setErr(e)
    } finally {
      setBusy(false)
    }
  }
  const add = (id: string) => run(() => save('/attendance/site-day/crew', { body: { site_id: siteId, employee_id: id, action: 'add' } }, ['/attendance']))
  const propose = () => run(() => save('/attendance/site-day/laborers', {
    body: { site_id: siteId, name_ar: search, gender, daily_wage: wage.trim(), phone: phone.trim() || null, national_id: nid.trim() || null, client_request_id: requestId, confirm_new: different },
  }, ['/attendance']))
  const errs = toErrs(err)
  const threeParts = search.split(' ').filter(Boolean).length >= 3
  const nidBad = nid.trim() !== '' && !/^\d{14}$/.test(nid.trim())
  // the API's 409 laborer_exists: the person already recorded (null when HR is the contact), or several share the name
  const exists = err instanceof ApiError && err.code === 'laborer_exists' ? (err.body as { reason: 'name' | 'national_id'; match: Match | null; ambiguous: boolean }) : null

  const card = (m: Match) => (
    <div className="flex items-center justify-between gap-2 rounded-lg border p-2">
      <div className="min-w-0 text-sm">
        <div className="font-semibold"><bdi>{localName(m as Row, lang)}</bdi> <span className="font-normal text-muted-foreground">#{m.code}</span></div>
        <div className="text-[13px] text-muted-foreground">{m.site ? localName(m.site as Row, lang) : t('site.homeNone')}</div>
      </div>
      {m.here && <span className="text-[13px] text-success">{t('siteDay.alreadyHere')}</span>}
      {!m.here && m.can_add && canAdd && (
        <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => void add(m.id)}>{t('siteDay.addToToday')}</Button>
      )}
      {!m.here && !m.can_add && <span className="max-w-40 text-[13px] text-muted-foreground">{t('siteDay.onOtherSite')}</span>}
    </div>
  )

  let result = null
  if (loading) result = <p className="text-sm text-muted-foreground">{t('siteDay.searching')}</p>
  else if (found?.ambiguous) result = <p className="text-sm text-warning">{t('siteDay.sameName')}</p>
  else if (found && !m) result = <p className="text-sm text-muted-foreground">{t('siteDay.noMatches')}</p>
  else if (m) result = card(m)
  // a name already taken: the manager checked the match and says it is someone else
  const offerDifferent = canPropose && !byCode && !different && ((found && (m || found.ambiguous)) || exists?.reason === 'name')
  const showNew = canPropose && search && !byCode && found && ((!m && !found.ambiguous) || different)

  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) reset() }}>
      <Button type="button" variant="outline" className="min-h-11" onClick={() => setOpen(true)}><UserPlus aria-hidden />{t('siteDay.addLaborer')}</Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('siteDay.addLaborer')}</DialogTitle>
          <DialogDescription>{t('siteDay.addLaborerHint', { site: siteName })}</DialogDescription>
        </DialogHeader>
        <DialogBody className="grid gap-3">
          <label className="grid gap-1 text-sm font-medium">
            {t('siteDay.laborerCodeOrName')}
            <Input value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off" className="min-h-11" aria-invalid={!!errs.name_ar || undefined} />
            <span className="text-[13px] font-normal text-muted-foreground">{t('siteDay.searchHint')}</span>
          </label>
          {search && <section aria-label={t('siteDay.matches')} aria-live="polite" className="grid gap-2">{result}</section>}
          {offerDifferent && (
            <Button type="button" variant="outline" className="min-h-11" onClick={() => { setDifferent(true); setErr(undefined) }}>{t('siteDay.differentPerson')}</Button>
          )}
          {showNew && (
            <section aria-label={t('siteDay.newLaborer')} className="grid gap-3 rounded-lg border border-dashed p-3">
              <h3 className="text-sm font-semibold">{t('siteDay.newLaborer')}</h3>
              {!threeParts && <p className="text-[13px] text-warning">{t('siteDay.threePartName')}</p>}
              <fieldset className="grid gap-1">
                <legend className="text-sm font-medium">{t('employee.gender')}</legend>
                <div className="grid grid-cols-2 gap-2">
                  {GENDERS.map((g) => (
                    <Button key={g} type="button" variant="outline" aria-pressed={gender === g} onClick={() => setGender(g)} className={gender === g ? 'min-h-11 border-primary bg-primary/10 text-primary' : 'min-h-11'}>
                      {t(`enum.gender.${g}`)}
                    </Button>
                  ))}
                </div>
                {errs.gender && <span className="text-[13px] text-destructive">{errs.gender}</span>}
              </fieldset>
              <label className="grid gap-1 text-sm font-medium">
                {t('siteDay.proposedWage')}
                <Input value={wage} onChange={(e) => setWage(e.target.value)} inputMode="decimal" dir="ltr" className="min-h-11 rtl:text-right" aria-invalid={!!errs.daily_wage || undefined} />
                {errs.daily_wage && <span className="text-[13px] text-destructive">{errs.daily_wage}</span>}
              </label>
              <label className="grid gap-1 text-sm font-medium">
                {t('siteDay.mobile')}
                <Input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="numeric" autoComplete="tel" maxLength={11} dir="ltr" className="min-h-11 rtl:text-right" aria-invalid={!!errs.phone || undefined} />
                {errs.phone && <span className="text-[13px] text-destructive">{errs.phone}</span>}
              </label>
              <label className="grid gap-1 text-sm font-medium">
                {t('siteDay.nationalId')}
                <Input value={nid} onChange={(e) => setNid(e.target.value)} inputMode="numeric" autoComplete="off" maxLength={14} dir="ltr" className="min-h-11 rtl:text-right" aria-invalid={nidBad || !!errs.national_id || undefined} />
                {(nidBad || errs.national_id) && <span className="text-[13px] text-destructive">{errs.national_id ?? t('siteDay.nationalIdLength')}</span>}
              </label>
              <p className="text-[13px] text-muted-foreground">{t('siteDay.wageNeedsHr')}</p>
              <Button type="button" className="min-h-11" disabled={busy || !wage.trim() || !gender || !threeParts || nidBad} onClick={() => void propose()}>{t('siteDay.addNewLaborer')}</Button>
            </section>
          )}
          {exists?.match && <section aria-label={t('siteDay.alreadyRecorded')} className="grid gap-2">{card(exists.match)}</section>}
          <ErrorBanner err={err} shown={['daily_wage', 'phone', 'gender', 'national_id']} />
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}
