import { expect, test } from 'vitest'
import { bulkRows, DAY_VALUES, type DayValue, parseBatch, type SheetRow, statusOf, undoRows } from './day-sheet'

const blank = Object.fromEntries(DAY_VALUES.map((f) => [f, null])) as Record<DayValue, null>
const row = (code: number, o: { day?: Partial<SheetRow['day']>; last_site_id?: string | null; hints?: SheetRow['hints']; department?: string | null; name_en?: string } = {}): SheetRow => ({
  employee: { id: `e${code}`, code, name_ar: `مهندس ${code}`, name_en: o.name_en ?? `Engineer-${code}`, department: o.department ?? null },
  day: { ...blank, state: 'missing', source: null, version: null, site_id: null, leave_code: null, ...o.day },
  hints: o.hints ?? {},
  last_site_id: o.last_site_id ?? null,
  totals: { worked: 0, leave: 0, absent: 0, missing: 0 },
})
const sheet = Array.from({ length: 12 }, (_, i) => row(i + 1))
const codes = (input: string) => parseBatch(input, sheet).matches.map((r) => r.employee.code)
const stored = (o: Partial<SheetRow['day']>) => ({ state: 'stored' as const, source: 'entered', version: 3, ...o })
const leaveSite = (code: string) => ({ absent: 'L-absent', sick: 'L-sick' })[code]

test('parseBatch takes a single code', () => {
  expect(codes('12')).toEqual([12])
})

test('parseBatch takes a comma list in input order', () => {
  expect(codes('1, 3, 6')).toEqual([1, 3, 6])
  expect(codes('6 1 6')).toEqual([6, 1])
})

test('parseBatch expands ranges either way round', () => {
  expect(codes('1-5')).toEqual([1, 2, 3, 4, 5])
  expect(codes('5-1')).toEqual([1, 2, 3, 4, 5])
})

test('parseBatch caps the expansion at 500 across the input', () => {
  const r = parseBatch('1-99999', sheet)
  expect(r.capped).toBe(true)
  expect(r.matches.length).toBeLessThanOrEqual(500)
  expect(r.matches).toHaveLength(12)
  expect(parseBatch('1-5', sheet).capped).toBe(false)
  const big = Array.from({ length: 600 }, (_, i) => row(i + 1))
  expect(parseBatch('1-400 401-600', big).matches).toHaveLength(500)
})

test('parseBatch reads Arabic digits and the Arabic comma', () => {
  expect(codes('١٢')).toEqual([12])
  expect(codes('1،3')).toEqual([1, 3])
})

test('parseBatch splits on newlines', () => {
  expect(codes('1\n2\r\n3')).toEqual([1, 2, 3])
})

test('parseBatch reports single codes that are not on the sheet', () => {
  expect(parseBatch('99999', sheet)).toEqual({ matches: [], unknown: ['99999'], capped: false, byName: false })
})

test('parseBatch matches nothing on blank input', () => {
  expect(parseBatch('  ', sheet)).toEqual({ matches: [], unknown: [], capped: false, byName: false })
})

test('parseBatch searches names case-insensitively', () => {
  expect(parseBatch('engineer-1', [row(1), row(2)]).matches.map((r) => r.employee.code)).toEqual([1])
})

test('parseBatch says when it searched by name, so a name never writes', () => {
  expect(parseBatch('engineer', sheet).byName).toBe(true)
  expect(parseBatch('1-3, 7', sheet).byName).toBe(false)
})

test('parseBatch searches the department', () => {
  const rows = [row(1, { department: 'Projects' }), row(2, { department: 'Sales' })]
  expect(parseBatch('proj', rows).matches.map((r) => r.employee.code)).toEqual([1])
})

test('bulkRows present uses the last site and skips rows without one', () => {
  const a = row(1, { last_site_id: 'S1', day: { version: null } })
  const b = row(2)
  expect(bulkRows({ kind: 'present' }, [a, b], leaveSite, false)).toEqual({ rows: [{ employee_id: 'e1', version: null, site_id: 'S1' }], skipped: [b] })
})

test('bulkRows present on an office row writes site_id null even without a last site', () => {
  const r = row(1, { day: { state: 'derived', leave_code: 'friday' } })
  expect(bulkRows({ kind: 'present' }, [r], leaveSite, true)).toEqual({ rows: [{ employee_id: 'e1', version: null, site_id: null }], skipped: [] })
})

