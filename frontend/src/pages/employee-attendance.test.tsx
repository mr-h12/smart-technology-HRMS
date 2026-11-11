import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { EmployeeAttendance } from './employee-attendance'
import { EmployeeFormPage } from './employees'

const user = userEvent.setup()
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-10-20T10:00:00Z')) // Tuesday 20 Oct in Cairo
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})

const emp = { id: 'e1', code: 7, name_ar: 'أحمد', name_en: 'Ahmed', current_type: 'engineer', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }
const VALUES = { in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null }
const day = (date: string, state: string, extra = {}) => ({ date, state, source: state === 'stored' ? 'entered' : null, version: state === 'stored' ? 1 : null, site_id: null, leave_code: null, ...VALUES, hints: {}, ...extra })
const dates = (start: string, n: number) => Array.from({ length: n }, (_, i) => new Date(Date.parse(`${start}T00:00:00Z`) + i * 864e5).toISOString().slice(0, 10))
/** Hired on 28 Sep; today is Tue 20 Oct: 15 present, 1 absent, 1 sick, 1 annual leave, 2 missing, the Fridays off, the rest future. */
const SPECIAL: Record<string, [string, object?]> = {
  '2026-09-26': ['not_employed'],
  '2026-09-27': ['not_employed'],
  '2026-10-03': ['missing'],
  '2026-10-04': ['stored', { leave_code: 'absent', site_id: 'l3' }],
  '2026-10-05': ['stored', { leave_code: 'annual', site_id: 'l4' }],
  '2026-10-06': ['missing'],
  '2026-10-20': ['stored', { leave_code: 'sick', site_id: 'l2' }],
}
const fill = (d: string) =>
  SPECIAL[d] ? day(d, ...SPECIAL[d])
  : new Date(`${d}T00:00:00Z`).getUTCDay() === 5 ? day(d, 'derived', { leave_code: 'friday', site_id: 'l1' })
  : d > '2026-10-20' ? day(d, 'future')
  : day(d, 'stored', { site_id: 's1', in_time: '08:45' })
