import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination } from '@/test-utils'
import { EmployeeFormPage, EmployeesPage } from './employees'

const residences = () => json(200, { data: [{ id: 'r1', name_ar: 'العجمي' }], meta: { pagination } })

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('list renders rows from the API', async () => {
  mockFetch({
    'GET /api/v1/employees': () =>
      json(200, {
        data: [{ id: 'e1', code: 7, name_ar: 'أحمد علي', department: 'المبيعات', pay_method: 'cash', current_type: 'engineer' }],
        meta: { pagination },
      }),
  })
  render(<MemoryRouter><EmployeesPage /></MemoryRouter>)
  expect(await screen.findByText('أحمد علي')).toBeInTheDocument()
  expect(screen.getByText('المبيعات')).toBeInTheDocument()
  expect(screen.getByText('نقدًا')).toBeInTheDocument()
  expect(within(screen.getByRole('table')).getByText('مهندس')).toBeInTheDocument()
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
  render(<MemoryRouter><EmployeesPage /></MemoryRouter>)
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
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
