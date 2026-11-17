/**
 * The fingerprint import's API shapes (FP-0). The spec is docs/superpowers/specs/2026-11-17-fingerprint-import-design.md;
 * change both together. Routes: POST /attendance/fingerprint-import/preview and /confirm, multipart.
 */

export type PunchFlag = 'missing_punch' | 'early_leave'

/** Only `conflict` and `leave` take HR's tick; confirm replaces only the ticked ones. */
export type FingerprintDayStatus = 'new' | 'refresh' | 'unchanged' | 'conflict' | 'leave' | 'locked' | 'not_office'

export type PunchRowError = 'bad_date' | 'bad_device_user_id' | 'missing_name' | 'bad_time' | 'future_date'

export type DaySource = 'entered' | 'imported' | 'derived' | 'leave' | 'manager' | 'fingerprint'

export type FingerprintDay = {
  employee: { id: string; code: number; name_ar: string; name_en: string | null }
  date: string
  /** HH:MM; at most one of the two is null (a one-punch day). On `refresh`, the merged day confirm stores (a stored time the file lacks is kept). */
  in_time: string | null
  out_time: string | null
  punch_count: number
  /** Chargeable late minutes (after the grace), as the period grid's hint; null without an in time, and null for `not_office`. */
  late_minutes: number | null
  flags: PunchFlag[]
  status: FingerprintDayStatus
  /** The stored day (not soft-deleted): set for `unchanged`, `refresh`, `leave` and `conflict`; null for `new`, `locked` and `not_office`. */
  existing: { in_time: string | null; out_time: string | null; source: DaySource; leave_code: string | null; version: number } | null
}

export type FingerprintUnmatched = { device_name: string; device_user_ids: number[]; days: number }

export type FingerprintFileError = { sheet: string; row: number; code: PunchRowError; message: string }

export type FingerprintPreview = {
  /** The first and last punch date read; null when the file holds no punch. */
  from: string | null
  to: string | null
  summary: { punches: number; days: number; unmatched: number; errors: number } & Record<FingerprintDayStatus, number>
  unmatched: FingerprintUnmatched[]
  days: FingerprintDay[]
  errors: FingerprintFileError[]
}

/** One ticked day. Sent as form fields replace[i][employee_id], replace[i][date], replace[i][version] beside `file`. */
export type FingerprintReplacement = { employee_id: string; date: string; version: number }

/** A day confirm meant to write and could not (stale version, a lock taken since, a day rule). */
export type FingerprintDayError = { employee_id: string; date: string; code: string; message: string }

export type FingerprintConfirmResult = {
  written: number
  refreshed: number
  replaced: number
  unchanged: number
  skipped: number
  errors: FingerprintDayError[]
}
