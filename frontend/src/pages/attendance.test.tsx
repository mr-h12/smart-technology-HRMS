import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'

const user = userEvent.setup()
afterEach(() => vi.unstubAllGlobals())

const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 19 }
const cell = (date: string, state: string, extra = {}) => ({ date, state, source: state === 'stored' ? 'entered' : null, site_id: null, leave_code: null, ...extra })
const rows = [{ employee: { id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1' },
  days: [cell('2026-10-01', 'stored', { site_id: 's1' }), cell('2026-10-02', 'derived', { leave_code: 'friday' }), cell('2026-10-03', 'missing'), cell('2026-10-21', 'future')] }]
const sites = [{ id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', outside_alexandria: false, active: true }]
const routes = {
  'GET /api/v1/attendance/period': () => json(200, { data: period }),
  'GET /api/v1/attendance/grid': () => json(200, { data: rows, meta: { pagination } }),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
}

test('the grid shows each employee per day with missing and derived days marked', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-02: جمعة' })).toHaveClass('border-dashed')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' })).toHaveClass('bg-warning/15')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toHaveClass('focus-visible:outline-solid') // outline-none alone would hide the focus ring
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
  expect(screen.getByRole('group', { name: 'أيام غير مسجلة' })).toHaveTextContent('19')
})

test('the grid isolates a Latin name from the employee code so RTL does not glue them', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect((await screen.findByText('Engineer-1')).tagName).toBe('BDI')
})

test('the period arrows move between periods and keep the group in the URL', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('period_start=2026-08-26'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('group=engineer')
})

test('a weekday code without a leave type and an office present day still get a label', async () => {
  const office = [{ employee: { id: 'e2', code: 2, name_ar: 'Office-1', name_en: 'Office-1' },
    days: [cell('2026-10-01', 'stored'), cell('2026-10-02', 'derived', { leave_code: 'thursday' }), cell('2026-10-04', 'not_employed')] }]
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': () => json(200, { data: office, meta: { pagination } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=office&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Office-1 2026-10-01: حاضر' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Office-1 2026-10-02: thursday' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Office-1 2026-10-04: خارج فترة العمل' })).toBeDisabled()
})

test('a locked period shows the badge and an empty group says so', async () => {
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true } }),
    'GET /api/v1/attendance/grid': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }),
  })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?tab=grid' })
  expect(await screen.findByText('لا يوجد موظفون في هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(screen.getByText('مقفلة')).toBeInTheDocument()
  expect(screen.getByRole('combobox', { name: 'المجموعة' })).toHaveValue('engineer')
})

test('clicking a day opens its editor', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/days/e1/2026-10-03': () => json(200, { data: { ...cell('2026-10-03', 'missing'), employee_id: 'e1', type: 'engineer', version: null,
    fields: ['in_time', 'out_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all', 'attendance.edit.all'], { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' }))
  expect(await screen.findByRole('dialog', { name: 'يوم 2026-10-03 — Engineer-1' })).toBeInTheDocument()
  expect(await screen.findByLabelText('وقت الحضور')).toBeEnabled()
})

const lockPerms = ['attendance.view.all', 'attendance.edit.all', 'attendance.lock.all']
const lockedPeriod = { ...period, is_locked: true, missing: 0, lock: { locked_by: { id: 'u1', name: 'HR-1' }, locked_at: '2026-10-26T08:00:00Z', reopened_by: null, reopened_at: null, reopen_reason: null } }

test('locking with missing days lists them and needs the confirm checkbox', async () => {
  const lock = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: lockedPeriod }))
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/missing': () => json(200, { data: [{ employee_id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1', date: '2026-10-03' }], meta: { pagination } }),
    'POST /api/v1/attendance/locks': lock,
  })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'قفل الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'قفل حضور الفترة' })
  expect(within(dialog).getByText('بعد القفل لا يمكن لأي مستخدم تعديل أيام هذه الفترة حتى يُعاد فتحها.')).toBeInTheDocument()
  const row = await within(dialog).findByRole('listitem')
  expect(row).toHaveTextContent('Engineer-1')
  expect(row).toHaveTextContent('2026-10-03')
  const submit = within(dialog).getByRole('button', { name: 'قفل الفترة' })
  expect(submit).toBeDisabled()
  await user.click(within(dialog).getByRole('checkbox', { name: 'أؤكد القفل رغم وجود أيام غير مسجلة' }))
  expect(submit).toBeEnabled()
  await user.click(submit)
  await waitFor(() => expect(lock).toHaveBeenCalled())
  expect(JSON.parse(String(lock.mock.calls[0][1]?.body))).toEqual({ group: 'engineer', period_start: '2026-09-26', confirm_missing: true })
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

test('reopen sends the reason', async () => {
  const reopen = vi.fn((_url: string, init?: RequestInit) =>
    JSON.parse(String(init?.body)).reason === 'Correction'
      ? json(422, { error: { code: 'validation_failed', message: 'invalid', details: [{ field: 'reason', code: 'invalid', message: 'Reason too short.' }] } })
      : json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: lockedPeriod }), 'POST /api/v1/attendance/locks/reopen': reopen })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByText(/قفلها HR-1 في/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'إعادة فتح الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'إعادة فتح حضور الفترة' })
  await user.type(within(dialog).getByLabelText('سبب إعادة الفتح'), 'Correction')
  await user.click(within(dialog).getByRole('button', { name: 'إعادة فتح الفترة' }))
  await waitFor(() => expect(reopen).toHaveBeenCalled())
  expect(JSON.parse(String(reopen.mock.calls[0][1]?.body))).toEqual({ group: 'engineer', period_start: '2026-09-26', reason: 'Correction' })
  expect(await within(dialog).findByText('Reason too short.')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('سبب إعادة الفتح')).toHaveAttribute('aria-invalid', 'true')
})

test('lock controls need attendance.lock', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all', 'attendance.edit.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إعادة فتح الفترة' })).not.toBeInTheDocument()
})
