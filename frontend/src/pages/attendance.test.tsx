import { screen, waitFor } from '@testing-library/react'
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
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-02: جمعة' })).toHaveClass('border-dashed')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' })).toHaveClass('bg-warning/15')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
  expect(screen.getByRole('group', { name: 'أيام غير مسجلة' })).toHaveTextContent('19')
})

test('the period arrows move between periods and keep the group in the URL', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('period_start=2026-08-26'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('group=engineer')
})

test('a weekday code without a leave type and an office present day still get a label', async () => {
  const office = [{ employee: { id: 'e2', code: 2, name_ar: 'Office-1', name_en: 'Office-1' },
    days: [cell('2026-10-01', 'stored'), cell('2026-10-02', 'derived', { leave_code: 'thursday' }), cell('2026-10-04', 'not_employed')] }]
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': () => json(200, { data: office, meta: { pagination } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=office' })
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
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance' })
  expect(await screen.findByText('لا يوجد موظفون في هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(screen.getByText('مقفلة')).toBeInTheDocument()
  expect(screen.getByRole('combobox', { name: 'المجموعة' })).toHaveValue('engineer')
})
