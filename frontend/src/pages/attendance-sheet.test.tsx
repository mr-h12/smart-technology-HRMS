import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'

const user = userEvent.setup()
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-10-18T10:00:00Z')) // Cairo 13:00 on 2026-10-18
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.clearAllMocks()
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
const open = (path = '/attendance?group=engineer', perms = ['attendance.view.all']) => renderWithAuth(<AttendancePage />, perms, { path })
const EDIT = ['attendance.view.all', 'attendance.edit.all']
const edit = (path = '/attendance?group=engineer') => open(path, EDIT)
const bulkOk = () => vi.fn((_url: string, init?: RequestInit) => json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: 9 })) }))
const officePeriod = () => json(200, { data: { ...period, group: 'office', start: '2026-10-01', end: '2026-10-31' } })
const sent = (fn: ReturnType<typeof vi.fn>, call = 0) => JSON.parse(String((fn.mock.calls[call][1] as RequestInit).body))
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

test('the day strip scrolls itself to the date once, never the page and never again on a re-render', async () => {
  // jsdom has no layout: the selected day sits 244px past the strip's end, everything else inside it
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
    return (this.getAttribute('aria-current') === 'date' ? { left: 500, right: 544 } : { left: 0, right: 300 }) as DOMRect
  })
  const scroll = vi.spyOn(Element.prototype, 'scrollBy')
  mockFetch(routes)
  open()
  const strip = await screen.findByRole('group', { name: 'أيام الفترة' })
  const own = () => scroll.mock.contexts.filter((el) => el === strip)
  expect(own()).toHaveLength(1)
  expect(scroll).toHaveBeenCalledWith({ left: 244 })
  await user.click(screen.getByRole('button', { name: /^غير مسجل/ })) // a re-render
  await user.click(screen.getByRole('button', { name: /^غير مسجل/ }))
  expect(own()).toHaveLength(1)
  vi.restoreAllMocks()
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

test('P on a missing engineer posts his last site in one bulk request', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  expect(within(tbl).getByRole('button', { name: 'مرضي Engineer-1' })).toBeDisabled() // no active sick leave type
  await user.click(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e1', version: null, site_id: 's1' }] })
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم تسجيل 1'))
})

test('a cohort sweep marks only the missing members of that site', async () => {
  const bulk = bulkOk()
  const rows = [
    ...engineers,
    row(4, 'Engineer-4', {}, { last_site_id: 's2' }),
    row(5, 'Engineer-5', { state: 'stored', source: 'entered', version: 3, site_id: 'sa', leave_code: 'absent' }, { last_site_id: 's1' }),
  ]
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(rows), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: 'تسجيل باقي Site-1 حاضرين' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 's1' }])
})

test('selected rows get Absent with the absent leave type and the bar shows the count', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-2' }))
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveTextContent('2 محدد')
  await user.click(within(bar).getByRole('button', { name: 'غياب' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 'sa' }, { employee_id: 'e2', version: 1, site_id: 'sa' }])
  await waitFor(() => expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument())
})

test('Set site gives the selection the chosen work site; office sheets have no Set site', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  const { unmount } = edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-3' }))
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(within(bar).getByRole('combobox', { name: 'تعيين موقع' })).not.toHaveTextContent('غياب') // work sites only
  const apply = within(bar).getByRole('button', { name: 'تطبيق' })
  expect(apply).toBeDisabled()
  await user.selectOptions(within(bar).getByRole('combobox', { name: 'تعيين موقع' }), 's1')
  expect(bulk).not.toHaveBeenCalled() // choosing (an arrow key on a closed select) never writes
  await user.click(apply)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e3', version: 2, site_id: 's1' }])
  unmount()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  edit('/attendance?group=office')
  await user.click(within(await table()).getByRole('checkbox', { name: 'تحديد Office-2' }))
  expect(within(screen.getByRole('group', { name: 'إجراءات المحدد' })).queryByRole('combobox')).not.toBeInTheDocument()
})

