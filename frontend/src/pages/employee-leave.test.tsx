import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { Leave } from './employee-leave'
import { EmployeeFormPage } from './employees'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-11-04T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const emp = { id: 'e1', name_ar: 'سامي فؤاد', current_type: 'office', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }
const balance = { entitlement: 21, casual_cap: 6, used: 10, casual_used: 3, remaining: 11, casual_remaining: 3, sick_days: 4, unpaid_days: 1 }
const leave = (b: object | null = balance) => json(200, { data: { employee_id: 'e1', year: 2026, type: 'office', hire_date: '2020-03-01', balance: b, adjustment: null } })
const LEAVE = 'GET /api/v1/employees/e1/leave'
const VIEW = ['employee.view.all', 'leave.view.all']

// a stat tile is the group around its label
const tile = (label: string) => screen.getByText(label).closest('[role="group"]') as HTMLElement
const remainingTile = async () => (await screen.findByText('الرصيد المتبقي')).closest('[role="group"]') as HTMLElement

test('the card shows the balance the server computed', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(await remainingTile()).toHaveTextContent('11')
  expect(tile('الرصيد السنوي')).toHaveTextContent('21')
  expect(tile('المستخدم')).toHaveTextContent('10')
  expect(tile('العارضة المستخدمة')).toHaveTextContent('3 من 6')
  expect(tile('أيام الإجازة المرضية')).toHaveTextContent('4')
  expect(tile('أيام الإجازة بدون أجر')).toHaveTextContent('1')
  expect(leaveCalls()).toEqual(['/api/v1/employees/e1/leave?year=2026'])
})

