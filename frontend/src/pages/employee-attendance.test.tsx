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
const engineer = {
  period: { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_date: '2026-09-25', next_date: null, is_locked: false },
  fields: ['in_time', 'out_time', 'late_minutes', 'ot_minutes', 'outside_minutes', 'advance_amount', 'note'],
  days: [
    day('2026-10-01', 'stored', { site_id: 's1', in_time: '08:45', out_time: '18:30', late_minutes: 5, advance_amount: '500.000000', hints: { late_minutes: 45, ot_minutes: 105 } }),
    day('2026-10-02', 'derived', { leave_code: 'friday', site_id: 'l1' }),
    day('2026-10-03', 'missing'),
    day('2026-10-20', 'stored', { leave_code: 'sick', site_id: 'l2' }),
    day('2026-10-21', 'future'),
  ],
  summary: { worked: 1, absent: 0, leave: 1, off: 1, missing: 1 },
}
const sites = [
  { id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', outside_alexandria: false, active: true },
  { id: 'l1', name_ar: 'إجازة الجمعة', name_en: null, kind: 'leave', leave_code: 'friday', active: true },
  { id: 'l2', name_ar: 'إجازة مرضية', name_en: null, kind: 'leave', leave_code: 'sick', active: true },
]
const PERIOD = 'GET /api/v1/attendance/employees/e1/period'
const routes = (data: object = engineer) => ({
  [PERIOD]: vi.fn((_url: string) => json(200, { data })),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
})

test('the table lists every day with its site or day type and the type’s values', async () => {
  mockFetch(routes())
  renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all'], { router: false })
  const worked = (await screen.findByRole('button', { name: 'الخميس 2026-10-01: Site-1' })).closest('tr')!
  const heads = screen.getAllByRole('columnheader').map((h) => h.textContent)
  expect(heads).toEqual(['اليوم', 'الموقع أو نوع اليوم', 'وقت الحضور', 'وقت الانصراف', 'دقائق التأخير', 'دقائق الوقت الإضافي', 'دقائق العمل خارج الإسكندرية', 'السلفة', 'ملاحظات'])
  const cells = within(worked).getAllByRole('cell')
  expect(cells[2]).toHaveTextContent(/8:45/)
  expect(cells[4]).toHaveTextContent('5') // the stored minutes win over the hint
  expect(cells[5]).toHaveTextContent('105') // no stored overtime: the hint, quieter
  expect(within(cells[5]).getByText('105')).toHaveClass('text-muted-foreground')
  expect(cells[7]).toHaveTextContent('500.00')
  expect(screen.getByRole('button', { name: 'الجمعة 2026-10-02: إجازة الجمعة' }).closest('tr')).toHaveClass('bg-muted-foreground/10')
  expect(within(screen.getByRole('button', { name: 'السبت 2026-10-03: غير مسجل' }).closest('tr')!).getByText('غير مسجل')).toHaveClass('bg-warning/15')
  expect(screen.getByRole('button', { name: 'الثلاثاء 2026-10-20: إجازة مرضية' }).closest('tr')).toHaveAttribute('aria-current', 'date')
  expect(screen.getByRole('button', { name: 'الأربعاء 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
  expect(within(worked).getByText('2026-10-01')).toHaveAttribute('dir', 'ltr')
})

test('the summary shows the five day counts the server computed', async () => {
  mockFetch(routes({ ...engineer, summary: { worked: 18, absent: 1, leave: 2, off: 4, missing: 3 } }))
  renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all'], { router: false })
  const tile = async (label: string) => (await screen.findByText(label)).closest('[role="group"]')
  expect(await tile('أيام العمل')).toHaveTextContent('18')
  expect(await tile('الغياب')).toHaveTextContent('1')
  expect(await tile('الإجازات')).toHaveTextContent('2')
  expect(await tile('الراحة والعطلات')).toHaveTextContent('4')
  expect(await tile('غير مسجلة')).toHaveTextContent('3')
})

test('office adds the computed lateness after its times', async () => {
  const office = { ...engineer, period: { ...engineer.period, group: 'office', start: '2026-10-01', end: '2026-10-31' }, fields: ['in_time', 'out_time'],
    days: [day('2026-10-01', 'stored', { in_time: '09:40', out_time: '17:00', hints: { late_minutes: 25 } })] }
  mockFetch(routes(office))
  renderWithAuth(<EmployeeAttendance emp={{ ...emp, current_type: 'office' }} />, ['attendance.view.all'], { router: false })
  const row = (await screen.findByRole('button', { name: 'الخميس 2026-10-01: حاضر' })).closest('tr')!
  expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['اليوم', 'الموقع أو نوع اليوم', 'وقت الحضور', 'وقت الانصراف', 'دقائق التأخير'])
  expect(within(row).getAllByRole('cell')[4]).toHaveTextContent('25')
})

test('the arrows move to the neighbouring period by its date, and a locked period says so', async () => {
  const r = routes({ ...engineer, period: { ...engineer.period, is_locked: true } })
  mockFetch(r)
  renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all'], { router: false })
  expect(await screen.findByText('مقفلة')).toBeInTheDocument()
  const range = screen.getByRole('button', { name: 'الفترة السابقة' }).nextElementSibling!
  expect(range).toHaveTextContent('من 2026-09-26 إلى 2026-10-25')
  expect(screen.getByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  expect(r[PERIOD].mock.calls[0][0]).toBe('/api/v1/attendance/employees/e1/period')
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(r[PERIOD].mock.calls.at(-1)?.[0]).toBe('/api/v1/attendance/employees/e1/period?date=2026-09-25'))
})

test('a day opens the day editor, and a save there refreshes the table', async () => {
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

test('in English the labels and the site name are English', async () => {
  await i18n.changeLanguage('en')
  mockFetch(routes())
  renderWithAuth(<EmployeeAttendance emp={emp} />, ['attendance.view.all'], { router: false })
  expect(await screen.findByRole('button', { name: 'Thursday 2026-10-01: Site-1' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Friday 2026-10-02: Friday' })).toBeInTheDocument()
  expect(screen.getByText('Days worked')).toBeInTheDocument()
})
