import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { queryClient } from '@/lib/query'
import { json, me, mockFetch, pagination, renderApp, renderWithAuth } from '@/test-utils'
import { type TeamFareRow, TeamFaresPage } from './team-fares'

const user = userEvent.setup()
const LEAD = ['employee.view.own', 'site.view.own']
const HR = ['fare.manage.all', 'employee.view.all', 'employee.edit.all', 'site.view.all', 'site.assign_manager.all']
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
const urls = (part: string) => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes(part))

const S1 = { id: 's1', name: 'العامرية' }
const S2 = { id: 's2', name: 'برج العرب' }
const R1 = { id: 'r1', name: 'كفر الدوار' }
const R2 = { id: 'r2', name: 'القاهرة' }
const row = (employee_id: string, name: string, type: TeamFareRow['type'], extra: Partial<TeamFareRow>): TeamFareRow => ({
  employee_id, code: 1, name, type, residence: R1, site: S1, amount: null, source: 'none', default_amount: null, overrides: [], ...extra,
})
const ROWS = [
  row('w1', 'محمود عبد الله', 'permanent_worker', { amount: '35.000000', source: 'default', default_amount: '35.000000' }),
  row('w2', 'سيد إبراهيم', 'permanent_worker', { amount: '30.000000', source: 'override', default_amount: '35.000000' }),
  row('w3', 'عادل منصور', 'day_laborer', { residence: R2, site: S2 }),
  row('w4', 'فتحي سالم', 'day_laborer', { residence: null, source: 'no_residence' }),
  row('e1', 'م. كريم فتحي', 'engineer', { residence: R2, site: null }),
]
const team = (rows = ROWS) => () => json(200, { data: { sites: [S1, S2], rows }, meta: { request_id: 'x' } })
const bodyRows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-05T10:00:00Z'))
  queryClient.clear()
  localStorage.clear()
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

test('lists the team with each source label', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, LEAD)
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات التيم' })).toBeInTheDocument()
  await screen.findByText('محمود عبد الله')
  for (const label of ['تلقائي', 'معدّل للعامل', 'مفيش مبلغ للسكن ده', 'مستني السكن', 'المهندس بيتحدد له بالاسم', 'السكن مش متسجل', 'حسب اليوم'])
    expect(screen.getByText(label)).toBeInTheDocument()
  expect(within(bodyRows()[0]).getByText(fmt(35))).toBeInTheDocument()
  expect(within(bodyRows()[1]).getByText(fmt(30))).toBeInTheDocument()
  expect(within(bodyRows()[2]).getByText('—')).toBeInTheDocument()
  expect(within(bodyRows()[0]).getByText('عامل مثبت')).toBeInTheDocument()
  // below 640 px each row is a card whose lines carry their column names
  for (const label of ['السكن', 'الموقع', 'المواصلات اليومية']) expect(within(bodyRows()[0]).getByText(label)).toBeInTheDocument()
  expect(urls('/team-fares')).toEqual(['/api/v1/team-fares'])
})

test('chips count and filter the rows', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, LEAD)
  const all = await screen.findByRole('button', { name: 'كل التيم (5)' })
  expect(all).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(5)
  const site2 = screen.getByRole('button', { name: 'برج العرب' })
  expect(screen.getByRole('button', { name: 'العامرية' })).toHaveAttribute('aria-pressed', 'false')
  await user.click(site2)
  expect(site2).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(1)
  expect(within(bodyRows()[0]).getByText('عادل منصور')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'من غير مبلغ (2)' }))
  expect(bodyRows().map((r) => within(r).getAllByRole('cell')[0].textContent)).toEqual([expect.stringContaining('عادل منصور'), expect.stringContaining('فتحي سالم')])
  await user.click(all)
  expect(bodyRows()).toHaveLength(5)
})

