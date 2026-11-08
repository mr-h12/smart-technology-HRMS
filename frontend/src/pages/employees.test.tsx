import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { cairoToday } from '@/components/form'
import { queryClient } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { EmployeeFormPage, EmployeeRoute, EmployeesPage } from './employees'

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
  let assigned = false
  const post = vi.fn(() => { assigned = true; return json(201, { data: emp }) })
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: assigned ? { ...emp, current_manager: { id: 'u1', name: 'خالد' } } : emp }), 'GET /api/v1/residences': residences,
    'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'خالد', is_active: true }, { id: 'u3', name: 'معطل', is_active: false }], meta: { pagination } }),
    'POST /api/v1/employees/e1/manager-assignments': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'employee.assign_manager.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين مدير' }))
  await screen.findByRole('option', { name: 'خالد' })
  expect(screen.queryByRole('option', { name: 'معطل' })).toBeNull() // only active accounts can manage
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

const worker = { ...emp, current_type: 'day_laborer', current_site: { id: 's1', name_ar: 'موقع أ', name_en: null }, site_assignments: [
  { id: 'a1', site_id: 's2', site_name_ar: 'موقع ب', site_name_en: null, effective_from: '2026-01-01' },
  { id: 'a2', site_id: 's1', site_name_ar: 'موقع أ', site_name_en: null, effective_from: '2026-06-01' },
] }

test('a worker shows the home site and moves with a return date', async () => {
  const post = vi.fn(() => json(201, { data: {} }))
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: worker }), 'GET /api/v1/residences': residences,
    'GET /api/v1/sites': () => json(200, { data: [{ id: 's1', name_ar: 'موقع أ' }, { id: 's2', name_ar: 'موقع ب' }], meta: { pagination } }),
    'POST /api/v1/employees/e1/site-assignments': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="site" />, ['employee.view.all', 'employee.assign_site.all', 'employee.assign_manager.all'], { router: false })
  expect(await screen.findByRole('term')).toHaveTextContent('الموقع الأساسي')
  expect(screen.getByRole('definition')).toHaveTextContent('موقع أ')
  expect(screen.queryByRole('tab', { name: 'المدير' })).toBeNull() // a worker's manager follows the site
  expect(screen.queryByRole('button', { name: 'تعيين مدير' })).toBeNull()
  expect(screen.getByRole('tab', { name: 'الموقع' })).toHaveAttribute('aria-selected', 'true')
  const rows = screen.getAllByRole('row').slice(1)
  expect(rows.map((r) => r.textContent)).toEqual(['موقع أ2026-06-01', 'موقع ب2026-01-01']) // newest first
  await user.click(screen.getByRole('button', { name: 'نقل إلى موقع' }))
  await screen.findByRole('option', { name: 'موقع ب' })
  await user.selectOptions(screen.getByLabelText('الموقع الأساسي'), 's2')
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-10-21')
  await user.type(screen.getByLabelText('يعود إلى الموقع السابق في'), '2026-10-22')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ site_id: 's2', effective_from: '2026-10-21', returns_on: '2026-10-22' })
  expect(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes('/sites'))).toBe('/api/v1/sites?per_page=100&kind=work&active=true')
})

test('an engineer keeps the manager tab', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_type: 'engineer', current_site: null, site_assignments: [] } }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="manager" />, ['employee.view.all', 'employee.assign_site.all', 'employee.assign_manager.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'المدير' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getByRole('button', { name: 'تعيين مدير' })).toBeInTheDocument()
  expect(screen.queryByRole('tab', { name: 'الموقع' })).toBeNull()
  expect(screen.queryByText('الموقع الأساسي')).toBeNull()
  expect(screen.queryByRole('button', { name: 'نقل إلى موقع' })).toBeNull()
})