test('an overdrawn balance shows in the destructive colour', async () => {
  mockFetch({ [LEAVE]: () => leave({ ...balance, used: 24, remaining: -3 }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  const remaining = await remainingTile()
  expect(within(remaining).getByText('-3')).toHaveClass('text-destructive')
})

test('a balance that is not overdrawn is not coloured', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  const remaining = await remainingTile()
  expect(within(remaining).getByText('11')).not.toHaveClass('text-destructive')
})

test('casual days over the cap show in the destructive colour', async () => {
  mockFetch({ [LEAVE]: () => leave({ ...balance, casual_used: 7, casual_remaining: -1 }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(within(tile('العارضة المستخدمة')).getByText('7 من 6')).toHaveClass('text-destructive')
})

test('casual days within the cap are not coloured', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(within(tile('العارضة المستخدمة')).getByText('3 من 6')).not.toHaveClass('text-destructive')
})

test('a cached balance is never shown: the card waits for a fresh one', async () => {
  let used = 10
  let release = () => {}
  let hold: Promise<void> | undefined
  mockFetch({ [LEAVE]: () => (hold ?? Promise.resolve()).then(() => leave({ ...balance, used })) as unknown as Response })
  const first = renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(tile('المستخدم')).toHaveTextContent('10')
  first.unmount()

  used = 11 // an attendance edit meanwhile
  hold = new Promise<void>((r) => (release = r))
  vi.setSystemTime(new Date('2026-11-04T10:00:05Z'))
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(screen.queryByText('المستخدم')).toBeNull()
  release()
  await waitFor(() => expect(tile('المستخدم')).toHaveTextContent('11'))
})

test('without a hire date the card asks for one', async () => {
  mockFetch({ [LEAVE]: () => leave({ ...balance, entitlement: null, casual_cap: null, remaining: null, casual_remaining: null }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(await screen.findByText(/سجّل تاريخ التعيين/)).toBeInTheDocument()
  expect(tile('الرصيد المتبقي')).toHaveTextContent('—')
  expect(tile('العارضة المستخدمة')).toHaveTextContent('3')
})

test('the year selector loads the previous and next year', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(screen.getByRole('button', { name: '2026' })).toHaveAttribute('aria-pressed', 'true')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await user.click(screen.getByRole('button', { name: '2027' }))
  expect(leaveCalls()).toEqual(['/api/v1/employees/e1/leave?year=2026', '/api/v1/employees/e1/leave?year=2025', '/api/v1/employees/e1/leave?year=2027'])
})

const detail = (
  <MemoryRouter initialEntries={['/employees/e1?tab=leave']}>
    <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
  </MemoryRouter>
)

const page = (type: string) => mockFetch({
  'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_type: type, current_site: null, site_assignments: [] } }),
  'GET /api/v1/residences': () => json(200, { data: [], meta: { pagination } }),
  [LEAVE]: () => leave(),
})

test('the employee page opens the leave tab', async () => {
  page('office')
  renderWithAuth(detail, VIEW, { router: false })
  expect(await screen.findByRole('tab', { name: 'الإجازات' })).toHaveAttribute('aria-selected', 'true')
  expect(await remainingTile()).toHaveTextContent('11')
})

const detailAt = (url: string) => (
  <MemoryRouter initialEntries={[url]}>
    <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
  </MemoryRouter>
)
const leaveCalls = () => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.startsWith('/api/v1/employees/e1/leave'))

test('the leave tab opens on the year in the link', async () => {
  page('office')
  renderWithAuth(detailAt('/employees/e1?tab=leave&year=2025'), VIEW, { router: false })
  expect(await remainingTile()).toHaveTextContent('11')
  expect(screen.getByRole('button', { name: '2025' })).toHaveAttribute('aria-pressed', 'true')
  expect(leaveCalls()).toEqual(['/api/v1/employees/e1/leave?year=2025'])
})

test('a year the selector does not offer falls back to the current year', async () => {
  page('office')
  renderWithAuth(detailAt('/employees/e1?tab=leave&year=2019'), VIEW, { router: false })
  expect(await remainingTile()).toHaveTextContent('11')
  expect(screen.getByRole('button', { name: '2026' })).toHaveAttribute('aria-pressed', 'true')
  expect(leaveCalls()).toEqual(['/api/v1/employees/e1/leave?year=2026'])
})

test('a day laborer has no leave tab', async () => {
  page('day_laborer')
  renderWithAuth(detail, VIEW, { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'الإجازات' })).toBeNull()
})

test('no leave tab without leave.view', async () => {
  page('engineer')
  renderWithAuth(detail, ['employee.view.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'الإجازات' })).toBeNull()
})

const ADJUST = 'PUT /api/v1/employees/e1/leave/2026'
const ADJUSTER = [...VIEW, 'leave.adjust.all']
const adjustment = { entitlement: 25, opening_used: 2, opening_casual_used: 1, reason: 'رصيد منقول من الدفتر', version: 3 }
const withAdjustment = (a: object | null) => json(200, { data: { employee_id: 'e1', year: 2026, type: 'office', hire_date: '2020-03-01', balance, adjustment: a } })
const puts = () => vi.mocked(fetch).mock.calls.filter(([, init]) => init?.method === 'PUT')
const openAdjust = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.click(await screen.findByRole('button', { name: 'تعديل الرصيد' }))
  return screen.findByRole('dialog')
}

test('the adjust button needs leave.adjust', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(screen.queryByRole('button', { name: 'تعديل الرصيد' })).toBeNull()
})

test('the dialog starts from the stored adjustment and sends it back with its version', async () => {
  mockFetch({ [LEAVE]: () => withAdjustment(adjustment), [ADJUST]: () => withAdjustment({ ...adjustment, opening_used: 4, version: 4 }) })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  expect(within(dialog).getByLabelText(/الرصيد السنوي البديل/)).toHaveValue('25')
  expect(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام')).toHaveValue('2')
  expect(within(dialog).getByLabelText('أيام العارضة المستخدمة قبل النظام')).toHaveValue('1')
  expect(within(dialog).getByLabelText('سبب التعديل')).toHaveValue('رصيد منقول من الدفتر')
  await user.clear(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'))
  await user.type(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'), '٤')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(puts()).toHaveLength(1)
  expect(JSON.parse(String(puts()[0][1]!.body))).toEqual({ entitlement: 25, opening_used: 4, opening_casual_used: 1, reason: 'رصيد منقول من الدفتر', version: 3 })
})

test('a first adjustment sends version null and a blank entitlement as null', async () => {
  mockFetch({ [LEAVE]: () => leave(), [ADJUST]: () => leave() })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  expect(within(dialog).getByLabelText(/الرصيد السنوي البديل/)).toHaveValue('')
  await user.type(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'), '3')
  await user.type(within(dialog).getByLabelText('سبب التعديل'), 'بداية العمل بالنظام')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(puts()).toHaveLength(1))
  expect(JSON.parse(String(puts()[0][1]!.body))).toEqual({ entitlement: null, opening_used: 3, opening_casual_used: 0, reason: 'بداية العمل بالنظام', version: null })
})

test('a changed value needs a reason before anything is sent', async () => {
  mockFetch({ [LEAVE]: () => leave(), [ADJUST]: () => leave() })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  await user.type(within(dialog).getByLabelText(/الرصيد السنوي البديل/), '30')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText('اكتب سبب التعديل.')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('سبب التعديل')).toHaveAttribute('aria-invalid', 'true')
  expect(puts()).toHaveLength(0)
})

test('a non-numeric opening value counts as 0, so it needs no reason and the server judges it', async () => {
  mockFetch({
    [LEAVE]: () => leave(),
    [ADJUST]: () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'opening_used', code: 'integer', message: 'اكتب عددًا صحيحًا.' }] } }),
  })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  await user.type(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'), 'x')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText('اكتب عددًا صحيحًا.')).toBeInTheDocument()
  expect(JSON.parse(String(puts()[0][1]!.body))).toMatchObject({ opening_used: 'x', reason: null })
})

test('the server refusal shows under its field', async () => {
  mockFetch({
    [LEAVE]: () => leave(),
    [ADJUST]: () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'opening_casual_used', code: 'lte', message: 'رسالة الخادم عن العارضة.' }] } }),
  })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  await user.type(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'), '2')
  await user.type(within(dialog).getByLabelText('أيام العارضة المستخدمة قبل النظام'), '3')
  await user.type(within(dialog).getByLabelText('سبب التعديل'), 'تصحيح')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText('رسالة الخادم عن العارضة.')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('أيام العارضة المستخدمة قبل النظام')).toHaveAttribute('aria-invalid', 'true')
  expect(puts()).toHaveLength(1)
})