function Opened() {
  const { pathname, state } = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <p>{`opened ${pathname} ${JSON.stringify(state)}`}</p>
      <button type="button" onClick={() => navigate(-1)}>back</button>
    </>
  )
}
const withEmployee = (permissions: string[], path = '/') =>
  renderWithAuth(<Routes><Route path="/" element={<TeamFaresPage />} /><Route path="employees/:id" element={<Opened />} /></Routes>, permissions, { path })

test('a name opens the employee with the rows the chip shows, in order, to step through', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  withEmployee(LEAD)
  await user.click(await screen.findByRole('button', { name: 'من غير مبلغ (2)' }))
  await user.click(screen.getByRole('link', { name: /فتحي سالم/ }))
  expect(await screen.findByText(`opened /employees/w4 ${JSON.stringify({ ids: ['w3', 'w4'], pos: 1, total: 2 })}`)).toBeInTheDocument()
})

test('the picked site manager and chip live in the URL, so Back from an employee shows the same team', async () => {
  mockFetch({
    'GET /api/v1/sites/manager-candidates': () => json(200, { data: MANAGERS }),
    'GET /api/v1/team-fares': (url: string) => (url.includes('manager_id=m2') ? team([row('w9', 'حسن علي', 'day_laborer', {}), row('w8', 'رضا سعيد', 'day_laborer', { site: S2 })])() : team()()),
  })
  withEmployee(HR)
  await user.click(await screen.findByRole('button', { name: 'م. هاني رزق' }))
  await user.click(await screen.findByRole('button', { name: 'برج العرب' }))
  await user.click(screen.getByRole('link', { name: /رضا سعيد/ }))
  await user.click(await screen.findByRole('button', { name: 'back' }))
  expect(await screen.findByRole('button', { name: 'برج العرب' })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByRole('button', { name: 'م. هاني رزق' })).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(1)
  await user.click(screen.getByRole('button', { name: 'م. أحمد سامي' })) // another team starts on all its rows
  expect(await screen.findByRole('button', { name: 'كل التيم (5)' })).toHaveAttribute('aria-pressed', 'true')
})

test('a chip in the URL that is not one of the team sites shows the whole team', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  withEmployee(LEAD, '/?chip=gone')
  expect(await screen.findByRole('button', { name: 'كل التيم (5)' })).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(5)
})

test('an empty team says so', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team([]) })
  renderWithAuth(<TeamFaresPage />, LEAD)
  expect(await screen.findByText('مفيش عمال في التيم النهارده.')).toBeInTheDocument()
})

test('a site manager sees the team read-only', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, LEAD)
  await screen.findByText('محمود عبد الله')
  expect(screen.getByText('مواصلات عمال التيم حسب السكن والموقع.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /تعديل/ })).toBeNull()
  expect(screen.queryByRole('button', { name: 'سجّل السكن' })).toBeNull()
  expect(within(bodyRows()[3]).getByText('السكن مش متسجل')).toBeInTheDocument()
})

test('the CEO gets not found', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, ['employee.view.all', 'site.view.all'])
  expect(screen.getByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  expect(urls('/team-fares')).toEqual([])
})

const MANAGERS = [{ id: 'm1', name: 'م. أحمد سامي' }, { id: 'm2', name: 'م. هاني رزق' }]

