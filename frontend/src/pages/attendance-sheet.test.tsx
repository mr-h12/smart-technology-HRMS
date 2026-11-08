import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { queryClient } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(), dismiss: vi.fn() }, Toaster: () => null }))
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
  return i18n.changeLanguage('ar')
})

const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 4 }
const blank = { source: null, version: null, site_id: null, leave_code: null, in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null }
const row = (code: number, name: string, day: object, extra: object = {}) => ({
  employee: { id: `e${code}`, code, name_ar: name, name_en: name, department: null },
  day: { ...blank, state: 'missing', ...day },
  hints: {},
  last_site_id: null,
  needs_site: false,
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
const days = [{ date: '2026-10-17', missing: 2, off: false }, { date: '2026-10-18', missing: 1, off: false }, { date: '2026-10-19', missing: 0, off: false }]
type TestRow = ReturnType<typeof row>
/** A row of the API's shape: every row names its type; a group's rows default to that group's. */
const typed = (group: string) => (r: object) => ({ ...r, employee: { type: group === 'office' ? 'office' : 'engineer', ...(r as TestRow).employee } })
const sheet = (rows: object[], group = 'engineer') => json(200, { data: { group, date: '2026-10-18', days, rows: rows.map(typed(group)) } })
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
/** Opens a table row's ⋯ menu (named by `trigger`) and picks `item` from it. */
const menu = async (trigger: string, item: string) => {
  await user.click(within(screen.getByRole('table')).getByRole('button', { name: trigger }))
  await user.click(await screen.findByRole('menuitem', { name: item }))
}

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
  expect(within(tbl).getByText('9:40 ص')).toBeInTheDocument()
})

test('missing and absent rows look different', async () => {
  mockFetch(routes)
  open()
  const tbl = await table()
  const status = (name: string) => within(within(tbl).getByText(name).closest('tr')!).getAllByRole('cell')[1] // employee (with its code), status
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

test('a card shows its period totals as one inline line: label then number, in the tone rules', async () => {
  const one = row(1, 'Engineer-1', {}, { totals: { worked: 3, leave: 0, absent: 0, missing: 2 } })
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet([one]) })
  open()
  const card = within(await screen.findByRole('list', { name: 'الموظف' })).getByRole('listitem')
  const line = card.lastElementChild as HTMLElement
  expect(line).toHaveTextContent(/في موقع\s*3.*إجازة\s*0.*غياب\s*0.*غير مسجل\s*2/)
  expect(within(line).getByText('إجمالي الفترة')).toHaveClass('sr-only')
  expect(within(line).getByText('3')).toHaveClass('text-success')
  expect(within(line).getByText('2')).toHaveClass('text-warning')
  for (const zero of within(line).getAllByText('0')) expect(zero).toHaveClass('text-muted-foreground')
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

test('the strip shows the short weekday above the date and tints the days the server marks off', async () => {
  vi.setSystemTime(new Date('2026-11-03T10:00:00Z'))
  // Saturday 31 is off from the server (a holiday, say), not from a client rule; Friday 30 is not
  const week = [{ date: '2026-10-30', missing: 0, off: false }, { date: '2026-10-31', missing: 0, off: true }, { date: '2026-11-02', missing: 1, off: false }, { date: '2026-11-03', missing: 0, off: false }]
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => json(200, { data: { group: 'engineer', date: '2026-11-02', days: week, rows: engineers } }) })
  open('/attendance?group=engineer&date=2026-11-02')
  const strip = await screen.findByRole('group', { name: 'أيام الفترة' })
  const day = (d: string) => within(strip).getByRole('button', { name: new RegExp(`^${d}`) })
  const monday = day('2026-11-02')
  expect(within(monday).getByText('إثنين').compareDocumentPosition(within(monday).getByText('2')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  expect(monday).toHaveClass('min-w-14') // «أربعاء» fits
  expect(day('2026-10-31')).toHaveClass('bg-muted-foreground/10')
  expect(day('2026-10-31')).toHaveClass('hover:bg-muted-foreground/15') // hover darkens the tint, never lightens it
  expect(day('2026-10-31')).not.toHaveClass('hover:bg-muted')
  expect(day('2026-10-30')).not.toHaveClass('bg-muted-foreground/10')
  // the selected day keeps the border and aria-current; today, not selected, only gets accent text
  expect(monday).toHaveAttribute('aria-current', 'date')
  expect(monday).toHaveClass('border-primary')
  const today = day('2026-11-03')
  expect(today).not.toHaveAttribute('aria-current')
  expect(today).toHaveClass('text-primary')
  expect(today).not.toHaveClass('border-primary')
})

test('in English the strip says Mon above the date', async () => {
  vi.setSystemTime(new Date('2026-11-03T10:00:00Z'))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => json(200, { data: { group: 'engineer', date: '2026-11-02', days: [{ date: '2026-11-02', missing: 0, off: false }], rows: engineers } }) })
  await i18n.changeLanguage('en')
  open('/attendance?group=engineer&date=2026-11-02')
  const monday = within(await screen.findByRole('group', { name: 'Period days' })).getByRole('button', { name: /^2026-11-02/ })
  expect(monday).toHaveTextContent(/^Mon2/)
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

test('the xl table has no code column: the code sits before the name in the employee cell, so the table fits 1280 in English', async () => {
  mockFetch(routes)
  edit()
  const tbl = await table()
  expect(within(tbl).queryByRole('columnheader', { name: 'الرقم الوظيفي' })).not.toBeInTheDocument()
  const name = within(tbl).getByText('Engineer-1')
  expect(name.parentElement).toHaveClass('flex-wrap') // code and name wrap apart rather than widen the column
  expect(name.previousElementSibling).toHaveTextContent('1')
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
  await user.click(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e1', version: null, site_id: 's1' }] })
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم تسجيل 1', expect.objectContaining({ action: expect.objectContaining({ label: 'تراجع' }) })))
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
  const sweep = screen.getByRole('button', { name: 'تسجيل باقي Site-1 حاضرين' })
  expect(sweep.className).not.toMatch(/(^|\s)(md:)?h-11/) // no page height class: the kit's min-h-tap is the 44 px touch target
  expect(sweep).toHaveClass('min-h-tap', 'bg-card') // an outline button: the sheet's one solid action is «mark the rest present»
  expect(screen.getByRole('button', { name: /^تسجيل الباقين حاضرين/ })).toHaveClass('bg-primary', 'text-primary-foreground')
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

test.each([
  ['a locked period', 423, 'period_locked', 'الفترة مقفلة'],
  ['a future date', 422, 'future_date', 'تاريخ مستقبلي'],
  ['a racing insert with no row', 409, 'concurrency_conflict', 'عدّل مستخدم آخر هذا السجل'],
])('%s names nobody: the toast is the plain message', async (_, status, code, message) => {
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': () => json(status, { error: { code, message } }) })
  edit()
  await table()
  await menu('إجراءات أخرى Engineer-1', 'غياب')
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith(message))
})

test('a row in flight is not sent again', async () => {
  const bulk = vi.fn(() => new Promise<Response>(() => {}) as unknown as Response) // never answers
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' }))
  await menu('إجراءات أخرى Engineer-1', 'غياب')
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
  expect(within(tbl).getByLabelText('وقت الحضور Office-1')).toHaveValue('9:40')
  const row = within(tbl).getByRole('row', { name: /Office-2/ })
  // an unrecorded row has no AM/PM chosen
  for (const b of within(row).getAllByRole('button', { name: /^(صباحًا|مساءً)$/ })) expect(b).toHaveAttribute('aria-pressed', 'false')
  const input = within(row).getByLabelText('وقت الحضور Office-2')
  await user.click(within(row).getByRole('button', { name: 'صباحًا' }))
  await user.type(input, '9:20')
  expect(bulk).not.toHaveBeenCalled() // nothing is sent while typing
  fireEvent.blur(input)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e11', version: null, site_id: null, in_time: '09:20' }] })
})

test('an office in-time in the afternoon: AM/PM from the box is sent with the time once, on Enter', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const row = within(await table()).getByRole('row', { name: /Office-2/ })
  const input = within(row).getByLabelText('وقت الحضور Office-2')
  await user.type(input, '1:15')
  await user.click(within(row).getByRole('button', { name: 'مساءً' }))
  expect(input).toHaveFocus()
  expect(bulk).not.toHaveBeenCalled()
  await user.type(input, '{Enter}')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e11', version: null, site_id: null, in_time: '13:15' }])
})

test('an office in-time by keyboard: Tab to PM, Space, Tab out sends it once, not on the way through the box', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const row = within(await table()).getByRole('row', { name: /Office-2/ })
  await user.type(within(row).getByLabelText('وقت الحضور Office-2'), '1:15')
  await user.tab()
  await user.tab()
  const pm = within(row).getByRole('button', { name: 'مساءً' })
  expect(pm).toHaveFocus()
  await user.keyboard(' ')
  expect(pm).toHaveAttribute('aria-pressed', 'true')
  expect(bulk).not.toHaveBeenCalled()
  await user.tab()
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e11', version: null, site_id: null, in_time: '13:15' }])
})

test('an office in-time: an AM/PM click alone writes nothing; it puts the cursor in the box, and leaving the box writes once', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const row = within(await table()).getByRole('row', { name: /Office-1/ })
  const input = within(row).getByLabelText('وقت الحضور Office-1')
  await user.click(within(row).getByRole('button', { name: 'مساءً' }))
  expect(input).toHaveFocus()
  expect(bulk).not.toHaveBeenCalled()
  await user.clear(input)
  await user.type(input, '1:15')
  await user.click(document.body)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e10', version: 1, site_id: null, in_time: '13:15' }])
})

test('an office in-time typed without AM/PM writes nothing, asks for the choice and puts the stored time back', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const input = within(await table()).getByLabelText('وقت الحضور Office-2')
  await user.type(input, '1:15')
  // on the sheet the hint is for screen readers only (the row does not grow); the toast says it on leaving
  expect(input).toHaveAccessibleDescription('اختر ص أو م.')
  expect(screen.getAllByText('اختر ص أو م.').every((el) => el.classList.contains('sr-only'))).toBe(true)
  fireEvent.blur(input)
  expect(input).toHaveValue('')
  expect(toast.error).toHaveBeenCalledWith('اختر ص أو م.')
  expect(bulk).not.toHaveBeenCalled()
})

