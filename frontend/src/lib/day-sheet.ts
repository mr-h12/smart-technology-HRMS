import { asciiDigits } from '@/components/form'

/** The day value fields of the attendance API, in form order. */
export const DAY_VALUES = ['in_time', 'out_time', 'scheduled_in', 'scheduled_out', 'late_minutes', 'ot_minutes', 'outside_minutes', 'outside_amount', 'transport', 'advance_amount', 'note'] as const
export type DayValue = (typeof DAY_VALUES)[number]

/** One `rows[]` item of `GET /attendance/sheet`. */
export type SheetRow = {
  employee: { id: string; code: number; name_ar: string; name_en: string | null; department: string | null }
  day: { state: 'stored' | 'derived' | 'missing'; source: string | null; version: number | null; site_id: string | null; leave_code: string | null } & Record<DayValue, string | number | null>
  hints: { late_minutes?: number; ot_minutes?: number }
  last_site_id: string | null
  totals: { worked: number; leave: number; absent: number; missing: number }
}

/** One `rows[]` item of `POST /attendance/days/bulk`. The endpoint refuses `clear: false`, so `clear` is `true` or absent. */
export type BulkRow = { employee_id: string; version: number | null; clear?: true; site_id?: string | null } & Partial<Record<DayValue, string | number | null>>

export type SheetAction = { kind: 'present' } | { kind: 'leave'; code: 'absent' | 'sick' } | { kind: 'site'; siteId: string } | { kind: 'time'; in_time: string }

export const MAX_BATCH = 500

/** A command-bar or pasted batch: employee codes (single, lists, ranges) or else a name search (`byName`; it must never write). */
export function parseBatch(input: string, rows: SheetRow[]): { matches: SheetRow[]; unknown: string[]; capped: boolean; byName: boolean } {
  const text = asciiDigits(input).trim()
  const tokens = text.split(/[\s,;،]+/).filter(Boolean)
  if (!tokens.every((t) => /^\d+([-–]\d+)?$/.test(t))) {
    const q = text.toLowerCase()
    const matches = rows.filter((r) => `${r.employee.code} ${r.employee.name_ar} ${r.employee.name_en ?? ''} ${r.employee.department ?? ''}`.toLowerCase().includes(q))
    return { matches, unknown: [], capped: false, byName: true }
  }
  const byCode = new Map(rows.map((r) => [r.employee.code, r]))
  const matches = new Set<SheetRow>()
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
      if (r) matches.add(r)
      else if (lo === hi) unknown.add(t)
    }
  }
  return { matches: [...matches], unknown: [...unknown], capped, byName: false }
}

const hhmm = (t: string | number | null) => (t == null ? null : String(t).slice(0, 5))

/** The bulk rows for one action over the chosen rows; rows already in the target state are left out. */
export function bulkRows(action: SheetAction, rows: SheetRow[], leaveSite: (code: string) => string | undefined, office: boolean): { rows: BulkRow[]; skipped: SheetRow[] } {
  const out: BulkRow[] = []
  const skipped: SheetRow[] = []
  const leave = action.kind === 'leave' ? leaveSite(action.code) : undefined
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
    } else if (!(stored && d.site_id === null && hhmm(d.in_time) === hhmm(action.in_time))) {
      out.push({ ...base, site_id: null, in_time: action.in_time })
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