test('an office employee has a site as well as a manager, with the move, the site tab and the manager tab', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { ...worker, current_type: 'office', current_manager: { id: 'u1', name: 'خالد' } } }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="site" />, ['employee.view.all', 'employee.assign_site.all', 'employee.assign_manager.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'الموقع' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getAllByRole('term').map((d) => d.textContent)).toEqual(['الموقع الأساسي', 'المدير'])
  expect(screen.getAllByRole('definition').map((d) => d.textContent)).toEqual(['موقع أ', 'خالد'])
  expect(screen.getByRole('tab', { name: 'المدير' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'نقل إلى موقع' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'تعيين مدير' })).toBeInTheDocument()
  expect(screen.getAllByRole('row').slice(1).map((r) => r.textContent)).toEqual(['موقع أ2026-06-01', 'موقع ب2026-01-01'])
})

test('no move button without employee.assign_site', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: worker }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="site" />, ['employee.view.all'], { router: false })
  expect(await screen.findByRole('definition')).toHaveTextContent('موقع أ')
  expect(screen.queryByRole('button', { name: 'نقل إلى موقع' })).toBeNull()
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

test('the list shows HR notes only when the server sends them, and a tap shows the whole note without opening the employee', async () => {
  const note = 'متأخر عن تسليم صورة البطاقة، يتابع مع المدير يوم الأحد'
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [{ ...row1, hr_notes: note }, { ...row1, id: 'e2', name_ar: 'منى', hr_notes: null }], meta: { pagination } }) })
  renderWithAuth(
    <Routes>
      <Route path="/" element={<EmployeesPage />} />
      <Route path="/employees/:id" element={<p>صفحة الموظف</p>} />
    </Routes>,
    ['employee.view.all'],
  )
  expect(await screen.findByRole('columnheader', { name: 'ملاحظات الموارد البشرية' })).toBeInTheDocument()
  const row = screen.getByRole('row', { name: /أحمد علي/ })
  const previews = within(row).getAllByRole('button', { name: note }) // under the name on phones, its own column from md
  expect(previews).toHaveLength(2)
  expect(previews[0]).toHaveAttribute('title', note)
  expect(previews[0]).toHaveAttribute('aria-expanded', 'false')
  await user.click(previews[0])
  expect(previews[0]).toHaveAttribute('aria-expanded', 'true')
  previews[1].focus()
  await user.keyboard('{Enter}')
  expect(previews[1]).toHaveAttribute('aria-expanded', 'true')
  expect(screen.queryByText('صفحة الموظف')).toBeNull()
  expect(within(screen.getByRole('row', { name: /منى/ })).queryByRole('button')).toBeNull()
})

test('without notes in the response there is no notes column', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [row1], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.own'])
  await screen.findByRole('row', { name: /أحمد علي/ })
  expect(screen.queryByRole('columnheader', { name: 'ملاحظات الموارد البشرية' })).toBeNull()
})

test('the phone box turns Arabic digits into ASCII and stops at 11 digits', async () => {
  mockFetch({ 'GET /api/v1/residences': residences })
  renderWithAuth(<MemoryRouter initialEntries={['/employees/new']}><Routes><Route path="employees/new" element={<EmployeeFormPage />} /></Routes></MemoryRouter>, ALL, { router: false })
  const phone = screen.getByLabelText('رقم الهاتف')
  expect(phone).toHaveAttribute('inputmode', 'numeric')
  expect(phone).toHaveAttribute('maxlength', '11')
  await user.type(phone, '٠١٠١٢٣٤٥٦٧٨')
  expect(phone).toHaveValue('01012345678')
})

test('stat tiles come from one summary request', async () => {
  mockFetch({
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
    'GET /api/v1/employees/summary': () => json(200, { data: { total: 40, office: 9, engineer: 7, permanent_worker: 13, day_laborer: 11 } }),
  })
  renderWithAuth(<EmployeesPage />, ['employee.view.all', 'employee.create.all'])
  expect(await within(screen.getByRole('group', { name: 'إجمالي الموظفين' })).findByText('40')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'المهندسون' })).findByText('7')).toBeInTheDocument()
  expect(await within(screen.getByRole('group', { name: 'عمال اليومية' })).findByText('11')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'إضافة موظف' })).toBeInTheDocument()
  const urls = vi.mocked(fetch).mock.calls.map(([u]) => String(u))
  expect(urls.filter((u) => u.includes('/employees/summary'))).toHaveLength(1)
  expect(urls.filter((u) => u.includes('per_page=1&') || u.endsWith('per_page=1'))).toHaveLength(0) // the five count calls are gone
})

