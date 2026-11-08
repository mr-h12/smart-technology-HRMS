import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Link, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { LeavePage } from './leave'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-04T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const row = { id: 'e1', code: 'E-101', name_ar: 'سامي فؤاد', name_en: 'Sami Fouad', type: 'engineer', hire_date: '2020-03-01', entitlement: 21, casual_cap: 6, used: 10, casual_used: 3, remaining: 11, casual_remaining: 3, sick_days: 4, unpaid_days: 1 }
const BALANCES = 'GET /api/v1/leave/balances'
const balances = (rows: object[] = [row]) => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })

function Opened() {
  const l = useLocation()
  return <p>{`opened ${l.pathname}${l.search}`}</p>
}
const page = (
  <Routes>
    <Route path="leave" element={<LeavePage />} />
    <Route path="employees/:id" element={<Opened />} />
  </Routes>
)
const render = () => renderWithAuth(page, ['leave.view.all'], { path: '/leave?tab=balances' })
const lastQuery = () => new URL(String(vi.mocked(fetch).mock.calls.at(-1)![0]), 'http://x').searchParams
const cellOf = (r: HTMLElement, header: string) => {
  const i = within(screen.getAllByRole('row')[0]).getAllByRole('columnheader').findIndex((h) => h.textContent === header)
  return within(r).getAllByRole('cell')[i]
}

test('each row shows the balance the server computed', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(r).toHaveTextContent('E-101')
  expect(r).toHaveTextContent('مهندس')
  expect(cellOf(r, 'الرصيد السنوي')).toHaveTextContent('21')
  expect(cellOf(r, 'المستخدم')).toHaveTextContent('10')
  expect(cellOf(r, 'العارضة المستخدمة')).toHaveTextContent('3 من 6')
  expect(cellOf(r, 'مرضي')).toHaveTextContent('4')
  expect(cellOf(r, 'بدون أجر')).toHaveTextContent('1')
  expect(cellOf(r, 'الرصيد المتبقي')).toHaveTextContent('11')
  expect(within(cellOf(r, 'الرصيد المتبقي')).getByText('11')).not.toHaveClass('text-destructive')
  expect(lastQuery().get('year')).toBe('2026')
})

test('an overdrawn balance shows in the destructive colour', async () => {
  mockFetch({ [BALANCES]: () => balances([{ ...row, used: 24, remaining: -3 }]) })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(within(cellOf(r, 'الرصيد المتبقي')).getByText('-3')).toHaveClass('text-destructive')
})

test('the year and type filters reach the request', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  await screen.findByText('سامي فؤاد')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await waitFor(() => expect(lastQuery().get('year')).toBe('2025'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'نوع الموظف' }), 'permanent_worker')
  await waitFor(() => expect(lastQuery().get('type')).toBe('permanent_worker'))
  expect(lastQuery().get('year')).toBe('2025')
  // day laborers have no balance, so they are not a choice
  expect(within(screen.getByRole('combobox', { name: 'نوع الموظف' })).queryByRole('option', { name: 'عامل باليومية' })).toBeNull()
})

test('the search waits for a pause in typing', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  await screen.findByText('سامي فؤاد')
  await user.type(screen.getByRole('textbox'), 'سامي')
  await waitFor(() => expect(lastQuery().get('q')).toBe('سامي'))
  expect(vi.mocked(fetch).mock.calls.map(([u]) => new URL(String(u), 'http://x').searchParams.get('q')).filter(Boolean)).toEqual(['سامي'])
})

test('a row opens the employee on the leave tab through a real link', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  const link = await screen.findByRole('link', { name: 'سامي فؤاد' })
  expect(link).toHaveAttribute('href', '/employees/e1?tab=leave&year=2026')
  await user.click(link)
  expect(await screen.findByText('opened /employees/e1?tab=leave&year=2026')).toBeInTheDocument()
})

test('the row link keeps the selected year', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  await screen.findByText('سامي فؤاد')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await waitFor(() => expect(screen.getByRole('link', { name: 'سامي فؤاد' })).toHaveAttribute('href', '/employees/e1?tab=leave&year=2025'))
})

test('a leaver is marked with the termination date', async () => {
  mockFetch({ [BALANCES]: () => balances([{ ...row, termination_date: '2025-09-30' }]) })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(r).toHaveTextContent('ترك العمل في 2025-09-30')
})

test('an employee leaving later this year is marked with the date to come', async () => {
  mockFetch({ [BALANCES]: () => balances([{ ...row, termination_date: '2026-12-15' }]) })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(r).toHaveTextContent('ينتهي في 2026-12-15')
  expect(r).not.toHaveTextContent('ترك العمل')
})

