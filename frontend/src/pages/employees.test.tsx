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

// A and B are the two engineers of the list; each neighbour is one row of the same list request.
const stepRoutes = (extra = {}) =>
  mockFetch({
    'GET /api/v1/residences': residences,
    'GET /api/v1/employees/A': () => json(200, { data: { ...emp, id: 'A', name_ar: 'أحمد' } }),
    'GET /api/v1/employees/B': () => json(200, { data: { ...emp, id: 'B', name_ar: 'بسمة' } }),
    'GET /api/v1/employees': (url) => json(200, { data: url.endsWith('type=engineer&page=1&per_page=1') ? [{ id: 'A' }] : url.endsWith('type=engineer&page=2&per_page=1') ? [{ id: 'B' }] : [], meta: { pagination } }),
    ...extra,
  })
const openA = (state: object = { list: '/employees?type=engineer', pos: 0, total: 2 }) =>
  renderWithAuth(
    <MemoryRouter initialEntries={[{ pathname: '/employees/A', state }]}>
      <Routes><Route path="employees/:id" element={<EmployeeRoute />} /></Routes>
    </MemoryRouter>,
    ALL,
    { router: false },
  )

test('the arrows step to the next employee of the list it was opened from', async () => {
  stepRoutes()
  openA()
  expect(await screen.findByLabelText('الاسم بالعربية')).toHaveValue('أحمد')
  expect(screen.getByText('1 من 2')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'الموظف السابق' })).toBeDisabled()
  await waitFor(() => expect(screen.getByRole('button', { name: 'الموظف التالي' })).toBeEnabled())
  await user.click(screen.getByRole('button', { name: 'الموظف التالي' }))
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('بسمة'))
  expect(screen.getByText('2 من 2')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'الموظف التالي' })).toBeDisabled()
  await waitFor(() => expect(screen.getByRole('button', { name: 'الموظف السابق' })).toBeEnabled())
  await user.click(screen.getByRole('tab', { name: 'السجل' })) // a tab writes the URL: the list context must survive it
  expect(await screen.findByText('2 من 2')).toBeInTheDocument()
})

test.each([
  ['/leave/balances?year=2026&type=office', '/api/v1/leave/balances?year=2026&type=office&page=2&per_page=1'],
  ['/employees', '/api/v1/employees?page=2&per_page=1'], // a list with no filter at all
])('the arrows step through the list of the screen it was opened from: %s', async (list, request) => {
  const rows = vi.fn((url: string) => json(200, { data: url === request ? [{ id: 'B' }] : [], meta: { pagination } }))
  stepRoutes({ [`GET /api/v1${list.split('?')[0]}`]: rows })
  openA({ list, pos: 0, total: 2 })
  await waitFor(() => expect(screen.getByRole('button', { name: 'الموظف التالي' })).toBeEnabled())
  await user.click(screen.getByRole('button', { name: 'الموظف التالي' }))
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('بسمة'))
  expect(screen.getByText('2 من 2')).toBeInTheDocument()
})

test('the arrows step through the ids of a list the server does not page, with no list request', async () => {
  stepRoutes()
  openA({ ids: ['A', 'B'], pos: 0, total: 2 })
  expect(await screen.findByLabelText('الاسم بالعربية')).toHaveValue('أحمد') // the arrows are live at once; click the form's, not the skeleton's
  await user.click(screen.getByRole('button', { name: 'الموظف التالي' }))
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('بسمة'))
  expect(screen.getByText('2 من 2')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'الموظف السابق' })).toBeEnabled()
  expect(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => /per_page=1(&|$)/.test(u))).toEqual([])
})

test('the list takes its filters from the URL and an opened row knows its place in the whole list', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [row1], meta: { pagination: { ...pagination, total: 13 } } }))
  mockFetch({ 'GET /api/v1/employees': list, 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': residences })
  renderWithAuth(
    <Routes>
      <Route path="employees" element={<EmployeesPage />} />
      <Route path="employees/:id" element={<EmployeeRoute />} />
    </Routes>,
    ALL,
    { path: '/employees?type=engineer&page=2' },
  )
  expect(await screen.findByRole('combobox', { name: 'نوع الموظف' })).toHaveValue('engineer')
  expect(list.mock.calls.map(([u]) => u)).toContain('/api/v1/employees?page=2&per_page=12&type=engineer')
  await user.click(await screen.findByText('المبيعات'))
  expect(await screen.findByText('13 من 13')).toBeInTheDocument() // the first row of page 2
  await waitFor(() => expect(list.mock.calls.map(([u]) => u)).toContain('/api/v1/employees?type=engineer&page=12&per_page=1'))
})