test('an all-scope user lands on the first site manager with no click, and picking another switches the team', async () => {
  mockFetch({
    'GET /api/v1/sites/manager-candidates': () => json(200, { data: MANAGERS }),
    'GET /api/v1/team-fares': (url: string) => (url.includes('manager_id=m2') ? team([row('w9', 'حسن علي', 'day_laborer', {})])() : team()()),
  })
  renderWithAuth(<TeamFaresPage />, HR)
  await screen.findByText('محمود عبد الله')
  const managers = screen.getByRole('group', { name: 'مدير الموقع' })
  const first = within(managers).getByRole('button', { name: 'م. أحمد سامي' })
  const second = within(managers).getByRole('button', { name: 'م. هاني رزق' })
  expect(first).toHaveAttribute('aria-pressed', 'true')
  expect(second).toHaveAttribute('aria-pressed', 'false')
  expect(screen.queryByRole('combobox')).toBeNull()
  expect(urls('/team-fares')).toEqual(['/api/v1/team-fares?manager_id=m1'])
  expect(screen.getByText('اختار مدير الموقع، سجّل السكن الناقص، وحدد المواصلات لكل سكن في كل موقع.')).toBeInTheDocument()

  await user.click(second)
  await screen.findByText('حسن علي')
  expect(second).toHaveAttribute('aria-pressed', 'true')
  expect(first).toHaveAttribute('aria-pressed', 'false')
  expect(screen.queryByText('محمود عبد الله')).toBeNull()
  expect(urls('/team-fares')).toEqual(['/api/v1/team-fares?manager_id=m1', '/api/v1/team-fares?manager_id=m2'])
})

test('while the site managers load the page shows the skeleton, not an empty state', async () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  renderWithAuth(<TeamFaresPage />, HR)
  await screen.findByRole('group', { name: 'مدير الموقع' })
  expect(document.querySelector('[aria-busy="true"]')).not.toBeNull()
  expect(screen.queryByText('لسه مفيش مديرين مواقع.')).toBeNull()
  expect(urls('/team-fares')).toEqual([])
})

test('with no site managers at all the page says so and asks for no team', async () => {
  mockFetch({ 'GET /api/v1/sites/manager-candidates': () => json(200, { data: [] }) })
  renderWithAuth(<TeamFaresPage />, HR)
  expect(await screen.findByText('لسه مفيش مديرين مواقع.')).toBeInTheDocument()
  expect(urls('/team-fares')).toEqual([])
})

test('the route renders the page', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(LEAD) }), 'GET /api/v1/team-fares': team() })
  renderApp('/team-fares')
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات التيم' })).toBeInTheDocument()
  await waitFor(() => expect(urls('/team-fares')).toHaveLength(1))
  expect(screen.getByRole('link', { name: 'مواصلات التيم' })).toHaveAttribute('href', '/team-fares')
})

const post = (part: string) => vi.mocked(fetch).mock.calls.filter(([u, i]) => i?.method === 'POST' && String(u).includes(part)).map(([, i]) => JSON.parse(String(i!.body)))
const edit = async (name: string) => user.click(await screen.findByRole('button', { name: `تعديل مواصلات ${name}` }))
const saving = (extra = {}) => mockFetch({
  'GET /api/v1/team-fares': team(),
  'GET /api/v1/sites/manager-candidates': () => json(200, { data: [{ id: 'm1', name: 'م. أحمد سامي' }] }),
  'POST /api/v1/employees/w2/fare-overrides': () => json(201, { data: {} }),
  'POST /api/v1/employees/e1/fare-overrides': () => json(201, { data: {} }),
  'POST /api/v1/fares': () => json(201, { data: {} }),
  'GET /api/v1/residences': () => json(200, { data: [{ id: 'r1', name_ar: 'كفر الدوار' }, { id: 'r2', name_ar: 'القاهرة' }], meta: { pagination } }),
  'PATCH /api/v1/employees/w4': () => json(200, { data: {} }),
  ...extra,
})
/** HR opens the page and picks the site manager m1. */
const asHr = async (permissions = HR) => {
  const view = renderWithAuth(<TeamFaresPage />, permissions)
  await user.click(await screen.findByRole('button', { name: 'م. أحمد سامي' }))
  return view
}