test('typing fast sends one search request, 250 ms after the last key', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const box = await screen.findByRole('textbox', { name: 'بحث بالاسم أو الرقم القومي أو الرقم الوظيفي' })
  await user.type(box, 'أحمد')
  expect(box).toHaveValue('أحمد') // the input itself is instant
  const searches = () => list.mock.calls.map(([u]) => decodeURIComponent(String(u))).filter((u) => u.includes('search='))
  expect(searches()).toHaveLength(0)
  await waitFor(() => expect(searches()).toHaveLength(1))
  expect(searches()[0]).toContain('search=أحمد')
})

test('opening an employee already in the cache starts a new form, not the previous one', async () => {
  mockFetch({
    'GET /api/v1/residences': residences,
    'GET /api/v1/employees/A': () => json(200, { data: { ...emp, id: 'A', name_ar: 'أحمد' } }),
    'GET /api/v1/employees/B': () => json(200, { data: { ...emp, id: 'B', name_ar: 'بسمة' } }),
  })
  renderWithAuth(
    <MemoryRouter initialEntries={['/employees/A']}>
      <Link to="/employees/A">إلى أحمد</Link>
      <Link to="/employees/B">إلى بسمة</Link>
      <Routes><Route path="employees/:id" element={<EmployeeRoute />} /></Routes>
    </MemoryRouter>,
    ALL,
    { router: false },
  )
  const name = await screen.findByLabelText('الاسم بالعربية')
  expect(name).toHaveValue('أحمد')
  await user.type(name, ' تعديل')
  await user.click(screen.getByRole('link', { name: 'إلى بسمة' }))
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('بسمة'))
  await user.click(screen.getByRole('link', { name: 'إلى أحمد' })) // A is cached now: the form must not keep B's values
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('أحمد'))
})

test('an employee opened from the cache still waits for the fresh record before the form starts', async () => {
  let name = 'أحمد'
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, name_ar: name } }) })
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  expect(await screen.findByLabelText('الاسم بالعربية')).toHaveValue('أحمد')
  first.unmount()
  name = 'أحمد المعدّل' // someone else saved while this user was away
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  expect(screen.queryByLabelText('الاسم بالعربية')).toBeNull() // the cached copy is not used to start the form
  expect(await screen.findByLabelText('الاسم بالعربية')).toHaveValue('أحمد المعدّل')
  expect(vi.mocked(fetch).mock.calls.filter(([u]) => String(u).endsWith('/employees/e1'))).toHaveLength(2)
})

test('a failed background refetch keeps the open form and what was typed, and shows the banner above it', async () => {
  let fail = false
  mockFetch({ 'GET /api/v1/residences': residences,
    'GET /api/v1/employees/e1': () => (fail ? json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) : json(200, { data: emp })) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  const name = await screen.findByLabelText('الاسم بالعربية')
  await user.type(name, ' تعديل')
  fail = true
  await act(() => queryClient.invalidateQueries())
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
  expect(screen.getByLabelText('الاسم بالعربية')).toBe(name) // the same input, not a remounted one
  expect(name).toHaveValue('أحمد تعديل')
})


test('search and type filter the query and clear filters resets them', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const main = () => list.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('per_page=12')).at(-1)!
  await user.type(await screen.findByRole('textbox', { name: 'بحث بالاسم أو الرقم القومي أو الرقم الوظيفي' }), 'أحمد')
  await user.selectOptions(screen.getByRole('combobox', { name: 'نوع الموظف' }), 'engineer')
  await waitFor(() => expect(main()).toContain('type=engineer'))
  await waitFor(() => expect(decodeURIComponent(main())).toContain('search=أحمد')) // the search follows after the typing pause
  expect(main()).toContain('page=1')
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(main()).not.toContain('type='))
  expect(screen.getByRole('textbox', { name: 'بحث بالاسم أو الرقم القومي أو الرقم الوظيفي' })).toHaveValue('')
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
  await user.type(screen.getByLabelText('بداية الوردية'), '0730') // a leading zero is 24-hour: no AM/PM needed
  await user.type(screen.getByLabelText('انتهاء الوردية'), '3:30')
  await user.click(within(screen.getByRole('group', { name: 'صباحًا أو مساءً: انتهاء الوردية' })).getByRole('button', { name: 'مساءً' }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const call = vi.mocked(fetch).mock.calls.find(([, i]) => i?.method === 'POST')!
  const body = JSON.parse(call[1]!.body as string)
  expect(body.daily_wage).toBe('250')
  expect(body).toMatchObject({ shift_start: '07:30', shift_end: '15:30' })
  expect(body).not.toHaveProperty('salary')
})