test('a horizontal swipe moves to the next employee; not a mostly vertical one, one on a focused field or one while a dialog is open', async () => {
  stepRoutes()
  openA()
  const swipe = (dx: number, dy: number, on: EventTarget = window) =>
    act(() => {
      on.dispatchEvent(Object.assign(new Event('touchstart', { bubbles: true }), { touches: [{ clientX: 300, clientY: 300 }] }))
      window.dispatchEvent(Object.assign(new Event('touchend'), { changedTouches: [{ clientX: 300 + dx, clientY: 300 + dy }] }))
    })
  await waitFor(() => expect(screen.getByRole('button', { name: 'الموظف التالي' })).toBeEnabled())
  const name = screen.getByLabelText('الاسم بالعربية')
  swipe(100, 80)
  expect(name).toHaveValue('أحمد')
  const dialog = document.createElement('div') // e.g. the phone nav sheet, whose backdrop is outside it
  dialog.setAttribute('role', 'dialog')
  document.body.append(dialog)
  swipe(100, 10)
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('أحمد')
  dialog.remove()
  act(() => name.focus()) // dragging the caret
  swipe(100, 10, name)
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('أحمد')
  act(() => name.blur())
  swipe(100, 10, name) // to the right: in Arabic the next employee comes in from the left; on a phone most swipes start on a field
  await waitFor(() => expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('بسمة'))
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

test('rapid employee form submissions send only one create request', async () => {
  const post = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'راجع البيانات' } }))
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees': post })
  createForm()
  const form = screen.getByRole('button', { name: 'حفظ' }).closest('form')!
  act(() => {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  })
  expect(post).toHaveBeenCalledTimes(1)
  expect(screen.getByRole('button', { name: 'جارٍ الحفظ…' })).toBeDisabled()
  expect(await screen.findByRole('button', { name: 'حفظ' })).toBeEnabled()
})

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

test('pay dialog opens with the current pay', async () => {
  const post = vi.fn(() => json(201, { data: {} }))
  const current_pay = { id: 'p1', effective_from: '2026-01-01', salary: '9000.000000', raise: '500.000000', insured_basic: '5000.000000', daily_wage: null, shift_start: '10:00:00', shift_end: '18:00:00' }
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees/e1/pay-changes': post,
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_pay, pay_changes: [current_pay] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1])
  expect(screen.getByLabelText('المرتب')).toHaveValue('9000')
  expect(screen.getByLabelText('العلاوة')).toHaveValue('500')
  expect(screen.getByLabelText('الأساسي التأميني')).toHaveValue('5000')
  expect(screen.getByLabelText('بداية الوردية')).toHaveValue('10:00')
  expect(screen.getByLabelText('انتهاء الوردية')).toHaveValue('6:00')
  // a cleared field goes out blank, so the server clears it instead of keeping the pay in force
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-11-01')
  await user.clear(screen.getByLabelText('الأساسي التأميني'))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string))
    .toEqual({ effective_from: '2026-11-01', salary: '9000', raise: '500', insured_basic: '', shift_start: '10:00', shift_end: '18:00' })
})

const now = { id: 'p1', effective_from: '2026-01-01', salary: '9000.000000', raise: '500.000000', insured_basic: '5000.000000', daily_wage: null, shift_start: '10:00:00', shift_end: '18:00:00' }
const ahead = { id: 'p2', effective_from: '2026-12-01', salary: '10000.000000', raise: '600.000000', insured_basic: '5500.000000', daily_wage: null, shift_start: '08:00:00', shift_end: '16:00:00' }

test('pay dialog fills from the pay in force on the chosen date', async () => {
  const post = vi.fn(() => json(201, { data: {} }))
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees/e1/pay-changes': post,
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_pay: now, pay_changes: [now, ahead] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1])
  const date = screen.getByLabelText('ساري اعتبارًا من')
  await user.type(date, '2025-06-01') // before the first row: nothing in force
  expect(screen.getByLabelText('المرتب')).toHaveValue('')
  await user.clear(date)
  await user.type(date, '2027-01-01') // the December row entered ahead is in force then, not today's
  expect(screen.getByLabelText('المرتب')).toHaveValue('10000')
  expect(screen.getByLabelText('بداية الوردية')).toHaveValue('8:00')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string))
    .toEqual({ effective_from: '2027-01-01', salary: '10000', raise: '600', insured_basic: '5500', shift_start: '08:00', shift_end: '16:00' })
})

test('a date change keeps the pay HR typed and refills only the untouched fields', async () => {
  const post = vi.fn(() => json(201, { data: {} }))
  mockFetch({ 'GET /api/v1/residences': residences, 'POST /api/v1/employees/e1/pay-changes': post,
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_pay: now, pay_changes: [now, ahead] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })
  await user.click((await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1])
  await user.clear(screen.getByLabelText('المرتب'))
  await user.type(screen.getByLabelText('المرتب'), '9500') // typed over today's 9000, before the date
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2027-01-01') // the December row is in force then
  expect(screen.getByLabelText('المرتب')).toHaveValue('9500')
  expect(screen.getByLabelText('العلاوة')).toHaveValue('600')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string))
    .toEqual({ effective_from: '2027-01-01', salary: '9500', raise: '600', insured_basic: '5500', shift_start: '08:00', shift_end: '16:00' })
})

