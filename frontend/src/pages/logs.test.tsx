import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useSearchParams } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { LogsPage } from './logs'

const user = userEvent.setup()

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})

const row = (over: Record<string, unknown> = {}) => ({
  id: 'l1', request_id: 'req-1', user_id: 'u1', method: 'POST', path: 'api/v1/sites/s1/allowance-rules', route: 'api/v1/sites/{site}/allowance-rules',
  query: null, status: 200, duration_ms: 12, ip_address: '172.18.0.1', user_agent: 'Mozilla/5.0', request_body: null, response_body: null,
  occurred_at: '2026-11-04T20:01:02Z', user: { id: 'u1', name: 'developer' }, ...over,
})
const list = (rows: unknown[]) => vi.fn<(url: string) => Response>(() => json(200, { data: rows, meta: { pagination } }))
const lastUrl = (fn: { mock: { lastCall?: unknown[] } }) => String(fn.mock.lastCall?.[0])

test('renders in Arabic by default, with the request line left-to-right', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row()]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByRole('heading', { name: 'سجلات النظام' })).toBeInTheDocument()
  const table = await screen.findByRole('table', { name: 'سجلات النظام' })
  expect(within(table).getByText('api/v1/sites/s1/allowance-rules').closest('[dir="ltr"]')).toHaveClass('font-mono')
  expect(within(table).getByText('developer')).toBeInTheDocument()
})

test('?tab=requests renders the request list, and an unknown tab falls back to it', async () => {
  for (const tab of ['requests', 'nope']) {
    const fn = list([row()])
    mockFetch({ 'GET /api/v1/request-log': fn })
    const { unmount } = renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: `/console/logs?tab=${tab}` })
    expect(await within(await screen.findByRole('table', { name: 'سجلات النظام' })).findByText('api/v1/sites/s1/allowance-rules')).toBeInTheDocument()
    unmount()
    vi.unstubAllGlobals()
  }
})

test('filters become the query string', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await i18n.changeLanguage('en')
  await user.selectOptions(await screen.findByLabelText('Status'), 'failed')
  await user.selectOptions(screen.getByLabelText('Method'), 'POST')
  await user.type(screen.getByLabelText('From'), '2026-11-04')
  await user.type(screen.getByLabelText('Path'), 'employees')
  await waitFor(() => expect(lastUrl(fn)).toContain('path=employees'))
  expect(lastUrl(fn)).toContain('status=failed')
  expect(lastUrl(fn)).toContain('method=POST')
  expect(lastUrl(fn)).toContain('from=2026-11-04')
  expect(lastUrl(fn)).toContain('per_page=20')
})

test('a request_id in the URL filters the list and clear filters drops it', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn })
  renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: '/console/logs?tab=requests&request_id=req-9' })
  await waitFor(() => expect(lastUrl(fn)).toContain('request_id=req-9'))
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(lastUrl(fn)).not.toContain('request_id'))
})

test('a new request_id in the URL goes back to page 1', async () => {
  const fn = vi.fn<(url: string) => Response>(() => json(200, { data: [row()], meta: { pagination: { ...pagination, total: 45, total_pages: 3, has_next_page: true } } }))
  mockFetch({ 'GET /api/v1/request-log': fn })
  function Link() {
    const [, setParams] = useSearchParams()
    return <button type="button" onClick={() => setParams({ request_id: 'req-2' })}>link</button>
  }
  renderWithAuth(<><LogsPage /><Link /></>, ['system_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'التالي' }))
  await waitFor(() => expect(lastUrl(fn)).toContain('page=2'))
  await user.click(screen.getByRole('button', { name: 'link' }))
  await waitFor(() => expect(lastUrl(fn)).toContain('request_id=req-2'))
  expect(lastUrl(fn)).toContain('page=1')
})

test('the status badge tone follows the code', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row(), row({ id: 'l2', status: 422 }), row({ id: 'l3', status: 500 })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByText('200')).toHaveClass('text-success')
  expect(screen.getByText('422')).toHaveClass('text-warning')
  expect(screen.getByText('500')).toHaveClass('text-destructive')
})

test('a 422 details sheet shows the request metadata and its masked body as it is', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row({ status: 422, query: { search: '[masked]' }, request_body: { kind: 'fixed', value: '[masked]' }, response_body: { message: 'invalid' } })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(sheet).getByText('req-1')).toBeInTheDocument()
  expect(within(sheet).getByText('api/v1/sites/{site}/allowance-rules')).toBeInTheDocument()
  expect(within(sheet).getByText('172.18.0.1')).toBeInTheDocument()
  expect(within(sheet).getByText('بيانات المتصفح').nextSibling).toHaveTextContent('Mozilla/5.0')
  const body = within(sheet).getByRole('region', { name: 'محتوى الطلب' })
  expect(within(body).getByText(/"value": "\[masked\]"/)).toHaveAttribute('dir', 'ltr')
  expect(within(sheet).getByRole('region', { name: 'معاملات الاستعلام' })).toHaveTextContent('"search": "[masked]"')
  expect(within(sheet).getByRole('region', { name: 'محتوى الاستجابة' })).toHaveTextContent('"message": "invalid"')
  expect(sheet).not.toHaveTextContent('[object Object]')
})

