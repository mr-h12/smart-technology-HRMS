import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { queryClient } from '@/lib/query'
import { json, me, mockFetch, renderApp, renderWithAuth } from '@/test-utils'
import { SettingsPage } from './settings'
vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'

const user = userEvent.setup()
const MANAGE = ['site.view.all', 'site.manage.all']
const pg = (total = 1) => ({ page: 1, per_page: 100, total, total_pages: 1, has_next_page: false, has_previous_page: false })
const list = (data: unknown[], total = data.length) => () => json(200, { data, meta: { pagination: pg(total) } })
const calls = (m: string) => vi.mocked(fetch).mock.calls.filter(([, i]) => (i?.method ?? 'GET') === m)
const urlWith = (part: string) => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes(part))!
const body = (fn: { mock: { calls: unknown[][] } }) => JSON.parse((fn.mock.calls[0] as [string, RequestInit])[1].body as string)
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)

const sites = [
  { id: 's1', name_ar: 'موقع أ', kind: 'work', leave_code: null, active: true },
  { id: 's2', name_ar: 'موقع ب', kind: 'work', leave_code: null, active: true },
]
const residences = [
  { id: 'r1', name_ar: 'العجمي' },
  { id: 'r2', name_ar: 'برج العرب' },
]

const rowMenu = async (name: string) => {
  const row = await screen.findByRole('row', { name: new RegExp(name) })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
}

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  vi.clearAllMocks()
})

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
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
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
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  const rowEl = await screen.findByRole('row', { name: /برج العرب/ })
  await user.click(within(rowEl).getAllByRole('button')[1])
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-10-01')
  await user.type(screen.getByLabelText('قيمة المواصلات'), '8.5')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  const body = JSON.parse(calls('POST')[0][1]!.body as string)
  expect(body).toEqual({ residence_id: 'r2', site_id: 's2', effective_from: '2026-10-01', amount: '8.5' })
})

test('fares grid shows a notice when more than 100 fares exist', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences), 'GET /api/v1/fares': list([], 150) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  expect(await screen.findByRole('status')).toBeInTheDocument()
})

test('a failing secondary load shows the banner on the fares page', async () => {
  mockFetch({
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }),
    'GET /api/v1/fares': list([]),
  })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
})

test('site save error shows under the field', async () => {
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'name_ar', code: 'unique', message: 'الاسم مستخدم' }] } }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'س')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('الاسم مستخدم')).toBeInTheDocument()
})

test('residences list renders and a load failure shows the banner', async () => {
  mockFetch({ 'GET /api/v1/residences': list(residences) })
  const { unmount } = renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=residences' })
  expect(await screen.findByText('العجمي')).toBeInTheDocument()
  unmount()
  queryClient.clear() // a later visit, after the cached list has expired
  mockFetch({ 'GET /api/v1/residences': () => json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=residences' })
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
})

test('a double click on Save submits once', async () => {
  mockFetch({
    'GET /api/v1/sites': list([]),
    'POST /api/v1/sites': () => json(201, { data: {} }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'س')
  await user.dblClick(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(calls('POST')).toHaveLength(1)
})

test('fares grid asks the server for the fares in force today', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences), 'GET /api/v1/fares': list([]) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  await screen.findByRole('row', { name: /العجمي/ })
  const url = vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes('/fares'))!
  expect(url).toMatch(/date=\d{4}-\d{2}-\d{2}/)
  expect(url).toContain('per_page=100')
})

test('fares grid warns when sites or residences overflow the page', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites, 150), 'GET /api/v1/residences': list(residences), 'GET /api/v1/fares': list([]) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  expect(await screen.findByRole('status')).toBeInTheDocument()
})

test('sites tab lists work sites and its add dialog sends kind work without a leave code', async () => {
  mockFetch({ 'GET /api/v1/sites': list([]), 'POST /api/v1/sites': () => json(201, { data: {} }) })
  renderWithAuth(<SettingsPage />, MANAGE)
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  expect(screen.queryByLabelText('كود الإجازة')).toBeNull()
  expect(screen.queryByLabelText('نوع الموقع')).toBeNull()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'سموحة')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(urlWith('/sites')).toContain('kind=work')
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toMatchObject({ name_ar: 'سموحة', kind: 'work', leave_code: null })
})