test('a reopened pay dialog drops the last error', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'POST /api/v1/employees/e1/pay-changes': () => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [{ field: 'salary', code: 'x', message: 'المرتب مطلوب' }] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, ALL, { router: false })
  const add = (await screen.findAllByRole('button', { name: 'إضافة تغيير' }))[1]
  await user.click(add)
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('المرتب مطلوب')).toBeInTheDocument()
  await user.keyboard('{Escape}')
  await user.click(add)
  expect(screen.getByRole('dialog')).toBeInTheDocument()
  expect(screen.queryByText('المرتب مطلوب')).toBeNull()
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

test('the archive switch shows only with employee.view.all, and ?view=archived without it lists the active employees', async () => {
  const list = vi.fn((_url: string) => json(200, { data: [row1], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/employees': list })
  renderWithAuth(<EmployeesPage />, ['employee.view.own'], { path: '/employees?view=archived' })
  await screen.findByRole('row', { name: /أحمد علي/ })
  expect(screen.queryByRole('group', { name: 'عرض الموظفين' })).toBeNull()
  expect(list.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('per_page=12')).every((u) => !u.includes('archived'))).toBe(true)
  expect(screen.getByRole('columnheader', { name: 'الحالة' })).toBeInTheDocument()
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

const archived = { ...emp, code: 7, termination_date: '2026-01-31', termination_reason: 'استقالة', archived: true }

test('an archived employee is reactivated after confirming, and the cleared dates show', async () => {
  let current: Record<string, unknown> = archived
  const patch = vi.fn(() => { current = { ...archived, termination_date: null, termination_reason: null, archived: false }; return json(200, { data: current }) })
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
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...archived, termination_date: today, archived: false } }) })
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await screen.findByLabelText('تاريخ إنهاء الخدمة')
  expect(screen.queryByRole('button', { name: 'إعادة التفعيل' })).toBeNull() // terminated today: still active
  first.unmount()
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: archived }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all'], { router: false })
  await screen.findByLabelText('تاريخ إنهاء الخدمة')
  expect(screen.queryByRole('button', { name: 'إعادة التفعيل' })).toBeNull()
})

test('an archived employee’s page shows the banner with the date and reason and changes nothing but its termination', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: archived }) })
  const perms = [...ALL, 'employee.edit_code.all', 'employee.assign_manager.all', 'employee.assign_site.all']
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, perms, { router: false })
  const banner = await screen.findByRole('status')
  expect(banner).toHaveTextContent('مؤرشف — ترك العمل في 2026-01-31')
  expect(within(banner).getByText('2026-01-31')).toHaveAttribute('dir', 'ltr')
  expect(banner).toHaveTextContent('السبب: استقالة')
  expect(screen.getByLabelText('الاسم بالعربية')).toBeDisabled()
  for (const name of ['حفظ', 'تغيير الرقم الوظيفي', 'تعيين مدير', 'نقل إلى موقع']) expect(screen.queryByRole('button', { name })).toBeNull()
  expect(screen.getByRole('button', { name: 'إعادة التفعيل' })).toBeInTheDocument()
  first.unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="history" />, perms, { router: false })
  expect(await screen.findByText('سجل تغييرات نوع الموظف')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة تغيير' })).toBeNull()
})

test('correcting the leaving details sends only the termination date and reason', async () => {
  const patch = vi.fn(() => json(200, { data: { ...archived, termination_date: '2026-01-30', termination_reason: 'انتهاء العقد' } }))
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: archived }), 'PATCH /api/v1/employees/e1': patch })
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all'], { router: false })
  await screen.findByRole('status')
  expect(screen.queryByRole('button', { name: 'تصحيح بيانات الترك' })).toBeNull()
  first.unmount()
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تصحيح بيانات الترك' }))
  const dialog = screen.getByRole('dialog', { name: 'تصحيح بيانات الترك' })
  const date = within(dialog).getByLabelText('تاريخ إنهاء الخدمة')
  const reason = within(dialog).getByLabelText('سبب إنهاء الخدمة')
  expect([date, reason].map((f) => (f as HTMLInputElement).value)).toEqual(['2026-01-31', 'استقالة'])
  await user.clear(date)
  await user.type(date, '2026-01-30')
  await user.clear(reason)
  await user.type(reason, 'انتهاء العقد')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ termination_date: '2026-01-30', termination_reason: 'انتهاء العقد' })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(screen.getByLabelText('تاريخ إنهاء الخدمة')).toHaveValue('2026-01-30') // the open form takes the saved values, so a later Save cannot send the old ones
  expect(screen.getByLabelText('سبب إنهاء الخدمة')).toHaveValue('انتهاء العقد')
})