test('the details subtitle isolates the time, the account and the duration, so an Arabic name keeps its place in English', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row({ user: { id: 'u1', name: 'أ.فاطمه' }, duration_ms: 27 })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await i18n.changeLanguage('en')
  await user.click(await screen.findByRole('button', { name: 'View details' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(sheet).getByText('أ.فاطمه').tagName).toBe('BDI')
  expect(within(sheet).getByText('27 ms').tagName).toBe('BDI')
  expect(within(sheet).getByText(/11\/4\/26/).tagName).toBe('BDI')
})

test('without user.view there is no account filter and no account list call', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByLabelText('المسار')).toBeInTheDocument()
  expect(screen.queryByLabelText('الحساب')).toBeNull()
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/users'))).toBe(false)
})

test('with user.view the account filter sends user_id', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn, 'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'developer' }], meta: { pagination } }) })
  renderWithAuth(<LogsPage />, ['system_log.view.all', 'user.view.all'])
  await screen.findByRole('option', { name: 'developer' })
  await user.selectOptions(screen.getByLabelText('الحساب'), 'u1')
  await waitFor(() => expect(lastUrl(fn)).toContain('user_id=u1'))
})

const err = (over: Record<string, unknown> = {}) => ({
  id: 'e1', request_id: 'req-1', user_id: 'u1', class: 'Illuminate\\Database\\UniqueConstraintViolationException',
  message: 'SQLSTATE 23505, constraint employees_national_id_unique\ninsert into "employees"', file: '/var/www/html/vendor/Connection.php', line: 838,
  trace: '#0 /var/www/html/app/A.php(12): App\\A->b()\n#1 {main}', sqlstate: '23505', occurred_at: '2026-11-04T20:01:03Z', ...over,
})

test('the logs page renders both tabs, and the bar switches between them', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row()]), 'GET /api/v1/error-log': list([err()]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByRole('tab', { name: 'الطلبات', selected: true })).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'الأخطاء' }))
  const table = await screen.findByRole('table', { name: 'الأخطاء' })
  expect(within(table).getByText('Illuminate\\Database\\UniqueConstraintViolationException')).toHaveAttribute('dir', 'ltr')
  expect(within(table).getByText(/^SQLSTATE 23505/)).toHaveClass('truncate')
  expect(within(table).getByText('req-1')).toHaveAttribute('dir', 'ltr')
})

test('error filters become the query string', async () => {
  const fn = list([err()])
  mockFetch({ 'GET /api/v1/error-log': fn })
  renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: '/console/logs?tab=errors' })
  await user.type(await screen.findByLabelText('من'), '2026-11-01')
  await user.type(screen.getByLabelText('إلى'), '2026-11-04')
  await waitFor(() => expect(lastUrl(fn)).toContain('to=2026-11-04'))
  expect(lastUrl(fn)).toContain('from=2026-11-01')
  expect(lastUrl(fn)).toContain('per_page=20')
})

test("the request sheet's link opens the Errors tab filtered by that request, and the chip clears it", async () => {
  const errors = list([err()])
  mockFetch({ 'GET /api/v1/request-log': list([row({ request_id: 'req-7', status: 500 })]), 'GET /api/v1/error-log': errors })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('link', { name: 'عرض أخطاء هذا الطلب' }))
  expect(await screen.findByRole('tab', { name: 'الأخطاء', selected: true })).toBeInTheDocument()
  await waitFor(() => expect(lastUrl(errors)).toContain('request_id=req-7'))
  expect(screen.queryByRole('dialog')).toBeNull()
  expect(screen.getByText('req-7', { selector: 'bdi' })).toHaveAttribute('dir', 'ltr')
  await user.click(screen.getByRole('button', { name: 'إلغاء التصفية بمعرّف الطلب' }))
  await waitFor(() => expect(lastUrl(errors)).not.toContain('request_id'))
  expect(screen.queryByRole('button', { name: 'إلغاء التصفية بمعرّف الطلب' })).toBeNull()
})

test('the error sheet shows where it happened and links back to its request', async () => {
  const requests = list([row()])
  mockFetch({ 'GET /api/v1/error-log': list([err({ request_id: 'req-3' })]), 'GET /api/v1/request-log': requests })
  renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: '/console/logs?tab=errors' })
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(sheet).getByText('/var/www/html/vendor/Connection.php:838')).toHaveAttribute('dir', 'ltr')
  expect(within(sheet).getByText('رمز خطأ قاعدة البيانات').nextSibling).toHaveTextContent('23505')
  expect(within(sheet).getByRole('region', { name: 'الرسالة' })).toHaveTextContent('insert into "employees"')
  const trace = within(sheet).getByRole('region', { name: 'تتبع الاستدعاءات' })
  expect(within(trace).getByText(/#0 \/var\/www\/html\/app\/A\.php\(12\)/)).toHaveAttribute('dir', 'ltr')
  await user.click(within(sheet).getByRole('link', { name: 'عرض الطلب' }))
  expect(await screen.findByRole('tab', { name: 'الطلبات', selected: true })).toBeInTheDocument()
  await waitFor(() => expect(lastUrl(requests)).toContain('request_id=req-3'))
  expect(screen.getByText('req-3', { selector: 'bdi' })).toBeInTheDocument()
})

test('an error with no request (a queued job) has no request link', async () => {
  mockFetch({ 'GET /api/v1/error-log': list([err({ request_id: null })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: '/console/logs?tab=errors' })
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  expect(within(await screen.findByRole('dialog')).queryByRole('link')).toBeNull()
})

test('without system_log.view the page is not found and loads nothing', async () => {
  mockFetch({})
  renderWithAuth(<LogsPage />, ['employee.view.all', 'audit_log.view.all'])
  expect(await screen.findByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('-log'))).toBe(false)
})
