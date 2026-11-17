import type { FingerprintConfirmResult, FingerprintDay, FingerprintPreview } from './fingerprint-import'

/** Synthetic people only. Every status and flag appears at least once, so a page test can find each. Days are ordered by employee code, then date, as the API orders them. */
const person = (id: string, code: number, name_ar: string, name_en: string | null) => ({ id, code, name_ar, name_en })
const sara = person('0193a000-0000-7000-8000-000000000101', 101, 'سارة نبيل', 'Sara Nabil')
const hadi = person('0193a000-0000-7000-8000-000000000102', 102, 'هادي سالم', 'Hadi Salem')
const mona = person('0193a000-0000-7000-8000-000000000103', 103, 'منى عادل', null)
const hany = person('0193a000-0000-7000-8000-000000000104', 104, 'هاني سمير', 'Hany Samir')
const ziad = person('0193a000-0000-7000-8000-000000000105', 105, 'زياد حلبي', 'Ziad Halabi')

const days: FingerprintDay[] = [
  { employee: sara, date: '2026-09-27', in_time: '08:55', out_time: '17:02', punch_count: 2, late_minutes: 0, flags: [], status: 'new', existing: null },
  { employee: sara, date: '2026-09-28', in_time: null, out_time: '16:45', punch_count: 1, late_minutes: null, flags: ['missing_punch', 'early_leave'], status: 'new', existing: null },
  { employee: sara, date: '2026-09-29', in_time: '09:20', out_time: '17:10', punch_count: 2, late_minutes: 5, flags: [], status: 'conflict', existing: { in_time: '09:00', out_time: '17:00', source: 'entered', leave_code: null, version: 2 } },
  { employee: hadi, date: '2026-09-27', in_time: '08:59', out_time: '17:30', punch_count: 3, late_minutes: 0, flags: [], status: 'refresh', existing: { in_time: '08:59', out_time: '17:00', source: 'fingerprint', leave_code: null, version: 1 } },
  { employee: hadi, date: '2026-09-29', in_time: '08:50', out_time: '17:05', punch_count: 3, late_minutes: 0, flags: [], status: 'leave', existing: { in_time: null, out_time: null, source: 'leave', leave_code: 'annual', version: 1 } },
  { employee: hadi, date: '2026-09-30', in_time: '09:02', out_time: '17:00', punch_count: 2, late_minutes: 0, flags: [], status: 'unchanged', existing: { in_time: '09:02', out_time: '17:00', source: 'fingerprint', leave_code: null, version: 3 } },
  { employee: mona, date: '2026-09-30', in_time: '09:01', out_time: '16:30', punch_count: 2, late_minutes: 0, flags: ['early_leave'], status: 'new', existing: null },
  { employee: hany, date: '2026-08-31', in_time: '09:05', out_time: '17:00', punch_count: 2, late_minutes: 0, flags: [], status: 'locked', existing: null },
  { employee: ziad, date: '2026-09-28', in_time: '08:40', out_time: '16:00', punch_count: 2, late_minutes: null, flags: [], status: 'not_office', existing: null },
]

/** `summary.punches` counts every punch read: the 19 of `days` plus the unmatched Guest01's 4 (two punches on each of its 2 days, within from and to). */
export const fingerprintPreview: FingerprintPreview = {
  from: '2026-08-31',
  to: '2026-09-30',
  summary: { punches: 23, days: 9, unmatched: 1, errors: 1, new: 3, refresh: 1, unchanged: 1, conflict: 1, leave: 1, locked: 1, not_office: 1 },
  unmatched: [{ device_name: 'Guest01', device_user_ids: [19], days: 2 }],
  days,
  errors: [{ sheet: 'Sheet1', row: 9, code: 'bad_time', message: 'وقت غير صالح في هذا الصف.' }],
}

/** A file with no punch for any office employee: nothing to show and nothing to confirm. */
export const fingerprintEmptyPreview: FingerprintPreview = {
  from: null,
  to: null,
  summary: { punches: 0, days: 0, unmatched: 0, errors: 0, new: 0, refresh: 0, unchanged: 0, conflict: 0, leave: 0, locked: 0, not_office: 0 },
  unmatched: [],
  days: [],
  errors: [],
}

/** Confirm with both tickable days ticked; the leave day went stale between preview and confirm. */
export const fingerprintConfirmResult: FingerprintConfirmResult = {
  written: 3,
  refreshed: 1,
  replaced: 1,
  unchanged: 1,
  skipped: 3,
  errors: [{ employee_id: hadi.id, date: '2026-09-29', code: 'concurrency_conflict', message: 'تغيّر هذا اليوم بعد المعاينة.' }],
}