test('an active employee’s page has no archive banner', async () => {
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: { ...archived, termination_date: cairoToday(), archived: false } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  expect(await screen.findByLabelText('الاسم بالعربية')).toBeEnabled()
  expect(screen.queryByRole('status')).toBeNull()
})

test('saving a termination date with open items shows the refusal and links to each item', async () => {
  const items = [{ kind: 'leave_request', id: 'r1', status: 'pending', from_date: '2026-11-12', to_date: '2026-11-13' }, { kind: 'wage_proposal', id: 'p1' }]
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'PATCH /api/v1/employees/e1': () => json(409, { error: { code: 'employee_has_open_items', message: 'M', items } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await user.type(await screen.findByLabelText('تاريخ إنهاء الخدمة'), '2026-11-15')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const alert = await screen.findByRole('alert')
  expect(alert).toHaveTextContent('M')
  const leave = within(alert).getByRole('link', { name: /2026-11-12.*2026-11-13.*قيد المراجعة/ })
  expect(leave).toHaveAttribute('href', '/leave?request=r1')
  expect(within(leave).getByText('2026-11-12')).toHaveAttribute('dir', 'ltr')
  expect(within(alert).getByRole('link', { name: 'يومية مقترحة بانتظار القرار' })).toHaveAttribute('href', '/wage-proposals?proposal=p1')
})

test('a refused save brings its alert into view, without motion for those who ask for less', async () => {
  const scroll = vi.spyOn(Element.prototype, 'scrollIntoView')
  vi.stubGlobal('matchMedia', (q: string) => ({ matches: q === '(prefers-reduced-motion: reduce)' }))
  mockFetch({ 'GET /api/v1/residences': residences, 'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'PATCH /api/v1/employees/e1': () => json(409, { error: { code: 'employee_has_open_items', message: 'M', items: [{ kind: 'wage_proposal', id: 'p1' }] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ALL, { router: false })
  await user.type(await screen.findByLabelText('تاريخ إنهاء الخدمة'), '2026-11-15')
  expect(scroll).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const alert = await screen.findByRole('alert')
  await waitFor(() => expect(scroll).toHaveBeenCalledOnce())
  expect(scroll.mock.contexts[0]).toBe(alert)
  expect(scroll).toHaveBeenCalledWith({ block: 'nearest', behavior: 'auto' })
  scroll.mockRestore()
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

// Make site manager: HR sees `site_manager` (null or the linked account); the CEO and site managers get no key at all.
const SM = ['employee.view.all', 'user.manage.all', 'site.assign_manager.all', 'employee.assign_manager.all']
const account = { id: 'u5', email: 'site.manager@smart.test', is_active: true, role: { id: 'r3', slug: 'manager', name: 'Site manager', name_ar: 'مدير الموقع' }, site_ids: ['s1', 's9'], engineer_ids: ['g1'] }
const smRoutes = (data: object, extra = {}) =>
  mockFetch({
    'GET /api/v1/residences': residences,
    'GET /api/v1/employees/e1': () => json(200, { data }),
    'GET /api/v1/sites': () => json(200, { data: [{ id: 's1', name_ar: 'موقع أ' }, { id: 's2', name_ar: 'موقع ب' }], meta: { pagination } }),
    'GET /api/v1/employees': () => json(200, { data: [{ id: 'e1', name_ar: 'أحمد' }, { id: 'g1', name_ar: 'مهندس أول' }, { id: 'g2', name_ar: 'مهندس ثان' }], meta: { pagination } }),
    'GET /api/v1/employees/site-manager-candidates': () => json(200, { data: [{ id: 'u7', name: 'حساب قديم', email: 'old@smart.test' }, { id: 'u8', name: 'حساب قديم', email: 'old.two@smart.test' }] }),
    ...extra,
  })
const bodyOf = (fn: ReturnType<typeof vi.fn>) => JSON.parse((fn.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)

test('Make site manager shows only with the three permissions, on an active employee whose record says it is not one', async () => {
  smRoutes({ ...emp, site_manager: null })
  const first = renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  expect(await screen.findByRole('button', { name: 'تعيين كمدير موقع' })).toBeInTheDocument()
  first.unmount()
  for (const missing of SM.slice(1)) {
    const r = renderWithAuth(<EmployeeDetailRoute id="e1" />, SM.filter((p) => p !== missing), { router: false })
    await screen.findByLabelText('الاسم بالعربية')
    expect(screen.queryByRole('button', { name: 'تعيين كمدير موقع' })).toBeNull()
    r.unmount()
  }
  smRoutes(emp) // no key: the CEO or a site manager
  const noKey = renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await screen.findByLabelText('الاسم بالعربية')
  expect(screen.queryByRole('button', { name: 'تعيين كمدير موقع' })).toBeNull()
  noKey.unmount()
  smRoutes({ ...archived, site_manager: null })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await screen.findByRole('status')
  expect(screen.queryByRole('button', { name: 'تعيين كمدير موقع' })).toBeNull()
})

test('a linked employee shows the account email, the inactive badge, and the edit button with the two assign permissions', async () => {
  smRoutes({ ...emp, site_manager: { ...account, is_active: false } })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'site.assign_manager.all', 'employee.assign_manager.all'], { router: false })
  const email = await screen.findByText('site.manager@smart.test')
  expect(email).toHaveAttribute('dir', 'ltr')
  const line = email.closest('div')!
  expect(within(line).getByRole('term')).toHaveTextContent('حساب مدير الموقع')
  expect(within(line).getByText('معطّل')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'مواقع ومهندسو مدير الموقع' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تعيين كمدير موقع' })).toBeNull()
})

test('an active linked account shows no inactive badge', async () => {
  smRoutes({ ...emp, site_manager: account })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all'], { router: false })
  await screen.findByText('site.manager@smart.test')
  expect(screen.queryByText('معطّل')).toBeNull()
  expect(screen.queryByRole('button', { name: 'مواقع ومهندسو مدير الموقع' })).toBeNull()
})

test('a new site-manager account posts the email, password, ticked sites, ticked engineers and the date', async () => {
  const post = vi.fn(() => json(201, { data: { ...emp, site_manager: account } }))
  smRoutes({ ...emp, site_manager: null }, { 'POST /api/v1/employees/e1/site-manager': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  const dialog = screen.getByRole('dialog', { name: 'تعيين كمدير موقع' })
  expect(within(dialog).getByLabelText('الحساب')).toHaveValue('')
  expect(within(dialog).getByRole('option', { name: 'حساب جديد' })).toBeInTheDocument()
  await user.type(within(dialog).getByLabelText('البريد الإلكتروني'), 'new.manager@smart.test')
  await user.type(within(dialog).getByLabelText('كلمة المرور'), 'Secret-2026')
  await user.click(await within(dialog).findByRole('checkbox', { name: 'موقع ب' }))
  const engineers = within(dialog).getByRole('group', { name: 'المهندسون' })
  expect(await within(engineers).findByRole('checkbox', { name: 'مهندس أول' })).toBeInTheDocument()
  expect(within(engineers).queryByRole('checkbox', { name: 'أحمد' })).toBeNull() // the employee themself
  expect(within(dialog).queryByLabelText('ساري اعتبارًا من')).toBeNull() // only once an engineer is ticked
  await user.click(within(engineers).getByRole('checkbox', { name: 'مهندس ثان' }))
  expect(within(dialog).getByLabelText('ساري اعتبارًا من')).toHaveValue(cairoToday())
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ email: 'new.manager@smart.test', password: 'Secret-2026', site_ids: ['s2'], engineer_ids: ['g2'], effective_from: cairoToday() })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('a new account with no engineer sends an empty list and no date', async () => {
  const post = vi.fn(() => json(201, { data: emp }))
  smRoutes({ ...emp, site_manager: null }, { 'POST /api/v1/employees/e1/site-manager': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'a@smart.test')
  await user.type(screen.getByLabelText('كلمة المرور'), 'Secret-2026')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ email: 'a@smart.test', password: 'Secret-2026', site_ids: [], engineer_ids: [] })
})

test('choosing an existing account hides the other fields and posts only the account', async () => {
  const post = vi.fn(() => json(201, { data: emp }))
  smRoutes({ ...emp, site_manager: null }, { 'POST /api/v1/employees/e1/site-manager': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  const dialog = screen.getByRole('dialog')
  await within(dialog).findAllByRole('option', { name: /^حساب قديم/ })
  await user.selectOptions(within(dialog).getByLabelText('الحساب'), 'u8')
  for (const label of ['البريد الإلكتروني', 'كلمة المرور']) expect(within(dialog).queryByLabelText(label)).toBeNull()
  expect(within(dialog).queryByRole('group', { name: 'المواقع التي يغطيها' })).toBeNull()
  expect(within(dialog).queryByRole('group', { name: 'المهندسون' })).toBeNull()
  expect(within(dialog).getByText(/يحتفظ بمواقعه ومهندسيه الحاليين/)).toBeInTheDocument()
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ user_id: 'u8' })
})

test('the chosen account’s whole email shows on its own line, left to right, outside the select', async () => {
  smRoutes({ ...emp, site_manager: null })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  const dialog = screen.getByRole('dialog')
  await within(dialog).findAllByRole('option', { name: /^حساب قديم/ })
  // a closed native select clips its label on a phone, and namesakes differ only by the email
  await user.selectOptions(within(dialog).getByLabelText('الحساب'), 'u7')
  expect(within(dialog).getByText('old@smart.test')).toHaveAttribute('dir', 'ltr')
  await user.selectOptions(within(dialog).getByLabelText('الحساب'), 'u8')
  const email = within(dialog).getByText('old.two@smart.test')
  expect(email).toHaveAttribute('dir', 'ltr')
  expect(email.closest('select')).toBeNull()
  expect(within(dialog).queryByText('old@smart.test')).toBeNull()
})

test('the account picker offers the site-manager accounts not yet linked to an employee, each by name and email', async () => {
  smRoutes({ ...emp, site_manager: null })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  const picker = within(screen.getByRole('dialog')).getByLabelText('الحساب')
  await within(picker).findAllByRole('option', { name: /^حساب قديم/ })
  // two accounts with one name: the email, isolated so it reads left to right in Arabic, tells them apart
  expect(within(picker).getAllByRole('option').map((o) => [o.getAttribute('value'), o.textContent])).toEqual([
    ['', 'حساب جديد'],
    ['u7', 'حساب قديم · \u2066old@smart.test\u2069'],
    ['u8', 'حساب قديم · \u2066old.two@smart.test\u2069'],
  ])
  const urls = vi.mocked(fetch).mock.calls.map(([u]) => String(u))
  expect(urls).toContain('/api/v1/employees/site-manager-candidates')
  expect(urls.filter((u) => u.includes('/sites/manager-candidates'))).toEqual([])
})

test('a refused email shows under its field; an employee already linked shows as the dialog error', async () => {
  let refusal = { field: 'email', code: 'unique', message: 'البريد مستخدم' }
  smRoutes({ ...emp, site_manager: null }, { 'POST /api/v1/employees/e1/site-manager': () => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [refusal] } }) })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'تعيين كمدير موقع' }))
  const dialog = screen.getByRole('dialog')
  const save = within(dialog).getByRole('button', { name: 'حفظ' })
  await user.click(save)
  await waitFor(() => expect(within(dialog).getByLabelText('البريد الإلكتروني')).toHaveAccessibleDescription('البريد مستخدم'))
  expect(within(dialog).queryByRole('alert')).toBeNull()
  refusal = { field: 'employee', code: 'x', message: 'هذا الموظف مدير موقع بالفعل' }
  await user.click(save)
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('هذا الموظف مدير موقع بالفعل')
})

test('editing starts from the current sites and engineers, keeps an inactive site and puts only the new engineers', async () => {
  const put = vi.fn(() => json(200, { data: { ...emp, site_manager: account } }))
  smRoutes({ ...emp, site_manager: account }, { 'PUT /api/v1/employees/e1/site-manager': put })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'مواقع ومهندسو مدير الموقع' }))
  const dialog = screen.getByRole('dialog', { name: 'مواقع ومهندسو مدير الموقع' })
  expect(within(dialog).queryByLabelText('الحساب')).toBeNull()
  expect(within(dialog).queryByLabelText('البريد الإلكتروني')).toBeNull()
  expect(await within(dialog).findByRole('checkbox', { name: 'موقع أ' })).toBeChecked()
  expect(within(dialog).getByRole('checkbox', { name: 'موقع ب' })).not.toBeChecked()
  const current = await within(dialog).findByRole('checkbox', { name: 'مهندس أول' })
  expect(current).toBeChecked()
  expect(current).toBeDisabled()
  // the fixed row reads as disabled and takes no hover fill
  expect(current.closest('label')).toHaveClass('has-disabled:cursor-not-allowed', 'has-disabled:text-muted-foreground', 'has-enabled:hover:bg-muted')
  expect(current.closest('label')).not.toHaveClass('hover:bg-muted')
  await user.click(within(dialog).getByRole('checkbox', { name: 'موقع ب' }))
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(put).toHaveBeenCalledTimes(1))
  // s9 is a covered site no longer active: it is not listed, and saving keeps it
  expect(bodyOf(put)).toEqual({ site_ids: ['s1', 's9', 's2'], engineer_ids: [] })
})