test('an office in-time box and its AM/PM choice are disabled while its write is in flight', async () => {
  const bulk = vi.fn(() => new Promise<Response>(() => {}) as unknown as Response) // never answers: the write stays in flight
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const row = within(await table()).getByRole('row', { name: /Office-1/ })
  const input = within(row).getByLabelText('وقت الحضور Office-1')
  await user.clear(input)
  await user.type(input, '0905')
  fireEvent.blur(input)
  await waitFor(() => expect(input).toBeDisabled())
  expect(within(row).getByRole('button', { name: 'صباحًا' })).toBeDisabled()
  expect(within(row).getByRole('button', { name: 'مساءً' })).toBeDisabled()
  expect(bulk).toHaveBeenCalledTimes(1)
})

test('an office in-time: Escape or an unreadable time puts the stored one back and sends nothing', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const input = within(await table()).getByLabelText('وقت الحضور Office-1')
  await user.clear(input)
  await user.type(input, '10:05{Escape}')
  expect(input).toHaveValue('9:40')
  fireEvent.blur(input)
  await user.clear(input)
  await user.type(input, '99')
  fireEvent.blur(input)
  expect(input).toHaveValue('9:40')
  expect(toast.error).toHaveBeenCalledWith('وقت غير صالح.')
  expect(bulk).not.toHaveBeenCalled()
})

test('a field row without a last site has its Present button disabled with the reason; a bulk Present skips it with a toast', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  const present = within(tbl).getByRole('button', { name: 'حاضر Engineer-3' })
  expect(present).toBeDisabled()
  expect(present).toHaveAccessibleDescription('بدون موقع سابق')
  expect(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' })).toBeEnabled()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-3' }))
  await user.click(within(screen.getByRole('group', { name: 'إجراءات المحدد' })).getByRole('button', { name: 'حاضر' }))
  expect(toast.warning).toHaveBeenCalledWith('تم تخطي 1 بدون موقع سابق؛ سجّلهم من التفاصيل.')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 's1' }])
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
    expect(screen.queryByRole('button', { name: /^(حاضر|غياب|مرضي) \D/ })).not.toBeInTheDocument() // a row's action, not a KPI tile («غياب 1»)
    expect(screen.queryByRole('button', { name: /^تسجيل (الباقين|باقي)/ })).not.toBeInTheDocument()
    expect(within(tbl).queryByRole('textbox', { name: /^وقت الحضور/ })).toBeNull()
    expect(container.querySelector('[aria-pressed][aria-label="صباحًا"]')).toBeNull()
    expect(screen.queryByRole('button', { name: 'لصق أرقام' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^تراجع/ })).not.toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })).toBeInTheDocument() // a reader can still search
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
  expect(screen.getByRole('button', { name: 'الفترة السابقة' }).nextElementSibling).toHaveTextContent('من 2026-09-26 إلى 2026-10-25')
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
  expect(screen.queryByText('2026-09-26')).not.toBeInTheDocument() // the range's start date
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'الفترة السابقة' })).toBeDisabled()
  expect(screen.queryByText(/أيام غير مسجلة:/)).not.toBeInTheDocument() // the header line waits for the real period
})

test('a group change does not show the old group\'s period', async () => {
  let calls = 0
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => (calls++ ? (new Promise(() => {}) as unknown as Response) : json(200, { data: period })) })
  renderWithAuth(<AttendancePage />, [...EDIT, 'attendance.lock.all'], { path: '/attendance?group=engineer' })
  expect(await screen.findByRole('button', { name: 'قفل الفترة' })).toBeInTheDocument()
  await user.selectOptions(screen.getByRole('combobox', { name: 'المجموعة' }), 'office')
  await waitFor(() => expect(calls).toBe(2))
  expect(screen.queryByText('2026-09-26')).not.toBeInTheDocument() // the range's start date
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
})

const key = (code: string, init: object = {}) => fireEvent.keyDown(document, { code, key: code.replace(/^Key/, '').toLowerCase(), ...init })
const rowOf = (name: string) => within(screen.getByRole('table')).getByText(name).closest('tr')!
const undoButton = () => screen.getByRole('button', { name: /^تراجع/ })
const crew = Array.from({ length: 5 }, (_, i) => row(i + 1, `Engineer-${i + 1}`, {}, { last_site_id: 's1' }))
const search = () => screen.getByRole('textbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })

test('the search filters the rows as you type, folding Arabic spellings, and says how many it found', async () => {
  const rows = [row(1, 'أحمد علي', {}), row(2, 'Omar Said', {}), row(3, 'فاطمة حسن', {})]
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(rows) })
  open()
  await table()
  expect(screen.queryByText('1 من 3')).not.toBeInTheDocument()
  await user.type(search(), 'احمد')
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(2)
  expect(within(screen.getByRole('table')).getByText('أحمد علي')).toBeInTheDocument()
  expect(screen.getByText('1 من 3')).toHaveAttribute('aria-live', 'polite')
  await user.clear(search())
  await user.type(search(), 'فاطمه')
  expect(within(screen.getByRole('table')).getByText('فاطمة حسن')).toBeInTheDocument()
  await user.clear(search())
  await user.type(search(), 'OMAR')
  expect(within(screen.getByRole('table')).getByText('Omar Said')).toBeInTheDocument()
  await user.clear(search())
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(4)
  expect(screen.queryByText(/^\d+ من 3$/)).not.toBeInTheDocument()
})

test('Enter in the search sends nothing and selects nothing', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(crew), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.type(search(), '2-4{Enter}')
  await user.clear(search())
  await user.type(search(), 'Engineer{Enter}')
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(6)
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).not.toHaveBeenCalled()
})

test('typing a search clears the selection, so a bulk action never writes hidden rows', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-2' }))
  await user.type(search(), 'Engineer-2')
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  await user.clear(search())
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
  expect(within(screen.getByRole('table')).getByRole('checkbox', { name: 'تحديد Engineer-1' })).not.toBeChecked()
  expect(bulk).not.toHaveBeenCalled()
})

test('P, J, / and Ctrl+Z do nothing', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  const keys: [string, object?][] = [['KeyJ'], ['KeyP'], ['KeyA'], ['Slash', { key: '/' }], ['KeyZ', { ctrlKey: true }], ['KeyZ', { metaKey: true }]]
  for (const [code, init] of keys) expect(key(code, init)).toBe(true) // not prevented: the browser keeps them
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).toHaveBeenCalledTimes(1)
  expect(undoButton()).toHaveTextContent('تراجع (1)')
  expect(search()).not.toHaveFocus()
  expect(document.querySelector('[aria-current="true"]')).toBeNull()
})

test('the bulk bar sits after the rows card, under no overflow box, and sticks to the bottom', async () => {
  mockFetch(routes)
  edit()
  await user.click(within(await table()).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveClass('sticky', 'bottom-0')
  for (let el = bar.parentElement; el; el = el.parentElement) expect(el.className).not.toMatch(/overflow-/) // sticky stops under any overflow box
  expect(bar.previousElementSibling).toContainElement(screen.getByRole('table'))
})

test('the paste dialog applies Absent to the pasted list and reports unknown IDs', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
  await user.type(within(dialog).getByRole('textbox', { name: 'الأرقام الوظيفية' }), '1{Enter}2{Enter}99')
  expect(within(dialog).getByRole('status')).toHaveTextContent('2 مطابق')
  expect(within(dialog).getByText('2 مطابق')).toHaveClass('rounded-md', 'bg-primary/10') // a kit Badge
  expect(within(dialog).getByText('غير موجود في الكشف: 99')).toHaveClass('bg-warning/10', 'whitespace-normal') // a long ID list wraps at 375 px
  // one solid action, the rest ghost
  expect(within(dialog).getByRole('button', { name: 'حاضر' })).toHaveClass('bg-primary')
  for (const b of ['غياب', 'مرضي']) {
    expect(within(dialog).getByRole('button', { name: b })).not.toHaveClass('border')
    expect(within(dialog).getByRole('button', { name: b })).not.toHaveClass('bg-primary')
  }
  await user.click(within(dialog).getByRole('button', { name: 'غياب' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 'sa' }, { employee_id: 'e2', version: 1, site_id: 'sa' }])
  await waitFor(() => expect(toast.warning).toHaveBeenCalledWith('غير موجود في الكشف: 99'))
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

/** Opens the paste dialog on a sheet of `rows`, types `ids` and returns the dialog. */
const pasteIds = async (rows: object[], ids: string, [button, title, box] = ['لصق أرقام', 'لصق الأرقام الوظيفية', 'الأرقام الوظيفية']) => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(rows) })
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: button }))
  const dialog = screen.getByRole('dialog', { name: title })
  await user.type(within(dialog).getByRole('textbox', { name: box }), ids)
  return dialog
}
const names = (dialog: HTMLElement, label = 'الموظفون الذين سيتم تسجيلهم') =>
  within(within(dialog).getByRole('list', { name: label })).getAllByRole('listitem').map((li) => li.textContent)

