import { expect, test } from 'vitest'
import { bulkRows, DAY_VALUES, type DayValue, fold, matches, parseBatch, parseSiteMessage, type SheetRow, statusOf, undoRows } from './day-sheet'

const blank = Object.fromEntries(DAY_VALUES.map((f) => [f, null])) as Record<DayValue, null>
const row = (code: number, o: { day?: Partial<SheetRow['day']>; last_site_id?: string | null; hints?: SheetRow['hints']; department?: string | null; name_en?: string; type?: string } = {}): SheetRow => ({
  employee: { id: `e${code}`, code, name_ar: `مهندس ${code}`, name_en: o.name_en ?? `Engineer-${code}`, department: o.department ?? null, type: o.type ?? 'engineer' },
  site_id: null,
  day: { ...blank, state: 'missing', source: null, version: null, site_id: null, leave_code: null, ...o.day },
  hints: o.hints ?? {},
  last_site_id: o.last_site_id ?? null,
  needs_site: false,
  totals: { worked: 0, leave: 0, absent: 0, missing: 0 },
  locked: false,
})
const sheet = Array.from({ length: 12 }, (_, i) => row(i + 1))
const codes = (input: string) => parseBatch(input, sheet).matches.map((r) => r.employee.code)
const stored = (o: Partial<SheetRow['day']>) => ({ state: 'stored' as const, source: 'entered', version: 3, ...o })
const leaveTypeId = (code: string) => ({ absent: 'L-absent', sick: 'L-sick' })[code]

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

test.each([
  ['احمد', 'أحمد'],
  ['ايمان', 'إيمان'],
  ['امال', 'آمال'],
  ['فاطمه', 'فاطمة'],
  ['مصطفي', 'مصطفى'],
  ['١٢', '12'],
  ['ENGINEER', 'engineer'],
])('fold makes %s and %s the same', (a, b) => {
  expect(fold(a)).toBe(fold(b))
})

test('matches finds a row by a folded Arabic name either way round', () => {
  const r = { ...row(1), employee: { ...row(1).employee, name_ar: 'أحمد فاطمة' } }
  expect(matches(r, 'احمد')).toBe(true)
  expect(matches(r, 'فاطمه')).toBe(true)
  expect(matches({ ...r, employee: { ...r.employee, name_ar: 'احمد' } }, 'أحمد')).toBe(true)
  expect(matches(r, 'محمود')).toBe(false)
})

test('matches finds a row by code in Arabic digits, English name in any case, and department', () => {
  const r = row(12, { name_en: 'Ahmed Ali', department: 'Projects' })
  expect(matches(r, '١٢')).toBe(true)
  expect(matches(r, 'AHMED')).toBe(true)
  expect(matches(r, 'proj')).toBe(true)
  expect(matches(r, 'sales')).toBe(false)
})

test('matches lets a blank query through', () => {
  expect(matches(row(1), '  ')).toBe(true)
})

test('parseBatch folds a name search', () => {
  const r = { ...row(1), employee: { ...row(1).employee, name_ar: 'إيمان' } }
  expect(parseBatch('ايمان', [r, row(2)]).matches.map((x) => x.employee.code)).toEqual([1])
})

test('bulkRows present uses the last site and skips rows without one', () => {
  const a = row(1, { last_site_id: 'S1', day: { version: null } })
  const b = row(2)
  expect(bulkRows({ kind: 'present' }, [a, b], leaveTypeId)).toEqual({ rows: [{ employee_id: 'e1', version: null, site_id: 'S1' }], skipped: [b] })
})

test('bulkRows present on an office row writes site_id null even without a last site', () => {
  const r = row(1, { type: 'office', day: { state: 'derived', leave_code: 'friday' } })
  expect(bulkRows({ kind: 'present' }, [r], leaveTypeId)).toEqual({ rows: [{ employee_id: 'e1', version: null, site_id: null }], skipped: [] })
})

test('bulkRows leaves out rows already present at that site', () => {
  const done = row(1, { last_site_id: 'S1', day: stored({ site_id: 'S1' }) })
  const moved = row(2, { last_site_id: 'S1', day: stored({ site_id: 'L-absent', leave_code: 'absent' }) })
  const office = row(3, { type: 'office', day: stored({ site_id: null }) })
  expect(bulkRows({ kind: 'present' }, [done, moved], leaveTypeId).rows).toEqual([{ employee_id: 'e2', version: 3, site_id: 'S1' }])
  expect(bulkRows({ kind: 'present' }, [office], leaveTypeId).rows).toEqual([])
})

test('bulkRows reads each row by its own type: in All an office row is present with no site, a field row at its last site', () => {
  const office = row(1, { type: 'office' })
  const field = row(2, { type: 'permanent_worker', last_site_id: 'S1' })
  expect(bulkRows({ kind: 'present' }, [office, field], leaveTypeId).rows).toEqual([{ employee_id: 'e1', version: null, site_id: null }, { employee_id: 'e2', version: null, site_id: 'S1' }])
})