test('select-all picks only the visible page', async () => {
  const many = Array.from({ length: 51 }, (_, i) => row(i + 1, `Engineer-${i + 1}`, {}))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(many) })
  edit()
  const tbl = await table()
  const all = within(tbl).getByRole('checkbox', { name: 'تحديد كل الصفحة' })
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  expect(all).toHaveProperty('indeterminate', true)
  await user.click(all)
  expect(all).toBeChecked()
  expect(screen.getByRole('group', { name: 'إجراءات المحدد' })).toHaveTextContent('50 محدد')
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  expect(within(screen.getByRole('table')).getByRole('checkbox', { name: 'تحديد Engineer-51' })).not.toBeChecked()
})

test('a 409 names the conflicting employees and refetches the sheet', async () => {
  const get = vi.fn((_url: string) => sheet(engineers))
  const bulk = vi.fn(() => json(409, { error: { code: 'concurrency_conflict', message: 'stale', details: [{ field: 'rows.1', code: 'concurrency_conflict', message: 'stale' }] } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': get, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-2' }))
  const before = get.mock.calls.length
  await user.click(within(screen.getByRole('group', { name: 'إجراءات المحدد' })).getByRole('button', { name: 'غياب' }))
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('عدّل مستخدم آخر Engineer-2 للتو؛ أُعيد تحميل الكشف، راجع ثم أعد المحاولة.'))
  await waitFor(() => expect(get.mock.calls.length).toBeGreaterThan(before))
})

test('a row in flight is not sent again', async () => {
  const bulk = vi.fn(() => new Promise<Response>(() => {}) as unknown as Response) // never answers
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' }))
  await user.click(within(tbl).getByRole('button', { name: 'غياب Engineer-1' }))
  await user.click(screen.getByRole('button', { name: /^تسجيل الباقين حاضرين/ }))
  expect(bulk).toHaveBeenCalledTimes(1)
})

test('more than 500 rows in one action are refused with a toast', async () => {
  const many = Array.from({ length: 501 }, (_, i) => row(i + 1, `Engineer-${i + 1}`, {}, { last_site_id: 's1' }))
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(many), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: /^تسجيل الباقين حاضرين/ }))
  expect(toast.error).toHaveBeenCalledWith('لا يمكن تسجيل أكثر من 500 صف في مرة واحدة؛ ضيّق الاختيار.')
  expect(bulk).not.toHaveBeenCalled()
})

test('an office in-time edit posts site null and the time', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const tbl = await table()
  expect(within(tbl).getByLabelText('وقت الحضور Office-1')).toHaveValue('09:40')
  const input = within(tbl).getByLabelText('وقت الحضور Office-2')
  fireEvent.change(input, { target: { value: '09:20' } })
  fireEvent.blur(input)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e11', version: null, site_id: null, in_time: '09:20' }] })
})

test('a row without a last site is skipped with a toast', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-3' }))
  expect(toast.warning).toHaveBeenCalledWith('تم تخطي 1 بدون موقع سابق؛ سجّلهم من التفاصيل.')
  expect(bulk).not.toHaveBeenCalled()
})

test.each([
  ['a locked period', EDIT, { ...period, is_locked: true }],
  ['a reader', ['attendance.view.all'], period],
])('%s sees no write controls', async (_, perms, p) => {
  for (const [group, rows] of [['engineer', engineers], ['office', office]] as const) {
    mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...p, group } }), 'GET /api/v1/attendance/sheet': () => sheet(rows, group) })
    const { container, unmount } = open(`/attendance?group=${group}`, perms)
    const tbl = await table()
    expect(within(tbl).getAllByRole('button', { name: /^التفاصيل / }).length).toBe(rows.length)
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^(حاضر|غياب|مرضي) / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^تسجيل (الباقين|باقي)/ })).not.toBeInTheDocument()
    expect(container.querySelector('input[type="time"]')).toBeNull()
    unmount()
  }
})