test('the paste preview names every match (ID and name) before applying, outside the live region', async () => {
  const dialog = await pasteIds(engineers, '1 2 99')
  expect(names(dialog)).toEqual(['1 Engineer-1', '2 Engineer-2'])
  expect(within(dialog).getByRole('status')).not.toContainElement(within(dialog).getByRole('list')) // 500 names are not re-announced per keystroke
  expect(within(dialog).getByRole('list')).toHaveClass('max-h-40', 'overflow-y-auto') // a long batch scrolls, the dialog stays on screen
})

test('a stale ID in the paste preview shows the employee who holds it now', async () => {
  // HR moved Previous-Holder from 7 to 8 and gave 7 to Current-Holder: the sheet carries today's codes
  const dialog = await pasteIds([row(7, 'Current-Holder', {}), row(8, 'Previous-Holder', {})], '7')
  expect(names(dialog)).toEqual(['7 Current-Holder'])
})

test('in English the paste preview shows the English name, else the Arabic one', async () => {
  await i18n.changeLanguage('en')
  const rows = [
    { ...row(1, 'x', {}), employee: { id: 'e1', code: 1, name_ar: 'مهندس واحد', name_en: 'Engineer One', department: null } },
    { ...row(2, 'x', {}), employee: { id: 'e2', code: 2, name_ar: 'مهندس اثنان', name_en: null, department: null } },
  ]
  const dialog = await pasteIds(rows, '1 2', ['Paste IDs', 'Paste employee IDs', 'Employee IDs'])
  expect(names(dialog, 'Employees to be marked')).toEqual(['1 Engineer One', '2 مهندس اثنان'])
})

test('the paste dialog never writes a name search', async () => {
  mockFetch(routes)
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
  await user.type(within(dialog).getByRole('textbox', { name: 'الأرقام الوظيفية' }), 'Engineer')
  for (const b of ['حاضر', 'غياب', 'مرضي']) expect(within(dialog).getByRole('button', { name: b })).toBeDisabled()
})

test('the paste undo label is the translated action count', async () => {
  i18n.addResource('ar', 'translation', 'attendance.sheet.actionCount', '{{action}} × {{count}}')
  try {
    mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulkOk() })
    edit()
    await table()
    await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
    const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
    await user.type(within(dialog).getByRole('textbox', { name: 'الأرقام الوظيفية' }), '1 2')
    await user.click(within(dialog).getByRole('button', { name: 'غياب' }))
    await waitFor(() => expect(screen.getByRole('button', { name: /^تراجع/ })).toHaveTextContent('تراجع (1)'))
    await user.click(screen.getByRole('button', { name: /^تراجع/ }))
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم التراجع: غياب × 2'))
  } finally {
    i18n.addResource('ar', 'translation', 'attendance.sheet.actionCount', '{{action}} ({{count}})')
  }
})

test('paste applies to every pasted ID on the sheet, whatever the filter', async () => {
  const bulk = bulkOk()
  const rows = crew.map((r, i) => (i < 3 ? r : { ...r, last_site_id: 's2' }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(rows), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(within(screen.getByRole('group', { name: 'حسب آخر موقع' })).getByRole('button', { name: /^Site-1/ }))
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
  await user.type(within(dialog).getByRole('textbox', { name: 'الأرقام الوظيفية' }), '1 4 5')
  await user.click(within(dialog).getByRole('button', { name: 'غياب' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows.map((r: { employee_id: string }) => r.employee_id)).toEqual(['e1', 'e4', 'e5'])
})

test('an action that changes nothing says so and sends nothing', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-2' })) // already at Site-1
  expect(toast.info).toHaveBeenCalledWith('لا يوجد ما يتغير')
  expect(bulk).not.toHaveBeenCalled()
})

test('an editable card with no last site shows the «اختر موقعًا» chip and no muted no-last-site line; Present keeps the reason for screen readers', async () => {
  mockFetch(routes)
  edit()
  await table()
  const card = within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-3').closest('li')!
  const present = within(card).getByRole('button', { name: 'حاضر Engineer-3' })
  expect(present).toBeDisabled()
  expect(present).toHaveAccessibleDescription('بدون موقع سابق')
  expect(within(card).getByRole('button', { name: 'تعيين موقع: اختر موقعًا، Engineer-3' })).toBeInTheDocument()
  expect(within(card).queryByText('بدون موقع سابق')).not.toBeInTheDocument()
})

test('Undo restores the previous day', async () => {
  const bulk = vi.fn((_url: string, init?: RequestInit) => json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: bulk.mock.calls.length === 1 ? 1 : null })) }))
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await user.click(undoButton())
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(2))
  expect(sent(bulk, 1)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e1', clear: true, version: 1 }] })
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم التراجع: حاضر Engineer-1'))
  expect(undoButton()).toHaveTextContent('تراجع (0)')
  expect(undoButton()).toHaveAttribute('aria-disabled', 'true')
})

test('undo rewrites a stored day in full, from the button too', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await menu('إجراءات أخرى Engineer-2', 'غياب')
  await waitFor(() => expect(undoButton()).toHaveAttribute('aria-disabled', 'false'))
  await user.click(undoButton())
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(2))
  expect(sent(bulk, 1).rows).toEqual([{ employee_id: 'e2', version: 9, site_id: 's1', in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null }])
})

test('an undo refused with 409 is dropped with a message', async () => {
  const bulk = vi.fn((_url: string, init?: RequestInit) => (bulk.mock.calls.length === 1
    ? json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: 1 })) })
    : json(409, { error: { code: 'concurrency_conflict', message: 'stale', details: [{ field: 'rows.0', code: 'concurrency_conflict', message: 'stale' }] } })))
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveAttribute('aria-disabled', 'false'))
  await user.click(undoButton())
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('تغيّر يوم Engineer-1 بعد ذلك؛ تعذّر التراجع وحُذف من القائمة.'))
  expect(undoButton()).toHaveTextContent('تراجع (0)')
  expect(undoButton()).toHaveAttribute('aria-disabled', 'true')
})

test('the undo stack keeps the last 15', async () => {
  const bulk = bulkOk()
  const many = Array.from({ length: 16 }, (_, i) => row(i + 1, `Engineer-${i + 1}`, {}, { last_site_id: 's1' }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(many), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  for (let i = 1; i <= 16; i++) {
    await user.click(within(screen.getByRole('table')).getByRole('button', { name: `حاضر Engineer-${i}` }))
    await waitFor(() => expect(undoButton()).toHaveTextContent(`تراجع (${Math.min(i, 15)})`))
  }
  for (let n = 15; n > 0; n--) {
    await user.click(undoButton())
    await waitFor(() => expect(undoButton()).toHaveTextContent(`تراجع (${n - 1})`))
  }
  const undone = bulk.mock.calls.slice(16).map((_, i) => sent(bulk, 16 + i).rows[0].employee_id)
  expect(undone).toEqual(Array.from({ length: 15 }, (_, i) => `e${16 - i}`)) // newest first; e1 was dropped
})

/**
 * A tiny server for Engineer-1 that refuses a stale version, like the endpoint. Versions are per day: 2026-10-17 starts stored
 * at v1, other days have no record. `meddle` has someone else edit the row right after our first write.
 */
function oneRow(meddle = false) {
  const days = new Map<string, { cur: number | null; n: number; day: object }>()
  const at = (date: string) => days.get(date) ?? days.set(date, { cur: null, n: 0, day: {} }).get(date)!
  const store = (d: ReturnType<typeof at>, site: string | null) => { d.cur = ++d.n; d.day = { state: 'stored', source: 'entered', version: d.cur, site_id: site, leave_code: site === 'sa' ? 'absent' : null } }
  store(at('2026-10-17'), 's1')
  const bulk = vi.fn((_url: string, init?: RequestInit) => {
    const { date, rows: [r] } = JSON.parse(String(init?.body))
    const d = at(date)
    if (r.version !== d.cur) return json(409, { error: { code: 'concurrency_conflict', message: 'stale', details: [{ field: 'rows.0', code: 'concurrency_conflict', message: 'stale' }] } })
    if (r.clear) { d.cur = null; d.day = {} } else store(d, r.site_id)
    const version = d.cur
    if (meddle && bulk.mock.calls.length === 1) store(d, 's1')
    return json(200, { data: [{ employee_id: r.employee_id, version }] })
  })
  const get = (url: string) => sheet([row(1, 'Engineer-1', at(new URL(url, 'http://x').searchParams.get('date')!).day, { last_site_id: 's1' }), engineers[1]])
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': get, 'POST /api/v1/attendance/days/bulk': bulk })
  return bulk
}
const presentThenAbsent = async () => {
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await menu('إجراءات أخرى Engineer-1', 'غياب')
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (2)'))
  await user.click(undoButton())
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await user.click(undoButton())
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (0)'))
}

test('two undos on the same row each send the version the last write returned', async () => {
  const bulk = oneRow()
  await presentThenAbsent()
  expect(sent(bulk, 3).rows).toEqual([{ employee_id: 'e1', clear: true, version: 3 }])
  expect(toast.error).not.toHaveBeenCalled()
})

test('an older undo still conflicts when someone else changed the row between the two writes', async () => {
  const bulk = oneRow(true)
  await presentThenAbsent()
  expect(sent(bulk, 3).rows).toEqual([{ employee_id: 'e1', clear: true, version: 1 }]) // not moved onto the other user's change
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('تغيّر يوم Engineer-1 بعد ذلك؛ تعذّر التراجع وحُذف من القائمة.'))
})

test('an undo on one day never rebases the same employee\'s entry for another day', async () => {
  const bulk = oneRow()
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' })) // 2026-10-18: a new row at v1
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await user.click(within(screen.getByRole('group', { name: 'أيام الفترة' })).getByRole('button', { name: /^2026-10-17/ }))
  await table()
  await menu('إجراءات أخرى Engineer-1', 'غياب') // 2026-10-17: v1 → v2
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (2)'))
  await user.click(undoButton()) // 2026-10-17 back to v1's day, now v3
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await user.click(undoButton())
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (0)'))
  expect(sent(bulk, 3)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e1', clear: true, version: 1 }] })
  expect(toast.error).not.toHaveBeenCalled()
})

test('Undo does nothing while a write is in flight', async () => {
  const bulk = vi.fn((_url: string, init?: RequestInit) => (bulk.mock.calls.length === 1
    ? json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: 1 })) })
    : (new Promise(() => {}) as unknown as Response))) // the second write never answers
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await menu('إجراءات أخرى Engineer-2', 'غياب')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(2))
  expect(undoButton()).toHaveAttribute('aria-disabled', 'true')
  await user.click(undoButton())
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).toHaveBeenCalledTimes(2) // the Engineer-1 entry is not undone under the pending write
  expect(undoButton()).toHaveTextContent('تراجع (1)')
})

