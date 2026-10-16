import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import '@/i18n'
import { FaresPage, ResidencesPage, SitesPage } from './sites'

const pg = (total = 1) => ({ page: 1, per_page: 100, total, total_pages: 1, has_next_page: false, has_previous_page: false })
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status })
const list = (data: unknown[], total = data.length) => () => json(200, { data, meta: { pagination: pg(total) } })

function mockFetch(routes: Record<string, () => Response>) {
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url.split('?')[0]}`
    return routes[key]?.() ?? json(404, { error: { code: 'nf', message: key } })
  }))
}
const calls = (m: string) => vi.mocked(fetch).mock.calls.filter(([, i]) => (i?.method ?? 'GET') === m)

const fmt = (v: number) => new Intl.NumberFormat('ar', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)

const sites = [
  { id: 's1', name_ar: 'موقع أ', kind: 'work', leave_code: null, active: true },
  { id: 's2', name_ar: 'موقع ب', kind: 'work', leave_code: null, active: true },
]
const residences = [
  { id: 'r1', name_ar: 'العجمي' },
  { id: 'r2', name_ar: 'برج العرب' },
]

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('fares grid puts the fare in force today in the right cell', async () => {
  mockFetch({
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': list(residences),
    'GET /api/v1/fares': list([
      { id: 'f1', residence_id: 'r1', site_id: 's1', effective_from: '2020-01-01', amount: '5.000000' },
      { id: 'f2', residence_id: 'r1', site_id: 's1', effective_from: '2020-06-01', amount: '7.500000' },
      { id: 'f3', residence_id: 'r1', site_id: 's1', effective_from: '2999-01-01', amount: '99.000000' },
      { id: 'f4', residence_id: 'r2', site_id: 's2', effective_from: '2020-01-01', amount: '12.000000' },
    ]),
  })
  render(<MemoryRouter><FaresPage /></MemoryRouter>)
  const row = (name: string) => within(screen.getByRole('row', { name: new RegExp(name) }))
  await screen.findByRole('row', { name: /العجمي/ })
  expect(row('العجمي').getByText(fmt(7.5))).toBeInTheDocument()
  expect(row('العجمي').queryByText(fmt(99))).toBeNull()
  expect(row('برج العرب').getByText(fmt(12))).toBeInTheDocument()
  expect(row('برج العرب').queryByText(fmt(7.5))).toBeNull()
})

test('clicking a fare cell posts a dated fare for that residence and site', async () => {
  mockFetch({
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': list(residences),
    'GET /api/v1/fares': list([]),
    'POST /api/v1/fares': () => json(201, { data: {} }),
  })
  render(<MemoryRouter><FaresPage /></MemoryRouter>)
  const user = userEvent.setup()
  const rowEl = await screen.findByRole('row', { name: /برج العرب/ })
  await user.click(within(rowEl).getAllByRole('button')[1])
  await user.type(screen.getByLabelText('ساري من'), '2026-10-01')
  await user.type(screen.getByLabelText('الأجرة'), '8.5')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const body = JSON.parse(calls('POST')[0][1]!.body as string)
  expect(body).toEqual({ residence_id: 'r2', site_id: 's2', effective_from: '2026-10-01', amount: '8.5' })
})

test('fares grid shows a notice when more than 100 fares exist', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences), 'GET /api/v1/fares': list([], 150) })
  render(<MemoryRouter><FaresPage /></MemoryRouter>)
  expect(await screen.findByRole('status')).toBeInTheDocument()
})

test('a failing secondary load shows the banner on the fares page', async () => {
  mockFetch({
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }),
    'GET /api/v1/fares': list([]),
  })
  render(<MemoryRouter><FaresPage /></MemoryRouter>)
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
})

test('site dialog shows leave code only for the leave kind and sends it', async () => {
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => json(201, { data: {} }),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  expect(screen.queryByLabelText('كود الإجازة')).toBeNull()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'جمعة')
  await user.selectOptions(within(screen.getByRole('dialog')).getByLabelText('نوع الموقع'), 'leave')
  await user.selectOptions(screen.getByLabelText('كود الإجازة'), 'friday')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const body = JSON.parse(calls('POST')[0][1]!.body as string)
  expect(body).toMatchObject({ name_ar: 'جمعة', kind: 'leave', leave_code: 'friday' })
})

test('site save error shows under the field', async () => {
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'name_ar', code: 'unique', message: 'الاسم مستخدم' }] } }),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'س')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('الاسم مستخدم')).toBeInTheDocument()
})

test('allowance rule history lists rules and adds one', async () => {
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], allowance_rules: [{ id: 'a1', effective_from: '2020-01-01', mode: 'fixed', value: '50.000000' }] } }),
    'POST /api/v1/sites/s1/allowance-rules': () => json(201, { data: { ...sites[0], allowance_rules: [] } }),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: 'بدلات' }))
  expect(await screen.findByText(fmt(50))).toBeInTheDocument()
  await user.type(screen.getByLabelText('ساري من'), '2026-10-01')
  await user.selectOptions(screen.getByLabelText('نمط البدل'), 'share_of_wage')
  await user.type(screen.getByLabelText('القيمة'), '0.5')
  await user.click(screen.getByRole('button', { name: 'إضافة' }))
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toEqual({ effective_from: '2026-10-01', mode: 'share_of_wage', value: '0.5' })
})

test('residences list renders and a load failure shows the banner', async () => {
  mockFetch({ 'GET /api/v1/residences': list(residences) })
  const { unmount } = render(<MemoryRouter><ResidencesPage /></MemoryRouter>)
  expect(await screen.findByText('العجمي')).toBeInTheDocument()
  unmount()
  mockFetch({ 'GET /api/v1/residences': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) })
  render(<MemoryRouter><ResidencesPage /></MemoryRouter>)
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
})

test('rules are fetched only when the dialog opens, once', async () => {
  mockFetch({
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], allowance_rules: [] } }),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  const btns = await screen.findAllByRole('button', { name: 'بدلات' })
  const detail = () => calls('GET').filter(([u]) => /\/sites\/s\d/.test(String(u)))
  expect(detail()).toHaveLength(0)
  await user.click(btns[0])
  await screen.findByLabelText('ساري من')
  expect(detail()).toHaveLength(1)
})

test('dialog form and errors are reset on reopen', async () => {
  let n = 0
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => (n++ === 0 ? json(500, { error: { code: 'server_error', message: 'تعذر الحفظ' } }) : json(201, { data: {} })),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  const open = async () => user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await open()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'قديم')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByRole('alert')
  await user.keyboard('{Escape}')
  await open()
  expect(screen.queryByRole('alert')).toBeNull()
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('')
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'جديد')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await open()
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('')
})

test('a double click on Save submits once', async () => {
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => json(201, { data: {} }),
  })
  render(<MemoryRouter><SitesPage /></MemoryRouter>)
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'س')
  await user.dblClick(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(calls('POST')).toHaveLength(1)
})
