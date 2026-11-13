import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { queryClient } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { NotificationBell } from './NotificationBell'

const user = userEvent.setup()
afterEach(async () => {
  vi.unstubAllGlobals()
  await i18n.changeLanguage('ar')
})

const missing = (id: string, read_at: string | null, groups: { group: string; period_start: string; period_end: string; missing: number; open?: number; crew_changes?: number }[]) => ({
  id, type: 'attendance_missing_days', date: '2026-10-20', read_at, data: { date: '2026-10-20', groups },
})
// the engineers' previous and current periods are both open, so the group appears twice
const unread = missing('n1', null, [
  { group: 'engineer', period_start: '2026-08-26', period_end: '2026-09-25', missing: 3 },
  { group: 'engineer', period_start: '2026-09-26', period_end: '2026-10-25', missing: 22 },
])
const read = missing('n2', '2026-10-19T08:00:00Z', [{ group: 'day_laborer', period_start: '2026-10-16', period_end: '2026-10-31', missing: 4 }])
const ahmed = { id: 'e1', code: 1042, name_ar: 'أحمد علي', name_en: 'Ahmed Ali' }
const anniversary = (employee: { id: string; code: number; name_ar: string; name_en: string | null } | null, read_at: string | null = null) => ({
  id: 'a1', type: 'leave_anniversary', date: '2026-11-04', ref: 'e1', read_at, data: { employee_id: 'e1', hire_date: '2025-11-04' }, employee,
})
const inbox = (rows: unknown[]) => () => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })

function Where() {
  const { pathname, search } = useLocation()
  return <output>{pathname + search}</output>
}

test('the bell shows the unread count and the missing-days lines', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([unread, read]) })
  renderWithAuth(<NotificationBell />, [])
  const bell = await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' })
  expect(bell).toHaveTextContent('1')
  await user.click(bell)
  const items = await screen.findAllByRole('menuitem')
  expect(items).toHaveLength(3)
  expect(items[1]).toHaveTextContent('أيام غير مسجلة — المهندسون: 22')
  expect(items[1]).toHaveTextContent('2026-09-26 – 2026-10-25')
  expect(items[0]).toHaveTextContent('أيام غير مسجلة — المهندسون: 3')
  expect(items[2]).toHaveTextContent('أيام غير مسجلة — عمال اليومية: 4')
  expect(screen.getByText(/متابعة الحضور حتى/, { selector: '.font-semibold' })).toBeInTheDocument() // the unread one is bold
})

test('choosing a line marks it read and opens the grid', async () => {
  const patch = vi.fn(() => json(200, { data: { ...unread, read_at: '2026-10-20T09:00:00Z' } }))
  mockFetch({ 'GET /api/v1/notifications': inbox([unread]), 'PATCH /api/v1/notifications/n1/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [], { path: '/employees' })
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /المهندسون: 22/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/attendance?group=engineer&period=2026-09-26&tab=grid')
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
})

test('choosing a line of a read notification sends nothing', async () => {
  const patch = vi.fn(() => json(200, { data: read }))
  mockFetch({ 'GET /api/v1/notifications': inbox([read]), 'PATCH /api/v1/notifications/n2/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /عمال اليومية: 4/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/attendance?group=day_laborer&period=2026-10-16&tab=grid')
  expect(patch).not.toHaveBeenCalled()
})

test('no unread notifications, no badge', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([]) })
  renderWithAuth(<NotificationBell />, [])
  const bell = await screen.findByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' })
  expect(bell).toHaveTextContent(/^$/)
  await user.click(bell)
  expect(await screen.findByText('لا توجد إشعارات.')).toBeInTheDocument()
})

test('more than nine unread show as 9+', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox(Array.from({ length: 10 }, (_, i) => missing(`n${i}`, null, []))) })
  renderWithAuth(<NotificationBell />, [])
  expect(await screen.findByRole('button', { name: 'فتح الإشعارات (10 غير مقروء)' })).toHaveTextContent(/^9\+$/)
})

