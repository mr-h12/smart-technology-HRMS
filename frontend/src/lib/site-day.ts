import { isHhmm } from '@/components/form'

type RequestSummary = { id: string; status: 'pending' | 'approved' | 'refused'; minutes: number | null; amount: string | null; version: number }

/** One `rows[]` item of `GET /attendance/site-day`: workers first, then the site manager's engineers. No pay figures. */
export type SiteDayRow = {
  employee: { id: string; code: number; name_ar: string; name_en: string | null; type: string }
  section: 'workers' | 'engineers'
  /** the person's scheduled shift on the date: their own override, else the field shift setting */
  shift: { start: string; end: string }
  day: { state: 'stored' | 'derived' | 'missing' | 'future'; version: number | null; site_id: string | null; leave_code: string | null; in_time: string | null; out_time: string | null; note: string | null }
  requests?: { overtime: RequestSummary | null; advance: RequestSummary | null }
  can_edit: boolean
  why_not: 'locked' | 'window' | 'hr_day' | 'open_day' | null
  /** past the yesterday window: an open day of theirs here, where only the departure can still be entered (HR is told at once) */
  departure_only: boolean
  /** with why_not open_day: the earlier field day that has an arrival and no departure */
  open_date: string | null
  /** where this site manager can complete that day; null when only HR can (HR's day, or not theirs) */
  open_site_id: string | null
  /** the day «زي امبارح» copies: the latest day in the 7 before at this site manager's sites or on a leave day-type, Fridays, Saturdays and holidays skipped (P10) */
  previous?: { date: string; site_id: string | null; leave_code: string | null; in_time: string | null; out_time: string | null } | null
}

/** One exact path per site and date, so the site tabs, the page and an open day's link share one cache entry. */
export const dayPath = (siteId: string, date: string | null) => `/attendance/site-day?${new URLSearchParams({ site_id: siteId, ...(date && { date }) })}`

/** The leave day-types a site manager may store (P11): code → the leave day-type's id. */
export type LeaveDayTypes = Partial<Record<'absent' | 'sick' | 'rest' | 'other', string>>

export type SiteDay = { site: { id: string; name_ar: string; name_en: string | null }; date: string; today: string; leave_day_types: LeaveDayTypes; rows: SiteDayRow[] }

export const MARKS = ['present', 'absent', 'sick'] as const
export type Mark = (typeof MARKS)[number]
/** The rarer leave types behind «⋯»; never annual, casual or unpaid, which need a leave request. */
export const MORE = ['rest', 'other'] as const

/** What the screen holds for one person before «حفظ اليوم»: the site (a work site or a leave type), the two times, and a note when typed (undefined: untouched). */
export type Draft = { site_id: string | null; in_time: string | null; out_time: string | null; note?: string | null; advance_request?: string | null }
export type Drafts = Record<string, Draft>

/** The people whose earlier field day still has no departure: the banner above the list names them. */
export const openDays = (rows: SiteDayRow[]) => rows.filter((r) => r.why_not === 'open_day' && r.open_date)

/** The status button a day presses on `siteId`'s list: a work day at that site is present (one at another site is only a hint), the absent and sick leave days their own; anything else none. */
export function markOf({ state, site_id, leave_code }: Pick<SiteDayRow['day'], 'state' | 'site_id' | 'leave_code'>, siteId: string): Mark | null {
  if (state !== 'stored') return null
  if (leave_code === null) return site_id === siteId ? 'present' : null
  return leave_code === 'absent' || leave_code === 'sick' ? leave_code : null
}

const stored = (r: SiteDayRow): Draft | null => (r.day.state === 'stored' ? { site_id: r.day.site_id, in_time: r.day.in_time, out_time: r.day.out_time } : null)

/** The day as the screen shows it: the unsaved draft, else what is stored, else nothing. */
export const current = (r: SiteDayRow, drafts: Drafts): Draft | null => drafts[r.employee.id] ?? stored(r)

/** Present at this site, by the screen's current value. */
export const isPresent = (d: Draft | null, siteId: string) => d !== null && d.site_id === siteId

/**
 * «حضور الكل»: every editable person with nothing recorded (no stored day, no draft) becomes present at `siteId` from their shift
 * start, with the departure left empty. A typed arrival, an absence and a stored leave day are left as they are.
 */
export function markAllPresent(rows: SiteDayRow[], drafts: Drafts, siteId: string): Drafts {
  const next = { ...drafts }
  for (const r of rows) if (r.can_edit && r.day.state === 'missing' && !drafts[r.employee.id]) next[r.employee.id] = { site_id: siteId, in_time: r.shift.start, out_time: null }
  return next
}

/** How many people «حضور الكل» would mark. */
export const markAllCount = (rows: SiteDayRow[], drafts: Drafts, siteId: string) => Object.keys(markAllPresent(rows, drafts, siteId)).length - Object.keys(drafts).length

