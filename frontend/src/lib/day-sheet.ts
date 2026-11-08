import { asciiDigits } from '@/components/form'

/** The day value fields of the attendance API, in form order. */
export const DAY_VALUES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out', 'late_minutes', 'ot_minutes', 'outside_minutes', 'outside_amount', 'transport', 'advance_amount', 'note'] as const
export type DayValue = (typeof DAY_VALUES)[number]

/** One `rows[]` item of `GET /attendance/sheet`. */
export type SheetRow = {
  employee: { id: string; code: number; name_ar: string; name_en: string | null; department: string | null; type: string }
  /** Where the employee is on the date: the day's work site, else a worker's or office employee's site then, else an engineer's last site. */
  site_id: string | null
  day: { state: 'stored' | 'derived' | 'missing'; source: string | null; version: number | null; site_id: string | null; leave_code: string | null } & Record<DayValue, string | number | null>
  hints: { late_minutes?: number; ot_minutes?: number }
  last_site_id: string | null
  totals: { worked: number; leave: number; absent: number; missing: number }
}

/** One `rows[]` item of `POST /attendance/days/bulk`. The endpoint refuses `clear: false`, so `clear` is `true` or absent. */
export type BulkRow = { employee_id: string; version: number | null; clear?: true; site_id?: string | null } & Partial<Record<DayValue, string | number | null>>

export type SheetAction = { kind: 'present' } | { kind: 'leave'; code: 'absent' | 'sick' } | { kind: 'site'; siteId: string } | { kind: 'time'; in_time?: string; out_time?: string }

export const MAX_BATCH = 500

/** The site filter's value for the rows with no site. */
export const NO_SITE = 'none'
/** The rows at the filter's site; '' keeps them all. */
export const atSite = (rows: SheetRow[], site: string) => (site ? rows.filter((r) => r.site_id === (site === NO_SITE ? null : site)) : rows)
export const isOfficeRow = (r: SheetRow) => r.employee.type === 'office'

/** Search form of a string: ASCII digits, lower case, and the Arabic letters people type interchangeably made one (أ/إ/آ→ا, ة→ه, ى→ي). */
export const fold = (s: string) => asciiDigits(s).toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')

/** Whether a sheet row matches a search on its code, Arabic or English name, or department. */
export const matches = (r: SheetRow, q: string) =>
  fold(`${r.employee.code} ${r.employee.name_ar} ${r.employee.name_en ?? ''} ${r.employee.department ?? ''}`).includes(fold(q).trim())

/** A pasted batch: employee codes (single, lists, ranges) or else a name search (`byName`; it must never write). */
export function parseBatch(input: string, rows: SheetRow[]): { matches: SheetRow[]; unknown: string[]; capped: boolean; byName: boolean } {
  const text = asciiDigits(input).trim()
  const tokens = text.split(/[\s,;،]+/).filter(Boolean)
  if (!tokens.every((t) => /^\d+([-–]\d+)?$/.test(t))) {
    return { matches: rows.filter((r) => matches(r, text)), unknown: [], capped: false, byName: true }
  }
  const byCode = new Map(rows.map((r) => [r.employee.code, r]))
  const found = new Set<SheetRow>()
  const unknown = new Set<string>()
  let n = 0
  let capped = false
  ids: for (const t of tokens) {
    const [a, b = a] = t.split(/[-–]/).map(Number)
    const [lo, hi] = a <= b ? [a, b] : [b, a]
    for (let code = lo; code <= hi; code++) {
      if (n++ === MAX_BATCH) {
        capped = true
        break ids
      }
      const r = byCode.get(code)
      if (r) found.add(r)
      else if (lo === hi) unknown.add(t)
    }
  }
  return { matches: [...found], unknown: [...unknown], capped, byName: false }
}

/** A stored time ("HH:MM" or "HH:MM:SS") as "HH:MM"; null stays null. */
export const hhmm = (t: string | number | null) => (t == null ? null : String(t).slice(0, 5))

/** The bulk rows for one action over the chosen rows; rows already in the target state are left out. */
export function bulkRows(action: SheetAction, rows: SheetRow[], leaveTypeId: (code: string) => string | undefined, office: boolean): { rows: BulkRow[]; skipped: SheetRow[] } {
  const out: BulkRow[] = []
  const skipped: SheetRow[] = []
  const leave = action.kind === 'leave' ? leaveTypeId(action.code) : undefined
  for (const r of rows) {
    const d = r.day
    const stored = d.state === 'stored'
    const base = { employee_id: r.employee.id, version: d.version }
    if (action.kind === 'present') {
      const site = office ? null : r.last_site_id
      if (!office && !site) skipped.push(r)
      else if (!(stored && d.site_id === site && d.leave_code === null)) out.push({ ...base, site_id: site })
    } else if (action.kind === 'leave') {
      if (!leave) skipped.push(r)
      else if (!(stored && d.site_id === leave)) out.push({ ...base, site_id: leave })
    } else if (action.kind === 'site') {
      if (!(stored && d.site_id === action.siteId)) out.push({ ...base, site_id: action.siteId })
    } else {
      // a time keeps the row where it is: office present has no site; a field row stays at its stored work site, and without one it is skipped
      const site = office ? null : d.site_id
      const times = Object.fromEntries((['in_time', 'out_time'] as const).flatMap((f) => (action[f] === undefined ? [] : [[f, action[f]]])))
      if (!office && !(stored && site && d.leave_code === null)) skipped.push(r)
      else if (!(stored && d.site_id === site && Object.entries(times).every(([f, v]) => hhmm(d[f as DayValue]) === v))) out.push({ ...base, site_id: site, ...times })
    }
  }
  return { rows: out, skipped }
}

/** The bulk rows that put each changed row back as it was: a stored day is rewritten in full, any other day is cleared. */
export function undoRows(before: SheetRow[], after: { employee_id: string; version: number | null }[]): BulkRow[] {
  const byId = new Map(before.map((r) => [r.employee.id, r]))
  return after.flatMap(({ employee_id, version }): BulkRow[] => {
    const d = byId.get(employee_id)?.day
    if (!d || d.version === version) return []
    if (d.state !== 'stored') return [{ employee_id, clear: true, version }]
    return [{ employee_id, version, site_id: d.site_id, ...Object.fromEntries(DAY_VALUES.map((f) => [f, d[f]])) }]
  })
}

/** A row's status on the sheet. Only office rows are ever late (from the server's lateness hint). */
export function statusOf(r: SheetRow, office: boolean): 'missing' | 'present' | 'late' | 'absent' | 'leave' | 'derived' {
  const d = r.day
  if (d.state !== 'stored') return d.state
  if (d.leave_code === 'absent') return 'absent'
  if (d.leave_code !== null) return 'leave'
  return office && (r.hints.late_minutes ?? 0) > 0 ? 'late' : 'present'
}