test('editing sends a newly ticked engineer with the date', async () => {
  const put = vi.fn(() => json(200, { data: { ...emp, site_manager: account } }))
  smRoutes({ ...emp, site_manager: account }, { 'PUT /api/v1/employees/e1/site-manager': put })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'مواقع ومهندسو مدير الموقع' }))
  const dialog = screen.getByRole('dialog')
  await user.click(await within(dialog).findByRole('checkbox', { name: 'مهندس ثان' }))
  const date = within(dialog).getByLabelText('ساري اعتبارًا من')
  await user.clear(date)
  await user.type(date, '2026-12-01')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(put).toHaveBeenCalledTimes(1))
  expect(bodyOf(put)).toEqual({ site_ids: ['s1', 's9'], engineer_ids: ['g2'], effective_from: '2026-12-01' })
})

test('a refused engineer shows under the engineers list and a refused site id under the sites list', async () => {
  const put = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'Invalid', details: [
    { field: 'engineer_ids', code: 'x', message: 'مهندس ثان ليس مهندسًا في هذا التاريخ' },
    { field: 'site_ids.1', code: 'exists', message: 'الموقع المحدد غير صالح' },
  ] } }))
  smRoutes({ ...emp, site_manager: account }, { 'PUT /api/v1/employees/e1/site-manager': put })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, SM, { router: false })
  await user.click(await screen.findByRole('button', { name: 'مواقع ومهندسو مدير الموقع' }))
  const dialog = screen.getByRole('dialog')
  await user.click(await within(dialog).findByRole('checkbox', { name: 'مهندس ثان' }))
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(within(dialog).getByRole('group', { name: 'المهندسون' })).findByText('مهندس ثان ليس مهندسًا في هذا التاريخ')).toBeInTheDocument()
  expect(within(within(dialog).getByRole('group', { name: 'المواقع التي يغطيها' })).getByText('الموقع المحدد غير صالح')).toBeInTheDocument()
  expect(within(dialog).queryByRole('alert')).toBeNull()
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