test('an unread notification of an unknown type is neither counted nor listed', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([{ id: 'x1', type: 'something_new', date: '2026-10-20', read_at: null, data: {} }]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(screen.getByRole('button', { name: /^فتح الإشعارات/ }))
  expect(await screen.findByText('لا توجد إشعارات.')).toBeInTheDocument() // loaded
  expect(screen.getByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' })).toHaveTextContent(/^$/)
})

test('a failed load says so in a short line, without the server dump', async () => {
  mockFetch({ 'GET /api/v1/notifications': () => json(500, { error: { code: 'server_error', message: 'SQLSTATE[42P01]: Undefined table' } }) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(screen.getByRole('button', { name: /^فتح الإشعارات/ }))
  expect(await screen.findByText('تعذّر تحميل الإشعارات.')).toBeInTheDocument()
  expect(screen.queryByText(/SQLSTATE/)).toBeNull()
  expect(screen.queryByText('لا توجد إشعارات.')).toBeNull()
})

test('a failed refetch keeps the last unread count and lines', async () => {
  let fail = false
  mockFetch({ 'GET /api/v1/notifications': () => (fail ? json(500, { error: { code: 'server_error', message: 'Boom' } }) : inbox([unread])()) })
  renderWithAuth(<NotificationBell />, [])
  await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' })
  fail = true
  await act(() => queryClient.invalidateQueries({ queryKey: ['notifications'] }))
  await user.click(screen.getByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  expect(await screen.findByText('تعذّر تحميل الإشعارات.')).toBeInTheDocument()
  expect(screen.getAllByRole('menuitem')).toHaveLength(2)
})

test('each notification is a group named by its title, and unread lines say so', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([unread, read]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  const groups = await screen.findAllByRole('group', { name: 'متابعة الحضور حتى 2026-10-20' })
  expect(groups).toHaveLength(2)
  // the unread word comes first, then a pause; a separator in the DOM keeps the count and the range apart in every screen reader
  const line = within(groups[0]).getByRole('menuitem', { name: /المهندسون: 22/ })
  expect(line).toHaveAccessibleName(/^غير مقروء، أيام غير مسجلة — المهندسون: 22،\s*2026-09-26 – 2026-10-25$/)
  expect(line.textContent).toContain('22، 2026-09-26')
  expect(within(groups[1]).getByRole('menuitem', { name: /عمال اليومية: 4/ })).not.toHaveAccessibleName(/غير مقروء/)
})

test('in English an unread line also starts with the unread word', async () => {
  await i18n.changeLanguage('en')
  mockFetch({ 'GET /api/v1/notifications': inbox([unread]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'Open notifications (1 unread)' }))
  expect(await screen.findByRole('menuitem', { name: /Engineers: 22/ })).toHaveAccessibleName(/^unread, Missing days, Engineers: 22,\s*2026-09-26 – 2026-10-25$/)
})

test('the badge stays on the bell, clear of the account button beside it', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([unread]) })
  renderWithAuth(<NotificationBell />, [])
  const badge = (await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' })).querySelector('span')!
  expect(badge).toHaveClass('-end-1', '-top-1')
  expect(badge).not.toHaveClass('-end-1.5', '-top-1.5')
})

test('while loading, the menu says so to screen readers', async () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  renderWithAuth(<NotificationBell />, [])
  await user.click(screen.getByRole('button', { name: /^فتح الإشعارات/ }))
  expect(await screen.findByText('جارٍ التحميل…')).toBeInTheDocument()
})

test('a one-year anniversary is listed with the name, code and hire date, and counted', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([anniversary(ahmed), unread]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (2 غير مقروء)' }))
  const line = await screen.findByRole('menuitem', { name: /مرّت سنة على تعيين أحمد علي/ })
  expect(line).toHaveAccessibleName(/^غير مقروء، مرّت سنة على تعيين أحمد علي \(1042\)،\s*تاريخ التعيين 2025-11-04$/)
  // in RTL the code stays an LTR run beside the name, in its parentheses
  expect(within(line).getByText('1042')).toHaveAttribute('dir', 'ltr')
})

test('choosing an anniversary marks it read and opens the employee on the Leave tab of the anniversary year', async () => {
  const lateDecember = { ...anniversary(ahmed), date: '2025-12-29' } // opened in January, it still lands on 2025
  const patch = vi.fn(() => json(200, { data: { ...lateDecember, read_at: '2026-01-04T09:00:00Z' } }))
  mockFetch({ 'GET /api/v1/notifications': inbox([lateDecember]), 'PATCH /api/v1/notifications/a1/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /مرّت سنة على تعيين/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/employees/e1?tab=leave&year=2025')
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
})

test('an anniversary whose employee is gone reads as a generic line, and choosing it only marks it read', async () => {
  const patch = vi.fn(() => json(200, { data: { ...anniversary(null), read_at: '2026-11-04T09:00:00Z' } }))
  mockFetch({ 'GET /api/v1/notifications': inbox([anniversary(null)]), 'PATCH /api/v1/notifications/a1/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [], { path: '/employees' })
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  const line = await screen.findByRole('menuitem')
  expect(line).toHaveAccessibleName(/^غير مقروء، مرّت سنة على تعيين أحد الموظفين،\s*تاريخ التعيين 2025-11-04$/)
  await user.click(line)
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
  expect(screen.getByRole('status')).toHaveTextContent(/^\/employees$/) // the employee page would be a 404
})

test('in English an anniversary uses the English name, else the Arabic one', async () => {
  await i18n.changeLanguage('en')
  mockFetch({ 'GET /api/v1/notifications': inbox([anniversary(ahmed), { ...anniversary({ ...ahmed, name_en: null }), id: 'a2' }]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'Open notifications (2 unread)' }))
  const [en, ar] = await screen.findAllByRole('menuitem')
  expect(en).toHaveAccessibleName(/^unread, One year since Ahmed Ali \(1042\) was hired,\s*Hired 2025-11-04$/)
  expect(ar).toHaveTextContent('One year since أحمد علي (1042) was hired')
})

const requested = (employee: { id: string; code: number; name_ar: string; name_en: string | null } | null, read_at: string | null = null) => ({
  id: 'q1', type: 'leave_requested', date: '2026-11-04', ref: 'r1', read_at, employee,
  data: { employee_id: 'e1', request_id: 'r1', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-12', days: 3 },
})

test('a new leave request is listed with the name, code, kind and dates, and counted', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([requested(ahmed), read]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  const line = await screen.findByRole('menuitem', { name: /طلب إجازة جديد/ })
  expect(line).toHaveAccessibleName(/^غير مقروء، طلب إجازة جديد: أحمد علي \(1042\)،\s*إجازة اعتيادية: 2026-11-10 – 2026-11-12$/)
  expect(within(line).getByText('1042')).toHaveAttribute('dir', 'ltr')
})

test('choosing a new leave request marks it read and opens it on the leave page', async () => {
  const patch = vi.fn(() => json(200, { data: { ...requested(ahmed), read_at: '2026-11-04T09:00:00Z' } }))
  mockFetch({ 'GET /api/v1/notifications': inbox([{ ...requested(ahmed), ref: null }]), 'PATCH /api/v1/notifications/q1/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /طلب إجازة جديد/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/leave?request=r1')
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
})

test('a leave request about someone the reader cannot name reads as a generic line, and choosing it only marks it read', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([requested(null, '2026-11-04T09:00:00Z')]) })
  renderWithAuth(<><NotificationBell /><Where /></>, [], { path: '/leave' })
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' }))
  const line = await screen.findByRole('menuitem')
  expect(line).toHaveAccessibleName(/^طلب إجازة جديد،\s*إجازة اعتيادية: 2026-11-10 – 2026-11-12$/)
  await user.click(line)
  expect(screen.getByRole('status')).toHaveTextContent(/^\/leave$/) // the request would be a 404
})

test('in English a new leave request uses the English name', async () => {
  await i18n.changeLanguage('en')
  mockFetch({ 'GET /api/v1/notifications': inbox([requested(ahmed)]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'Open notifications (1 unread)' }))
  expect(await screen.findByRole('menuitem')).toHaveAccessibleName(/^unread, New leave request: Ahmed Ali \(1042\),\s*Annual leave: 2026-11-10 – 2026-11-12$/)
})

const escalated = (employee: { id: string; code: number; name_ar: string; name_en: string | null } | null) => ({
  id: 'x1', type: 'leave_escalated', date: '2026-11-04', ref: 'r1', read_at: null, employee,
  data: { employee_id: 'e1', request_id: 'r1', kind: 'casual', from_date: '2026-11-10', to_date: '2026-11-11', days: 2 },
})
const decided = (status: string, employee: { id: string; code: number; name_ar: string; name_en: string | null } | null = ahmed) => ({
  id: `d-${status}`, type: 'leave_decided', date: '2026-11-04', ref: 'r2', read_at: null, employee,
  data: { employee_id: 'e1', request_id: 'r2', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-12', days: 3, status },
})

test('an escalated leave request is listed for its decider and opens the request', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([escalated(ahmed)]), 'PATCH /api/v1/notifications/x1/read': () => json(200, { data: {} }) })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  const line = await screen.findByRole('menuitem', { name: /أُحيل إليك طلب إجازة/ })
  expect(line).toHaveAccessibleName(/^غير مقروء، أُحيل إليك طلب إجازة: أحمد علي \(1042\)،\s*إجازة عارضة: 2026-11-10 – 2026-11-11$/)
  await user.click(line)
  expect(screen.getByRole('status')).toHaveTextContent('/leave?request=r1')
})

test('an approved and a refused leave are told apart, and a named one opens its request', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([decided('approved'), decided('refused', null)]), 'PATCH /api/v1/notifications/d-approved/read': () => json(200, { data: {} }) })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (2 غير مقروء)' }))
  expect(await screen.findByRole('menuitem', { name: /رُفض طلب إجازة/ })).toBeInTheDocument()
  await user.click(screen.getByRole('menuitem', { name: /تمت الموافقة على إجازة أحمد علي \(1042\)/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/leave?request=r2')
})

const cancelled = (employee: { id: string; code: number; name_ar: string; name_en: string | null } | null) => ({
  id: 'c1', type: 'leave_cancelled', date: '2026-11-05', ref: 'r3', read_at: null, employee,
  data: { employee_id: 'e1', request_id: 'r3', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-12', days: 3 },
})

test('a cancelled leave request is listed and counted, and opens the request', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([cancelled(ahmed)]), 'PATCH /api/v1/notifications/c1/read': () => json(200, { data: {} }) })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  const line = await screen.findByRole('menuitem')
  expect(line).toHaveAccessibleName(/^غير مقروء، أُلغي طلب إجازة أحمد علي \(1042\)،\s*إجازة اعتيادية: 2026-11-10 – 2026-11-12$/)
  await user.click(line)
  expect(screen.getByRole('status')).toHaveTextContent('/leave?request=r3')
})

