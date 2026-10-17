import { render, screen, waitFor, within } from '@testing-library/react'
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

test('list rows show the employee, ID, type badge, department, hire date and status', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const row = await screen.findByRole('row', { name: /أحمد علي/ })
  expect(within(row).getByText('7')).toBeInTheDocument()
  expect(within(row).getByText('مهندس')).toBeInTheDocument()
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
  render(
    <MemoryRouter initialEntries={['/employees/new']}>
      <Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>,
  )
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'أحمد')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const err = await screen.findByText('الرقم القومي مستخدم')
  await waitFor(() => expect(screen.getByLabelText('الرقم القومي')).toHaveAccessibleDescription('الرقم القومي مستخدم'))
  expect(err).toBeInTheDocument()
})

const createForm = () =>
  render(
    <MemoryRouter initialEntries={['/employees/new']}>
      <Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>,
  )

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

test('Enter in a document row does not submit the employee form', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'GET /api/v1/employees/e1': () => json(200, { data: { id: 'e1', name_ar: 'أحمد', current_type: 'office', type_changes: [], pay_changes: [], documents: [] } }),
  })
  render(
    <MemoryRouter initialEntries={['/employees/e1']}>
      <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>,
  )
  const note = (await screen.findAllByLabelText('ملاحظات'))[0]
  await userEvent.type(note, 'x{Enter}')
  expect(vi.mocked(fetch).mock.calls.some(([, i]) => i?.method === 'PATCH')).toBe(false)
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
  render(
    <MemoryRouter initialEntries={['/employees/e1']}>
      <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
    </MemoryRouter>,
  )
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