test('in English the site chips, cohorts, last site and toasts use the English names', async () => {
  const en = (r: ReturnType<typeof row>, n: number) => ({ ...r, employee: { ...r.employee, name_ar: `مهندس-${n}`, name_en: `Engineer-${n} EN` } })
  const bulk = vi.fn(() => json(409, { error: { code: 'concurrency_conflict', message: 'stale', details: [{ field: 'rows.0', code: 'concurrency_conflict', message: 'stale' }] } }))
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/sheet': () => sheet([en(engineers[0], 1), en(engineers[1], 2)]),
    'GET /api/v1/sites': () => json(200, { data: [{ ...sites[0], name_ar: 'موقع-1', name_en: 'Site-1 EN' }, sites[1]], meta: { pagination } }),
    'POST /api/v1/attendance/days/bulk': bulk,
  })
  await i18n.changeLanguage('en')
  edit()
  const tbl = await table()
  expect(within(tbl).getByRole('button', { name: 'Set site: Site-1 EN, Engineer-2 EN' })).toBeInTheDocument() // Engineer-2's site today
  expect(within(tbl).getByRole('button', { name: 'Set site: Site-1 EN, Engineer-1 EN' })).toBeInTheDocument() // Engineer-1's last site
  expect(within(screen.getByRole('group', { name: 'By last site' })).getByRole('button', { name: /^Site-1 EN/ })).toBeInTheDocument()
  await menu('More actions: Engineer-1 EN', 'Absent')
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Someone else just changed Engineer-1 EN; the sheet was reloaded. Check it and try again.'))
})

test('a leave day with an unknown code is named from its leave type, in Arabic and English', async () => {
  const custom = [...sites, { id: 'su', name_ar: 'إجازة-1', name_en: 'Leave-1 EN', kind: 'leave', leave_code: 'unpaid', active: true }]
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/sheet': () => sheet([row(4, 'Engineer-4', { state: 'stored', source: 'entered', version: 1, site_id: 'su', leave_code: 'unpaid' })]),
    'GET /api/v1/sites': () => json(200, { data: custom, meta: { pagination } }),
  })
  const { unmount } = open()
  expect(await within(await table()).findByText('إجازة-1')).toBeInTheDocument()
  unmount()
  await i18n.changeLanguage('en')
  open()
  expect(await within(await table()).findByText('Leave-1 EN')).toBeInTheDocument()
})

test('a failed refetch keeps the sheet on screen under the error', async () => {
  let calls = 0
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => (calls++ ? json(500, { error: { code: 'server_error', message: 'Server down' } }) : sheet(engineers)), 'POST /api/v1/attendance/days/bulk': bulkOk() })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Server down')
  expect(within(screen.getByRole('table')).getByText('Engineer-2')).toBeInTheDocument()
})

test('the Total card clears the cohort filter too', async () => {
  mockFetch(routes)
  open()
  await table()
  const cohorts = screen.getByRole('group', { name: 'حسب آخر موقع' })
  await user.click(within(cohorts).getByRole('button', { name: /^Site-1/ }))
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(3) // header + Engineer-1, Engineer-2
  await user.click(screen.getByRole('button', { name: /^الإجمالي/ }))
  expect(within(cohorts).getByRole('button', { name: /^Site-1/ })).toHaveAttribute('aria-pressed', 'false')
  expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(4)
})

test('an undo of another day names that day in its toast', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await user.click(within(screen.getByRole('group', { name: 'أيام الفترة' })).getByRole('button', { name: /^2026-10-17/ }))
  await table()
  await user.click(undoButton())
  await waitFor(() => expect(toast.success).toHaveBeenCalledTimes(2))
  const { container } = render(<>{vi.mocked(toast.success).mock.calls[1][0]}</>)
  expect(container).toHaveTextContent('تم التراجع: حاضر Engineer-1 (2026-10-18)')
  expect(within(container).getByText('2026-10-18')).toHaveAttribute('dir', 'ltr') // an Arabic name before it would otherwise flip the date in RTL
})

test('a refused row names its employee with the reason', async () => {
  const bulk = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'rows.0.site_id', code: 'site_inactive', message: 'هذا الموقع غير نشط.' }] } }))
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Engineer-1: هذا الموقع غير نشط.'))
})

test('Enter on Undo keeps focus on the button while the undo is in flight', async () => {
  const bulk = vi.fn((_url: string, init?: RequestInit) => (bulk.mock.calls.length === 1
    ? json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: 1 })) })
    : (new Promise(() => {}) as unknown as Response))) // the undo never answers
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  undoButton().focus()
  await user.keyboard('{Enter}')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(2))
  // a native `disabled` is what drops focus to <body> in a browser; jsdom keeps it, so the attribute itself is checked
  expect(undoButton()).not.toBeDisabled()
  expect(undoButton()).toHaveAttribute('aria-disabled', 'true')
  expect(document.activeElement).toBe(undoButton())
})

// Gate 2b defects
test('the paste box takes its direction from what is typed, so RTL does not draw 1-3 as 3-1', async () => {
  mockFetch(routes)
  edit()
  await table()
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  expect(within(screen.getByRole('dialog')).getByRole('textbox', { name: 'الأرقام الوظيفية' })).toHaveAttribute('dir', 'auto')
})

test('below xl both groups get the cards; the table shows only from xl', async () => {
  for (const [rows, group] of [[engineers, 'engineer'], [office, 'office']] as const) {
    mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(rows, group) })
    const { unmount } = open(`/attendance?group=${group}`)
    expect((await table()).closest('.hidden')).toHaveClass('xl:block')
    expect(screen.getByRole('list', { name: 'الموظف' })).toHaveClass('xl:hidden')
    unmount()
  }
})

test('a cohort chip grows to its full label and carries the full name as its title', async () => {
  mockFetch(routes)
  open()
  await table()
  const cohorts = screen.getByRole('group', { name: 'حسب آخر موقع' })
  expect(cohorts).toHaveClass('flex', 'flex-wrap') // not a fixed column grid that cuts the label at 1024
  expect(within(cohorts).getByText('بدون موقع سابق')).toHaveAttribute('title', 'بدون موقع سابق')
  expect(within(cohorts).getByText('Site-1')).toHaveAttribute('title', 'Site-1')
})

