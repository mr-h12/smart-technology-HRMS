import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { queryClient } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { type FareRequest, type SiteFareRow } from './site-fares'
import { TeamFaresPage } from './team-fares'

const user = userEvent.setup()
const MANAGER = ['employee.view.own', 'site.view.own', 'fare.request.own']
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
const urls = (part: string) => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes(part))
const body = (fn: { mock: { calls: unknown[][] } }, i = 0) => JSON.parse((fn.mock.calls[i] as [string, RequestInit])[1].body as string)

const S1 = { id: 's1', name: 'العامرية' }
const S2 = { id: 's2', name: 'برج العرب' }
const R1 = { id: 'r1', name: 'كفر الدوار' }
const row = (employee_id: string, name: string, type: SiteFareRow['type'], extra: Partial<SiteFareRow> = {}): SiteFareRow => ({
  employee_id, code: 7, name, type, residence: R1, amount: null, source: 'none', default_amount: null, pending: null, ...extra,
})
const AT_S1 = [
  row('w1', 'محمود عبد الله', 'permanent_worker', { amount: '35.000000', source: 'default', default_amount: '35.000000' }),
  row('w2', 'سيد إبراهيم', 'day_laborer', { amount: '0.000000', source: 'override' }),
  row('w3', 'عامل جديد أول', 'day_laborer', { residence: null, source: 'no_residence' }),
  row('e1', 'م. كريم فتحي', 'engineer'),
]
const AT_S2 = [row('w9', 'حسن علي', 'permanent_worker', { amount: '45.500000', source: 'default' }), row('e1', 'م. كريم فتحي', 'engineer', { amount: '60.000000', source: 'override' })]
const site = (url: string) => {
  const s = new URL(url, 'http://x').searchParams.get('site_id')
  return json(200, { data: { sites: [S1, S2], site: s === 's2' ? S2 : S1, rows: s === 's2' ? AT_S2 : AT_S1 } })
}
const req = (over: Partial<FareRequest> = {}): FareRequest => ({
  id: 'q1', status: 'pending', outcome: 'pending', current_amount: '35.000000', requested_amount: '42.750000', approved_amount: null, effective_from: null,
  reason: 'سكن أبعد', refusal_reason: null, created_at: '2026-11-20T08:00:00Z', decided_at: null, version: 1,
  site: { id: 's1', name_ar: 'العامرية', name_en: null }, employee: { id: 'w1', code: 7, name_ar: 'محمود عبد الله', name_en: null }, ...over,
})
const none = () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } })
const base = (extra: Record<string, (url: string, init?: RequestInit) => Response> = {}) =>
  mockFetch({ 'GET /api/v1/team-fares': () => json(200, { data: { sites: [S1, S2], rows: [] } }), 'GET /api/v1/team-fares/site': site, 'GET /api/v1/fare-requests/mine': none, ...extra })
const tableRow = (name: string) => screen.getByText(name).closest('tr')!

beforeEach(() => queryClient.clear())
afterEach(() => vi.unstubAllGlobals())