test.each([true, false])('late permit in the employee file respects generate permission (%s)', async (canGenerate) => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, code: 7, department: 'قسم الاختبار' } }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [{
      type: 'late_permit', fields: [{ name: 'addressee', input: 'text', required: true, max: 160 }],
    }] } }),
    'GET /api/v1/employees/e1/hr-form-exports': () => json(200, { data: [], meta: { pagination } }),
    'GET /api/v1/employees/e1/hr-forms/experience-certificate/defaults': () => json(200, { data: { praise: '' } }),
    'POST /api/v1/employees/e1/hr-forms/manual/late_permit': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.view.all', ...(canGenerate ? ['hr_forms.generate.all'] : []),
  ], { router: false })

  const heading = await screen.findByRole('heading', { name: 'إذن تأخير' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/الطباعة فقط/)).toBeInTheDocument()
  if (!canGenerate) {
    expect(section.queryByRole('button', { name: 'إنشاء الملف' })).toBeNull()
    return
  }
  expect(section.getByLabelText('الاسم')).toHaveValue(emp.name_ar)
  expect(section.getByLabelText('الرقم الوظيفي')).toHaveValue('7')
  expect(section.getByLabelText('القسم')).toHaveValue('قسم الاختبار')
  const addressee = section.getByLabelText('مقدم لسيادتكم')
  expect(addressee).toBeRequired()
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  await user.type(addressee, 'مدير الاختبار')
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/late_permit') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({ addressee: 'مدير الاختبار', idempotency_key: expect.any(String) })
})