test('editing a worker posts an override by default', async () => {
  saving()
  await asHr()
  await edit('سيد إبراهيم')
  const dialog = await screen.findByRole('dialog', { name: 'تعديل مواصلات سيد إبراهيم · كفر الدوار ← العامرية' })
  expect(within(dialog).getByText(`التلقائي ${fmt(35)}`)).toBeInTheDocument()
  expect(within(dialog).getByRole('radio', { name: 'للعامل ده بس' })).toBeChecked()
  const amount = within(dialog).getByLabelText('المبلغ اليومي')
  expect(amount).toHaveValue('30')
  await user.clear(amount)
  await user.type(amount, '32')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(post('/fare-overrides')).toEqual([{ site_id: 's1', effective_from: '2026-11-05', amount: '32' }])
})

test('the pair option posts the residence × site fare', async () => {
  saving()
  await asHr()
  await edit('محمود عبد الله')
  const dialog = await screen.findByRole('dialog')
  await user.click(within(dialog).getByRole('radio', { name: 'لكل اللي ساكنين في كفر الدوار وبيشتغلوا في العامرية (عاملين)' }))
  await user.clear(within(dialog).getByLabelText('المبلغ اليومي'))
  await user.type(within(dialog).getByLabelText('المبلغ اليومي'), '40')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(post('/fares')).toEqual([{ residence_id: 'r1', site_id: 's1', effective_from: '2026-11-05', amount: '40' }])
  expect(post('/fare-overrides')).toEqual([])
})

test('the amount starts from what applies, else the default, else empty', async () => {
  const rows = [
    row('w1', 'محمود عبد الله', 'permanent_worker', { amount: '35.500000', source: 'override', default_amount: '35.000000' }),
    row('w2', 'سيد إبراهيم', 'permanent_worker', { default_amount: '40.000000' }),
    row('w3', 'عادل منصور', 'day_laborer', {}),
    row('e1', 'م. كريم فتحي', 'engineer', { site: null, overrides: [{ site_id: 's1', amount: '50.000000' }] }),
  ]
  saving({ 'GET /api/v1/team-fares': team(rows) })
  await asHr()
  for (const [name, value] of [['محمود عبد الله', '35.5'], ['سيد إبراهيم', '40'], ['عادل منصور', ''], ['م. كريم فتحي', '50']]) {
    await edit(name)
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('المبلغ اليومي')).toHaveValue(value)
    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  }
})

test('the pair option counts the workers in Arabic plural forms', async () => {
  const pairOf = (n: number) => Array.from({ length: n }, (_, i) => row(`w${i}`, `عامل ${i}`, 'permanent_worker', {}))
  for (const [n, label] of [[1, 'عامل واحد'], [3, '3 عمال'], [11, '11 عامل']] as const) {
    queryClient.clear()
    saving({ 'GET /api/v1/team-fares': team(pairOf(n)) })
    const { unmount } = await asHr()
    await edit('عامل 0')
    expect(within(await screen.findByRole('dialog')).getByRole('radio', { name: `لكل اللي ساكنين في كفر الدوار وبيشتغلوا في العامرية (${label})` })).toBeInTheDocument()
    unmount()
  }
})

test('engineers pick a site and have no pair option', async () => {
  saving()
  await asHr()
  await edit('م. كريم فتحي')
  const dialog = await screen.findByRole('dialog', { name: 'تعديل مواصلات م. كريم فتحي' })
  expect(within(dialog).queryByRole('radio')).toBeNull()
  const site = within(dialog).getByRole('combobox', { name: 'الموقع' })
  expect(within(site).getAllByRole('option').map((o) => o.textContent)).toEqual(['العامرية', 'برج العرب'])
  await user.selectOptions(site, 's2')
  expect(within(dialog).getByLabelText('المبلغ اليومي')).toHaveValue('')
  await user.type(within(dialog).getByLabelText('المبلغ اليومي'), '50')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(post('/fare-overrides')).toEqual([{ site_id: 's2', effective_from: '2026-11-05', amount: '50' }])
})