test('no write controls while the header period does not hold the date', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, start: '2026-08-26', end: '2026-09-25' } }) })
  edit()
  expect(within(await table()).getByRole('button', { name: 'التفاصيل Engineer-1' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'حاضر Engineer-1' })).not.toBeInTheDocument()
})

test('the header keeps its period while a day inside it loads', async () => {
  let calls = 0
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => (calls++ ? (new Promise(() => {}) as unknown as Response) : json(200, { data: period })) })
  edit()
  await table()
  await user.click(within(screen.getByRole('group', { name: 'أيام الفترة' })).getByRole('button', { name: /^2026-10-17/ }))
  await waitFor(() => expect(calls).toBe(2))
  expect(screen.getByText('من 2026-09-26 إلى 2026-10-25')).toBeInTheDocument()
  expect(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' })).toBeInTheDocument() // still writable
})

test('the KPI and cohort filters survive a date change', async () => {
  const get = vi.fn((_url: string) => sheet(engineers))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': get })
  open()
  await table()
  await user.click(screen.getByRole('button', { name: /^غير مسجل/ }))
  await user.click(within(screen.getByRole('group', { name: 'حسب آخر موقع' })).getByRole('button', { name: /^Site-1/ }))
  await user.click(within(screen.getByRole('group', { name: 'أيام الفترة' })).getByRole('button', { name: /^2026-10-17/ }))
  await waitFor(() => expect(get.mock.calls.at(-1)?.[0]).toContain('date=2026-10-17'))
  expect(await screen.findByRole('button', { name: /^غير مسجل/ })).toHaveAttribute('aria-pressed', 'true')
  expect(within(screen.getByRole('group', { name: 'حسب آخر موقع' })).getByRole('button', { name: /^Site-1/ })).toHaveAttribute('aria-pressed', 'true')
})

test('day buttons carry one interpolated label', async () => {
  mockFetch(routes)
  open()
  const strip = await screen.findByRole('group', { name: 'أيام الفترة' })
  expect(within(strip).getByRole('button', { name: '2026-10-17 — 2 غير مسجل' })).toBeInTheDocument()
})

test('a KPI or cohort filter change clears the selection, so a bulk action never writes hidden rows', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-2' }))
  await user.click(screen.getByRole('button', { name: /^غير مسجل/ }))
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  await user.click(within(screen.getByRole('table')).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(screen.getByRole('group', { name: 'حسب آخر موقع' })).getByRole('button', { name: /^Site-1/ }))
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  expect(bulk).not.toHaveBeenCalled()
})

test('while another period or group loads, the header shows no stale range, missing count or lock', async () => {
  let calls = 0
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => (calls++ ? (new Promise(() => {}) as unknown as Response) : json(200, { data: period })) })
  renderWithAuth(<AttendancePage />, [...EDIT, 'attendance.lock.all'], { path: '/attendance?group=engineer' })
  await table()
  expect(await screen.findByRole('button', { name: 'قفل الفترة' })).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' })) // the date jumps to 2026-08-26, outside the loaded period
  await waitFor(() => expect(calls).toBe(2))
  expect(screen.queryByText('من 2026-09-26 إلى 2026-10-25')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'الفترة السابقة' })).toBeDisabled()
  expect(screen.getByRole('group', { name: 'أيام غير مسجلة' })).toHaveTextContent('—')
})

test('a group change does not show the old group\'s period', async () => {
  let calls = 0
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => (calls++ ? (new Promise(() => {}) as unknown as Response) : json(200, { data: period })) })
  renderWithAuth(<AttendancePage />, [...EDIT, 'attendance.lock.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'قفل الفترة' })).toBeInTheDocument()
  await user.selectOptions(screen.getByRole('combobox', { name: 'المجموعة' }), 'office')
  await waitFor(() => expect(calls).toBe(2))
  expect(screen.queryByText('من 2026-09-26 إلى 2026-10-25')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
})