test('an employee still employed carries no leaver mark', async () => {
  mockFetch({ [BALANCES]: () => balances([{ ...row, termination_date: null }]) })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(r).not.toHaveTextContent('ترك العمل')
  expect(r).not.toHaveTextContent('ينتهي في')
})

test('no rows shows the empty state', async () => {
  mockFetch({ [BALANCES]: () => balances([]) })
  render()
  expect(await screen.findByText('لا توجد نتائج')).toBeInTheDocument()
})

const REQUESTS = 'GET /api/v1/leave/requests'
const req = (id: string, status: string, p: object = {}) => ({
  id, employee: { id: 'e1', code: 101, name_ar: 'سامي فؤاد', name_en: 'Sami Fouad' }, kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-12', days: 3,
  status, note: null, requested_by: { id: 'u2', name: 'مدير' }, created_at: '2026-11-04T08:00:00Z', version: 1, ...p,
})
const requests = (rows: object[]) => () => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })
const at = (path: string, perms = ['leave.view.all', 'leave.request.all', 'leave.adjust.all']) => renderWithAuth(page, perms, { path })
const requestsCall = () => vi.mocked(fetch).mock.calls.map(([u]) => new URL(String(u), 'http://x')).filter((u) => u.pathname === '/api/v1/leave/requests').at(-1)!.searchParams

test('the Requests tab is the default and lists each request in the server order', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending'), req('r2', 'approved', { kind: 'sick', from_date: '2026-10-01', to_date: '2026-10-02', days: 2 })]) })
  at('/leave')
  expect(await screen.findByRole('tab', { name: 'الطلبات' })).toHaveAttribute('aria-selected', 'true')
  const rows = (await screen.findAllByText('سامي فؤاد')).map((n) => n.closest('tr')!)
  expect(rows[0]).toHaveTextContent('101')
  expect(rows[0]).toHaveTextContent('إجازة اعتيادية')
  expect(rows[0]).toHaveTextContent('2026-11-10 – 2026-11-12')
  expect(cellOf(rows[0], 'الأيام المحسوبة')).toHaveTextContent('3')
  expect(rows[0]).toHaveTextContent('قيد المراجعة')
  expect(rows[1]).toHaveTextContent('إجازة مرضية')
  expect(rows[1]).toHaveTextContent('موافق عليه')
  expect(requestsCall().get('year')).toBeNull() // every year: a pending request for next year must not hide
})

test('the status and year filters reach the request', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending')]) })
  const user = userEvent.setup()
  at('/leave')
  await screen.findByText('سامي فؤاد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'الحالة' }), 'pending')
  await waitFor(() => expect(requestsCall().get('status')).toBe('pending'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'السنة' }), '2027')
  await waitFor(() => expect(requestsCall().get('year')).toBe('2027'))
  expect(requestsCall().get('status')).toBe('pending')
})

test('a link to one request opens the Requests tab with that row highlighted behind its dialog', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending'), req('r2', 'pending', { from_date: '2026-12-01', to_date: '2026-12-01', days: 1 })]) })
  at('/leave?tab=balances&request=r2')
  expect(await screen.findByRole('dialog', { name: 'تفاصيل طلب الإجازة' })).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'الطلبات', hidden: true })).toHaveAttribute('aria-selected', 'true')
  const rows = (await screen.findAllByText('سامي فؤاد')).map((n) => n.closest('tr')!)
  expect(rows[1]).toHaveAttribute('aria-current', 'true')
  expect(rows[0]).not.toHaveAttribute('aria-current')
})

test('the Balances tab keeps the balances list', async () => {
  mockFetch({ [BALANCES]: () => balances(), [REQUESTS]: requests([]) })
  const user = userEvent.setup()
  at('/leave')
  await user.click(await screen.findByRole('tab', { name: 'الأرصدة' }))
  expect(await screen.findByText('E-101')).toBeInTheDocument()
})

test('a site manager with leave.request.own sees the request button', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending')]) })
  at('/leave', ['leave.view.own', 'leave.request.own', 'employee.view.own'])
  await screen.findByText('سامي فؤاد')
  expect(screen.getByRole('button', { name: 'طلب إجازة' })).toBeInTheDocument()
})

test('a request whose employee was deleted lists with a dash for the name and code', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending', { employee: null })]) })
  at('/leave')
  const r = (await screen.findByText('إجازة اعتيادية')).closest('tr')!
  expect(cellOf(r, 'الموظف')).toHaveTextContent('—')
  expect(cellOf(r, 'الرقم الوظيفي')).toHaveTextContent('—')
})