test('the engineer amount follows the picked site', async () => {
  const rows = [row('e1', 'م. كريم فتحي', 'engineer', { site: null, overrides: [{ site_id: 's1', amount: '50.000000' }] })]
  saving({ 'GET /api/v1/team-fares': team(rows) })
  await asHr()
  await edit('م. كريم فتحي')
  const dialog = await screen.findByRole('dialog')
  const amount = within(dialog).getByLabelText('المبلغ اليومي')
  expect(amount).toHaveValue('50')
  await user.selectOptions(within(dialog).getByRole('combobox', { name: 'الموقع' }), 's2')
  expect(amount).toHaveValue('')
  await user.selectOptions(within(dialog).getByRole('combobox', { name: 'الموقع' }), 's1')
  expect(amount).toHaveValue('50')
})

test('a worker with no residence has no pair option', async () => {
  saving()
  await asHr()
  await edit('فتحي سالم')
  const dialog = await screen.findByRole('dialog', { name: 'تعديل مواصلات فتحي سالم · العامرية' })
  expect(within(dialog).queryByRole('radio', { name: /لكل اللي ساكنين/ })).toBeNull()
  expect(within(dialog).queryByRole('combobox')).toBeNull()
})

test('a 422 shows the server message', async () => {
  saving({ 'POST /api/v1/employees/w2/fare-overrides': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'effective_from', code: 'locked', message: 'الفترة مقفولة' }] } }) })
  await asHr()
  await edit('سيد إبراهيم')
  const dialog = await screen.findByRole('dialog')
  await user.clear(within(dialog).getByLabelText('المبلغ اليومي'))
  await user.type(within(dialog).getByLabelText('المبلغ اليومي'), '32')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText('الفترة مقفولة')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('يسري من')).toHaveAccessibleDescription('الفترة مقفولة')
})

test('a site_id 422 shows in the banner when the dialog has no site field', async () => {
  saving({ 'POST /api/v1/employees/w2/fare-overrides': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'site_id', code: 'invalid', message: 'الموقع مش تبعك' }] } }) })
  await asHr()
  await edit('سيد إبراهيم')
  const dialog = await screen.findByRole('dialog')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('الموقع مش تبعك')
})

const NO_RESIDENCE = [row('w4', 'فتحي سالم', 'day_laborer', { residence: null, source: 'no_residence' }), row('e1', 'م. كريم فتحي', 'engineer', { residence: null, site: null })]
const ENGINEER_ROUTES = { 'GET /api/v1/team-fares': team(NO_RESIDENCE), 'PATCH /api/v1/employees/e1': () => json(200, { data: {} }) }
/** Only the engineer's row can be asked for by position: both rows carry the same button text. */
const openEngineerResidence = async () => {
  await screen.findByText('م. كريم فتحي')
  await user.click(within(bodyRows()[1]).getByRole('button', { name: 'سجّل السكن' }))
  return screen.findByRole('dialog', { name: 'سكن م. كريم فتحي' })
}

test('an engineer with no residence gets the button, and saving patches that engineer', async () => {
  saving(ENGINEER_ROUTES)
  await asHr()
  const dialog = await openEngineerResidence()
  await user.selectOptions(within(dialog).getByRole('combobox', { name: 'السكن' }), 'r2')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(patched()).toEqual([['/api/v1/employees/e1', { residence_id: 'r2' }]])
})

test('without employee.edit a row with no residence keeps the badge and has no button', async () => {
  saving(ENGINEER_ROUTES)
  await asHr(['fare.manage.all', 'employee.view.all', 'site.view.all', 'site.manage.all'])
  await screen.findByText('م. كريم فتحي')
  expect(screen.queryByRole('button', { name: 'سجّل السكن' })).toBeNull()
  for (const r of bodyRows()) expect(within(r).getByText('السكن مش متسجل')).toBeInTheDocument()
})

const patched = () => vi.mocked(fetch).mock.calls.filter(([, i]) => i?.method === 'PATCH').map(([u, i]) => [String(u), JSON.parse(String(i!.body))])

