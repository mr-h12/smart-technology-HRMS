import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'

const user = userEvent.setup()
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-10-18T10:00:00Z')) // Cairo 13:00 on 2026-10-18
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 4 }
const blank = { source: null, version: null, site_id: null, leave_code: null, in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null }
const row = (code: number, name: string, day: object, extra: object = {}) => ({
  employee: { id: `e${code}`, code, name_ar: name, name_en: name, department: null },
  day: { ...blank, state: 'missing', ...day },
  hints: {},
  last_site_id: null,
  totals: { worked: 12, leave: 4, absent: 1, missing: 2 },
  ...extra,
})
const engineers = [
  row(1, 'Engineer-1', {}, { last_site_id: 's1' }),
  row(2, 'Engineer-2', { state: 'stored', source: 'entered', version: 1, site_id: 's1' }, { last_site_id: 's1' }),
  row(3, 'Engineer-3', { state: 'stored', source: 'entered', version: 2, site_id: 'sa', leave_code: 'absent' }),
]
const office = [
  { ...row(10, 'Office-1', { state: 'stored', source: 'entered', version: 1, in_time: '09:40' }, { hints: { late_minutes: 25 } }), employee: { id: 'e10', code: 10, name_ar: 'Office-1', name_en: 'Office-1', department: 'Accounts' } },
  row(11, 'Office-2', {}),
]
const days = [{ date: '2026-10-17', missing: 2 }, { date: '2026-10-18', missing: 1 }, { date: '2026-10-19', missing: 0 }]
const sheet = (rows: object[], group = 'engineer') => json(200, { data: { group, date: '2026-10-18', days, rows } })
const sites = [
  { id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', leave_code: null, active: true },
  { id: 'sa', name_ar: 'غياب', name_en: 'Absent', kind: 'leave', leave_code: 'absent', active: true },
]
const routes = {
  'GET /api/v1/attendance/period': () => json(200, { data: period }),
  'GET /api/v1/attendance/sheet': () => sheet(engineers),
  'GET /api/v1/attendance/grid': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
}
const open = (path = '/attendance?group=engineer') => renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path })
const table = async () => screen.findByRole('table')