test('leave types tab lists kind=leave rows and adds one with its code', async () => {
  mockFetch({ 'GET /api/v1/sites': list([{ id: 'l1', name_ar: 'جمعة', kind: 'leave', leave_code: 'friday', active: true }]), 'POST /api/v1/sites': () => json(201, { data: {} }) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=leave-types' })
  expect(await screen.findByText('جمعة')).toBeInTheDocument()
  expect(urlWith('/sites')).toContain('kind=leave')
  await user.click(screen.getByRole('button', { name: 'إضافة نوع إجازة' }))
  expect(screen.getByLabelText('كود الإجازة')).toHaveValue('friday')
  expect(within(screen.getByLabelText('كود الإجازة')).queryByRole('option', { name: '' })).toBeNull()
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'مرضي')
  await user.selectOptions(screen.getByLabelText('كود الإجازة'), 'sick')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toMatchObject({ name_ar: 'مرضي', kind: 'leave', leave_code: 'sick' })
})

test('tabs follow ?tab= and switching shows the other table', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=residences' })
  expect(await screen.findByRole('tab', { name: 'محل الإقامة' })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByText('العجمي')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'المواقع' }))
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
})

test('the tab strip wraps from sm up, so no tab label is cut off on a tablet', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, MANAGE)
  expect(await screen.findByRole('tablist')).toHaveClass('sm:h-auto', 'sm:flex-wrap')
})

test('an unknown tab falls back to sites', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=bogus' })
  expect(await screen.findByRole('tab', { name: 'المواقع' })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
})

test('old routes redirect to their settings tab', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(['site.view.all']) }),
    'GET /api/v1/sites': list(sites),
    'GET /api/v1/residences': list(residences),
    'GET /api/v1/fares': list([]),
  })
  renderApp('/fares')
  expect(await screen.findByRole('tab', { name: 'المواصلات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getByRole('link', { name: 'الإعدادات' })).toBeInTheDocument()
})

test('allowance rule history lists rules and adds one', async () => {
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [{ id: 'a1', effective_from: '2020-01-01', mode: 'fixed', value: '50.000000' }] } }),
    'POST /api/v1/sites/s1/allowance-rules': () => json(201, { data: { ...sites[0], allowance_rules: [] } }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  expect(await screen.findByText(fmt(50))).toBeInTheDocument()
  expect(screen.getByText('2020-01-01')).toHaveAttribute('dir', 'ltr') // an ISO date is not drawn backwards in RTL
  await user.type(screen.getByLabelText('ساري اعتبارًا من'), '2026-10-01')
  await user.selectOptions(screen.getByLabelText('نوع البدل'), 'share_of_wage')
  await user.type(screen.getByLabelText('القيمة'), '0.5')
  await user.click(screen.getByRole('button', { name: 'إضافة قاعدة' }))
  expect(JSON.parse(calls('POST')[0][1]!.body as string)).toEqual({ effective_from: '2026-10-01', mode: 'share_of_wage', value: '0.5' })
})

test('rules are fetched only when the dialog opens, once', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [] } }) })
  renderWithAuth(<SettingsPage />, MANAGE)
  const detail = () => calls('GET').filter(([u]) => /\/sites\/s\d/.test(String(u)))
  await rowMenu('موقع أ')
  expect(detail()).toHaveLength(0)
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  await screen.findByLabelText('ساري اعتبارًا من')
  expect(detail()).toHaveLength(1)
})

test('share-of-wage rule shows the exact multiplier, not money', async () => {
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [], allowance_rules: [{ id: 'a1', effective_from: '2020-01-01', mode: 'share_of_wage', value: '0.125000' }] } }),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'البدلات' }))
  expect(await screen.findByText('×0.125')).toBeInTheDocument()
})

test('a dialog opened from a row menu resets on reopen and leaves the page clickable', async () => {
  let n = 0
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'PATCH /api/v1/sites/s1': () => (n++ === 0 ? json(500, { error: { code: 'server_error', message: 'تعذر الحفظ' } }) : json(200, { data: {} })),
  })
  renderWithAuth(<SettingsPage />, MANAGE)
  const edit = async () => {
    await rowMenu('موقع أ')
    await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  }
  await edit()
  await user.clear(screen.getByLabelText('الاسم بالعربية'))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'قديم')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByRole('alert')
  await user.keyboard('{Escape}')
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await edit()
  expect(screen.queryByRole('alert')).toBeNull()
  expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('موقع أ')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await user.click(screen.getByRole('tab', { name: 'محل الإقامة' }))
  expect(screen.getByRole('tab', { name: 'محل الإقامة' })).toHaveAttribute('aria-selected', 'true')
})

