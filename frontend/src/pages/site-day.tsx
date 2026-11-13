import { SitePeriod } from './site-period'
import { useQueryClient } from '@tanstack/react-query'
import { HardHat, MapPinOff, Search, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { ErrorBanner, intlLocale, isHhmm, localName, type Row, stopAtMissingPeriod } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { queryKey, useApi, useSave } from '@/lib/query'
import { changed, checkOutAll, checkOutCount, counts, current, dayPath, type Drafts, likeYesterday, likeYesterdayCount, markAllCount, markAllPresent, openDays, saveBody, type SiteDay, type SiteDayRow } from '@/lib/site-day'
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
  const { can } = useAuth()
  const allowed = can('attendance.record')
  const [params, setParams] = useSearchParams()
  const { data: mine = [], err: sitesErr, loading: sitesLoading } = useApi<Row[]>(allowed ? MY_SITES : null)
  const [working, setWorking] = useState<string[] | null>(null) // null: the picker is open
  const chosen = working === null ? [] : mine.filter((s) => working.includes(s.id))
  const site = chosen.find((s) => s.id === params.get('site')) ?? chosen[0]
  const date = params.get('date')
  const periodTab = params.get('tab') === 'period'
  const path = allowed && site && !periodTab ? dayPath(site.id, date) : null
  const { data, err, loading, reload } = useApi<SiteDay>(path)
  const [all, setAll] = useState<Record<string, Drafts>>({})
  const [filter, setFilter] = useState('')
  const [saves, setSaves] = useState<Record<string, { phase: 'saving' | 'saved' } | { phase: 'failed'; error: unknown; staleIds: string[] }>>({})
  const setSaveState = (key: string, state: (typeof saves)[string] | null) => {
    setSaves((previous) => state === null ? Object.fromEntries(Object.entries(previous).filter(([id]) => id !== key)) : { ...previous, [key]: state })
  }
  const save = useSave()
  const qc = useQueryClient()
  const siteId: string = site?.id ?? ''
  const dirty = Object.values(all).some((d) => Object.keys(d).length > 0)
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
  }
  const openDate = (d: string | null, at?: string) => {
    // an open day may be at another of the manager's sites: it joins the working list so the page can show it
    if (at) setWorking((w) => (w === null || w.includes(at) ? w : [...w, at]))
    setParams((p) => (d === null ? p.delete('date') : p.set('date', d), at && p.set('site', at), p.delete('tab'), p))
  }

  if (sitesErr) return <div className="mx-auto w-full max-w-3xl"><ErrorBanner err={sitesErr} shown={[]} /></div>
  if (sitesLoading) return <div className="mx-auto w-full max-w-3xl"><TableSkeleton rows={4} /></div>
  if (!mine.length) return <div className="mx-auto w-full max-w-3xl"><EmptyState icon={MapPinOff} title={t('siteDay.noSites')} /></div>
  if (working === null) {
    const assigned = mine.map((s) => s.id)
    const requestedSite = periodTab ? params.get('site') : null
    const previous = [...new Set([...readWorking(), ...(requestedSite ? [requestedSite] : [])])].filter((id) => assigned.includes(id))
    return (
      <SitePicker sites={mine} initial={mine.length === 1 ? assigned : previous} unsavedAt={unsavedAt}
        onContinue={(ids) => {
          writeWorking(ids)
          setWorking(ids)
          pick(ids.includes(params.get('site') ?? '') ? params.get('site')! : ids[0])
        }} />
    )
  }

  const day = data?.site.id === siteId ? data : undefined
  const key = day ? `${day.site.id}|${day.date}` : ''
  const drafts = all[key] ?? {}
  const saveState = saves[key]
  const locked = saveState?.phase === 'saving'
  const saveErr = saveState?.phase === 'failed' ? saveState.error : undefined
  const staleIds = saveState?.phase === 'failed' ? saveState.staleIds : []
  const rows = day?.rows ?? []
  const setDrafts = (d: Drafts) => {
    if (locked) return
    const versions = new Map(rows.map((r) => [r.employee.id, r.day.version]))
    setAll((a) => ({
      ...a,
      [key]: Object.fromEntries(Object.entries(d).map(([id, draft]) => {
        const prior = a[key]?.[id]?.baseVersion
        return [id, { ...draft, baseVersion: prior === undefined ? versions.get(id) ?? null : prior }]
      })),
    }))
    if (!(saveErr instanceof ApiError && saveErr.status === 409)) setSaveState(key, null)
  }
  const pending = rows.filter((r) => changed(r, drafts[r.employee.id])).length
  const c = counts(rows, drafts, siteId, day?.late_grace_minutes ?? 15)
  const isToday = !!day && day.date === day.today

  const onSave = async () => {
    if (!day || !path || locked) return
    // a period-less "9:30" must never reach the API, which would read it as a 24-hour time
    if (stopAtMissingPeriod(document.body)) return
    const bad = rows.filter((r) => { const d = drafts[r.employee.id]; return d && [d.in_time, d.out_time].some((v) => v !== null && !isHhmm(v)) })
    if (bad.length) {
      setSaveState(key, { phase: 'failed', error: new Error(t('siteDay.badTime', { names: bad.map((r) => localName(r.employee as Row, lang)).join('، ') })), staleIds: [] })
      return
    }
    const invalidAdvances = rows.filter((r) => {
      const amount = drafts[r.employee.id]?.advance_request
      return amount != null && (!/^\d{1,12}(\.\d{1,6})?$/.test(amount) || !/[1-9]/.test(amount))
    })
    if (invalidAdvances.length) {
      setSaveState(key, { phase: 'failed', error: new Error(t('attendanceRequests.invalidAmount')), staleIds: [] })
      return
    }
    const savedPath = path
    const savedAt = key
    const sent = drafts // the drafts this request carries; rows stay read-only until it returns
    const body = saveBody(day.site.id, day.date, rows, sent)
    const submitted = new Set(body.rows.map((r) => r.employee_id))
    setSaveState(savedAt, { phase: 'saving' })
    try {
      const fresh = await save<SiteDay>('/attendance/site-day', { body }, ['/attendance'])
      qc.setQueryData(queryKey(savedPath), (old: Row | undefined) => ({ ...old, data: fresh }))
      // clear only what was sent: anything changed since then stays unsaved
      setAll((a) => ({ ...a, [savedAt]: Object.fromEntries(Object.entries(a[savedAt] ?? {}).filter(([id, d]) => !submitted.has(id) || sent[id] !== d)) }))
      setSaveState(savedAt, { phase: 'saved' })
    } catch (e) {
      // a 409 detail's field is rows.N, N indexing the rows sent (as the day sheet reads it)
      const who = (d: { field?: string | null }) => (d.field?.startsWith('rows.') ? body.rows[Number(d.field.split('.')[1])] : undefined)
      setSaveState(savedAt, { phase: 'failed', error: e, staleIds: e instanceof ApiError && e.status === 409 ? e.details.flatMap((d) => who(d)?.employee_id ?? []) : [] })
    }
  }
  // only the rows another user changed lose their draft; the rest stay to save again on the new versions
  const loadLatest = () => {
    setAll((a) => ({ ...a, [key]: staleIds.length ? omit(a[key] ?? {}, ...staleIds) : {} }))
    setSaveState(key, null)
    reload()
  }

  const yesterday = day?.editable_yesterday
  const showDatePicker = !!yesterday && !!day && (isToday || day.date === yesterday)
  const dateLine = day && (
    <p className="mt-1 text-sm text-muted-foreground">
      <time dateTime={day.date}>{new Intl.DateTimeFormat(intlLocale(lang), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${day.date}T00:00:00Z`))}</time>
      {isToday && ` · ${t('siteDay.today')}`}
      {!isToday && !showDatePicker && <Button type="button" variant="link" className="ms-2 h-auto px-0 text-sm" onClick={() => openDate(null)}>{t('siteDay.backToToday')}</Button>}
    </p>
  )

  const staleNames = rows.filter((r) => staleIds.includes(r.employee.id)).map((r) => localName(r.employee as Row, lang))
  const needle = filter.trim().toLowerCase()
  const shown = needle ? rows.filter((r) => `${r.employee.name_ar} ${r.employee.name_en ?? ''} ${r.employee.code}`.toLowerCase().includes(needle)) : rows
  const workers = shown.filter((r) => r.section === 'workers')
  const engineers = shown.filter((r) => r.section === 'engineers')
  const markN = locked ? 0 : markAllCount(rows, drafts, siteId)
  const outN = locked ? 0 : checkOutCount(rows, drafts, siteId)
  const likeN = locked || !day ? 0 : likeYesterdayCount(rows, drafts, siteId, day.leave_day_types)
  const siteName = site ? localName(day?.site ?? site, lang) : ''
  const crewTools = isToday && (can('employee.crew') || can('employee.propose_laborer'))
  const person = (r: SiteDayRow, removable: boolean) => (
    <PersonRow key={r.employee.id} r={r} draft={current(r, drafts)} siteId={siteId} date={day?.date ?? ''} siteName={siteName} grace={day?.late_grace_minutes ?? 15} leave={day?.leave_day_types ?? {}} sites={mine} locked={locked} onOpenDay={openDate}
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
  else if (saveState?.phase === 'saved') status = t('siteDay.saved')

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 lg:max-w-6xl">
      <nav aria-label={t('siteDay.site')} className="flex flex-wrap items-center gap-2">
        {chosen.length > 1 && chosen.map((s) => (
          <SiteTab key={s.id} s={s} active={s.id === siteId} date={date} unsaved={unsavedAt(s.id)} onPick={() => pick(s.id)} />
        ))}
        <Button type="button" variant="ghost" className="min-h-11" onClick={() => setWorking(null)}>{t('siteDay.changeSites')}</Button>
      </nav>
      <SegmentedControl className="w-fit" label={t('sitePeriod.workspace')} value={periodTab ? 'period' : 'day'}
        options={[
          { value: 'day', label: t('sitePeriod.day'), content: <span aria-hidden>{t('sitePeriod.day')}</span> },
          { value: 'period', label: t('sitePeriod.tab'), content: <span aria-hidden>{t('sitePeriod.tab')}</span> },
        ]}
        onChange={(value) => setParams((p) => (value === 'period' ? p.set('tab', value) : p.delete('tab'), p))} />
      {periodTab && site ? (
        <>
          <h1 className="text-page-title">{localName(site, lang)}</h1>
          <SitePeriod key={siteId} site={siteId} which={params.get('which') === 'previous' ? 'previous' : 'current'} at={params.get('at')}
            dirty={dirty || Object.values(saves).some((state) => state.phase === 'saving')}
            changePeriod={(which) => setParams((p) => (p.set('which', which), p.delete('at'), p))} openDay={openDate} />
        </>
      ) : <>
      {site && (
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-page-title text-balance">{siteName}</h1>
            {dateLine}
            {showDatePicker && <SegmentedControl className="mt-2 w-fit" label={t('siteDay.datePicker')} value={isToday ? 'today' : 'yesterday'}
              options={[
                { value: 'today', label: t('siteDay.today'), content: <span aria-hidden>{t('siteDay.today')}</span> },
                { value: 'yesterday', label: t('siteDay.yesterday'), content: <span aria-hidden>{t('siteDay.yesterday')}</span> },
              ]}
              onChange={(value) => openDate(value === 'today' ? null : yesterday)} />}
            <p className="mt-1 text-[13px] text-muted-foreground">{t('siteDay.hrOwnsSites')}</p>
          </div>
          {day && (
            <div className="flex flex-wrap gap-2" role="group" aria-label={t('siteDay.bulk', { site: siteName })}>
              <Button type="button" className="min-h-11" disabled={!markN} onClick={() => setDrafts(markAllPresent(rows, drafts, siteId))}>
                {t('siteDay.markAll', { count: markN })}
              </Button>
              <Button type="button" variant="outline" className="min-h-11" disabled={!likeN} onClick={() => setDrafts(likeYesterday(rows, drafts, siteId, day.leave_day_types))}>
                {t('siteDay.likeYesterday', { count: likeN })}
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
      </>}
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