test('create is blocked while a shift time has no AM/PM; choosing PM sends 12:00 and 22:15', async () => {
  const post = vi.fn(() => json(201, { data: { id: 'e9' } }))
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees': post })
  createForm()
  const start = await screen.findByLabelText('بداية الوردية')
  const end = screen.getByLabelText('انتهاء الوردية')
  await user.type(start, '12:00')
  await user.type(end, '10:15')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(post).not.toHaveBeenCalled()
  expect(start).toHaveFocus()
  expect(screen.getAllByText('اختر ص أو م.')).toHaveLength(2)
  for (const f of ['بداية الوردية', 'انتهاء الوردية'])
    await user.click(within(screen.getByRole('group', { name: `صباحًا أو مساءً: ${f}` })).getByRole('button', { name: 'مساءً' }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toMatchObject({ shift_start: '12:00', shift_end: '22:15' })
})

test('a pay change is blocked while a shift time has no AM/PM', async () => {
  openEmp(history)
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1])
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2025-01-01')
  await user.type(screen.getByLabelText('انتهاء الوردية'), '11:30')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  expect(screen.getByLabelText('انتهاء الوردية')).toHaveFocus()
  expect(vi.mocked(fetch).mock.calls.some(([, i]) => i?.method === 'POST')).toBe(false)
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

// An own-scoped (manager) record arrives without national_id, insurance_no, bank_account, hr_notes, current_pay and pay_changes.
const scoped = { id: 'e1', name_ar: 'أحمد', gender: 'male', phone: '0100', pay_method: 'cash', current_type: 'office', type_changes: [], documents: [], current_manager: null, manager_assignments: [] }
const sensitive = ['الرقم القومي', 'الرقم التأميني', 'ملاحظات الموارد البشرية', 'الحساب البنكي', 'طريقة صرف المرتب']

test('a record without the sensitive fields hides them, the pay section and the pay history', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: scoped }) })
  const { unmount } = renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.own'], { router: false })
  expect(await screen.findByLabelText('رقم الهاتف')).toBeDisabled()
  for (const label of sensitive) expect(screen.queryByLabelText(label)).toBeNull()
  expect(screen.queryByText('البيانات المالية')).toBeNull()
  unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ['employee.view.own'], { router: false })
  expect(await screen.findByText('سجل تغييرات نوع الموظف')).toBeInTheDocument()
  expect(screen.queryByText('سجل تغييرات الأجر')).toBeNull()
})

