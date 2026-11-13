import { expect, test } from 'vitest'
import { chargeableLate, changed, likeYesterday, likeYesterdayCount, checkOutAll, checkOutCount, counts, departureDiff, markAllCount, markAllPresent, saveBody, type SiteDayRow } from './site-day'

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

test('chargeable lateness starts on the minute after the grace period', () => {
  expect(chargeableLate('09:15', '09:00', 15)).toBe(0)
  expect(chargeableLate('09:16', '09:00', 15)).toBe(1)
  expect(chargeableLate('09:25', '09:00', 15)).toBe(10)
  expect(chargeableLate('08:50', '09:00', 15)).toBe(0)
  const r = row('l', { state: 'stored', version: 1, site_id: S, in_time: '09:20' }, { shift: { start: '09:00', end: '16:00' } })
  expect(counts([r], {}, S).late).toBe(1)
})

test('«زي امبارح» copies yesterday into empty rows only: work here as present from its arrival, the manager\'s leave types as they were', () => {
  const leave = { absent: 'leave-absent', sick: 'leave-sick', rest: 'leave-rest', other: 'leave-other' }
  const prev = (p: Partial<NonNullable<SiteDayRow['previous']>>) => ({ previous: { date: '2026-10-21', site_id: 's9', leave_code: null, in_time: '08:10', out_time: '16:00', ...p } })
  const worked = row('worked', {}, prev({ site_id: S }))
  const elsewhere = row('elsewhere', {}, prev({}))
  const sick = row('sick', {}, prev({ site_id: 'leave-sick', leave_code: 'sick', in_time: null, out_time: null }))
  const annual = row('annual', {}, prev({ site_id: 'leave-annual', leave_code: 'annual', in_time: null, out_time: null }))
  const none = row('none')
  const typed = row('typed', {}, prev({}))
  const drafts = { typed: { site_id: S, in_time: '09:00', out_time: null } }
  const next = likeYesterday([worked, elsewhere, sick, annual, none, typed, absent], drafts, S, leave)
  expect(next.worked).toEqual({ site_id: S, in_time: '08:10', out_time: null })
  expect(next.sick).toEqual({ site_id: 'leave-sick', in_time: null, out_time: null })
  expect(next).not.toHaveProperty('elsewhere') // a work day at another site is not drafted as present here
  expect(next).not.toHaveProperty('annual')
  expect(next).not.toHaveProperty('none')
  expect(next.typed).toBe(drafts.typed)
  expect(likeYesterdayCount([worked, elsewhere, sick, annual, none, typed], drafts, S, leave)).toBe(2)
})

test('a typed note is sent only when it differs from the stored one', () => {
  const r = row('n', { state: 'stored', version: 1, site_id: S, in_time: '08:00', note: 'old' })
  expect(changed(r, { site_id: S, in_time: '08:00', out_time: null, note: 'old' })).toBe(false)
  expect(saveBody(S, '2026-10-22', [r], { n: { site_id: S, in_time: '08:00', out_time: null, note: 'new' } }).rows[0]).toMatchObject({ note: 'new' })
  expect(saveBody(S, '2026-10-22', [r], { n: { site_id: S, in_time: '08:30', out_time: null } }).rows[0]).not.toHaveProperty('note')
})