test('selects the early departure request in the employee file and submits its schedule', async () => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  const schedule = { regular_start: '08:30', regular_end: '17:30', requested_end: '16:00', issued_on: '2026-11-15' }
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, code: 7, department: 'قسم الاختبار' } }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [
      { type: 'late_permit', fields: [] },
      { type: 'early_leave_request', fields: Object.keys(schedule).map((name) => ({
        name, input: name === 'issued_on' ? 'date' : 'time', required: true, max: null,
      })) },
    ] } }),
    'POST /api/v1/employees/e1/hr-forms/manual/early_leave_request': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.generate.all',
  ], { router: false })

  await user.selectOptions(await screen.findByRole('combobox', { name: 'نماذج للطباعة' }), 'early_leave_request')
  const heading = screen.getByRole('heading', { name: 'طلب انصراف باكرًا' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/الطباعة فقط/)).toBeInTheDocument()
  expect(section.getByLabelText('الاسم')).toHaveValue(emp.name_ar)
  expect(section.getByLabelText('القسم')).toHaveValue('قسم الاختبار')
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  await user.type(section.getByLabelText('بداية الدوام الرسمية'), schedule.regular_start)
  await user.type(section.getByLabelText('نهاية الدوام الرسمية'), schedule.regular_end)
  await user.type(section.getByLabelText('وقت الانصراف المطلوب'), schedule.requested_end)
  await user.type(section.getByLabelText('تاريخ التحرير'), schedule.issued_on)
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/early_leave_request') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({ ...schedule, idempotency_key: expect.any(String) })
})

test('requires confirmation of the resignation declarations in the employee file', async () => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [
      { type: 'resignation', fields: [{ name: 'confirm_declarations', input: 'checkbox', required: true, max: null }] },
    ] } }),
    'POST /api/v1/employees/e1/hr-forms/manual/resignation': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.generate.all',
  ], { router: false })

  const heading = await screen.findByRole('heading', { name: 'استقالة' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/الطباعة فقط/)).toBeInTheDocument()
  const confirm = section.getByRole('checkbox', { name: /أؤكد أن الموظف/ })
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  await user.click(confirm)
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/resignation') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({ confirm_declarations: 'on', idempotency_key: expect.any(String) })
})

