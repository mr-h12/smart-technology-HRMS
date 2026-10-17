import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { EmployeeFormPage, EmployeesPage } from './employees'

const residences = () => json(200, { data: [{ id: 'r1', name_ar: 'العجمي' }], meta: { pagination } })

const user = userEvent.setup()

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

const row1 = { id: 'e1', code: 7, name_ar: 'أحمد علي', name_en: 'Ahmed Ali', department: 'المبيعات', hire_date: '2024-02-01', termination_date: null, pay_method: 'cash', current_type: 'engineer' }

const ALL = ['employee.view.all', 'employee.create.all', 'employee.edit.all']
const emp = { id: 'e1', name_ar: 'أحمد', current_type: 'office', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }

function EmployeeDetailRoute({ id, tab }: { id: string; tab?: string }) {
  return (
    <MemoryRouter initialEntries={[`/employees/${id}${tab ? `?tab=${tab}` : ''}`]}>
      <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>
  )
}

test('assigning a manager posts the chosen user and date', async () => {
  const post = vi.fn(() => json(201, { data: { ...emp, current_manager: { id: 'u1', name: 'خالد' } } }))
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': residences,
    'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'خالد', is_active: true }], meta: { pagination } }),
    'POST /api/v1/employees/e1/manager-assignments': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'employee.assign_manager.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين مدير' }))
  await screen.findByRole('option', { name: 'خالد' })
  await user.selectOptions(screen.getByLabelText('المدير'), 'u1')
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-11-01')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ manager_id: 'u1', effective_from: '2026-11-01' })
  expect(await screen.findByText('خالد')).toBeInTheDocument()
})

test('without the permission there is no assign button', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_manager: null } }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.own'], { router: false })
  expect(await screen.findByText('بدون مدير')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تعيين مدير' })).toBeNull()
})

test('manager tab lists the assignment history', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp,
    current_manager: { id: 'u1', name: 'خالد' }, manager_assignments: [{ id: 'm1', manager_id: 'u1', manager_name: 'خالد', effective_from: '2026-01-01' }] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="manager" />, ['employee.view.all'], { router: false })
  const row = await screen.findByRole('row', { name: /2026-01-01/ })
  expect(within(row).getByText('خالد')).toBeInTheDocument()
})

test('a document is updated from its row dialog and the returned record is shown', async () => {
  const put = vi.fn(() => json(200, { data: { ...emp, documents: [{ id: 'd1', kind: 'criminal_record', received_at: '2026-10-01', expires_at: null, note: 'أصل' }] } }))
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'PUT /api/v1/employees/e1/documents/criminal_record': put })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, ALL, { router: false })
  const row = await screen.findByRole('row', { name: /صحيفة الحالة الجنائية/ })
  await user.click(within(row).getByRole('button', { name: 'تعديل' }))
  await user.type(screen.getByLabelText('ملاحظات'), 'أصل')
  await user.type(screen.getByLabelText('تاريخ الاستلام'), '2026-10-01')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((put.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ received_at: '2026-10-01', expires_at: null, note: 'أصل' })
  expect(await within(screen.getByRole('row', { name: /صحيفة الحالة الجنائية/ })).findByText('2026-10-01')).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.filter(([u]) => String(u).endsWith('/employees/e1'))).toHaveLength(1)
})

test('list rows show the employee, ID, type badge, department, hire date and status', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const row = await screen.findByRole('row', { name: /أحمد علي/ })
  expect(within(row).getByText('7')).toBeInTheDocument()
  expect(within(row).getAllByText('مهندس')).toHaveLength(2) // under the name on phones, its own column from sm
  expect(within(row).getByText('المبيعات')).toBeInTheDocument()
  expect(within(row).getByText('2024-02-01')).toBeInTheDocument()
  expect(within(row).getByText('نشط')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'إضافة موظف' })).toBeNull()
})

test('stat tiles show the server totals per type', async () => {
  const totals: Record<string, number> = { '': 40, office: 9, engineer: 7, permanent_worker: 13, day_laborer: 11 }
  mockFetch({
    'GET /api/v1/employees': (url) =>
      json(200, { data: [], meta: { pagination: { ...pagination, total: totals[new URL(url, 'http://x').searchParams.get('type') ?? ''] } } }),
  })
  renderWithAuth(<EmployeesPage />, ['employee.view.all', 'employee.create.all'])
  expect(await within(screen.getByRole('group', { name: 'إجمالي الموظفين' })).findByText('40')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'المهندسون' })).findByText('7')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'عمال اليومية' })).findByText('11')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'إضافة موظف' })).toBeInTheDocument()
})

test('search and type filter the query and clear filters resets them', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const main = () => list.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('per_page=12')).at(-1)!
  await user.type(await screen.findByRole('textbox', { name: 'بحث بالاسم أو الرقم القومي أو الرقم الوظيفي' }), 'أحمد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'نوع الموظف' }), 'engineer')
  await waitFor(() => expect(main()).toContain('type=engineer'))
  expect(decodeURIComponent(main())).toContain('search=أحمد')
  expect(main()).toContain('page=1')
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(main()).not.toContain('type='))
  expect(screen.queryByRole('button', { name: 'مسح عوامل التصفية' })).toBeNull()
})

