import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import '@/i18n'
import { EmployeeFormPage, EmployeesPage } from './employees'

const pagination = { page: 1, per_page: 20, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false }
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status })

function mockFetch(routes: Record<string, () => Response>) {
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url.split('?')[0]}`
    return routes[key]?.() ?? json(404, { error: { code: 'nf', message: key } })
  }))
}
const residences = () => json(200, { data: [{ id: 'r1', name_ar: 'العجمي' }], meta: { pagination } })

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('list renders rows from the API', async () => {
  mockFetch({
    'GET /api/v1/employees': () =>
      json(200, {
        data: [{ id: 'e1', code: 7, name_ar: 'أحمد علي', department: 'المبيعات', pay_method: 'cash', current_type: 'office' }],
        meta: { pagination },
      }),
  })
  render(<MemoryRouter><EmployeesPage /></MemoryRouter>)
  expect(await screen.findByText('أحمد علي')).toBeInTheDocument()
  expect(screen.getByText('المبيعات')).toBeInTheDocument()
  expect(screen.getByText('نقدي')).toBeInTheDocument()
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