test('prints a warning from the employee file with its incident fields and print-only notice', async () => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  const fields = [
    ['job_title', 'المسمى الوظيفي', 'مهندسة اختبار', 'text'],
    ['warning_date', 'تاريخ الإنذار', '2026-11-15', 'date'],
    ['warning_incident', 'الواقعة', 'لم يتم تسليم ملف الاختبار.', 'textarea'],
    ['warning_period', 'التواريخ أو المدة المذكورة', 'لمدة يوم واحد.', 'textarea'],
    ['warning_prior_occurrence', 'بيان التكرار السابق', 'هذه أول مرة.', 'textarea'],
    ['warning_explanation_request', 'طلب التوضيح', 'يرجى توضيح السبب.', 'textarea'],
  ]
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [{
      type: 'warning_notice', fields: fields.map(([name, , , input]) => ({ name, input, required: true, max: null })),
    }] } }),
    'POST /api/v1/employees/e1/hr-forms/manual/warning_notice': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.generate.all',
  ], { router: false })

  const heading = await screen.findByRole('heading', { name: 'نموذج إنذار' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/لا تنشئ سجلًا تأديبيًا أو خصمًا أو مدخلًا للرواتب/)).toBeInTheDocument()
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  for (const [, label, value] of fields) {
    await user.type(section.getByLabelText(label), value)
  }
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/warning_notice') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({
    ...Object.fromEntries(fields.map(([name, , value]) => [name, value])), idempotency_key: expect.any(String),
  })
})

test('generates the mission permit from the employee file', async () => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  const fields = [
    ['job_title', 'المسمى الوظيفي', 'مهندسة اختبار', 'text'],
    ['mission_date', 'تاريخ المأمورية', '2026-11-15', 'date'],
    ['mission_reason', 'سبب المأمورية', 'متابعة موقع الاختبار.', 'textarea'],
    ['department_manager', 'مدير القسم', 'مدير الاختبار', 'text'],
  ]
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [{
      type: 'mission_permit', fields: fields.map(([name, , , input]) => ({ name, input, required: true, max: null })),
    }] } }),
    'POST /api/v1/employees/e1/hr-forms/manual/mission_permit': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.generate.all',
  ], { router: false })

  const heading = await screen.findByRole('heading', { name: 'إذن مأمورية' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/الطباعة فقط/)).toBeInTheDocument()
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  for (const [, label, value] of fields) {
    await user.type(section.getByLabelText(label), value)
  }
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/mission_permit') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({
    ...Object.fromEntries(fields.map(([name, , value]) => [name, value])), idempotency_key: expect.any(String),
  })
})

test('generates the leave permit from the employee file', async () => {
  const generate = vi.fn(() => json(202, { data: { id: 'export1' } }))
  const fields = [
    ['addressee', 'مقدم لسيادتكم', 'مدير الاختبار', 'text'],
    ['permit_date', 'تاريخ الإذن', '2026-11-16', 'date'],
    ['start_time', 'من الساعة', '14:30', 'time'],
    ['end_time', 'إلى الساعة', '16:00', 'time'],
    ['reason', 'الأسباب', 'موعد طبي', 'textarea'],
    ['job_title', 'المسمى الوظيفي', 'مهندسة اختبار', 'text'],
    ['responsible_manager', 'المدير المسؤول', 'مسؤول القسم', 'text'],
    ['issued_on', 'تاريخ التحرير', '2026-11-15', 'date'],
  ]
  mockFetch({
    'GET /api/v1/employees/e1': () => json(200, { data: emp }),
    'GET /api/v1/residences': residences,
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [{
      type: 'leave_permit', fields: fields.map(([name, , , input]) => ({ name, input, required: true, max: null })),
    }] } }),
    'POST /api/v1/employees/e1/hr-forms/manual/leave_permit': generate,
  })
  renderWithAuth(<EmployeeDetailRoute id="e1" tab="documents" />, [
    'employee.view.all', 'hr_forms.generate.all',
  ], { router: false })

  const heading = await screen.findByRole('heading', { name: 'إذن انصراف' })
  const section = within(heading.closest('section')!)
  expect(section.getByText(/الطباعة فقط/)).toBeInTheDocument()
  const action = section.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  for (const [, label, value] of fields) {
    await user.type(section.getByLabelText(label), value)
  }
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual/leave_permit') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({
    ...Object.fromEntries(fields.map(([name, , value]) => [name, value])), idempotency_key: expect.any(String),
  })
})