test('after a stale version the dialog reloads the current values, and the retry sends the version now shown', async () => {
  let stored = adjustment
  mockFetch({
    [LEAVE]: () => withAdjustment(stored),
    [ADJUST]: () => (puts().length === 1 ? json(409, { error: { code: 'concurrency_conflict', message: 'غيّر شخص آخر هذا السجل.' } }) : withAdjustment({ ...stored, version: 6 })),
  })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  stored = { ...adjustment, opening_used: 7, reason: 'تعديل زميل', version: 5 } // someone else saves meanwhile
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText(/عُدِّل هذا السجل/)).toBeInTheDocument()
  expect(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام')).toHaveValue('7')
  expect(within(dialog).getByLabelText('سبب التعديل')).toHaveValue('تعديل زميل')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(JSON.parse(String(puts()[1][1]!.body))).toMatchObject({ opening_used: 7, version: 5 })
})

test('a saved adjustment seeds the next open even before the balance refetch lands', async () => {
  let gets = 0
  mockFetch({
    // the first load answers; every refetch after the save never does
    [LEAVE]: () => (gets++ ? (new Promise(() => {}) as unknown as Response) : withAdjustment(adjustment)),
    [ADJUST]: (_, init) => withAdjustment({ ...adjustment, ...JSON.parse(String(init!.body)), version: 4 }),
  })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, ADJUSTER, { router: false })
  let dialog = await openAdjust(user)
  await user.clear(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'))
  await user.type(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام'), '4')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  dialog = await openAdjust(user)
  expect(within(dialog).getByLabelText('الأيام المستخدمة قبل النظام')).toHaveValue('4')
  expect(within(dialog).queryByText(/عُدِّل هذا السجل/)).toBeNull()
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(puts()).toHaveLength(2))
  expect(JSON.parse(String(puts()[1][1]!.body)).version).toBe(4)
})

test('a save refetches the leave balance, not the employee record', async () => {
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_site: null, site_assignments: [] } }),
    'GET /api/v1/residences': () => json(200, { data: [], meta: { pagination } }),
    [LEAVE]: () => withAdjustment(adjustment),
    [ADJUST]: () => withAdjustment({ ...adjustment, version: 4 }),
  })
  const user = userEvent.setup()
  renderWithAuth(detail, ADJUSTER, { router: false })
  const dialog = await openAdjust(user)
  const records = () => vi.mocked(fetch).mock.calls.filter(([u]) => u === '/api/v1/employees/e1').length
  const before = records()
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.filter(([u]) => String(u).startsWith('/api/v1/employees/e1/leave')).length).toBeGreaterThan(1))
  expect(records()).toBe(before)
})