/** «انصراف الكل»: everyone editable who is present here with an arrival and no departure leaves at their shift end. A typed departure stays. */
export function checkOutAll(rows: SiteDayRow[], drafts: Drafts, siteId: string): Drafts {
  const next = { ...drafts }
  for (const r of rows) {
    const d = current(r, drafts)
    if (r.can_edit && d && isPresent(d, siteId) && d.in_time && !d.out_time) next[r.employee.id] = { ...d, out_time: r.shift.end }
  }
  return next
}

/** How many people «انصراف الكل» would check out. */
export const checkOutCount = (rows: SiteDayRow[], drafts: Drafts, siteId: string) => {
  const next = checkOutAll(rows, drafts, siteId)
  return rows.filter((r) => next[r.employee.id] !== drafts[r.employee.id]).length
}

const noteChanged = (r: SiteDayRow, d: Draft) => d.note !== undefined && (d.note || null) !== (r.day.state === 'stored' ? r.day.note : null)

/** Whether the draft differs from the stored day (only those rows are sent). */
export function changed(r: SiteDayRow, d: Draft | undefined): boolean {
  if (!d) return false
  const s = stored(r)
  return !s || s.site_id !== d.site_id || s.in_time !== d.in_time || s.out_time !== d.out_time || noteChanged(r, d) || d.advance_request !== undefined
}

/**
 * «زي امبارح»: every editable person with nothing recorded today copies their previous day. A work day at this site becomes present
 * here from that day's arrival (the departure stays empty until the evening); absent, sick, rest and other stay that leave type;
 * anything else (a work day at another site, annual, casual, unpaid, or no previous day) stays empty.
 */
export function likeYesterday(rows: SiteDayRow[], drafts: Drafts, siteId: string, leave: LeaveDayTypes): Drafts {
  const next = { ...drafts }
  for (const r of rows) {
    const p = r.previous
    if (!r.can_edit || r.day.state !== 'missing' || drafts[r.employee.id] || !p) continue
    if (p.leave_code === null && p.site_id === siteId) next[r.employee.id] = { site_id: siteId, in_time: p.in_time ?? r.shift.start, out_time: null }
    else if (p.leave_code && p.leave_code in leave) next[r.employee.id] = { site_id: leave[p.leave_code as keyof LeaveDayTypes]!, in_time: null, out_time: null }
  }
  return next
}

export const likeYesterdayCount = (rows: SiteDayRow[], drafts: Drafts, siteId: string, leave: LeaveDayTypes) =>
  Object.keys(likeYesterday(rows, drafts, siteId, leave)).length - Object.keys(drafts).length

/** What is still open on the screen: people with nothing recorded, and present people with no departure. Leave days count as recorded. */
export function counts(rows: SiteDayRow[], drafts: Drafts, siteId: string) {
  let present = 0
  let away = 0
  let unrecorded = 0
  let noCheckout = 0
  let late = 0
  for (const r of rows) {
    const d = current(r, drafts)
    if (!d) {
      if (r.day.state === 'missing') unrecorded++
      else away++ // a derived weekly off or holiday
    } else if (isPresent(d, siteId)) {
      present++
      if (d.in_time && !d.out_time) noCheckout++
      if (isHhmm(d.in_time) && minutes(d.in_time) > minutes(r.shift.start)) late++
    } else away++
  }
  return { present, away, unrecorded, noCheckout, late }
}

/** The save body: changed rows only, each with the version it was read at. A leave day sends no times. */
export function saveBody(siteId: string, date: string, rows: SiteDayRow[], drafts: Drafts) {
  return {
    site_id: siteId,
    date,
    rows: rows.filter((r) => changed(r, drafts[r.employee.id])).map((r) => {
      const d = drafts[r.employee.id]!
      const work = d.site_id === siteId
      return {
        employee_id: r.employee.id, version: r.day.version, site_id: d.site_id, in_time: work ? d.in_time : null, out_time: work ? d.out_time : null,
        ...(work && d.advance_request !== undefined && { advance_request: d.advance_request }),
        ...(noteChanged(r, d) && { note: d.note || null }),
      }
    }),
  }
}

const minutes = (hhmm: string) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5))

/** The arrival against the shift start: positive = late (raw minutes; payroll takes the daily grace off once, calculations §0a). */
export const arrivalDiff = (arrival: string | null, shiftStart: string): number | null => (arrival ? minutes(arrival) - minutes(shiftStart) : null)

/** The departure against the shift end: negative = left early, positive = stayed later (a time difference only; overtime needs HR's approval). */
export const departureDiff = (out: string | null, shiftEnd: string): number | null => (out ? minutes(out) - minutes(shiftEnd) : null)