test('a cancelled leave request about someone the reader cannot name reads as a generic line', async () => {
  await i18n.changeLanguage('en')
  mockFetch({ 'GET /api/v1/notifications': inbox([cancelled(null)]) })
  renderWithAuth(<NotificationBell />, [])
  await user.click(await screen.findByRole('button', { name: 'Open notifications (1 unread)' }))
  expect(await screen.findByRole('menuitem')).toHaveAccessibleName(/^unread, A leave request was cancelled,\s*Annual leave: 2026-11-10 – 2026-11-12$/)
})

test('the evening alert adds the open days and the crew changes to a group\'s line, and a past-day departure has its own line', async () => {
  mockFetch({
    'GET /api/v1/notifications': inbox([
      missing('m1', null, [
        { group: 'day_laborer', period_start: '2026-10-16', period_end: '2026-10-31', missing: 3, open: 2, crew_changes: 4 },
        { group: 'engineer', period_start: '2026-09-26', period_end: '2026-10-25', missing: 0, open: 1, crew_changes: 0 },
      ]),
      { id: 'w1', type: 'wage_proposed', date: '2026-11-04', ref: 'p1', read_at: null, data: { employee_id: 'e1', daily_wage: '350', site_name_ar: 'Site-1', site_name_en: null }, employee: ahmed },
      { id: 'x1', type: 'attendance_past_edit', date: '2026-11-04', ref: 'd1', read_at: null, data: { employee_id: 'e1', date: '2026-11-02', out_time: '16:00', site_name_ar: 'Site-1', site_name_en: null, group: 'engineer', period_start: '2026-10-26' }, employee: ahmed },
    ]),
    'PATCH /api/v1/notifications/x1/read': () => json(200, { data: {} }),
  })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: /فتح الإشعارات/ }))
  const items = (await screen.findAllByRole('menuitem')).map((i) => i.textContent)
  expect(items[0]).toContain('أيام غير مسجلة — عمال اليومية: 3 · بلا انصراف: 2 · تغييرات الطاقم: 4')
  expect(items[1]).toContain('المهندسون · بلا انصراف: 1')
  expect(items[2]).toContain('أجر يومي مقترح لـ أحمد علي (1042)')
  expect(items[2]).not.toContain('350')
  expect(items[3]).toContain('انصراف أحمد علي (1042) ليوم 2026-11-02 بعد المهلة')
  expect(items[3]).toContain('Site-1')
  await user.click(screen.getByRole('menuitem', { name: /بعد المهلة/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/attendance?group=engineer&period=2026-10-26&tab=grid')
})

test('a proposed wage opens that proposal on the proposed-wages page', async () => {
  const patch = vi.fn(() => json(200, { data: {} }))
  mockFetch({
    'GET /api/v1/notifications': inbox([{ id: 'w1', type: 'wage_proposed', date: '2026-11-04', ref: 'p1', read_at: null, data: { employee_id: 'e1', daily_wage: '350', site_name_ar: 'Site-1', site_name_en: null }, employee: ahmed }]),
    'PATCH /api/v1/notifications/w1/read': patch,
  })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: /فتح الإشعارات/ }))
  await user.click(await screen.findByRole('menuitem', { name: /أجر يومي مقترح/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/wage-proposals?proposal=p1')
})