test('a selected card carries the same selected tint as its row, and the row tint spans the totals cell', async () => {
  mockFetch(routes)
  edit()
  const tbl = await table()
  await user.click(within(tbl).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  const card = within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-1').closest('li')!
  const tr = rowOf('Engineer-1')
  expect(card).toHaveClass('bg-accent')
  expect(tr).toHaveClass('bg-accent', 'hover:bg-accent') // the row's own hover fill must not hide the tint under the pointer that just ticked it
  expect(within(tr).getAllByRole('cell').at(-1)).not.toHaveClass('bg-muted/40')
  expect(within(rowOf('Engineer-2')).getAllByRole('cell').at(-1)).toHaveClass('bg-muted/40')
  expect(within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-2').closest('li')).not.toHaveClass('bg-accent')
})

test('office totals say present, not at a site', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  open('/attendance?group=office')
  const [, ...rows] = within(await table()).getAllByRole('row')
  for (const r of rows) {
    const totals = within(r).getAllByRole('cell').at(-1)!
    expect(within(totals).getByText('حاضر')).toBeInTheDocument()
    expect(totals).not.toHaveTextContent('في موقع')
  }
  const cards = within(screen.getByRole('list', { name: 'الموظف' })).getAllByRole('listitem')
  for (const c of cards) expect(c.lastElementChild).not.toHaveTextContent('في موقع')
})

// Gate 2b, D3 round 2: the table fits its card at every width where it shows
test('below 2xl the table Present is an icon named by its tooltip; the label comes back at 2xl; ⋯ is always an icon with a tooltip', async () => {
  mockFetch(routes)
  edit()
  const tr = within(await table()).getByText('Engineer-1').closest('tr')!
  const present = within(tr).getByRole('button', { name: 'حاضر Engineer-1' })
  expect(present).toHaveAttribute('title', 'حاضر')
  expect(within(present).getByText('حاضر')).toHaveClass('hidden', '2xl:inline')
  expect(within(tr).getByRole('button', { name: 'إجراءات أخرى Engineer-1' })).toHaveAttribute('title', 'إجراءات أخرى')
  // a card keeps its labelled Present and no tooltip
  const card = within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-1').closest('li')!
  expect(within(card).getByRole('button', { name: 'حاضر Engineer-1' })).not.toHaveAttribute('title')
  expect(within(within(card).getByRole('button', { name: 'حاضر Engineer-1' })).getByText('حاضر')).not.toHaveClass('hidden')
})

test('a disabled Present keeps the no-last-site reason as its tooltip in the table', async () => {
  mockFetch(routes)
  edit()
  const tr = within(await table()).getByText('Engineer-3').closest('tr')!
  expect(within(tr).getByRole('button', { name: 'حاضر Engineer-3' })).toHaveAttribute('title', 'بدون موقع سابق')
})

test('the table totals sit in two content-sized columns, so their labels never overlap', async () => {
  mockFetch(routes)
  open()
  const cell = within(await table()).getByText('Engineer-1').closest('tr')!.lastElementChild!
  expect(cell.firstElementChild).toHaveClass('grid-cols-[auto_auto]', 'w-max') // w-max: the table cell must reserve the grid's full width (1280 en cut "Missing" by 2 px)
  expect(cell.firstElementChild).not.toHaveClass('grid-cols-4')
  expect(cell).toHaveTextContent(/12.*4.*1.*2/)
})

test('a long employee name wraps in the table instead of widening it', async () => {
  mockFetch(routes)
  open()
  expect(within(await table()).getByText('Engineer-1').closest('td')).toHaveClass('whitespace-normal')
})

// Gate 2b, round 3
test('the cards have their own select-page box: it selects and clears the page and shows a partial pick', async () => {
  mockFetch(routes)
  edit()
  await table()
  const list = screen.getByRole('list', { name: 'الموظف' })
  const all = screen.getAllByRole('checkbox', { name: 'تحديد كل الصفحة' }).find((el) => !el.closest('table'))
  expect(all).toBeDefined()
  expect(all!.closest('label')).toHaveClass('xl:hidden', 'min-h-tap') // a touch-height line, hidden where the table header has its own
  await user.click(within(list).getAllByRole('checkbox')[0])
  expect(all).toHaveProperty('indeterminate', true)
  await user.click(all!)
  expect(all).toBeChecked()
  for (const box of within(list).getAllByRole('checkbox')) expect(box).toBeChecked()
  await user.click(all!)
  for (const box of within(list).getAllByRole('checkbox')) expect(box).not.toBeChecked()
})

test('a reader gets no select-page box on the cards', async () => {
  mockFetch(routes)
  open()
  await table()
  expect(screen.queryByRole('checkbox', { name: 'تحديد كل الصفحة' })).not.toBeInTheDocument()
})


// S2b: row buttons (owner decision 3)
test('a row keeps Present and puts Absent, Sick and Details in its ⋯ menu', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tr = within(await table()).getByText('Engineer-1').closest('tr')!
  expect(within(tr).queryByRole('button', { name: /^(غياب|مرضي|التفاصيل) / })).not.toBeInTheDocument()
  await user.click(within(tr).getByRole('button', { name: 'إجراءات أخرى Engineer-1' }))
  const items = await screen.findAllByRole('menuitem')
  expect(items.map((i) => i.textContent)).toEqual(['غياب', 'مرضي', 'التفاصيل'])
  expect(items[1]).toHaveAttribute('aria-disabled', 'true') // no active sick leave type
  await user.click(items[0])
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 'sa' }])
})

test('Details in the ⋯ menu opens the day editor', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/days/e1/2026-10-18': () => json(200, { data: { ...blank, state: 'missing', date: '2026-10-18', employee_id: 'e1', type: 'engineer',
    fields: ['in_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  edit()
  await table()
  await menu('إجراءات أخرى Engineer-1', 'التفاصيل')
  expect(await screen.findByRole('dialog', { name: 'يوم 2026-10-18 — Engineer-1' })).toBeInTheDocument()
})

test('Present looks pressed on a row already present, like a badge', async () => {
  mockFetch(routes)
  edit()
  const tbl = await table()
  const on = within(tbl).getByRole('button', { name: 'حاضر Engineer-2' })
  expect(on).toHaveAttribute('aria-pressed', 'true')
  expect(within(tbl).getByRole('button', { name: 'حاضر Engineer-1' })).toHaveAttribute('aria-pressed', 'false')
})

test('the bulk bar and the paste dialog still offer Present, Absent and Sick', async () => {
  mockFetch(routes)
  edit()
  await user.click(within(await table()).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  for (const b of ['حاضر', 'غياب', 'مرضي']) expect(within(bar).getByRole('button', { name: b })).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
  for (const b of ['حاضر', 'غياب', 'مرضي']) expect(within(dialog).getByRole('button', { name: b })).toBeInTheDocument()
})

// S2b: times on the rows
const timed = row(2, 'Engineer-2', { state: 'stored', source: 'entered', version: 1, site_id: 's1', in_time: '08:45:00', out_time: '18:00:00' }, { last_site_id: 's1', hints: { late_minutes: 45, ot_minutes: 75 } })
const timedRoutes = { ...routes, 'GET /api/v1/attendance/sheet': () => sheet([engineers[0], timed, engineers[2]]) }

test('a present field row shows its arrival and departure with the late and OT hints; other rows have no time boxes', async () => {
  mockFetch(timedRoutes)
  edit()
  const tbl = await table()
  const heads = within(tbl).getAllByRole('columnheader').map((h) => h.textContent)
  expect(heads).toEqual(expect.arrayContaining(['وقت الحضور', 'وقت الانصراف']))
  const tr = rowOf('Engineer-2')
  expect(within(tr).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })).toHaveValue('8:45')
  expect(within(tr).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })).toHaveValue('6:00')
  expect(within(tr).getByText('متأخر 45 د')).toHaveClass('text-warning')
  expect(within(tr).getByText('إضافي 75 د')).toHaveClass('text-warning')
  expect(within(rowOf('Engineer-1')).queryByRole('textbox')).not.toBeInTheDocument()
  expect(within(rowOf('Engineer-3')).queryByRole('textbox')).not.toBeInTheDocument()
})

test('a field row\'s departure edit posts out_time with its site_id', async () => {
  const bulk = bulkOk()
  mockFetch({ ...timedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  const tr = rowOf('Engineer-2')
  const out = within(tr).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })
  await user.clear(out)
  await user.type(out, '3:00{Enter}')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk)).toEqual({ date: '2026-10-18', rows: [{ employee_id: 'e2', version: 1, site_id: 's1', out_time: '15:00' }] })
})

test('the time pair is one unit: Tab from arrival to departure and typing there sends nothing until focus leaves the pair', async () => {
  const bulk = bulkOk()
  mockFetch({ ...timedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  const tr = rowOf('Engineer-2')
  const arrival = within(tr).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })
  await user.clear(arrival)
  await user.type(arrival, '0900')
  await user.tab()
  await user.tab()
  await user.tab()
  const departure = within(tr).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })
  expect(departure).toHaveFocus()
  await user.clear(departure)
  await user.type(departure, '5:00')
  await user.click(within(tr).getAllByRole('button', { name: 'مساءً' })[1]) // departure's PM: still inside the pair
  expect(bulk).not.toHaveBeenCalled()
  await user.click(document.body)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).toHaveBeenCalledTimes(1)
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e2', version: 1, site_id: 's1', in_time: '09:00', out_time: '17:00' }])
})

test('focus selects the time, so typing 845 replaces 8:00, even after an AM/PM click', async () => {
  const bulk = bulkOk()
  const day = { state: 'stored', source: 'entered', version: 1, site_id: 's1', in_time: '08:00:00', out_time: '17:00:00' }
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet([row(2, 'Engineer-2', day, { last_site_id: 's1' })]), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  const tr = rowOf('Engineer-2')
  const arrival = within(tr).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })
  await user.click(arrival)
  await user.click(within(tr).getAllByRole('button', { name: 'صباحًا' })[0]) // its mousedown keeps the cursor, and the selection, in the box
  await user.keyboard('845')
  expect(arrival).toHaveValue('845')
  await user.click(document.body)
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e2', version: 1, site_id: 's1', in_time: '08:45' }])
})

test('10:30 with no AM/PM is never written as 10:30 AM', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office'), 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=office')
  const input = within(await table()).getByLabelText('وقت الحضور Office-2')
  await user.type(input, '10:30')
  fireEvent.blur(input)
  expect(toast.error).toHaveBeenCalledWith('اختر ص أو م.')
  expect(input).toHaveValue('')
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).not.toHaveBeenCalled()
})

test('office rows keep the in-time only', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  edit('/attendance?group=office')
  const tbl = await table()
  expect(within(tbl).getAllByRole('textbox', { name: /^وقت الحضور/ })).toHaveLength(2)
  expect(within(tbl).queryByRole('textbox', { name: /^وقت الانصراف/ })).not.toBeInTheDocument()
  expect(within(tbl).queryByRole('columnheader', { name: 'وقت الانصراف' })).not.toBeInTheDocument()
})

