import { useQueryClient } from '@tanstack/react-query'
import { HardHat, MapPinOff, Search, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { cairoToday, ErrorBanner, intlLocale, isHhmm, localName, type Row, stopAtMissingPeriod } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { queryKey, useApi, useSave } from '@/lib/query'
import { changed, checkOutAll, checkOutCount, counts, current, dayPath, type Draft, type Drafts, markAllCount, markAllPresent, openDays, saveBody, type SiteDay, type SiteDayRow } from '@/lib/site-day'
import { cn } from '@/lib/utils'
import { NotFoundPage } from './pages'
import { AddLaborer } from './site-day-add'
import { SitePicker, SiteTab } from './site-day-picker'
import { PersonRow } from './site-day-row'

// own scope: the site manager's work sites only
const MY_SITES = '/sites?kind=work&active=true&per_page=100'
const WORK_SITES = 'hrms.siteDay.sites'

/** The site manager's last working list on this device (a convenience only: HR owns assignments and the server checks access). */
const readWorking = (): string[] => {
  try {
    const v: unknown = JSON.parse(localStorage.getItem(WORK_SITES) ?? '[]')
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
  } catch {
    return []
  }
}
const writeWorking = (ids: string[]) => {
  try {
    localStorage.setItem(WORK_SITES, JSON.stringify(ids))
  } catch {
    // a private window: nothing is preselected next time
  }
}

/**
 * Unsaved changes for this tab, per user: they survive leaving the page (another screen, back, reload) and are gone when the tab
 * closes, which `beforeunload` still warns about. Saving or «تحميل الأحدث» clears them as before.
 */
const draftsKey = (userId: string) => `hrms.siteDay.drafts.${userId}`
const isDrafts = (v: unknown): v is Drafts =>
  typeof v === 'object' && v !== null && Object.values(v).every((d) => typeof d === 'object' && d !== null && 'site_id' in d && 'in_time' in d && 'out_time' in d)
/** Cairo's yesterday: past it only an open day's departure can still be saved, and the open-day banner leads there. */
const yesterday = () => {
  const d = new Date(`${cairoToday()}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() - 1)
  return d.toISOString().slice(0, 10)
}
/** The stored drafts, without days older than yesterday, so an old day never keeps the tab "unsaved" (review of #73). */
const readDrafts = (userId: string): Record<string, Drafts> => {
  try {
    const v: unknown = JSON.parse(sessionStorage.getItem(draftsKey(userId)) ?? '{}')
    const since = yesterday()
    return typeof v === 'object' && v !== null
      ? Object.fromEntries(Object.entries(v).filter((e): e is [string, Drafts] => isDrafts(e[1]) && (e[0].split('|')[1] ?? '') >= since))
      : {}
  } catch {
    return {}
  }
}
const writeDrafts = (userId: string, all: Record<string, Drafts>) => {
  try {
    const kept = Object.fromEntries(Object.entries(all).filter(([, d]) => Object.keys(d).length > 0))
    if (Object.keys(kept).length) sessionStorage.setItem(draftsKey(userId), JSON.stringify(kept))
    else sessionStorage.removeItem(draftsKey(userId))
  } catch {
    // storage blocked: changes live only while the page is open, as before
  }
}

const omit = (d: Drafts, ...ids: string[]): Drafts => Object.fromEntries(Object.entries(d).filter(([k]) => !ids.includes(k)))

/**
 * The site manager's attendance workspace, URL state `?site=&date=` (design Part 2, mockup A; owner, 2026-11-04; PR #22 review).
 * Entry: choose the sites to work on today (a working list, not an assignment), then one site at a time.
 * Morning: «حضور الكل», fix exceptions, «حفظ اليوم». Evening: «انصراف الكل», fix exceptions, save. Unsaved changes are kept per site and
 * date, so switching sites or changing the working list never loses or mixes them. While a site's day is saving, its rows are read-only.
 */
export function SiteDayPage() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const { can, me } = useAuth()
  const userId = me?.id ?? ''
  const allowed = can('attendance.record')
  const [params, setParams] = useSearchParams()
  const { data: mine = [], err: sitesErr, loading: sitesLoading } = useApi<Row[]>(allowed ? MY_SITES : null)
  // null: the picker is open. A link or reload with `?site=` reopens that site with the last working list, skipping the picker.
  const [working, setWorking] = useState<string[] | null>(() => {
    const open = params.get('site')
    return open ? [...new Set([...readWorking(), open])] : null
  })
  const chosen = working === null ? [] : mine.filter((s) => working.includes(s.id))
  // only the URL's own site: one that is not (or no longer) the manager's opens the picker, never another site silently (review of #73)
  const site = chosen.find((s) => s.id === params.get('site'))
  const date = params.get('date')
  const path = allowed && site ? dayPath(site.id, date) : null
  const { data, err, loading, reload } = useApi<SiteDay>(path)
  const [all, setAll] = useState<Record<string, Drafts>>(() => (userId ? readDrafts(userId) : {}))
  const [filter, setFilter] = useState('')
  const [saveErr, setSaveErr] = useState<unknown>()
  const [savingKey, setSavingKey] = useState<string | null>(null)
  const [savedKey, setSavedKey] = useState<string | null>(null)
  // the last 409: the site|date it hit and the employees another user changed meanwhile (none named: the whole day)
  const [stale, setStale] = useState<{ at: string; ids: string[] }>({ at: '', ids: [] })
  const save = useSave()
  const qc = useQueryClient()
  const siteId: string = site?.id ?? ''
  const dirty = Object.values(all).some((d) => Object.keys(d).length > 0)
  // the names whose restored drafts were dropped because their row is gone or read-only now (HR's day, a closed window), and those
  // cut down to their departure because the row now takes only that (past the manager's window with an open day)
  const [dropped, setDropped] = useState<{ at: string; names: string[]; trimmed: string[] }>({ at: '', names: [], trimmed: [] })
  useEffect(() => {
    if (!data) return
    const at = `${data.site.id}|${data.date}`
    const mineHere = all[at]
    if (!mineHere) return
    const byId = new Map(data.rows.map((r) => [r.employee.id, r]))
    const gone = Object.keys(mineHere).filter((id) => { const r = byId.get(id); return !r || !r.can_edit })
    // a departure-only row keeps only the departure typed on it; the rest of the draft is dropped (review of #73, 2026-11-22)
    const cut: Drafts = {}
    for (const [id, d] of Object.entries(mineHere)) {
      const r = byId.get(id)
      if (!r || !r.can_edit || !r.departure_only) continue
      const kept: Draft = { site_id: r.day.site_id, in_time: r.day.in_time, out_time: d.out_time, version: d.version, name: d.name }
      if (d.site_id !== kept.site_id || d.in_time !== kept.in_time || d.note !== undefined || d.advance_request !== undefined) cut[id] = kept
    }
    const next = (id: string) => cut[id] ?? mineHere[id]
    const same = Object.keys(mineHere).filter((id) => { const r = byId.get(id); return r && r.can_edit && !changed(r, next(id)) })
    if (!gone.length && !same.length && !Object.keys(cut).length) return
    setAll((a) => ({ ...a, [at]: omit({ ...(a[at] ?? {}), ...cut }, ...gone, ...same) }))
    const nameOf = (id: string) => { const r = byId.get(id); return r ? [localName(r.employee as Row, lang)] : mineHere[id].name ? [mineHere[id].name] : [] }
    if (gone.length || Object.keys(cut).length) setDropped({ at, names: gone.flatMap(nameOf), trimmed: Object.keys(cut).flatMap(nameOf) })
  }, [data]) // once per answer for the open day; reading `all` here must not rerun it on every keystroke
  useEffect(() => {
    if (userId) writeDrafts(userId, all)
  }, [userId, all])
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])
  if (!allowed) return <NotFoundPage />

  const unsavedAt = (id: string) => Object.entries(all).some(([k, d]) => k.startsWith(`${id}|`) && Object.keys(d).length > 0)
  const pick = (id: string) => {
    setParams((p) => (p.set('site', id), p), { replace: true })
    setSaveErr(undefined)
  }
  const openDate = (d: string | null, at?: string) => {
    // an open day may be at another of the manager's sites: it joins the working list so the page can show it
    if (at) setWorking((w) => (w === null || w.includes(at) ? w : [...w, at]))
    setParams((p) => (d === null ? p.delete('date') : p.set('date', d), at && p.set('site', at), p))
    setSaveErr(undefined)
  }

  if (sitesErr) return <div className="mx-auto w-full max-w-3xl"><ErrorBanner err={sitesErr} shown={[]} /></div>
  if (sitesLoading) return <div className="mx-auto w-full max-w-3xl"><TableSkeleton rows={4} /></div>
  if (!mine.length) return <div className="mx-auto w-full max-w-3xl"><EmptyState icon={MapPinOff} title={t('siteDay.noSites')} /></div>
  // the picker also opens when the URL's site is not (or no longer) one of the manager's
  if (working === null || !site) {
    const assigned = mine.map((s) => s.id)
    const previous = readWorking().filter((id) => assigned.includes(id))
    return (
      <SitePicker sites={mine} initial={mine.length === 1 ? assigned : previous} unsavedAt={unsavedAt}
        onContinue={(ids) => {
          writeWorking(ids)
          setWorking(ids)
          pick(ids[0])
        }} />
    )
  }

  const day = data?.site.id === siteId ? data : undefined
  const key = day ? `${day.site.id}|${day.date}` : ''
  const drafts = all[key] ?? {}
  const locked = savingKey !== null && savingKey === key
  const setDrafts = (d: Drafts) => {
    if (locked) return
    // each draft keeps the version of the day it was first made against, also when a status press builds it anew, and the person's
    // name, so it can be named if it is dropped once their row is gone (reviews of #73, 2026-11-19 and 2026-11-22)
    const stamped = Object.fromEntries(Object.entries(d).map(([id, x]): [string, Draft] => {
      if (x.version !== undefined && x.name !== undefined) return [id, x]
      const was = drafts[id]
      const r = rows.find((row) => row.employee.id === id)
      const version = x.version !== undefined ? x.version : was?.version !== undefined ? was.version : (r?.day.version ?? null)
      return [id, { ...x, version, name: x.name ?? was?.name ?? (r ? localName(r.employee as Row, lang) : undefined) }]
    }))
    setAll((a) => ({ ...a, [key]: stamped }))
    setSavedKey(null)
    setSaveErr(undefined)
  }
  const rows = day?.rows ?? []
  const pending = rows.filter((r) => changed(r, drafts[r.employee.id])).length
  const c = counts(rows, drafts, siteId)
  const isToday = !!day && day.date === day.today

  const onSave = async () => {
    if (!day || !path || locked) return
    // a period-less "9:30" must never reach the API, which would read it as a 24-hour time
    if (stopAtMissingPeriod(document.body)) return
    const bad = rows.filter((r) => { const d = drafts[r.employee.id]; return d && [d.in_time, d.out_time].some((v) => v !== null && !isHhmm(v)) })
    if (bad.length) {
      setSaveErr(new Error(t('siteDay.badTime', { names: bad.map((r) => localName(r.employee as Row, lang)).join('، ') })))
      return
    }
    const invalidAdvance = rows.some((r) => {
      const amount = drafts[r.employee.id]?.advance_request
      return amount != null && (!/^\d{1,12}(\.\d{1,6})?$/.test(amount) || !/[1-9]/.test(amount))
    })
    if (invalidAdvance) {
      setSaveErr(new Error(t('attendanceRequests.invalidAmount')))
      return
    }
    const savedPath = path
    const savedAt = key
    const sent = drafts // the drafts this request carries; rows stay read-only until it returns
    const body = saveBody(day.site.id, day.date, rows, sent)
    setSavingKey(savedAt)
    setSaveErr(undefined)
    try {
      const fresh = await save<SiteDay>('/attendance/site-day', { body }, ['/attendance'])
      qc.setQueryData(queryKey(savedPath), (old: Row | undefined) => ({ ...old, data: fresh }))
      // clear only what was sent: anything changed since then stays unsaved
      setAll((a) => ({ ...a, [savedAt]: Object.fromEntries(Object.entries(a[savedAt] ?? {}).filter(([id, d]) => sent[id] !== d)) }))
      setSavedKey(savedAt)
    } catch (e) {
      // a 409 detail's field is rows.N, N indexing the rows sent (as the day sheet reads it)
      const who = (d: { field?: string | null }) => (d.field?.startsWith('rows.') ? body.rows[Number(d.field.split('.')[1])] : undefined)
      setStale({ at: savedAt, ids: e instanceof ApiError && e.status === 409 ? e.details.flatMap((d) => who(d)?.employee_id ?? []) : [] })
      setSaveErr(e)
    } finally {
      setSavingKey(null)
    }
  }
  // only the rows another user changed lose their draft; the rest stay to save again on the new versions
  const loadLatest = () => {
    const at = stale.at || key
    setAll((a) => ({ ...a, [at]: stale.ids.length ? omit(a[at] ?? {}, ...stale.ids) : {} }))
    setSaveErr(undefined)
    reload()
  }

  const dateLine = day && (
    <p className="mt-1 text-sm text-muted-foreground">
      <time dateTime={day.date}>{new Intl.DateTimeFormat(intlLocale(lang), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${day.date}T00:00:00Z`))}</time>
      {isToday && ` · ${t('siteDay.today')}`}
      {!isToday && <Button type="button" variant="link" className="ms-2 h-auto px-0 text-sm" onClick={() => openDate(null)}>{t('siteDay.backToToday')}</Button>}
    </p>
  )

  const staleNames = stale.at === key ? rows.filter((r) => stale.ids.includes(r.employee.id)).map((r) => localName(r.employee as Row, lang)) : []
  const needle = filter.trim().toLowerCase()
  const shown = needle ? rows.filter((r) => `${r.employee.name_ar} ${r.employee.name_en ?? ''} ${r.employee.code}`.toLowerCase().includes(needle)) : rows
  const workers = shown.filter((r) => r.section === 'workers')
  const engineers = shown.filter((r) => r.section === 'engineers')
  const markN = locked ? 0 : markAllCount(rows, drafts, siteId)
  const outN = locked ? 0 : checkOutCount(rows, drafts, siteId)
  const siteName = site ? localName(day?.site ?? site, lang) : ''
  const crewTools = isToday && (can('employee.crew') || can('employee.propose_laborer'))
  const person = (r: SiteDayRow, removable: boolean) => (
    <PersonRow key={r.employee.id} r={r} draft={current(r, drafts)} siteId={siteId} leave={day?.leave_day_types ?? {}} sites={mine} locked={locked} onOpenDay={openDate}
      removable={removable && !locked} onRemoved={reload} set={(d) => setDrafts(d === null ? omit(drafts, r.employee.id) : { ...drafts, [r.employee.id]: d })} />
  )

  let body
  if (err) body = <ErrorBanner err={err} shown={[]} />
  else if (site && (loading || !day)) body = <TableSkeleton rows={4} />
  else if (!rows.length) body = <EmptyState icon={Users} title={t('siteDay.empty')} />
  else
    body = (
      <ul aria-label={t('siteDay.rows')} className="flex flex-col gap-2">
        {workers.map((r) => person(r, isToday && can('employee.crew') && r.employee.type === 'day_laborer' && r.day.state !== 'stored' && !drafts[r.employee.id]))}
        {engineers.length > 0 && (
          <li className="pt-2">
            <h2 className="flex items-center gap-2 text-section-title"><HardHat className="size-4 text-muted-foreground" aria-hidden />{t('siteDay.engineers')}</h2>
          </li>
        )}
        {engineers.map((r) => person(r, false))}
      </ul>
    )

  let status = ''
  if (locked) status = t('siteDay.saving')
  else if (pending > 0) status = t('siteDay.unsaved', { count: pending })
  else if (savedKey === key) status = t('siteDay.saved')

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 lg:max-w-6xl">
      <nav aria-label={t('siteDay.site')} className="flex flex-wrap items-center gap-2">
        {chosen.length > 1 && chosen.map((s) => (
          <SiteTab key={s.id} s={s} active={s.id === siteId} date={date} unsaved={unsavedAt(s.id)} onPick={() => pick(s.id)} />
        ))}
        <Button type="button" variant="ghost" className="min-h-11" onClick={() => setWorking(null)}>{t('siteDay.changeSites')}</Button>
      </nav>
      {site && (
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-page-title text-balance">{siteName}</h1>
            {dateLine}
            <p className="mt-1 text-[13px] text-muted-foreground">{t('siteDay.hrOwnsSites')}</p>
          </div>
          {day && (
            <div className="flex flex-wrap gap-2" role="group" aria-label={t('siteDay.bulk', { site: siteName })}>
              <Button type="button" className="min-h-11" disabled={!markN} onClick={() => setDrafts(markAllPresent(rows, drafts, siteId))}>
                {t('siteDay.markAll', { count: markN })}
              </Button>
              <Button type="button" variant="outline" className="min-h-11" disabled={!outN} onClick={() => setDrafts(checkOutAll(rows, drafts, siteId))}>
                {t('siteDay.checkOutAll', { count: outN })}
              </Button>
              {crewTools && <AddLaborer siteId={siteId} siteName={siteName} canAdd={can('employee.crew')} canPropose={can('employee.propose_laborer')} onDone={reload} />}
            </div>
          )}
        </div>
      )}
      {rows.length > 6 && (
        <div className="relative">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input type="search" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={t('siteDay.find')} aria-label={t('siteDay.find')} className="min-h-11 ps-9" />
        </div>
      )}
      {/* drafts restored for rows that are gone or read-only now are dropped, and named, never sent (review of #73) */}
      {dropped.at === key && dropped.names.length > 0 && (
        <Alert tone="warning">{t('siteDay.draftsDropped', { names: new Intl.ListFormat(intlLocale(lang)).format(dropped.names) })}</Alert>
      )}
      {dropped.at === key && dropped.trimmed.length > 0 && (
        <Alert tone="warning">{t('siteDay.draftsTrimmed', { names: new Intl.ListFormat(intlLocale(lang)).format(dropped.trimmed) })}</Alert>
      )}
      {saveErr !== undefined && (saveErr instanceof ApiError && saveErr.status === 409 ? (
        <Alert tone="danger">
          <p>{staleNames.length ? t('siteDay.conflictRows', { names: new Intl.ListFormat(intlLocale(lang)).format(staleNames) }) : t('siteDay.conflict')}</p>
          <Button type="button" variant="outline" className="mt-2" onClick={loadLatest}>{t('siteDay.loadLatest')}</Button>
        </Alert>
      ) : <ErrorBanner err={saveErr} shown={[]} />)}
      {day && <OpenDays rows={openDays(rows)} lang={lang} onOpen={openDate} />}
      {body}
      {day && rows.length > 0 && (
        <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t bg-card/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
          <p className="flex flex-wrap gap-x-3 text-sm">
            <span>{t('siteDay.count.present', { count: c.present })}</span>
            <span className={cn(c.unrecorded > 0 && 'font-semibold text-warning')}>{t('siteDay.count.unrecorded', { count: c.unrecorded })}</span>
            <span className={cn(c.noCheckout > 0 && 'font-semibold text-warning')}>{t('siteDay.count.noCheckout', { count: c.noCheckout })}</span>
            {c.late > 0 && <span className="font-semibold text-warning">{t('siteDay.lateCount', { count: c.late })}</span>}
          </p>
          <p role="status" className="text-sm text-muted-foreground sm:ms-auto">{status}</p>
          <Button type="button" className="min-h-11 w-full sm:w-auto" disabled={!pending || locked} onClick={() => void onSave()}>{t('siteDay.save')}</Button>
        </div>
      )}
    </div>
  )
}

/**
 * Above the list: who still has an earlier field day with no departure, and so cannot be recorded today until it is completed
 * (owner, 2026-11-04). A day the site manager can complete links to it; HR completes the others.
 */
function OpenDays({ rows, lang, onOpen }: { rows: SiteDayRow[]; lang: string; onOpen: (date: string, site: string) => void }) {
  const { t } = useTranslation()
  if (!rows.length) return null
  return (
    <Alert tone="warning">
      <p className="font-semibold">{t('siteDay.openDays.title', { count: rows.length })}</p>
      <ul className="mt-1 grid gap-1">
        {rows.map((r) => (
          <li key={r.employee.id} className="flex flex-wrap items-center gap-x-2">
            <bdi>{localName(r.employee as Row, lang)}</bdi>
            <span className="text-muted-foreground">·</span>
            {r.open_site_id ? (
              <Button type="button" variant="link" className="h-auto min-h-11 px-0" onClick={() => onOpen(r.open_date!, r.open_site_id!)}>
                {t('siteDay.openDay', { date: r.open_date })}
              </Button>
            ) : <span>{t('siteDay.why.open_day_hr', { date: r.open_date })}</span>}
          </li>
        ))}
      </ul>
    </Alert>
  )
}