test('a stored adjustment is shown on the card with its reason', async () => {
  mockFetch({ [LEAVE]: () => withAdjustment(adjustment) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(screen.getByText(/رصيد منقول من الدفتر/)).toBeInTheDocument()
})

const REQUESTS = 'GET /api/v1/leave/requests'
const request = { id: 'r1', employee: { id: 'e1', code: 101, name_ar: 'سامي فؤاد', name_en: null }, kind: 'casual', from_date: '2026-11-10', to_date: '2026-11-11', days: 2, status: 'pending', note: null, requested_by: { id: 'u1', name: 'منى' }, created_at: '2026-11-04T08:00:00Z', version: 1 }
const requestCalls = () => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.startsWith('/api/v1/leave/requests?'))

test('the Leave tab lists the year\'s requests under the balance', async () => {
  mockFetch({ [LEAVE]: () => leave(), [REQUESTS]: () => json(200, { data: [request], meta: { pagination } }) })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, VIEW)
  const list = await screen.findByRole('table', { name: 'طلبات الإجازة' })
  const row = within(list).getAllByRole('row')[1]
  expect(within(row).getByRole('link', { name: 'إجازة عارضة' })).toHaveAttribute('href', '/leave?request=r1')
  expect(row).toHaveTextContent('2026-11-10 – 2026-11-11')
  expect(row).toHaveTextContent('2')
  expect(row).toHaveTextContent('قيد المراجعة')
  expect(new URL(requestCalls()[0], 'http://x').searchParams.toString()).toBe('employee_id=e1&year=2026&per_page=100')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await waitFor(() => expect(new URL(requestCalls().at(-1)!, 'http://x').searchParams.get('year')).toBe('2025'))
})

test('a year without requests says so', async () => {
  mockFetch({ [LEAVE]: () => leave(), [REQUESTS]: () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(await screen.findByText('لا توجد طلبات إجازة.')).toBeInTheDocument()
})

test('the request button opens the dialog for this employee, with no picker', async () => {
  mockFetch({ [LEAVE]: () => leave(), [REQUESTS]: () => json(200, { data: [], meta: { pagination } }), 'GET /api/v1/leave/requests/preview': () => json(200, { data: { dates: [], days: 1, balance, keep: 1, unpaid: 0, remaining_after: 10 } }) })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, [...VIEW, 'leave.request.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'طلب إجازة' }))
  const dialog = await screen.findByRole('dialog', { name: 'طلب إجازة' })
  expect(within(dialog).queryByLabelText('الموظف')).toBeNull()
  fireEvent.change(within(dialog).getByLabelText('من'), { target: { value: '2026-11-10' } })
  fireEvent.change(within(dialog).getByLabelText('إلى'), { target: { value: '2026-11-10' } })
  await waitFor(() => expect(within(dialog).getByRole('status')).toHaveTextContent(/الرصيد بعدها 10$/))
  expect(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes('/preview'))).toContain('employee_id=e1')
})

test('without leave.request there is no request button', async () => {
  mockFetch({ [LEAVE]: () => leave(), [REQUESTS]: () => json(200, { data: [], meta: { pagination } }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await screen.findByText('لا توجد طلبات إجازة.')
  expect(screen.queryByRole('button', { name: 'طلب إجازة' })).toBeNull()
})

test('an archived employee’s leave tab shows the balance and requests without request or adjust', async () => {
  mockFetch({ [LEAVE]: () => leave(), [REQUESTS]: () => json(200, { data: [request], meta: { pagination } }) })
  renderWithAuth(<Leave emp={emp} readOnly />, [...VIEW, 'leave.request.all', 'leave.adjust.all'])
  expect(await remainingTile()).toHaveTextContent('11')
  const list = await screen.findByRole('table', { name: 'طلبات الإجازة' })
  expect(within(list).getByRole('link', { name: 'إجازة عارضة' })).toHaveAttribute('href', '/leave?request=r1')
  expect(screen.queryByRole('button', { name: 'طلب إجازة' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'تعديل الرصيد' })).toBeNull()
})