test('on a card the two time boxes stack below sm and sit side by side from sm', async () => {
  mockFetch(timedRoutes)
  edit()
  await table()
  const card = within(screen.getByRole('list', { name: 'الموظف' })).getByText('Engineer-2').closest('li')!
  const departure = within(card).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })
  const pair = departure.closest('.grid.sm\\:grid-cols-2')
  expect(pair).toContainElement(within(card).getByRole('textbox', { name: 'وقت الحضور Engineer-2' }))
  expect(card.querySelector('.w-44')).toBeNull()
})

test('a locked sheet shows the times as plain text', async () => {
  mockFetch({ ...timedRoutes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true } }) })
  edit()
  await table()
  const tr = rowOf('Engineer-2')
  expect(within(tr).queryByRole('textbox')).not.toBeInTheDocument()
  expect(within(tr).getByText('8:45 ص')).toBeInTheDocument()
  expect(within(tr).getByText('6:00 م')).toBeInTheDocument()
})

// S2b: the site chip
const chipSites = [...sites, { id: 's2', name_ar: 'Site-2', name_en: 'Site-2', kind: 'work', leave_code: null, active: true }, { id: 's3', name_ar: 'Site-3', name_en: 'Site-3', kind: 'work', leave_code: null, active: false }]
const chipRoutes = { ...routes, 'GET /api/v1/sites': () => json(200, { data: chipSites, meta: { pagination } }) }

test('the site chip shows the day\'s site, else the last site, else «اختر موقعًا»', async () => {
  mockFetch(chipRoutes)
  edit()
  const tbl = await table()
  expect(within(rowOf('Engineer-2')).getByRole('button', { name: 'تعيين موقع: Site-1، Engineer-2' })).toHaveTextContent('Site-1')
  expect(within(rowOf('Engineer-1')).getByRole('button', { name: 'تعيين موقع: Site-1، Engineer-1' })).toHaveTextContent('Site-1')
  expect(within(rowOf('Engineer-3')).getByRole('button', { name: 'تعيين موقع: اختر موقعًا، Engineer-3' })).toHaveTextContent('اختر موقعًا')
  expect(within(tbl).queryByText(/^آخر موقع/)).not.toBeInTheDocument()
})

test('the site chip lists the active work sites and posts the picked one', async () => {
  const bulk = bulkOk()
  mockFetch({ ...chipRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(within(rowOf('Engineer-1')).getByRole('button', { name: 'تعيين موقع: Site-1، Engineer-1' }))
  const items = await screen.findAllByRole('menuitemradio')
  expect(items.map((i) => i.textContent)).toEqual(['Site-1', 'Site-2'])
  await user.click(items[1])
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: null, site_id: 's2' }])
})

test('the site chip checks the day\'s site on a present row', async () => {
  mockFetch(chipRoutes)
  edit()
  await table()
  await user.click(within(rowOf('Engineer-2')).getByRole('button', { name: 'تعيين موقع: Site-1، Engineer-2' }))
  expect(await screen.findByRole('menuitemradio', { name: 'Site-1' })).toHaveAttribute('aria-checked', 'true')
})

test('a locked sheet shows the site as plain text, no chip', async () => {
  mockFetch({ ...chipRoutes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true } }) })
  open()
  await table()
  expect(within(rowOf('Engineer-2')).getByText('Site-1')).toBeInTheDocument()
  expect(within(rowOf('Engineer-2')).queryByRole('button', { name: /Site-1/ })).not.toBeInTheDocument()
})

// S2b: the times dialog
const at = (code: number, inT: string, outT: string) => row(code, `Engineer-${code}`, { state: 'stored', source: 'entered', version: code, site_id: 's1', in_time: inT, out_time: outT }, { last_site_id: 's1' })
const crewTimes = [at(1, '08:00:00', '17:00:00'), at(2, '09:00:00', '17:00:00'), row(3, 'Engineer-3', {}, { last_site_id: 's1' })]
const timesDialog = async (codes: number[], bulk = bulkOk()) => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => sheet(crewTimes), 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  const tbl = await table()
  for (const c of codes) await user.click(within(tbl).getByRole('checkbox', { name: `تحديد Engineer-${c}` }))
  await user.click(within(screen.getByRole('group', { name: 'إجراءات المحدد' })).getByRole('button', { name: 'تعديل الأوقات' }))
  return screen.getByRole('dialog', { name: 'تعديل الأوقات' })
}

test('the times dialog pre-fills a shared departure, shows «قيم مختلفة» for a mixed arrival and leaves the blank arrival unsent', async () => {
  const bulk = bulkOk()
  const dialog = await timesDialog([1, 2], bulk)
  expect(dialog).toHaveTextContent('2 محددون. الحقل الفارغ لا يغيّر شيئًا.')
  const arrival = within(dialog).getByRole('textbox', { name: 'وقت الحضور' })
  const departure = within(dialog).getByRole('textbox', { name: 'وقت الانصراف' })
  expect(arrival).toHaveValue('')
  expect(arrival).toHaveAccessibleDescription(expect.stringContaining('قيم مختلفة'))
  expect(departure).toHaveValue('5:00')
  expect(within(dialog).getAllByText('قيم مختلفة')).toHaveLength(1)
  await user.clear(departure)
  await user.type(departure, '4:00')
  await user.click(within(dialog).getByRole('button', { name: 'تطبيق' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: 1, site_id: 's1', out_time: '16:00' }, { employee_id: 'e2', version: 2, site_id: 's1', out_time: '16:00' }])
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  expect(screen.queryByRole('group', { name: 'إجراءات المحدد' })).not.toBeInTheDocument()
})

test('the times dialog skips a row that is not present with a count toast', async () => {
  const bulk = bulkOk()
  const dialog = await timesDialog([1, 3], bulk)
  const arrival = within(dialog).getByRole('textbox', { name: 'وقت الحضور' })
  expect(arrival).toHaveValue('8:00') // shared by the one present row
  await user.clear(arrival)
  await user.type(arrival, '0830')
  await user.click(within(dialog).getByRole('button', { name: 'تطبيق' }))
  expect(toast.warning).toHaveBeenCalledWith('تم تخطي 1 غير حاضرين')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows).toEqual([{ employee_id: 'e1', version: 1, site_id: 's1', in_time: '08:30' }])
})

test('the times dialog keeps Apply disabled while both boxes are blank or a box lacks AM/PM', async () => {
  const dialog = await timesDialog([1, 2]) // their arrivals differ: a blank box with no AM/PM
  const apply = within(dialog).getByRole('button', { name: 'تطبيق' })
  expect(apply).toBeDisabled()
  const arrival = within(dialog).getByRole('textbox', { name: 'وقت الحضور' })
  await user.type(arrival, '10:30')
  expect(apply).toBeDisabled()
  await user.click(within(dialog).getAllByRole('button', { name: 'صباحًا' })[0])
  expect(apply).toBeEnabled()
  await user.click(within(dialog).getByRole('button', { name: 'إلغاء' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

// S2b: Undo on the success toast
test('the success toast carries Undo; one toast id, so a newer write replaces it, and its Undo puts back the newest write', async () => {
  const bulk = bulkOk()
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await menu('إجراءات أخرى Engineer-2', 'غياب')
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (2)'))
  const [[, first], [, second]] = vi.mocked(toast.success).mock.calls as unknown as [string, { id: string; action: { onClick: () => void } }][]
  expect(first.id).toBeTruthy()
  expect(second.id).toBe(first.id)
  second.action.onClick()
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(3))
  expect(sent(bulk, 2).rows.map((r: { employee_id: string }) => r.employee_id)).toEqual(['e2'])
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  expect(undoButton()).toBeInTheDocument() // the Undo button stays
})

// S2b: class sweep
test('row controls use the kit touch floor, with no fixed heights; the status pill is a kit badge', async () => {
  mockFetch(routes)
  edit()
  const tr = within(await table()).getByText('Engineer-1').closest('tr')!
  for (const b of within(tr).getAllByRole('button')) expect(b.className).not.toMatch(/(^|\s)h-(9|11)(\s|$)/)
  expect(within(tr).getByRole('checkbox').closest('label')).toHaveClass('min-h-tap', 'min-w-tap')
  expect(within(tr).getAllByText('غير مسجل')[0]).toHaveClass('rounded-md', 'font-semibold', 'bg-warning-bg') // the pill, before the totals' label
})

// S2b review fixes
test('the bar\'s Undo dismisses the applied toast, so its Undo can never undo an older write', async () => {
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulkOk() })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await menu('إجراءات أخرى Engineer-2', 'غياب')
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (2)'))
  const { id } = vi.mocked(toast.success).mock.calls[1][1] as { id: string }
  await user.click(undoButton())
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  expect(toast.dismiss).toHaveBeenCalledWith(id)
})

test('the toast\'s Undo waits while a write is in flight: the toast stays and nothing is undone', async () => {
  const bulk = vi.fn((_url: string, init?: RequestInit) => (bulk.mock.calls.length === 1
    ? json(200, { data: JSON.parse(String(init?.body)).rows.map((r: { employee_id: string }) => ({ employee_id: r.employee_id, version: 1 })) })
    : (new Promise(() => {}) as unknown as Response))) // the second write never answers
  mockFetch({ ...routes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await user.click(within(await table()).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  await menu('إجراءات أخرى Engineer-2', 'غياب')
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(2))
  const { action } = vi.mocked(toast.success).mock.calls[0][1] as unknown as { action: { onClick: (e: { preventDefault: () => void }) => void } }
  const preventDefault = vi.fn()
  action.onClick({ preventDefault })
  expect(preventDefault).toHaveBeenCalled() // sonner keeps a toast whose action prevented the default
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).toHaveBeenCalledTimes(2)
  expect(undoButton()).toHaveTextContent('تراجع (1)')
})

test('a refused time puts the stored one back and a later blur sends nothing', async () => {
  const bulk = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'rows.0.out_time', code: 'out_before_in', message: 'الانصراف قبل الحضور.' }] } }))
  mockFetch({ ...timedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  const departure = within(rowOf('Engineer-2')).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })
  await user.clear(departure)
  await user.type(departure, '0700{Enter}')
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Engineer-2: الانصراف قبل الحضور.'))
  await waitFor(() => expect(departure).toHaveValue('6:00'))
  await user.click(departure)
  await user.click(document.body)
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).toHaveBeenCalledTimes(1)
})