test('view-only users with the data see pay read-only', async () => {
  const full = { ...emp, gender: 'male', pay_method: 'bank', bank_account: 'EG01', national_id: '29001011234567', current_pay: null,
    pay_changes: [{ id: 'p1', effective_from: '2026-01-01', salary: '9000.000000', raise: '0', insured_basic: null, daily_wage: null, shift_start: '08:00:00', shift_end: '16:30:00' }] }
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: full }) })
  const { unmount } = renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all'], { router: false })
  expect(await screen.findByLabelText('الرقم القومي')).toBeDisabled()
  expect(screen.getByText('البيانات المالية')).toBeInTheDocument()
  expect(screen.getByLabelText('الحساب البنكي')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ' })).toBeNull()
  unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ['employee.view.all'], { router: false })
  expect(await screen.findByText('سجل تغييرات الأجر')).toBeInTheDocument()
  expect(screen.getByRole('row', { name: /2026-01-01/ })).toHaveTextContent(/8:00 ص.*4:30 م/)
  expect(screen.queryByRole('button', { name: 'إضافة تغيير' })).toBeNull()
})

test('saving a record that came without the sensitive fields does not send them', async () => {
  const patch = vi.fn(() => json(200, { data: scoped }))
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: scoped }), 'PATCH /api/v1/employees/e1': patch })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.own', 'employee.edit.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(patch).toHaveBeenCalled())
  const body = JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)
  for (const k of ['national_id', 'insurance_no', 'bank_account', 'hr_notes']) expect(body).not.toHaveProperty(k)
  expect(body).toMatchObject({ name_ar: 'أحمد', phone: '0100' })
})

test('the archive switch asks for archived employees from page 1 and shows the termination date instead of the status', async () => {
  const list = vi.fn((url: string) => json(200, { data: [url.includes('archived=true') ? { ...row1, termination_date: '2026-01-31' } : row1], meta: { pagination: { ...pagination, total: 30 } } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const main = () => list.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('per_page=12')).at(-1)!
  await screen.findByRole('row', { name: /أحمد علي/ })
  expect(main()).not.toContain('archived')
  await user.click(screen.getByRole('button', { name: 'التالي' })) // page 2, so the switch must send the list back to page 1
  await waitFor(() => expect(main()).toContain('page=2'))
  await user.click(within(screen.getByRole('group', { name: 'عرض الموظفين' })).getByRole('button', { name: 'الأرشيف' }))
  await waitFor(() => expect(main()).toContain('archived=true'))
  expect(main()).toContain('page=1')
  expect(await screen.findByRole('columnheader', { name: 'تاريخ إنهاء الخدمة' })).toBeInTheDocument()
  expect(screen.queryByRole('columnheader', { name: 'الحالة' })).toBeNull()
  expect(await within(screen.getByRole('row', { name: /أحمد علي/ })).findByText('2026-01-31')).toBeInTheDocument()
})

test('an active employee with a future termination date shows when they leave; the others show Active', async () => {
  const today = cairoToday()
  const later = new Date(Date.parse(`${today}T00:00:00Z`) + 864e5).toISOString().slice(0, 10)
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [{ ...row1, termination_date: today }, { ...row1, id: 'e2', name_ar: 'سعيد', termination_date: later }], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  expect(within(await screen.findByRole('row', { name: /أحمد علي/ })).getByText('نشط')).toBeInTheDocument() // the termination day is still worked
  expect(within(screen.getByRole('row', { name: /سعيد/ })).getByText(later)).toHaveAttribute('dir', 'ltr')
  expect(within(screen.getByRole('row', { name: /سعيد/ })).getByText(/ينتهي في/)).toBeInTheDocument()
})

const archived = { ...emp, code: 7, termination_date: '2026-01-31', termination_reason: 'استقالة' }

test('an archived employee is reactivated after confirming, and the cleared dates show', async () => {
  let current: Record<string, unknown> = archived
  const patch = vi.fn(() => { current = { ...archived, termination_date: null, termination_reason: null }; return json(200, { data: current }) })
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: current }), 'PATCH /api/v1/employees/e1': patch })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  expect(await screen.findByLabelText('تاريخ إنهاء الخدمة')).toHaveValue('2026-01-31')
  await user.click(screen.getByRole('button', { name: 'إعادة التفعيل' }))
  expect(within(screen.getByRole('dialog')).getByText(/2026-01-31/)).toBeInTheDocument()
  expect(patch).not.toHaveBeenCalled()
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'إعادة التفعيل' }))
  await waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ termination_date: null, termination_reason: null })
  await waitFor(() => expect(screen.queryByRole('button', { name: 'إعادة التفعيل' })).toBeNull())
  expect(screen.getByLabelText('تاريخ إنهاء الخدمة')).toHaveValue('') // the open form drops the cleared values, so Save cannot send them back
  expect(screen.getByLabelText('سبب إنهاء الخدمة')).toHaveValue('')
})

