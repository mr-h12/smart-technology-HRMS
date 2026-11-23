import { expect, test } from 'vitest'
import { arrivalDiff, changed, checkOutAll, checkOutCount, counts, departureDiff, markAllCount, markAllPresent, saveBody, type SiteDayRow } from './site-day'

const S = 's1'
const row = (id: string, day: Partial<SiteDayRow['day']> = {}, extra: Partial<SiteDayRow> = {}): SiteDayRow => ({
  employee: { id, code: 1, name_ar: id, name_en: null, type: 'day_laborer' },
  section: 'workers',
  shift: { start: '08:00', end: '16:00' },
  day: { state: 'missing', version: null, site_id: null, leave_code: null, in_time: null, out_time: null, note: null, ...day },
  can_edit: true,
  why_not: null,
  departure_only: false,
  open_date: null,
  open_site_id: null,
  ...extra,
})

const empty = row('empty')
const typed = row('typed')
const absent = row('absent', { state: 'stored', version: 1, site_id: 'leave-absent', leave_code: 'absent' })
const annual = row('annual', { state: 'stored', version: 2, site_id: 'leave-annual', leave_code: 'annual' }, { can_edit: false, why_not: 'hr_day' })
const friday = row('friday', { state: 'derived', site_id: 'leave-friday', leave_code: 'friday' })
const rows = [empty, typed, absent, annual, friday]

test('mark all present fills the shift start, leaves the departure empty, and keeps typed, absent, leave and derived days', () => {
  const drafts = { typed: { site_id: S, in_time: '08:30', out_time: null } }
  const next = markAllPresent(rows, drafts, S)
  expect(next.empty).toEqual({ site_id: S, in_time: '08:00', out_time: null })
  expect(next.typed).toBe(drafts.typed)
  expect(next).not.toHaveProperty('absent')
  expect(next).not.toHaveProperty('annual')
  expect(next).not.toHaveProperty('friday')
  expect(markAllCount(rows, drafts, S)).toBe(1)
})

test('a person with their own shift gets their own start and end', () => {
  const r = row('own', {}, { shift: { start: '07:00', end: '15:00' } })
  const morning = markAllPresent([r], {}, S)
  expect(morning.own.in_time).toBe('07:00')
  expect(checkOutAll([r], morning, S).own.out_time).toBe('15:00')
})

test('check out all fills the shift end only for present people with an arrival and no departure', () => {
  const stored = row('stored', { state: 'stored', version: 1, site_id: S, in_time: '08:00' })
  const manual = row('manual')
  const elsewhere = row('elsewhere', { state: 'stored', version: 1, site_id: 's2', in_time: '08:00' })
  const drafts = { manual: { site_id: S, in_time: '08:00', out_time: '15:00' } }
  const next = checkOutAll([stored, manual, elsewhere, absent, empty], drafts, S)
  expect(next.stored).toEqual({ site_id: S, in_time: '08:00', out_time: '16:00' })
  expect(next.manual).toBe(drafts.manual)
  expect(next).not.toHaveProperty('elsewhere')
  expect(next).not.toHaveProperty('empty')
  expect(checkOutCount([stored, manual, elsewhere], drafts, S)).toBe(1)
})

test('counts what is still open', () => {
  const drafts = markAllPresent(rows, {}, S)
  expect(counts(rows, {}, S)).toEqual({ present: 0, away: 3, unrecorded: 2, noCheckout: 0, late: 0 })
  expect(counts(rows, drafts, S)).toEqual({ present: 2, away: 3, unrecorded: 0, noCheckout: 2, late: 0 })
})

test('the save body sends changed rows only, with their versions, and no times on a leave day', () => {
  const stored = row('stored', { state: 'stored', version: 3, site_id: S, in_time: '08:00' })
  const drafts = {
    empty: { site_id: S, in_time: '08:00', out_time: null },
    stored: { site_id: S, in_time: '08:00', out_time: null }, // unchanged
    absent: { site_id: 'leave-sick', in_time: '08:00', out_time: null },
  }
  expect(changed(stored, drafts.stored)).toBe(false)
  expect(saveBody(S, '2026-10-22', [empty, stored, absent], drafts)).toEqual({
    site_id: S,
    date: '2026-10-22',
    rows: [
      { employee_id: 'empty', version: null, site_id: S, in_time: '08:00', out_time: null },
      { employee_id: 'absent', version: 1, site_id: 'leave-sick', in_time: null, out_time: null },
    ],
  })
})

test('the departure difference', () => {
  expect(departureDiff('15:00', '16:00')).toBe(-60)
  expect(departureDiff('17:30', '16:00')).toBe(90)
  expect(departureDiff(null, '16:00')).toBeNull()
})

test('the arrival difference and the late count', () => {
  expect(arrivalDiff('09:20', '09:00')).toBe(20)
  expect(arrivalDiff('08:50', '09:00')).toBe(-10)
  const r = row('l', { state: 'stored', version: 1, site_id: S, in_time: '09:20' }, { shift: { start: '09:00', end: '16:00' } })
  expect(counts([r], {}, S).late).toBe(1)
})

test('a typed note is sent only when it differs from the stored one', () => {
  const r = row('n', { state: 'stored', version: 1, site_id: S, in_time: '08:00', note: 'old' })
  expect(changed(r, { site_id: S, in_time: '08:00', out_time: null, note: 'old' })).toBe(false)
  expect(saveBody(S, '2026-10-22', [r], { n: { site_id: S, in_time: '08:00', out_time: null, note: 'new' } }).rows[0]).toMatchObject({ note: 'new' })
  expect(saveBody(S, '2026-10-22', [r], { n: { site_id: S, in_time: '08:30', out_time: null } }).rows[0]).not.toHaveProperty('note')
})