test('a refetch with the same stored times leaves a typed time alone', async () => {
  const get = vi.fn((_url: string) => sheet([engineers[0], timed, engineers[2]]))
  mockFetch({ ...timedRoutes, 'GET /api/v1/attendance/sheet': get })
  edit()
  await table()
  const arrival = within(rowOf('Engineer-2')).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })
  await user.clear(arrival)
  await user.type(arrival, '0930')
  const calls = get.mock.calls.length
  await queryClient.invalidateQueries()
  await waitFor(() => expect(get.mock.calls.length).toBeGreaterThan(calls))
  expect(within(rowOf('Engineer-2')).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })).toHaveValue('0930')
})

test('Escape puts both boxes of the pair back and nothing is sent', async () => {
  const bulk = bulkOk()
  mockFetch({ ...timedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  const tr = rowOf('Engineer-2')
  const arrival = within(tr).getByRole('textbox', { name: 'وقت الحضور Engineer-2' })
  const departure = within(tr).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' })
  await user.clear(arrival)
  await user.type(arrival, '0930')
  await user.clear(departure)
  await user.type(departure, '1900{Escape}')
  expect(arrival).toHaveValue('8:45')
  expect(departure).toHaveValue('6:00')
  await user.click(document.body)
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).not.toHaveBeenCalled()
})

test('xl time cells keep room for the box and its AM/PM, and sit middle-aligned like the rest of the row', async () => {
  mockFetch(timedRoutes)
  edit()
  await table()
  const cell = within(rowOf('Engineer-2')).getByRole('textbox', { name: 'وقت الانصراف Engineer-2' }).closest('td')!
  expect(cell.firstElementChild).toHaveClass('min-w-38')
  expect(cell).not.toHaveClass('align-top')
})

test('the site chip does nothing when the checked site is picked again', async () => {
  const bulk = bulkOk()
  mockFetch({ ...chipRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(within(rowOf('Engineer-2')).getByRole('button', { name: 'تعيين موقع: Site-1، Engineer-2' }))
  await user.click(await screen.findByRole('menuitemradio', { name: 'Site-1' }))
  await new Promise((r) => setTimeout(r, 20))
  expect(bulk).not.toHaveBeenCalled()
  expect(toast.info).not.toHaveBeenCalled()
})

test('the times dialog keeps Apply disabled when no selected row is present', async () => {
  const dialog = await timesDialog([3])
  await user.type(within(dialog).getByRole('textbox', { name: 'وقت الحضور' }), '0830')
  expect(within(dialog).getByRole('button', { name: 'تطبيق' })).toBeDisabled()
})

test('a times dialog box selects on focus, so typing 3 then م over 5:00 gives 3:00 PM', async () => {
  const bulk = bulkOk()
  const dialog = await timesDialog([1, 2], bulk)
  const departure = within(dialog).getByRole('textbox', { name: 'وقت الانصراف' })
  await user.click(departure)
  await user.keyboard('3')
  await user.click(within(dialog).getAllByRole('button', { name: 'مساءً' })[1])
  expect(departure).toHaveValue('3')
  await user.click(within(dialog).getByRole('button', { name: 'تطبيق' }))
  await waitFor(() => expect(bulk).toHaveBeenCalledTimes(1))
  expect(sent(bulk).rows.map((r: { out_time: string }) => r.out_time)).toEqual(['15:00', '15:00'])
})

// HR's site filter (owner, 2026-11-08)
const twoSites = () => json(200, { data: [...sites, { id: 's2', name_ar: 'Site-2', name_en: 'Site-2', kind: 'work', leave_code: null, active: true }], meta: { pagination } })
const placed = [
  row(1, 'Engineer-1', {}, { last_site_id: 's1', site_id: 's1' }),
  row(2, 'Engineer-2', { state: 'stored', source: 'entered', version: 1, site_id: 's2' }, { last_site_id: 's2', site_id: 's2' }),
  row(3, 'Engineer-3', {}, { last_site_id: 's2', site_id: 's2' }),
  row(4, 'Engineer-4', {}, { site_id: null }),
]
const placedRoutes = { ...routes, 'GET /api/v1/sites': twoSites, 'GET /api/v1/attendance/sheet': () => sheet(placed) }
const listed = () => within(screen.getByRole('table')).getAllByRole('row').slice(1).map((r) => within(r).getByText(/^(Engineer|Office|Worker)-/).textContent)
const siteFilter = () => screen.findByRole('combobox', { name: 'الموقع' })

test('the rows, the KPI counts and the cohorts follow the chosen site', async () => {
  mockFetch(placedRoutes)
  open('/attendance?group=engineer&site=s2')
  await table()
  expect(listed()).toEqual(['Engineer-2', 'Engineer-3'])
  expect(screen.getByRole('button', { name: /^الإجمالي/ })).toHaveTextContent('1 من 2 مسجل')
  expect(screen.getByRole('button', { name: /^غير مسجل/ })).toHaveTextContent('1')
  const cohorts = within(screen.getByRole('group', { name: 'حسب آخر موقع' })).getAllByRole('button')
  expect(cohorts).toHaveLength(1)
  expect(cohorts[0]).toHaveTextContent('Site-2')
})

test('none lists only the rows with no site', async () => {
  mockFetch(placedRoutes)
  open('/attendance?group=engineer&site=none')
  await table()
  expect(listed()).toEqual(['Engineer-4'])
  expect(await siteFilter()).toHaveValue('none')
})

test('picking a site narrows the loaded rows without another request, and drops the selection', async () => {
  const get = vi.fn((_url: string) => sheet(placed))
  mockFetch({ ...placedRoutes, 'GET /api/v1/attendance/sheet': get })
  edit()
  await table()
  await user.click(within(screen.getByRole('table')).getByRole('checkbox', { name: 'تحديد Engineer-1' }))
  const filter = await siteFilter()
  await within(filter).findByRole('option', { name: 'Site-2' })
  await user.selectOptions(filter, 's2')
  await waitFor(() => expect(listed()).toEqual(['Engineer-2', 'Engineer-3']))
  expect(screen.queryByRole('toolbar', { name: 'إجراء جماعي' })).toBeNull()
  expect(get).toHaveBeenCalledTimes(1)
  expect(get.mock.calls[0][0]).not.toContain('site')
})

test('a site change empties undo: the bar disables, and neither it nor the toast writes the hidden rows', async () => {
  const bulk = bulkOk()
  mockFetch({ ...placedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit()
  await table()
  await user.click(within(screen.getByRole('table')).getByRole('button', { name: 'حاضر Engineer-1' }))
  await waitFor(() => expect(undoButton()).toHaveTextContent('تراجع (1)'))
  const toastUndo = vi.mocked(toast.success).mock.calls.at(-1)![1]!.action as { onClick: (e: { preventDefault: () => void }) => void }
  const filter = await siteFilter()
  await within(filter).findByRole('option', { name: 'Site-2' })
  await user.selectOptions(filter, 's2')
  await waitFor(() => expect(listed()).toEqual(['Engineer-2', 'Engineer-3']))
  expect(undoButton()).toHaveTextContent('تراجع (0)')
  expect(undoButton()).toHaveAttribute('aria-disabled', 'true')
  expect(toast.dismiss).toHaveBeenCalledWith('attendance-sheet-applied')
  await user.click(undoButton())
  toastUndo.onClick({ preventDefault: () => {} })
  await new Promise((resolve) => setTimeout(resolve))
  expect(bulk).toHaveBeenCalledTimes(1)
})

test('marking the rest writes only the rows of the chosen site', async () => {
  const bulk = bulkOk()
  mockFetch({ ...placedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=engineer&site=s2')
  await table()
  await user.click(screen.getByRole('button', { name: 'تسجيل الباقين حاضرين (1)' }))
  await waitFor(() => expect(bulk).toHaveBeenCalled())
  expect(sent(bulk).rows.map((r: { employee_id: string }) => r.employee_id)).toEqual(['e3'])
})

test('a cohort sweep and the paste box see only the rows of the chosen site', async () => {
  const bulk = bulkOk()
  mockFetch({ ...placedRoutes, 'POST /api/v1/attendance/days/bulk': bulk })
  edit('/attendance?group=engineer&site=s2')
  await table()
  await user.click(screen.getByRole('button', { name: 'لصق أرقام' }))
  const dialog = screen.getByRole('dialog', { name: 'لصق الأرقام الوظيفية' })
  await user.type(within(dialog).getByRole('textbox', { name: 'الأرقام الوظيفية' }), '1 3')
  expect(names(dialog)).toEqual(['3 Engineer-3']) // 1 is at Site-1, out of view
  await user.keyboard('{Escape}')
  await user.click(screen.getByRole('button', { name: 'تسجيل باقي Site-2 حاضرين' }))
  await waitFor(() => expect(bulk).toHaveBeenCalled())
  expect(sent(bulk).rows.map((r: { employee_id: string }) => r.employee_id)).toEqual(['e3'])
})

test('the strip drops the count while a site is chosen: it is the whole group, not the filtered rows', async () => {
  mockFetch(placedRoutes)
  open('/attendance?group=engineer&site=s2')
  await table()
  const day = screen.getByRole('button', { name: '2026-10-17' })
  expect(day).not.toHaveTextContent('2')
})

test('someone without all-scope attendance gets no filter, and a site in the URL filters nothing', async () => {
  mockFetch(placedRoutes)
  open('/attendance?group=engineer&site=s2', ['attendance.view.own'])
  await table()
  expect(listed()).toEqual(['Engineer-1', 'Engineer-2', 'Engineer-3', 'Engineer-4'])
  expect(screen.queryByRole('combobox', { name: 'الموقع' })).toBeNull()
})

const everyType = [
  { ...row(1, 'Engineer-1', { state: 'stored', source: 'entered', version: 1, site_id: 's1', in_time: '08:00', out_time: '16:00' }, { last_site_id: 's1', site_id: 's1' }), employee: { id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1', department: null, type: 'engineer' } },
  { ...row(2, 'Engineer-2', {}, { site_id: null }), employee: { id: 'e2', code: 2, name_ar: 'Engineer-2', name_en: 'Engineer-2', department: null, type: 'engineer' } },
  { ...row(10, 'Office-1', { state: 'stored', source: 'entered', version: 1, in_time: '09:40' }, { hints: { late_minutes: 25 }, site_id: 's1' }), employee: { id: 'e10', code: 10, name_ar: 'Office-1', name_en: 'Office-1', department: 'Accounts', type: 'office' } },
  { ...row(11, 'Office-2', {}, { site_id: null, needs_site: true }), employee: { id: 'e11', code: 11, name_ar: 'Office-2', name_en: 'Office-2', department: null, type: 'office' } },
  { ...row(20, 'Worker-1', {}, { site_id: null, needs_site: true }), employee: { id: 'e20', code: 20, name_ar: 'Worker-1', name_en: 'Worker-1', department: null, type: 'permanent_worker' } },
]
const allSheet = (rows: object[] = everyType) => json(200, { data: { group: 'all', date: '2026-10-18', days: days.map((d) => ({ ...d, missing: null })), rows } })
const allRoutes = { ...placedRoutes, 'GET /api/v1/attendance/sheet': () => allSheet() }
const allPath = '/attendance?group=all'
const SET = 'تحديد الموقع'
const lockedHead = { ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true, lock: { locked_by: { name: 'HR-1' }, locked_at: '2026-10-18T08:00:00Z' } } }) }

test('All is view-only: Details on every row, no quick action, no selection, no cohort panel', async () => {
  mockFetch(allRoutes)
  edit(allPath)
  const tbl = await table()
  expect(within(tbl).getAllByRole('button', { name: /^التفاصيل / })).toHaveLength(5)
  expect(within(tbl).queryByRole('checkbox')).toBeNull()
  expect(within(tbl).queryByRole('textbox')).toBeNull()
  expect(within(tbl).queryByRole('button', { name: /^حاضر / })).toBeNull()
  expect(screen.queryByRole('group', { name: 'حسب آخر موقع' })).toBeNull()
  expect(screen.queryByRole('button', { name: /^تسجيل الباقين/ })).toBeNull()
  expect(screen.queryByRole('button', { name: 'لصق أرقام' })).toBeNull()
})

test('All opens the day editor from Details', async () => {
  mockFetch({ ...allRoutes, 'GET /api/v1/attendance/days/e20/2026-10-18': () => json(200, { data: { ...blank, state: 'missing', date: '2026-10-18', employee_id: 'e20', type: 'permanent_worker', fields: ['in_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  open(allPath)
  await user.click(within(await table()).getByRole('button', { name: 'التفاصيل Worker-1' }))
  expect(await screen.findByRole('dialog', { name: /Worker-1/ })).toBeInTheDocument()
})

test('All has no lock UI: the engineers\' lock says nothing about the other types', async () => {
  mockFetch({ ...lockedHead, 'GET /api/v1/sites': twoSites, 'GET /api/v1/attendance/sheet': () => allSheet() })
  edit(allPath)
  await table()
  expect(screen.queryByText('الفترة مقفلة؛ الكشف للعرض فقط.')).toBeNull()
  expect(screen.queryByText(/قفلها/)).toBeNull()
  expect(screen.queryByRole('button', { name: 'إعادة الفتح' })).toBeNull()
})

test('in All the strip shows no number and its label claims no count', async () => {
  mockFetch(allRoutes)
  open(allPath)
  await table()
  const day = screen.getByRole('button', { name: '2026-10-17' })
  expect(day.getAttribute('aria-label')).not.toMatch(/\d غير مسجل|null/)
  expect(day).not.toHaveTextContent('null')
})

test('in All each row reads by its own type: late, its times, type and site', async () => {
  mockFetch(allRoutes)
  open(allPath)
  await table()
  const off = rowOf('Office-1')
  expect(within(off).getByText('موظف مثبت شركة')).toBeInTheDocument()
  expect(off).toHaveTextContent('متأخر 25 د')
  expect(within(off).getByText('Site-1')).toBeInTheDocument()
  expect(within(off).getByText('حاضر')).toBeInTheDocument() // an office employee's worked days are "present" in their totals
  const eng = rowOf('Engineer-1')
  expect(within(eng).getByText('مهندس')).toBeInTheDocument()
  expect(within(eng).getByText('في موقع')).toBeInTheDocument()
  expect(off.querySelectorAll('td')).toHaveLength(eng.querySelectorAll('td').length)
  expect(eng).toHaveTextContent('Site-1')
  expect(screen.getByRole('button', { name: /^حاضر/ })).toHaveTextContent('2') // a late office employee was still there
})

test('a single office group renders as before: arrival column first, no type, no out time', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': officePeriod, 'GET /api/v1/attendance/sheet': () => sheet(office, 'office') })
  edit('/attendance?group=office')
  const headers = within(await table()).getAllByRole('columnheader').map((h) => h.textContent)
  expect(headers.slice(1, 4)).toEqual(['الموظف', 'وقت الحضور', 'الحالة'])
  expect(screen.queryByText('موظف مثبت شركة')).toBeNull()
})

test('the set-site button shows for an office employee or worker with no site, to a holder of the permission', async () => {
  mockFetch(allRoutes)
  open(allPath, ['attendance.view.all', 'employee.assign_site.all'])
  await table()
  expect(within(rowOf('Office-2')).getByRole('button', { name: `${SET} Office-2` })).toBeInTheDocument()
  expect(within(rowOf('Worker-1')).getByRole('button', { name: `${SET} Worker-1` })).toBeInTheDocument()
  expect(within(rowOf('Engineer-2')).queryByRole('button', { name: new RegExp(SET) })).toBeNull() // an engineer's site comes from the recorded day
  expect(within(rowOf('Office-1')).queryByRole('button', { name: new RegExp(SET) })).toBeNull() // has a site
})

test('the server decides who gets the set-site button: a laborer off every crew has none and reads as no site', async () => {
  const offCrew = { ...row(21, 'Worker-2', {}, { site_id: null, needs_site: false }), employee: { id: 'e21', code: 21, name_ar: 'Worker-2', name_en: 'Worker-2', department: null, type: 'day_laborer' } }
  mockFetch({ ...allRoutes, 'GET /api/v1/attendance/sheet': () => allSheet([offCrew]) })
  open(allPath, ['attendance.view.all', 'employee.assign_site.all'])
  await table()
  expect(within(rowOf('Worker-2')).queryByRole('button', { name: new RegExp(SET) })).toBeNull()
  expect(within(rowOf('Worker-2')).getByText('بدون موقع')).toBeInTheDocument()
})

test('no set-site button without employee.assign_site', async () => {
  mockFetch(allRoutes)
  open(allPath)
  await table()
  expect(screen.queryByRole('button', { name: new RegExp(SET) })).toBeNull()
})

test('setting a site saves from the sheet date and refreshes the sheet', async () => {
  const get = vi.fn((_url: string) => allSheet())
  const post = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: {} }))
  mockFetch({ ...allRoutes, 'GET /api/v1/attendance/sheet': get, 'POST /api/v1/employees/e11/site-assignments': post })
  open(`${allPath}&site=none`, ['attendance.view.all', 'employee.assign_site.all'])
  await table()
  await user.click(within(rowOf('Office-2')).getByRole('button', { name: `${SET} Office-2` }))
  const dialog = await screen.findByRole('dialog', { name: SET })
  await within(dialog).findByRole('option', { name: 'Site-2' })
  await user.selectOptions(within(dialog).getByLabelText('الموقع الأساسي'), 's2')
  expect(within(dialog).queryByLabelText('يعود إلى الموقع السابق في')).toBeNull() // there is no previous site to return to
  const calls = get.mock.calls.length
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(JSON.parse(String(post.mock.calls[0][1]?.body))).toEqual({ site_id: 's2', effective_from: '2026-10-18' })
  await waitFor(() => expect(get.mock.calls.length).toBeGreaterThan(calls))
})