const engineer = {
  period: { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_date: '2026-09-25', next_date: null, is_locked: false },
  fields: ['in_time', 'out_time', 'late_minutes', 'ot_minutes', 'outside_minutes', 'advance_amount', 'note'],
  days: dates('2026-09-26', 30).map(fill),
  summary: { worked: 15, absent: 1, leave: 2, off: 3, missing: 2 },
}
const sites = [
  { id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', outside_alexandria: false, active: true },
  { id: 'l1', name_ar: 'إجازة الجمعة', name_en: null, kind: 'leave', leave_code: 'friday', active: true },
  { id: 'l2', name_ar: 'إجازة مرضية', name_en: null, kind: 'leave', leave_code: 'sick', active: true },
  { id: 'l3', name_ar: 'غياب', name_en: null, kind: 'leave', leave_code: 'absent', active: true },
  { id: 'l4', name_ar: 'إجازة اعتيادية', name_en: null, kind: 'leave', leave_code: 'annual', active: true },
]
const PERIOD = 'GET /api/v1/attendance/employees/e1/period'
const routes = (data: object = engineer) => ({
  [PERIOD]: vi.fn((_url: string) => json(200, { data })),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
})
const show = () => renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all'], { router: false })
/** The filled shape holding a day's number. */
const circle = async (name: string) => within(await screen.findByRole('button', { name })).getByText(/^\d+$/)

test('the calendar starts on Saturday, splits at a month change and leaves the days outside the period blank', async () => {
  mockFetch(routes())
  show()
  const first = await screen.findByRole('button', { name: 'السبت 2026-09-26: خارج فترة العمل' })
  expect(screen.queryByRole('table')).toBeNull()
  expect([...screen.getByText('سبت').parentElement!.children].map((c) => c.textContent)).toEqual(['سبت', 'أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'])
  expect(screen.getAllByRole('heading').map((h) => h.textContent)).toEqual(['سبتمبر 2026', 'أكتوبر 2026'])
  const sep = first.closest('ol')!
  const oct = screen.getByRole('button', { name: 'الخميس 2026-10-01: Site-1' }).closest('ol')!
  expect(sep).toHaveClass('grid-cols-7')
  expect(oct).toHaveClass('grid-cols-7')
  expect(within(sep).getAllByRole('button').map((b) => b.textContent)).toEqual(['26', '27', '28', '29', '30'])
  expect(within(oct).getAllByRole('button')).toHaveLength(25)
  // each month's first day sits in its own weekday column (Saturday is the first); the rest flow after it
  expect(first.closest('li')).toHaveStyle({ gridColumnStart: '1' })
  expect(within(oct).getAllByRole('listitem')[0]).toHaveStyle({ gridColumnStart: '6' })
  expect(within(oct).getAllByRole('listitem')[1].style.gridColumnStart).toBe('')
  expect(screen.queryByRole('button', { name: /2026-09-25|2026-10-26/ })).toBeNull()
})

test('a day laborer’s half month is one section, and a stored day with no site reads present', async () => {
  const half = { ...engineer, period: { ...engineer.period, group: 'day_laborer', start: '2026-10-16', end: '2026-10-31' }, fields: ['in_time', 'out_time'],
    days: dates('2026-10-16', 16).map((d) => (d === '2026-10-17' ? day(d, 'stored') : fill(d))) }
  mockFetch(routes(half))
  renderWithAuth(<EmployeeAttendance emp={{ ...emp, current_type: 'day_laborer' }} />, ['attendance.view.all'], { router: false })
  const friday = await screen.findByRole('button', { name: 'الجمعة 2026-10-16: إجازة الجمعة' })
  expect(screen.getAllByRole('heading').map((h) => h.textContent)).toEqual(['أكتوبر 2026'])
  expect(friday.closest('li')).toHaveStyle({ gridColumnStart: '7' })
  expect(screen.getByRole('button', { name: 'السبت 2026-10-17: حاضر' })).toBeInTheDocument()
})

test('each day’s colour comes from what it is, and the legend counts the same days', async () => {
  mockFetch(routes())
  show()
  expect(await circle('الخميس 2026-10-01: Site-1')).toHaveClass('bg-success/20', 'text-success')
  expect(await circle('الأحد 2026-10-04: غياب')).toHaveClass('bg-destructive/20', 'text-destructive')
  expect(await circle('الثلاثاء 2026-10-20: إجازة مرضية')).toHaveClass('bg-info/20', 'text-info')
  expect(await circle('الاثنين 2026-10-05: إجازة اعتيادية')).toHaveClass('bg-warning/20', 'text-warning')
  expect(await circle('السبت 2026-10-03: غير مسجل')).toHaveClass('bg-muted-foreground/20', 'text-muted-foreground')
  const off = await circle('الجمعة 2026-10-02: إجازة الجمعة')
  expect(off).toHaveClass('text-muted-foreground', 'font-normal') // dimmed, not the bold of a recorded day
  expect(off).not.toHaveClass('font-semibold')
  expect(off.className).not.toMatch(/\bbg-/)
  expect(await circle('الأربعاء 2026-10-21: لم يأتِ بعد')).toHaveClass('opacity-50')
  expect(await circle('السبت 2026-09-26: خارج فترة العمل')).toHaveClass('opacity-30')
  expect(screen.getByRole('button', { name: 'الخميس 2026-10-01: Site-1' })).toHaveAttribute('title', 'Site-1')
  const items = within(screen.getByRole('list', { name: 'دليل الألوان' })).getAllByRole('listitem')
  expect(items.map((li) => li.textContent)).toEqual(['حاضر 15', 'غائب 1', 'مرضي 1', 'إجازة 1', 'غير مسجل 2'])
  expect(items[0].firstChild).toHaveClass('bg-success/20')
  expect(items[1].firstChild).toHaveClass('bg-destructive/20')
})

test('today is marked, and a day that cannot be edited is disabled', async () => {
  mockFetch(routes())
  show()
  const today = await screen.findByRole('button', { name: 'الثلاثاء 2026-10-20: إجازة مرضية' })
  expect(today).toHaveAttribute('aria-current', 'date')
  expect(within(today).getByText('20')).toHaveClass('ring-2', 'ring-primary')
  expect(screen.getAllByRole('button').filter((b) => b.hasAttribute('aria-current'))).toEqual([today])
  expect(screen.getByRole('button', { name: 'الأربعاء 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'السبت 2026-09-26: خارج فترة العمل' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'الجمعة 2026-10-02: إجازة الجمعة' })).toBeEnabled()
})

test('a weekly off after today is future unless a record was stored for it', async () => {
  // the fixture derives Friday 23 Oct; a holiday a period lock stored on Saturday 24 Oct has a record to show
  mockFetch(routes({ ...engineer, days: engineer.days.map((d) => (d.date === '2026-10-24' ? day(d.date, 'stored', { leave_code: 'official_holiday' }) : d)) }))
  show()
  const derived = 'الجمعة 2026-10-23: إجازة الجمعة'
  const stored = 'السبت 2026-10-24: إجازة رسمية'
  expect(await screen.findByRole('button', { name: derived })).toBeDisabled()
  expect(await circle(derived)).toHaveClass('opacity-50')
  expect(screen.getByRole('button', { name: stored })).toBeEnabled()
  expect(await circle(stored)).not.toHaveClass('opacity-50')
})

test('the arrows move to the neighbouring period by its date, and a locked period says so', async () => {
  const r = routes({ ...engineer, period: { ...engineer.period, is_locked: true } })
  mockFetch(r)
  show()
  expect(await screen.findByText('مقفلة')).toBeInTheDocument()
  const range = screen.getByRole('button', { name: 'الفترة السابقة' }).nextElementSibling!
  expect(range).toHaveTextContent('من 2026-09-26 إلى 2026-10-25')
  expect(screen.getByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  expect(r[PERIOD].mock.calls[0][0]).toBe('/api/v1/attendance/employees/e1/period')
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(r[PERIOD].mock.calls.at(-1)?.[0]).toBe('/api/v1/attendance/employees/e1/period?date=2026-09-25'))
})

test('a day opens the day editor, and a save there refreshes the calendar', async () => {
  const r = routes()
  const put = vi.fn(() => json(200, { data: {} }))
  mockFetch({ ...r, 'PUT /api/v1/attendance/days/e1/2026-10-03': put,
    'GET /api/v1/attendance/days/e1/2026-10-03': () => json(200, { data: { ...day('2026-10-03', 'missing'), employee_id: 'e1', type: 'engineer',
      fields: ['in_time', 'out_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all', 'attendance.edit.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'السبت 2026-10-03: غير مسجل' }))
  const editor = await screen.findByRole('dialog', { name: 'يوم 2026-10-03 — أحمد' })
  const reads = r[PERIOD].mock.calls.length
  await user.selectOptions(await within(editor).findByLabelText('الموقع أو نوع اليوم'), 's1')
  await user.click(within(editor).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(put).toHaveBeenCalled())
  await waitFor(() => expect(r[PERIOD].mock.calls.length).toBeGreaterThan(reads))
})

function EmployeeDetailRoute({ tab }: { tab: string }) {
  return (
    <MemoryRouter initialEntries={[`/employees/e1?tab=${tab}`]}>
      <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>
  )
}

test('the employee screen has the attendance tab only with attendance.view', async () => {
  mockFetch({ ...routes(), 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': () => json(200, { data: [], meta: { pagination } }) })
  const { unmount } = renderWithAuth(<EmployeeDetailRoute tab="attendance" />, ['employee.view.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'الحضور' })).toBeNull()
  unmount()
  renderWithAuth(<EmployeeDetailRoute tab="attendance" />, ['employee.view.all', 'attendance.view.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'الحضور' })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByRole('button', { name: 'الخميس 2026-10-01: Site-1' })).toBeInTheDocument()
})

test('in English the labels, the month and the site name are English', async () => {
  await i18n.changeLanguage('en')
  mockFetch(routes())
  show()
  expect(await screen.findByRole('button', { name: 'Thursday 2026-10-01: Site-1' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Friday 2026-10-02: Friday' })).toBeInTheDocument()
  expect(screen.getAllByRole('heading').map((h) => h.textContent)).toEqual(['September 2026', 'October 2026'])
  expect(screen.getByText('Sat').parentElement!.firstChild).toHaveTextContent('Sat')
  const items = within(screen.getByRole('list', { name: 'Colour key' })).getAllByRole('listitem')
  expect(items.map((li) => li.textContent)).toEqual(['Present 15', 'Absent 1', 'Sick 1', 'Leave 1', 'Missing 2'])
})