test('site managers dialog pre-checks current managers and saves the ticked ids', async () => {
  const put = vi.fn(() => json(200, { data: { ...sites[0], managers: [] } }))
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers: [{ id: 'u1', name: 'خالد' }], allowance_rules: [] } }),
    'GET /api/v1/users': list([{ id: 'u1', name: 'خالد', is_active: true }, { id: 'u2', name: 'سامي', is_active: true }, { id: 'u3', name: 'معطل', is_active: false }]),
    'PUT /api/v1/sites/s1/managers': put,
  })
  renderWithAuth(<SettingsPage />, [...MANAGE, 'site.assign_manager.all'])
  await rowMenu('موقع أ')
  await user.click(await screen.findByRole('menuitem', { name: 'المديرون' }))
  expect(await screen.findByRole('checkbox', { name: 'خالد' })).toBeChecked()
  expect(screen.getByRole('checkbox', { name: 'سامي' })).not.toBeChecked()
  await user.click(screen.getByRole('checkbox', { name: 'سامي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(put)).toEqual({ manager_ids: ['u1', 'u2'] })
  expect(screen.queryByRole('checkbox', { name: 'معطل' })).toBeNull() // only active accounts can manage a site
  expect(urlWith('/users')).toBe('/api/v1/users?per_page=100') // the lookup every page shares
})

test('switching tabs back to one already shown makes no new request', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites), 'GET /api/v1/residences': list(residences), 'GET /api/v1/fares': list([]) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  await screen.findByRole('row', { name: /العجمي/ })
  await user.click(screen.getByRole('tab', { name: 'المواقع' }))
  await screen.findByText('موقع أ')
  await user.click(screen.getByRole('tab', { name: 'المواصلات' }))
  await screen.findByRole('row', { name: /العجمي/ })
  expect(calls('GET').filter(([u]) => String(u).includes('/fares'))).toHaveLength(1)
})

test('adding a site shows it in the fares grid too', async () => {
  let all = [sites[0]]
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: all, meta: { pagination: pg(all.length) } }),
    'GET /api/v1/residences': list(residences),
    'GET /api/v1/fares': list([]),
    'POST /api/v1/sites': () => { all = [...all, { id: 's9', name_ar: 'موقع جديد', kind: 'work', leave_code: null, active: true }]; return json(201, { data: {} }) },
  })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=fares' })
  await screen.findByRole('columnheader', { name: 'موقع أ' })
  await user.click(screen.getByRole('tab', { name: 'المواقع' }))
  await user.click(await screen.findByRole('button', { name: 'إضافة موقع' }))
  await user.type(screen.getByLabelText('الاسم بالعربية'), 'موقع جديد')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByText('موقع جديد')
  await user.click(screen.getByRole('tab', { name: 'المواصلات' }))
  expect(await screen.findByRole('columnheader', { name: 'موقع جديد' })).toBeInTheDocument()
})

test('without manage permissions there is no add button and no row actions', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, ['site.view.own'])
  expect(await screen.findByText('موقع أ')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة موقع' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
})

test('site.manage alone shows no managers action', async () => {
  mockFetch({ 'GET /api/v1/sites': list([sites[0]]) })
  renderWithAuth(<SettingsPage />, MANAGE)
  await rowMenu('موقع أ')
  expect(await screen.findByRole('menuitem', { name: 'تعديل' })).toBeInTheDocument()
  expect(screen.queryByRole('menuitem', { name: 'المديرون' })).toBeNull()
})

const rules = {
  month_days: '30', day_hours: '8', overtime_multiplier: '1.5',
  lateness_multiplier_office: '1', lateness_multiplier_engineer: '1.5', lateness_multiplier_permanent_worker: '2', lateness_multiplier_day_laborer: '2',
  office_grace_minutes: '15', office_shift_start: '09:00', office_shift_end: '17:00', worker_shift_start: '08:00', worker_shift_end: '16:00',
  weekly_off_office: 'friday,saturday', weekly_off_field: 'friday',
  leave_days_under_one_year: '10', leave_days_one_year_plus: '21', leave_casual_days: '6',
}
const RULES = ['site.view.all', 'setting.view.all', 'setting.manage.all']