test('a link to one request clears the status, year and page the list was left on', async () => {
  mockFetch({ [REQUESTS]: () => json(200, { data: [req('r1', 'pending')], meta: { pagination: { ...pagination, total: 45, total_pages: 3, has_next_page: true } } }) })
  const user = userEvent.setup()
  renderWithAuth(<>{page}<Link to="/leave?request=r2">go</Link></>, ['leave.view.all'], { path: '/leave' })
  await screen.findByText('سامي فؤاد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'الحالة' }), 'refused')
  await user.selectOptions(screen.getByRole('combobox', { name: 'السنة' }), '2025')
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  await waitFor(() => expect(requestsCall().get('page')).toBe('2'))
  await user.click(screen.getByRole('link', { name: 'go' }))
  await waitFor(() => expect(screen.getByRole('combobox', { name: 'الحالة', hidden: true })).toHaveValue(''))
  expect(screen.getByRole('combobox', { name: 'السنة', hidden: true })).toHaveValue('')
  expect(await screen.findByRole('button', { name: 'السابق', hidden: true })).toBeDisabled()
})

test('without leave.request there is no request button', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending')]) })
  at('/leave', ['leave.view.all'])
  await screen.findByText('سامي فؤاد')
  expect(screen.queryByRole('button', { name: 'طلب إجازة' })).toBeNull()
})

test('the request button opens the request dialog', async () => {
  mockFetch({ [REQUESTS]: requests([]), 'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }) })
  const user = userEvent.setup()
  at('/leave')
  await user.click(await screen.findByRole('button', { name: 'طلب إجازة' }))
  expect(await screen.findByRole('dialog', { name: 'طلب إجازة' })).toBeInTheDocument()
})

test('no requests shows the empty state', async () => {
  mockFetch({ [REQUESTS]: requests([]) })
  at('/leave')
  expect(await screen.findByText('لا توجد طلبات إجازة.')).toBeInTheDocument()
})

const shownReq = (id: string, p: object = {}) => ({ ...req(id, 'pending'), decision_reason: null, decided_by: null, decided_at: null, approval: null, ...p })

test('a link to a request not on the loaded page loads it by id and opens it', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending')]), 'GET /api/v1/leave/requests/r9': () => json(200, { data: shownReq('r9', { requested_by: { id: 'u3', name: 'مدير قديم' } }) }) })
  at('/leave?request=r9')
  const dialog = await screen.findByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(dialog).findByText('مدير قديم')).toBeInTheDocument()
})

test('a link to a request the reader cannot see shows the refusal inside the dialog', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending')]), 'GET /api/v1/leave/requests/r9': () => json(404, { error: { code: 'not_found', message: 'السجل غير موجود.' } }) })
  at('/leave?request=r9')
  const dialog = await screen.findByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(dialog).findByText('السجل غير موجود.')).toBeInTheDocument()
})

test('a row opens its request, keeps the list filters, and closing keeps the row highlighted', async () => {
  mockFetch({ [REQUESTS]: requests([req('r1', 'pending'), req('r2', 'pending')]), 'GET /api/v1/leave/requests/r2': () => json(200, { data: shownReq('r2', { requested_by: { id: 'u3', name: 'مدير الموقع' } }) }) })
  const user = userEvent.setup()
  at('/leave')
  await screen.findAllByText('سامي فؤاد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'الحالة' }), 'pending')
  await user.click(screen.getAllByRole('link', { name: 'سامي فؤاد' })[1])
  const dialog = await screen.findByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(dialog).findByText('مدير الموقع')).toBeInTheDocument()
  await user.click(within(dialog).getByRole('button', { name: 'إغلاق' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(screen.getByRole('combobox', { name: 'الحالة' })).toHaveValue('pending')
  expect(screen.getAllByRole('row')[2]).toHaveAttribute('aria-current', 'true')
  await user.click(screen.getAllByRole('link', { name: 'سامي فؤاد' })[1])
  expect(await screen.findByRole('dialog', { name: 'تفاصيل طلب الإجازة' })).toBeInTheDocument()
})

test('without leave.view at all scope the type filter has no office option', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const { unmount } = renderWithAuth(page, ['leave.view.own'], { path: '/leave?tab=balances' })
  await screen.findByText('سامي فؤاد')
  const options = () => within(screen.getByRole('combobox', { name: 'نوع الموظف' })).getAllByRole('option').map((o) => o.getAttribute('value'))
  expect(options()).toEqual(['', 'engineer', 'permanent_worker'])
  unmount()
  render()
  await screen.findByText('سامي فؤاد')
  expect(options()).toEqual(['', 'office', 'engineer', 'permanent_worker'])
})