test('the manager picks a site and sees everyone there with the fare at that site, unset apart from zero', async () => {
  base()
  renderWithAuth(<TeamFaresPage />, MANAGER)
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات الموقع' })).toBeInTheDocument()
  await screen.findByText('محمود عبد الله')
  const sites = screen.getByRole('group', { name: 'مواقعك' })
  expect(within(sites).getByRole('button', { name: 'العامرية' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(tableRow('محمود عبد الله')).getByText(fmt(35))).toBeInTheDocument()
  expect(within(tableRow('سيد إبراهيم')).getByText(fmt(0))).toBeInTheDocument()
  expect(within(tableRow('سيد إبراهيم')).getByText('(صفر (محددة))')).toBeInTheDocument()
  expect(within(tableRow('عامل جديد أول')).getByText('غير محددة')).toBeInTheDocument()
  expect(within(tableRow('م. كريم فتحي')).getByText('غير محددة')).toBeInTheDocument()
  expect(within(tableRow('سيد إبراهيم')).getByText('عامل باليومية')).toBeInTheDocument()

  await user.click(within(sites).getByRole('button', { name: 'برج العرب' }))
  await screen.findByText('حسن علي')
  expect(screen.queryByText('محمود عبد الله')).toBeNull()
  expect(within(tableRow('م. كريم فتحي')).getByText(fmt(60))).toBeInTheDocument()   // the engineer's amount at this site
  expect(urls('/team-fares/site')).toEqual(['/api/v1/team-fares/site?site_id=s1', '/api/v1/team-fares/site?site_id=s2'])
})

test('a request sends the amount typed in Arabic digits as ASCII, the reason and one form id across a retry', async () => {
  let calls = 0
  const post = vi.fn(() => (++calls === 1 ? json(500, { error: { code: 'server_error', message: 'x' } }) : json(201, { data: req() })))
  base({ 'POST /api/v1/fare-requests': post })
  renderWithAuth(<TeamFaresPage />, MANAGER)
  await user.click(await screen.findByRole('button', { name: 'طلب تعديل مواصلات عامل جديد أول' }))
  const dialog = await screen.findByRole('dialog', { name: 'طلب تعديل مواصلات · عامل جديد أول · العامرية' })
  expect(within(dialog).getByText('غير محددة')).toBeInTheDocument()
  expect(within(dialog).getByText('تبقى المواصلات الحالية سارية حتى توافق الموارد البشرية.')).toBeInTheDocument()
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الطلب' }))
  expect(within(dialog).getByText('اكتب مبلغًا صحيحًا (رقم موجب أو صفر، حتى ٦ أرقام عشرية).')).toBeInTheDocument()
  expect(within(dialog).getByText('اكتب سبب الطلب.')).toBeInTheDocument()
  expect(post).not.toHaveBeenCalled()
  await user.type(within(dialog).getByLabelText('المبلغ اليومي المطلوب'), '٤٢٫٧٥')
  expect(within(dialog).getByLabelText('المبلغ اليومي المطلوب')).toHaveValue('42.75')
  await user.type(within(dialog).getByLabelText('سبب الطلب'), 'سكن أبعد')
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(body(post, 0)).toMatchObject({ employee_id: 'w3', site_id: 's1', amount: '42.75', reason: 'سكن أبعد' })
  expect(body(post, 1).client_request_id).toBe(body(post, 0).client_request_id)
})

test('a request already pending shows it in the dialog with a way to open it, and a pending row offers no second request', async () => {
  const withPending = (url: string) => {
    const r = site(url)
    return r.json().then(() => json(200, { data: { sites: [S1, S2], site: S1, rows: [{ ...AT_S1[0], pending: { id: 'q1', requested_amount: '42.750000', created_at: null, mine: true } }, { ...AT_S1[1], pending: { id: 'q2', requested_amount: '1.000000', created_at: null, mine: false } }, AT_S1[2]] } }))
  }
  const post = vi.fn(() => json(409, { error: { code: 'fare_request_pending', message: 'x', pending: { id: 'q9', requested_amount: '50.000000', created_at: null, mine: true } } }))
  mockFetch({ 'GET /api/v1/team-fares': () => json(200, { data: { sites: [S1], rows: [] } }), 'GET /api/v1/team-fares/site': withPending as never, 'GET /api/v1/fare-requests/mine': none, 'POST /api/v1/fare-requests': post })
  renderWithAuth(<TeamFaresPage />, MANAGER)
  await screen.findByText('محمود عبد الله')
  expect(within(tableRow('محمود عبد الله')).getByText(`طلب قيد المراجعة: ${fmt(42.75)}`)).toBeInTheDocument()
  expect(within(tableRow('محمود عبد الله')).getByRole('button', { name: 'عرض الطلب' })).toBeInTheDocument()
  expect(within(tableRow('سيد إبراهيم')).getByText('طلب قيد المراجعة من مدير آخر')).toBeInTheDocument()
  expect(within(tableRow('سيد إبراهيم')).queryByRole('button')).toBeNull()

  await user.click(screen.getByRole('button', { name: 'طلب تعديل مواصلات عامل جديد أول' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByLabelText('المبلغ اليومي المطلوب'), '50')
  await user.type(within(dialog).getByLabelText('سبب الطلب'), 'x')
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الطلب' }))
  expect(await within(dialog).findByText(/يوجد طلب لهذا الموظف في هذا الموقع ينتظر قرار الموارد البشرية/)).toBeInTheDocument()
  expect(within(dialog).getByRole('button', { name: 'إرسال الطلب' })).toBeDisabled()
  expect(within(dialog).getByRole('button', { name: 'عرض الطلب' })).toBeInTheDocument()
})

test('the manager reads each outcome: pending, approved as asked, revised and rejected with HR\'s reason', async () => {
  const list = [req(), req({ id: 'q2', status: 'approved', outcome: 'approved', approved_amount: '42.750000', effective_from: '2026-11-21', decided_at: '2026-11-20T09:00:00Z' }),
    req({ id: 'q3', status: 'approved', outcome: 'revised', requested_amount: '50.000000', approved_amount: '35.250000', effective_from: '2026-11-20', decided_at: '2026-11-20T09:00:00Z', employee: { id: 'w2', code: 8, name_ar: 'سيد إبراهيم', name_en: null } }),
    req({ id: 'q4', status: 'refused', outcome: 'refused', refusal_reason: 'نفس السكن والموقع', decided_at: '2026-11-20T09:00:00Z', employee: { id: 'w3', code: 9, name_ar: 'عامل جديد أول', name_en: null } })]
  base({
    'GET /api/v1/fare-requests/mine': () => json(200, { data: list, meta: { pagination: { ...pagination, total: 4 } } }),
    'GET /api/v1/fare-requests/mine/q3': () => json(200, { data: list[2] }),
    'GET /api/v1/fare-requests/mine/q4': () => json(200, { data: list[3] }),
  })
  renderWithAuth(<TeamFaresPage />, MANAGER, { path: '/team-fares?site=s1&request=q4' })
  const dialog = await screen.findByRole('dialog', { name: 'طلب مواصلات' })
  expect(await within(dialog).findByText('نفس السكن والموقع')).toBeInTheDocument()
  expect(within(dialog).getByText('مرفوض')).toBeInTheDocument()
  expect(within(dialog).queryByText('المعتمد')).toBeNull()
  await user.keyboard('{Escape}')
  const requests = screen.getByRole('table', { name: 'طلبات المواصلات' })
  for (const label of ['قيد المراجعة', 'معتمد كما طُلب', 'معتمد بمبلغ معدّل', 'مرفوض']) expect(within(requests).getByText(label)).toBeInTheDocument()
  await user.click(within(requests).getByRole('link', { name: /سيد إبراهيم/ }))
  const revised = await screen.findByRole('dialog', { name: 'طلب مواصلات' })
  expect(await within(revised).findByText(fmt(35.25))).toBeInTheDocument()
  expect(revised).toHaveTextContent(fmt(50))
  expect(revised).toHaveTextContent('2026-11-20')
})

test('a manager without fare.request keeps the read-only team view', async () => {
  mockFetch({ 'GET /api/v1/team-fares': () => json(200, { data: { sites: [S1], rows: [] } }) })
  renderWithAuth(<TeamFaresPage />, ['employee.view.own', 'site.view.own'])
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات التيم' })).toBeInTheDocument()
  expect(urls('/team-fares/site')).toEqual([])
})

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

test('with crypto.randomUUID missing (plain HTTP, old phones) the request dialog opens and sends a v4 form id (review of #76, 2026-11-22)', async () => {
  const post = vi.fn((_u: string, _i?: RequestInit) => json(201, { data: {} }))
  base({ 'POST /api/v1/fare-requests': post })
  const real = globalThis.crypto
  vi.stubGlobal('crypto', { getRandomValues: (a: Uint8Array<ArrayBuffer>) => real.getRandomValues(a) })
  renderWithAuth(<TeamFaresPage />, MANAGER)
  await user.click(await screen.findByRole('button', { name: 'طلب تعديل مواصلات م. كريم فتحي' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByLabelText('المبلغ اليومي المطلوب'), '50')
  await user.type(within(dialog).getByLabelText('سبب الطلب'), 'سكن أبعد')
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalledOnce())
  expect(body(post).client_request_id).toMatch(V4)
})

test('while the next site loads the previous site\'s table is not shown, so no request can open on it (review of #76, 2026-11-22)', async () => {
  let release: (r: Response) => void = () => {}
  base({
    'GET /api/v1/team-fares/site': (url: string) => new URL(url, 'http://x').searchParams.get('site_id') === 's2'
      ? new Promise<Response>((r) => { release = r }) as unknown as Response
      : site(url),
  })
  renderWithAuth(<TeamFaresPage />, MANAGER)
  await screen.findByText('محمود عبد الله')
  await user.click(within(screen.getByRole('group', { name: 'مواقعك' })).getByRole('button', { name: 'برج العرب' }))
  await waitFor(() => expect(urls('site_id=s2')).toHaveLength(1))
  expect(screen.queryByText('محمود عبد الله')).toBeNull()                                     // site A's rows are gone while B loads
  expect(screen.queryByRole('button', { name: 'طلب تعديل مواصلات م. كريم فتحي' })).toBeNull()
  release(site('/x?site_id=s2'))
  expect(await screen.findByText('حسن علي')).toBeInTheDocument()
})

test('a request keeps the site it was opened on, and is refused when the page moves to another site under it (review of #76, 2026-11-22)', async () => {
  let sites = [S1, S2]
  const post = vi.fn((_u: string, _i?: RequestInit) => json(201, { data: {} }))
  base({ 'GET /api/v1/team-fares': () => json(200, { data: { sites, rows: [] } }), 'POST /api/v1/fare-requests': post })
  renderWithAuth(<TeamFaresPage />, MANAGER)
  await user.click(await screen.findByRole('button', { name: 'طلب تعديل مواصلات م. كريم فتحي' }))
  expect(await screen.findByRole('dialog', { name: 'طلب تعديل مواصلات · م. كريم فتحي · العامرية' })).toBeInTheDocument()
  sites = [S2]                                                                                  // HR took site A away; the page falls back to B
  await queryClient.invalidateQueries()
  await waitFor(() => expect(urls('site_id=s2').length).toBeGreaterThan(0))
  const dialog = screen.getByRole('dialog', { name: 'طلب تعديل مواصلات · م. كريم فتحي · العامرية' })   // still site A
  await user.type(within(dialog).getByLabelText('المبلغ اليومي المطلوب'), '50')
  await user.type(within(dialog).getByLabelText('سبب الطلب'), 'سكن أبعد')
  expect(within(dialog).getByText('تغيّر الموقع المعروض في الصفحة. أغلق هذه النافذة وافتح الطلب من الموقع الصحيح.')).toBeInTheDocument()
  expect(within(dialog).getByRole('button', { name: 'إرسال الطلب' })).toBeDisabled()
  expect(post).not.toHaveBeenCalled()
})