test('rules tab loads the settings, saves typed values and toasts', async () => {
  const put = vi.fn(() => json(200, { data: { ...rules, office_grace_minutes: '20', weekly_off_field: 'friday,thursday' } }))
  mockFetch({ 'GET /api/v1/settings': () => json(200, { data: rules }), 'PUT /api/v1/settings': put })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  const grace = await screen.findByLabelText('فترة السماح للتأخير (دقيقة)')
  expect(grace).toHaveValue('15')
  expect(screen.getByLabelText('بدء وردية العمال المثبتين')).toHaveValue('8:00')
  const end = screen.getByLabelText('انتهاء وردية العمال المثبتين')
  expect(end).toHaveValue('4:00')
  expect(within(screen.getByRole('group', { name: 'صباحًا أو مساءً: انتهاء وردية العمال المثبتين' })).getByRole('button', { name: 'مساءً' })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeDisabled()
  await user.clear(grace)
  await user.type(grace, '٢٠')
  const field = screen.getByRole('group', { name: 'الراحة الأسبوعية: العاملون بالمواقع' })
  await user.click(within(field).getByRole('checkbox', { name: 'الخميس' }))
  await user.clear(end)
  await user.type(end, '5')
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(body(put)).toEqual({ ...rules, office_grace_minutes: '20', weekly_off_field: 'friday,thursday', worker_shift_end: '17:00' })
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم حفظ التغييرات.'))
})

test('a rejected rule value shows under its field', async () => {
  mockFetch({
    'GET /api/v1/settings': () => json(200, { data: rules }),
    'PUT /api/v1/settings': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'overtime_multiplier', code: 'invalid', message: 'صيغة القيمة غير صحيحة' }] } }),
  })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  const ot = await screen.findByLabelText('معامل الوقت الإضافي')
  await user.type(ot, '5')
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  await waitFor(() => expect(ot).toHaveAccessibleDescription('صيغة القيمة غير صحيحة'))
  expect(toast.success).not.toHaveBeenCalled()
})