test('bulkRows leaves out rows already present at that site', () => {
  const done = row(1, { last_site_id: 'S1', day: stored({ site_id: 'S1' }) })
  const moved = row(2, { last_site_id: 'S1', day: stored({ site_id: 'L-absent', leave_code: 'absent' }) })
  const office = row(3, { day: stored({ site_id: null }) })
  expect(bulkRows({ kind: 'present' }, [done, moved], leaveSite, false).rows).toEqual([{ employee_id: 'e2', version: 3, site_id: 'S1' }])
  expect(bulkRows({ kind: 'present' }, [office], leaveSite, true).rows).toEqual([])
})

test('bulkRows absent uses the absent leave day-type and passes the version through', () => {
  const a = row(1, { day: stored({ site_id: 'S1', version: 4 }) })
  const done = row(2, { day: stored({ site_id: 'L-absent', leave_code: 'absent' }) })
  expect(bulkRows({ kind: 'leave', code: 'absent' }, [a, done], leaveSite, false)).toEqual({ rows: [{ employee_id: 'e1', version: 4, site_id: 'L-absent' }], skipped: [] })
})

test('bulkRows skips every row when the leave day-type is unknown', () => {
  const rows = [row(1), row(2)]
  expect(bulkRows({ kind: 'leave', code: 'absent' }, rows, () => undefined, false)).toEqual({ rows: [], skipped: rows })
})

test('bulkRows site sets the site unless it is already there', () => {
  const rows = [row(1), row(2, { day: stored({ site_id: 'S2' }) })]
  expect(bulkRows({ kind: 'site', siteId: 'S2' }, rows, leaveSite, false).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 'S2' }])
})

test('bulkRows time writes the office in-time with site_id null', () => {
  const a = row(1)
  const same = row(2, { day: stored({ site_id: null, in_time: '09:20:00' }) })
  expect(bulkRows({ kind: 'time', in_time: '09:20' }, [a, same], leaveSite, true).rows).toEqual([{ employee_id: 'e1', version: null, site_id: null, in_time: '09:20' }])
})

test('undoRows restores every value of a stored day with the new version', () => {
  const before = row(1, { day: stored({ site_id: 'S1', in_time: '08:00', advance_amount: '500.000000', ot_minutes: 30 }) })
  expect(undoRows([before], [{ employee_id: 'e1', version: 4 }])).toEqual([{ ...blank, employee_id: 'e1', version: 4, site_id: 'S1', in_time: '08:00', advance_amount: '500.000000', ot_minutes: 30 }])
})

test('undoRows clears rows that were missing or derived before', () => {
  const before = [row(1), row(2, { day: { state: 'derived', leave_code: 'friday' } })]
  expect(undoRows(before, [{ employee_id: 'e1', version: 1 }, { employee_id: 'e2', version: 1 }])).toEqual([
    { employee_id: 'e1', clear: true, version: 1 },
    { employee_id: 'e2', clear: true, version: 1 },
  ])
})

test('undoRows leaves out rows whose version did not change', () => {
  expect(undoRows([row(1, { day: stored({ site_id: 'S1' }) })], [{ employee_id: 'e1', version: 3 }])).toEqual([])
})

test('statusOf tells every state apart', () => {
  expect(statusOf(row(1), false)).toBe('missing')
  expect(statusOf(row(1, { day: { state: 'derived', leave_code: 'friday' } }), false)).toBe('derived')
  expect(statusOf(row(1, { day: stored({ site_id: 'S1' }) }), false)).toBe('present')
  expect(statusOf(row(1, { day: stored({ site_id: 'L-absent', leave_code: 'absent' }) }), false)).toBe('absent')
  expect(statusOf(row(1, { day: stored({ site_id: 'L-sick', leave_code: 'sick' }) }), false)).toBe('leave')
})

test('statusOf marks an office row late from the hint', () => {
  expect(statusOf(row(1, { day: stored({ site_id: null, in_time: '09:40' }), hints: { late_minutes: 25 } }), true)).toBe('late')
  expect(statusOf(row(1, { day: stored({ site_id: null, in_time: '09:00' }), hints: { late_minutes: 0 } }), true)).toBe('present')
})

test('statusOf never marks a field row late', () => {
  expect(statusOf(row(1, { day: stored({ site_id: 'S1' }), hints: { late_minutes: 25, ot_minutes: 0 } }), false)).toBe('present')
})