test('the Reactivate dialog describes its warning to screen readers', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: archived }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await user.click(await screen.findByRole('button', { name: 'إعادة التفعيل' }))
  expect(screen.getByRole('dialog')).toHaveAccessibleDescription(/سيُمسح تاريخ إنهاء الخدمة وسببه/)
})

test('a form dialog without a description has no aria-describedby', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/users': () => json(200, { data: [], meta: { pagination } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'employee.assign_manager.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين مدير' }))
  expect(screen.getByRole('dialog')).not.toHaveAttribute('aria-describedby')
})

test('Reactivate shows only for an archived employee and with employee.edit', async () => {
  const today = cairoToday()
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...archived, termination_date: today } }) })
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await screen.findByLabelText('تاريخ إنهاء الخدمة')
  expect(screen.queryByRole('button', { name: 'إعادة التفعيل' })).toBeNull() // terminated today: still active
  first.unmount()
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: archived }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all'], { router: false })
  await screen.findByLabelText('تاريخ إنهاء الخدمة')
  expect(screen.queryByRole('button', { name: 'إعادة التفعيل' })).toBeNull()
})

const codeRoutes = (patch: (url: string, init?: RequestInit) => Response) =>
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, code: 7 } }), 'PATCH /api/v1/employees/e1/code': patch })
const codeBody = (patch: ReturnType<typeof vi.fn>, i: number) => JSON.parse((patch.mock.calls[i] as unknown as [string, RequestInit])[1].body as string)

test('Change ID shows only with employee.edit_code', async () => {
  codeRoutes(() => json(200, { data: emp }))
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await screen.findByLabelText('الاسم بالعربية')
  expect(screen.queryByRole('button', { name: 'تغيير الرقم الوظيفي' })).toBeNull()
  first.unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" />, [...ALL, 'employee.edit_code.all'], { router: false })
  expect(await screen.findByRole('button', { name: 'تغيير الرقم الوظيفي' })).toBeInTheDocument()
})

test('a taken ID shows the holder and offers to swap, which resends with swap', async () => {
  const patch = vi.fn((_url: string, init?: RequestInit) =>
    JSON.parse(init!.body as string).swap ? json(200, { data: { ...emp, code: 12 } }) : json(409, { error: { code: 'code_taken', message: 'الرقم الوظيفي 12 مستخدم للموظف سعيد' } }))
  codeRoutes(patch)
  renderWithAuth(<EmployeeDetailRoute id="e1" />, [...ALL, 'employee.edit_code.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تغيير الرقم الوظيفي' }))
  const dialog = screen.getByRole('dialog')
  expect(within(dialog).getByText(/قوالب الحضور/)).toBeInTheDocument()
  const box = within(dialog).getByLabelText('الرقم الوظيفي الجديد')
  expect(box).toHaveValue('7')
  await user.clear(box)
  await user.type(box, '12')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('الرقم الوظيفي 12 مستخدم للموظف سعيد')
  expect(codeBody(patch, 0)).toEqual({ code: '12' })
  await user.click(within(dialog).getByRole('button', { name: 'تبديل الرقمين' }))
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(2))
  expect(codeBody(patch, 1)).toEqual({ code: '12', swap: true })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('an ID out of range shows the field error under the box', async () => {
  codeRoutes(() => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'code', code: 'max', message: 'الرقم الوظيفي من 0 إلى 250' }] } }))
  renderWithAuth(<EmployeeDetailRoute id="e1" />, [...ALL, 'employee.edit_code.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تغيير الرقم الوظيفي' }))
  const box = screen.getByLabelText('الرقم الوظيفي الجديد')
  await user.clear(box)
  await user.type(box, '100000')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(box).toHaveAccessibleDescription('الرقم الوظيفي من 0 إلى 250'))
  expect(screen.queryByRole('button', { name: 'تبديل الرقمين' })).toBeNull()
})