test('clicking a row opens the employee', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(
    <Routes>
      <Route path="/" element={<EmployeesPage />} />
      <Route path="/employees/:id" element={<p>صفحة الموظف</p>} />
    </Routes>,
    ['employee.view.all'],
  )
  await user.click(await screen.findByText('المبيعات'))
  expect(await screen.findByText('صفحة الموظف')).toBeInTheDocument()
})

test('create form shows a server field error under its field', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'POST /api/v1/employees': () =>
      json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'national_id', code: 'unique', message: 'الرقم القومي مستخدم' }] } }),
  })
  renderWithAuth(<MemoryRouter initialEntries={['/employees/new']}><Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes></MemoryRouter>, ALL, { router: false })
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'أحمد')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const err = await screen.findByText('الرقم القومي مستخدم')
  await waitFor(() => expect(screen.getByLabelText('الرقم القومي')).toHaveAccessibleDescription('الرقم القومي مستخدم'))
  expect(err).toBeInTheDocument()
})

const createForm = () =>
  renderWithAuth(<MemoryRouter initialEntries={['/employees/new']}><Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes></MemoryRouter>, ALL, { router: false })

test('a 500 on save shows the error banner', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'POST /api/v1/employees': () => json(500, { error: { code: 'server_error', message: 'تعذر الحفظ' } }),
  })
  createForm()
  await userEvent.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر الحفظ')
})

test('an error on a field that is not rendered goes to the banner', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'POST /api/v1/employees': () =>
      json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'daily_wage', code: 'required', message: 'الأجر اليومي مطلوب' }] } }),
  })
  createForm()
  await userEvent.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('الأجر اليومي مطلوب')
})

test('a field error is not repeated in the banner', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'POST /api/v1/employees': () =>
      json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'phone', code: 'x', message: 'هاتف خاطئ' }] } }),
  })
  createForm()
  await userEvent.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByText('هاتف خاطئ')
  expect(screen.queryByRole('alert')).toBeNull()
})

test('create sends only the pay fields the type uses', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees': () => json(422, { error: { code: 'v', message: 'm' } }) })
  createForm()
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('المرتب'), '9000')
  await user.selectOptions(screen.getByLabelText('نوع الموظف'), 'day_laborer')
  await user.type(screen.getByLabelText('الأجر اليومي'), '250')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const call = vi.mocked(fetch).mock.calls.find(([, i]) => i?.method === 'POST')!
  const body = JSON.parse(call[1]!.body as string)
  expect(body.daily_wage).toBe('250')
  expect(body).not.toHaveProperty('salary')
})

test('list load failure shows the banner', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
  expect(screen.getAllByRole('alert')).toHaveLength(1)
})

const openEmp = (type_changes: unknown[]) => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'GET /api/v1/employees/e1': () => json(200, { data: { id: 'e1', name_ar: 'أحمد', current_type: 'day_laborer', type_changes, pay_changes: [], documents: [] } }),
    'POST /api/v1/employees/e1/type-changes': () => json(201, { data: {} }),
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })
}
const history = [
  { id: 't1', effective_from: '2020-01-01', type: 'office' },
  { id: 't2', effective_from: '2026-06-01', type: 'day_laborer' },
]

test('pay dialog shapes its fields by the type in force on the chosen date', async () => {
  openEmp(history)
  const user = userEvent.setup()
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1])
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2025-01-01')
  expect(screen.getByLabelText('المرتب')).toBeInTheDocument()
  expect(screen.queryByLabelText('الأجر اليومي')).toBeNull()
  await user.clear(screen.getByLabelText('ساري اعتبارًا من'))
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-07-01')
  expect(screen.getByLabelText('الأجر اليومي')).toBeInTheDocument()
})

test('type-change dialog rejects the type already in force on that date', async () => {
  openEmp(history)
  const user = userEvent.setup()
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[0])
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2025-01-01')
  await user.selectOptions(screen.getByLabelText('نوع الموظف'), 'office')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText(/نوعًا مختلفًا/)).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.some(([, i]) => i?.method === 'POST')).toBe(false)
  await user.selectOptions(screen.getByLabelText('نوع الموظف'), 'engineer')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.some(([, i]) => i?.method === 'POST')).toBe(true))
})

test('Arabic-Indic digits and decimal separator are typed as ASCII', async () => {
  mockFetch({ 'GET /api/v1/residences': residences })
  createForm()
  await userEvent.type(screen.getByLabelText('المرتب'), '١٢٣٫٥۰')
  expect(screen.getByLabelText('المرتب')).toHaveValue('123.50')
})

test('a residences load failure shows the banner', async () => {
  mockFetch({ 'GET /api/v1/residences': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) })
  createForm()
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
})