test('HR fills a missing residence', async () => {
  saving()
  await asHr()
  await user.click(await screen.findByRole('button', { name: 'سجّل السكن' }))
  const dialog = await screen.findByRole('dialog', { name: 'سكن فتحي سالم' })
  await user.selectOptions(within(dialog).getByRole('combobox', { name: 'السكن' }), 'r2')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(patched()).toEqual([['/api/v1/employees/w4', { residence_id: 'r2' }]])
})

test('a residence 422 shows in the dialog', async () => {
  saving({ 'PATCH /api/v1/employees/w4': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'bank_account', code: 'required', message: 'رقم الحساب مطلوب' }] } }) })
  await asHr()
  await user.click(await screen.findByRole('button', { name: 'سجّل السكن' }))
  const dialog = await screen.findByRole('dialog')
  await user.selectOptions(within(dialog).getByRole('combobox', { name: 'السكن' }), 'r1')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  expect(await within(dialog).findByText('رقم الحساب مطلوب')).toBeInTheDocument()
})

const CAN_ADD = [...HR, 'site.manage.all']
const NEW_RESIDENCE = { id: 'r3', name_ar: 'سيدي بشر' }
/** `/residences` answers with the new row once it has been posted, as the server does. */
const addable = (extra = {}) => {
  let residences = [{ id: 'r1', name_ar: 'كفر الدوار' }, { id: 'r2', name_ar: 'القاهرة' }]
  saving({
    ...ENGINEER_ROUTES,
    'GET /api/v1/residences': () => json(200, { data: residences, meta: { pagination } }),
    'POST /api/v1/residences': () => {
      residences = [...residences, NEW_RESIDENCE]
      return json(201, { data: NEW_RESIDENCE })
    },
    ...extra,
  })
}
const openAdd = async (dialog: HTMLElement) => {
  await user.click(within(dialog).getByRole('button', { name: 'إضافة محل إقامة' }))
  return screen.findByRole('dialog', { name: 'إضافة محل إقامة' })
}

test('a residence added from the dialog ends up selected, and saving patches with its id', async () => {
  addable()
  await asHr(CAN_ADD)
  const dialog = await openEngineerResidence()
  const added = await openAdd(dialog)
  await user.type(within(added).getByRole('textbox'), 'سيدي بشر')
  await user.click(within(added).getByRole('button', { name: 'حفظ' }))
  const select = await screen.findByRole('combobox', { name: 'السكن' })
  expect(screen.getByRole('dialog', { name: 'سكن م. كريم فتحي' })).toBeInTheDocument()
  await waitFor(() => expect(select).toHaveDisplayValue('سيدي بشر'))
  expect(post('/residences')).toEqual([{ name_ar: 'سيدي بشر' }])
  expect(patched()).toEqual([])
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(patched()).toEqual([['/api/v1/employees/e1', { residence_id: 'r3' }]])
})

test('without site.manage the dialog has the select and no add button', async () => {
  addable()
  await asHr()
  const dialog = await openEngineerResidence()
  expect(within(dialog).getByRole('combobox', { name: 'السكن' })).toBeInTheDocument()
  expect(within(dialog).queryByRole('button', { name: 'إضافة محل إقامة' })).toBeNull()
})

test('a duplicate name shows inside the add dialog and leaves the selection alone', async () => {
  addable({ 'POST /api/v1/residences': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'name_ar', code: 'unique', message: 'الاسم ده موجود بالفعل' }] } }) })
  await asHr(CAN_ADD)
  const dialog = await openEngineerResidence()
  const added = await openAdd(dialog)
  await user.type(within(added).getByRole('textbox'), 'القاهرة')
  await user.click(within(added).getByRole('button', { name: 'حفظ' }))
  expect(await within(added).findByText('الاسم ده موجود بالفعل')).toBeInTheDocument()
  expect(within(dialog).getByRole('combobox', { name: 'السكن', hidden: true })).toHaveValue('')
  expect(patched()).toEqual([])
})