test('the ID is sent as typed, trimmed, so the server rejects what is not a whole number; a blank box sends null', async () => {
  const patch = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'code', code: 'integer', message: 'الرقم الوظيفي يجب أن يكون عددًا صحيحًا' }] } }))
  codeRoutes(patch)
  renderWithAuth(<EmployeeDetailRoute id="e1" />, [...ALL, 'employee.edit_code.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تغيير الرقم الوظيفي' }))
  const box = screen.getByLabelText('الرقم الوظيفي الجديد')
  const save = within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' })
  await user.clear(box)
  await user.type(box, ' 1e3 ')
  await user.click(save)
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
  expect(codeBody(patch, 0)).toEqual({ code: '1e3' })
  await user.clear(box)
  await user.type(box, '  ')
  await user.click(save)
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(2))
  expect(codeBody(patch, 1)).toEqual({ code: null })
})

test('editing the ID after a taken-ID answer clears the holder banner and the swap offer', async () => {
  codeRoutes(() => json(409, { error: { code: 'code_taken', message: 'الرقم الوظيفي 12 مستخدم للموظف سعيد' } }))
  renderWithAuth(<EmployeeDetailRoute id="e1" />, [...ALL, 'employee.edit_code.all'], { router: false })
  await user.click(await screen.findByRole('button', { name: 'تغيير الرقم الوظيفي' }))
  const dialog = screen.getByRole('dialog')
  const box = within(dialog).getByLabelText('الرقم الوظيفي الجديد')
  await user.clear(box)
  await user.type(box, '12')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('سعيد')
  await user.type(box, '3')
  expect(within(dialog).queryByRole('alert')).toBeNull()
  expect(within(dialog).getByRole('button', { name: 'حفظ' })).toBeInTheDocument()
})

test('a site manager gets no office option in the type filter and no office tile', async () => {
  mockFetch({
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
    'GET /api/v1/employees/summary': () => json(200, { data: { total: 4, engineer: 1, permanent_worker: 2, day_laborer: 1 } }),
  })
  renderWithAuth(<EmployeesPage />, ['employee.view.own'])
  const filter = await screen.findByRole('combobox', { name: 'نوع الموظف' })
  expect(within(filter).getAllByRole('option').map((o) => o.getAttribute('value'))).toEqual(['', 'engineer', 'permanent_worker', 'day_laborer'])
  expect(screen.getByRole('group', { name: 'المهندسون' })).toBeInTheDocument()
  const tiles = screen.getByRole('group', { name: 'إجمالي الموظفين' }).parentElement!
  expect(tiles.children).toHaveLength(4)
  expect(tiles).toHaveClass('xl:grid-cols-4')
  expect(screen.queryByRole('group', { name: 'موظفو الشركة' })).toBeNull()
})

test('HR keeps the office option and tile', async () => {
  mockFetch({ 'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }) })
  renderWithAuth(<EmployeesPage />, ['employee.view.all'])
  const filter = await screen.findByRole('combobox', { name: 'نوع الموظف' })
  expect(within(filter).getByRole('option', { name: 'موظف مثبت شركة' })).toHaveValue('office')
  const tiles = screen.getByRole('group', { name: 'إجمالي الموظفين' }).parentElement!
  expect(tiles.children).toHaveLength(5)
  expect(tiles).toHaveClass('xl:grid-cols-5')
})

test('the documents tab needs employee_document.view', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': residences })
  const { unmount } = renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, ['employee.view.own'], { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'المستندات' })).toBeNull()
  unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, ['employee.view.all', 'employee_document.view.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'المستندات' })).toHaveAttribute('aria-selected', 'true')
})