test('bulkRows site skips office rows: an office day has no work site', () => {
  const office = row(1, { type: 'office' })
  const field = row(2)
  expect(bulkRows({ kind: 'site', siteId: 'S2' }, [office, field], leaveTypeId)).toEqual({ rows: [{ employee_id: 'e2', version: null, site_id: 'S2' }], skipped: [office] })
})

test('bulkRows absent uses the absent leave day-type and passes the version through', () => {
  const a = row(1, { day: stored({ site_id: 'S1', version: 4 }) })
  const done = row(2, { day: stored({ site_id: 'L-absent', leave_code: 'absent' }) })
  expect(bulkRows({ kind: 'leave', code: 'absent' }, [a, done], leaveTypeId)).toEqual({ rows: [{ employee_id: 'e1', version: 4, site_id: 'L-absent' }], skipped: [] })
})

test('bulkRows skips every row when the leave day-type is unknown', () => {
  const rows = [row(1), row(2)]
  expect(bulkRows({ kind: 'leave', code: 'absent' }, rows, () => undefined)).toEqual({ rows: [], skipped: rows })
})

test('bulkRows site sets the site unless it is already there', () => {
  const rows = [row(1), row(2, { day: stored({ site_id: 'S2' }) })]
  expect(bulkRows({ kind: 'site', siteId: 'S2' }, rows, leaveTypeId).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 'S2' }])
})

test('bulkRows time keeps a field row at its stored site and sends only the given field', () => {
  const r = row(1, { day: stored({ site_id: 'S1', in_time: '08:00:00', out_time: '17:00:00' }) })
  expect(bulkRows({ kind: 'time', out_time: '15:00' }, [r], leaveTypeId)).toEqual({ rows: [{ employee_id: 'e1', version: 3, site_id: 'S1', out_time: '15:00' }], skipped: [] })
})

test('bulkRows time skips a field row with no stored work site', () => {
  const missing = row(1, { last_site_id: 'S1' })
  const absent = row(2, { day: stored({ site_id: 'L-absent', leave_code: 'absent' }) })
  expect(bulkRows({ kind: 'time', in_time: '08:30' }, [missing, absent], leaveTypeId)).toEqual({ rows: [], skipped: [missing, absent] })
})

test('bulkRows time leaves out a row whose given values already match', () => {
  const same = row(1, { day: stored({ site_id: 'S1', in_time: '08:00:00', out_time: '17:00:00' }) })
  const other = row(2, { day: stored({ site_id: 'S1', in_time: '09:00:00', out_time: '17:00:00' }) })
  expect(bulkRows({ kind: 'time', in_time: '08:00', out_time: '17:00' }, [same, other], leaveTypeId).rows).toEqual([{ employee_id: 'e2', version: 3, site_id: 'S1', in_time: '08:00', out_time: '17:00' }])
})

test('bulkRows time carries approved overtime minutes, unless they are already stored', () => {
  const r = row(1, { day: stored({ site_id: 'S1', in_time: '08:00:00', out_time: '18:00:00', ot_minutes: 0 }) })
  const same = row(2, { day: stored({ site_id: 'S1', in_time: '08:00:00', out_time: '18:00:00', ot_minutes: 75 }) })
  expect(bulkRows({ kind: 'time', ot_minutes: 75 }, [r, same], leaveTypeId).rows).toEqual([{ employee_id: 'e1', version: 3, site_id: 'S1', ot_minutes: 75 }])
})

test('bulkRows time writes the office in-time with site_id null', () => {
  const a = row(1, { type: 'office' })
  const same = row(2, { type: 'office', day: stored({ site_id: null, in_time: '09:20:00' }) })
  expect(bulkRows({ kind: 'time', in_time: '09:20' }, [a, same], leaveTypeId).rows).toEqual([{ employee_id: 'e1', version: null, site_id: null, in_time: '09:20' }])
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

test('site messages resolve employee IDs and reject invalid, repeated and unknown lines', () => {
  const result = parseSiteMessage('١ ٠٩:١٦ ١٦:٠٠\n2 غياب\n1 09:00 16:00\n100 absent\n3 09:00 28:00\n4 16:00 09:00', sheet)
  expect(result.entries).toEqual([{ row: sheet[0], inTime: '09:16', outTime: '16:00' }, { row: sheet[1] }])
  expect(result.invalid).toEqual([3, 4, 5, 6])
  expect(parseSiteMessage('1 absent\n'.repeat(501), sheet).capped).toBe(true)
})

test('site messages allow overnight shifts for permanent workers only', () => {
  expect(parseSiteMessage('1 22:00 06:00', sheet).invalid).toEqual([1])
  expect(parseSiteMessage('1 22:00 06:00', sheet, true).entries).toEqual([
    { row: sheet[0], inTime: '22:00', outTime: '06:00' },
  ])
})