test('the day sheet is the default tab and ?tab=grid shows the grid', async () => {
  const getPeriod = vi.fn((_url: string) => json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': getPeriod })
  const { unmount } = open()
  expect(await screen.findByRole('tab', { name: 'كشف اليوم' })).toHaveAttribute('aria-selected', 'true')
  expect(within(await table()).getByText('Engineer-2')).toBeInTheDocument()
  expect(getPeriod.mock.calls[0][0]).toContain('date=2026-10-18') // the header period is the one holding the date
  unmount()
  open('/attendance?group=engineer&tab=grid')
  expect(await screen.findByText('لا يوجد موظفون في هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'جدول الفترة' })).toHaveAttribute('aria-selected', 'true')
})

test('a malformed or future date falls back to today', async () => {
  const get = vi.fn((_url: string) => sheet(engineers))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': get })
  open('/attendance?group=engineer&date=2026-10-30')
  await table()
  expect(get.mock.calls[0][0]).toContain('date=2026-10-18')
})

test('the KPI cards count the rows and filter them', async () => {
  mockFetch(routes)
  open()
  const rows = within(await table()).getAllByRole('row')
  expect(rows).toHaveLength(4) // header + 3
  expect(screen.getByRole('button', { name: /^الإجمالي/ })).toHaveTextContent('2 من 3 مسجل')
  expect(screen.getByRole('button', { name: /^في موقع/ })).toHaveTextContent('1')
  expect(screen.getByRole('button', { name: /^غياب/ })).toHaveTextContent('1')
  const missing = screen.getByRole('button', { name: /^غير مسجل/ })
  expect(missing).toHaveAttribute('aria-pressed', 'false')
  await user.click(missing)
  expect(missing).toHaveAttribute('aria-pressed', 'true')
  const left = within(screen.getByRole('table')).getAllByRole('row')
  expect(left).toHaveLength(2)
  expect(left[1]).toHaveTextContent('Engineer-1')
})

test('office KPI cards split on time from late', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  open('/attendance?group=office')
  const tbl = await table()
  expect(screen.getByRole('button', { name: /^متأخر/ })).toHaveTextContent('1')
  expect(screen.getByRole('button', { name: /^في الموعد/ })).toHaveTextContent('0')
  expect(within(tbl).getByText('متأخر 25 د')).toBeInTheDocument()
  expect(within(tbl).getByText('09:40')).toBeInTheDocument()
})

test('missing and absent rows look different', async () => {
  mockFetch(routes)
  open()
  const tbl = await table()
  const status = (name: string) => within(within(tbl).getByText(name).closest('tr')!).getAllByRole('cell')[2] // code, employee, status
  const missing = within(status('Engineer-1')).getByText('غير مسجل')
  const absent = within(status('Engineer-3')).getByText('غياب')
  expect(missing).toHaveClass('bg-warning-bg', 'border-warning-border', 'text-warning')
  expect(absent).toHaveClass('bg-destructive-bg', 'border-destructive-border', 'text-destructive')
})

test('period totals sit in the last cell of each row and at the bottom of each card', async () => {
  mockFetch(routes)
  open()
  const [head, ...rows] = within(await table()).getAllByRole('row')
  expect(within(head).getAllByRole('columnheader').at(-1)).toHaveTextContent('إجمالي الفترة')
  for (const r of rows) expect(within(r).getAllByRole('cell').at(-1)).toHaveTextContent(/12.*4.*1.*2/)
  const cards = within(screen.getByRole('list', { name: 'الموظف' })).getAllByRole('listitem')
  expect(cards).toHaveLength(3)
  for (const c of cards) expect(c.lastElementChild).toHaveTextContent(/^إجمالي الفترة.*12.*4.*1.*2/)
})

test('the day strip marks the date and disables future days', async () => {
  const get = vi.fn((_url: string) => sheet(engineers))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': get })
  open()
  const strip = await screen.findByRole('group', { name: 'أيام الفترة' })
  expect(within(strip).getByRole('button', { name: /^2026-10-18/ })).toHaveAttribute('aria-current', 'date')
  expect(within(strip).getByRole('button', { name: /^2026-10-19/ })).toBeDisabled()
  await user.click(within(strip).getByRole('button', { name: /^2026-10-17/ }))
  await waitFor(() => expect(get.mock.calls.at(-1)?.[0]).toContain('date=2026-10-17'))
  expect(get.mock.calls.at(-1)?.[0]).toContain('group=engineer')
})

test('field cohorts group by last site and office cohorts by department', async () => {
  mockFetch(routes)
  open()
  await table()
  const cohorts = screen.getByRole('group', { name: 'حسب آخر موقع' })
  const site = within(cohorts).getByRole('button', { name: /^Site-1/ })
  expect(site).toHaveTextContent('1/2')
  expect(within(cohorts).getByRole('button', { name: /^بدون موقع سابق/ })).toHaveTextContent('1/1')
  await user.click(site)
  expect(site).toHaveAttribute('aria-pressed', 'true')
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(3)
  expect(within(screen.getByRole('table')).queryByText('Engineer-3')).not.toBeInTheDocument()
})

test('office cohorts group by department', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  open('/attendance?group=office')
  await table()
  const cohorts = screen.getByRole('group', { name: 'حسب القسم' })
  expect(within(cohorts).getByRole('button', { name: /^Accounts/ })).toHaveTextContent('1/1')
  expect(within(cohorts).getByRole('button', { name: /^بدون قسم/ })).toHaveTextContent('0/1')
})

test('51 rows paginate at 50', async () => {
  const many = Array.from({ length: 51 }, (_, i) => row(i + 1, `Engineer-${i + 1}`, {}))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(many) })
  open()
  expect(within(await table()).getAllByRole('row')).toHaveLength(51)
  expect(screen.getByText('عرض 1–50 من 51')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(2)
  await user.click(screen.getByRole('button', { name: /^غير مسجل/ })) // a filter goes back to page 1
  expect(screen.getByText('عرض 1–50 من 51')).toBeInTheDocument()
})

test('the employee code sits in its own element apart from the name', async () => {
  mockFetch(routes)
  open()
  const name = within(await table()).getByText('Engineer-1')
  expect(name.tagName).toBe('BDI')
  const card = within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-1')
  expect(card.tagName).toBe('BDI')
  expect(card.previousElementSibling).toHaveTextContent('1')
})

test('a locked period shows the read-only banner', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true } }) })
  open()
  expect(await screen.findByText('الفترة مقفلة؛ الكشف للعرض فقط.')).toBeInTheDocument()
})

test('Details opens the day editor', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/days/e1/2026-10-18': () => json(200, { data: { ...blank, state: 'missing', date: '2026-10-18', employee_id: 'e1', type: 'engineer',
    fields: ['in_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  open()
  await user.click(within(await table()).getByRole('button', { name: 'التفاصيل Engineer-1' }))
  expect(await screen.findByRole('dialog', { name: 'يوم 2026-10-18 — Engineer-1' })).toBeInTheDocument()
})