test('view-only users see the rules read-only', async () => {
  mockFetch({ 'GET /api/v1/settings': () => json(200, { data: rules }) })
  renderWithAuth(<SettingsPage />, ['site.view.all', 'setting.view.all'], { path: '/settings?tab=rules' })
  expect(await screen.findByLabelText('عدد أيام الشهر')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
})

test('the rules tab is hidden without setting.view', async () => {
  mockFetch({ 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=rules' })
  expect(await screen.findByRole('tab', { name: 'المواقع' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'قواعد العمل' })).toBeNull()
})

test('the rules form starts from the settings as they are now, not from the cache', async () => {
  let current = rules
  let release = () => {}
  let hold: Promise<void> | undefined
  mockFetch({ 'GET /api/v1/settings': () => (hold ?? Promise.resolve()).then(() => json(200, { data: current })) as unknown as Response, 'GET /api/v1/sites': list(sites) })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  expect(await screen.findByLabelText('فترة السماح للتأخير (دقيقة)')).toHaveValue('15')
  await user.click(screen.getByRole('tab', { name: 'المواقع' }))
  await screen.findByText('موقع أ')
  current = { ...rules, office_grace_minutes: '20' } // another HR user saved meanwhile
  hold = new Promise<void>((r) => (release = r))
  await user.click(screen.getByRole('tab', { name: 'قواعد العمل' }))
  expect(screen.queryByLabelText('فترة السماح للتأخير (دقيقة)')).toBeNull() // no form from the cached copy while the fresh one is on its way
  release()
  expect(await screen.findByLabelText('فترة السماح للتأخير (دقيقة)')).toHaveValue('20')
})

test('a failed background refetch keeps the rules form and what was typed, with a banner', async () => {
  let fail = false
  mockFetch({ 'GET /api/v1/settings': () => (fail ? json(500, { error: { code: 'server_error', message: 'تعذر التحميل' } }) : json(200, { data: rules })) })
  renderWithAuth(<SettingsPage />, RULES, { path: '/settings?tab=rules' })
  const grace = await screen.findByLabelText('فترة السماح للتأخير (دقيقة)')
  await user.type(grace, '5')
  fail = true
  await act(() => queryClient.invalidateQueries())
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر التحميل')
  expect(screen.getByLabelText('فترة السماح للتأخير (دقيقة)')).toBe(grace)
  expect(grace).toHaveValue('155')
})

test('the managers dialog ticks the managers as they are now, not as cached', async () => {
  let managers = [{ id: 'u1', name: 'خالد' }]
  mockFetch({
    'GET /api/v1/sites': list([sites[0]]),
    'GET /api/v1/sites/s1': () => json(200, { data: { ...sites[0], managers, allowance_rules: [] } }),
    'GET /api/v1/users': list([{ id: 'u1', name: 'خالد', is_active: true }, { id: 'u2', name: 'سامي', is_active: true }]),
  })
  renderWithAuth(<SettingsPage />, [...MANAGE, 'site.assign_manager.all'])
  const open = async () => { await rowMenu('موقع أ'); await user.click(await screen.findByRole('menuitem', { name: 'المديرون' })) }
  await open()
  expect(await screen.findByRole('checkbox', { name: 'سامي' })).not.toBeChecked()
  await user.keyboard('{Escape}')
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  managers = [{ id: 'u1', name: 'خالد' }, { id: 'u2', name: 'سامي' }]
  await open()
  await waitFor(() => expect(screen.getByRole('checkbox', { name: 'سامي' })).toBeChecked())
})

test('the leave-type dialog offers the Saturday code', async () => {
  mockFetch({ 'GET /api/v1/sites': list([]) })
  renderWithAuth(<SettingsPage />, MANAGE, { path: '/settings?tab=leave-types' })
  await user.click(await screen.findByRole('button', { name: 'إضافة نوع إجازة' }))
  expect(within(screen.getByLabelText('كود الإجازة')).getByRole('option', { name: 'السبت' })).toHaveValue('saturday')
})

const HOLIDAYS = ['attendance.view.all', 'holiday.manage.all']
const lockedPeriod = () => json(423, { error: { code: 'period_locked', message: 'فترة الحضور مقفلة ولا يمكن تعديلها.' } })

test('holidays tab lists, adds and shows a locked-period refusal', async () => {
  let n = 0
  const post = vi.fn(() => (n++ === 0 ? json(201, { data: {} }) : lockedPeriod()))
  mockFetch({ 'GET /api/v1/attendance/holidays': list([{ id: 'h1', date: '2026-10-06', name: 'Holiday-1' }]), 'POST /api/v1/attendance/holidays': post })
  renderWithAuth(<SettingsPage />, HOLIDAYS, { path: '/settings?tab=holidays' })
  expect(await screen.findByRole('row', { name: /2026-10-06.*Holiday-1/ })).toBeInTheDocument()
  expect(screen.getByText('2026-10-06')).toHaveAttribute('dir', 'ltr')
  expect(urlWith('/attendance/holidays')).toContain('per_page=100')
  const add = async (date: string, name: string) => {
    await user.click(screen.getByRole('button', { name: 'إضافة إجازة رسمية' }))
    await user.type(screen.getByLabelText('التاريخ'), date)
    await user.type(screen.getByLabelText('اسم الإجازة'), name)
    await user.click(screen.getByRole('button', { name: 'حفظ' }))
  }
  await add('2026-10-20', 'Holiday-2')
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(body(post)).toEqual({ date: '2026-10-20', name: 'Holiday-2' })
  await add('2026-10-21', 'Holiday-3')
  expect(await within(screen.getByRole('dialog')).findByRole('alert')).toHaveTextContent('فترة الحضور مقفلة ولا يمكن تعديلها.')
})

test('a holiday is edited and deleted from its row menu', async () => {
  const patch = vi.fn(() => json(200, { data: {} }))
  const del = vi.fn(() => new Response(null, { status: 204 }))
  mockFetch({ 'GET /api/v1/attendance/holidays': list([{ id: 'h1', date: '2026-10-06', name: 'Holiday-1' }]), 'PATCH /api/v1/attendance/holidays/h1': patch, 'DELETE /api/v1/attendance/holidays/h1': del })
  renderWithAuth(<SettingsPage />, HOLIDAYS, { path: '/settings?tab=holidays' })
  await rowMenu('Holiday-1')
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  expect(screen.getByRole('dialog', { name: 'تعديل الإجازة الرسمية' })).toBeInTheDocument()
  await user.clear(screen.getByLabelText('اسم الإجازة'))
  await user.type(screen.getByLabelText('اسم الإجازة'), 'Holiday-9')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(patch)).toEqual({ date: '2026-10-06', name: 'Holiday-9' })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await rowMenu('Holiday-1')
  await user.click(await screen.findByRole('menuitem', { name: 'حذف' }))
  expect(screen.getByRole('dialog', { name: 'حذف الإجازة الرسمية «Holiday-1»؟' })).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'حذف' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(del).toHaveBeenCalledTimes(1)
})

test('holiday controls need holiday.manage', async () => {
  mockFetch({ 'GET /api/v1/attendance/holidays': list([{ id: 'h1', date: '2026-10-06', name: 'Holiday-1' }]) })
  renderWithAuth(<SettingsPage />, ['attendance.view.all'], { path: '/settings?tab=holidays' })
  expect(await screen.findByText('Holiday-1')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة إجازة رسمية' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
})

test('a user with only attendance.view lands on the holidays tab', async () => {
  mockFetch({ 'GET /api/v1/attendance/holidays': list([]) })
  renderWithAuth(<SettingsPage />, ['attendance.view.all'], { path: '/settings' })
  expect(await screen.findByRole('tab', { name: 'الإجازات الرسمية' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.getAllByRole('tab')).toHaveLength(1)
})
