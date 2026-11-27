import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { MyWageProposalsPage } from './my-wage-proposals'

afterEach(() => vi.unstubAllGlobals())

const user = userEvent.setup()
const row = (over: object = {}) => ({
  id: 'p1',
  kind: 'new_laborer',
  status: 'pending',
  reason: null,
  effective_from: null,
  proposed_wage: '350.500000',
  approved_wage: null,
  refusal_reason: null,
  created_at: '2026-11-04T07:30:00Z',
  decided_at: null,
  site: { id: 's1', name_ar: 'موقع تجريبي', name_en: null },
  employee: { id: 'e1', code: 1043, name_ar: 'عامل جديد أول', name_en: null },
  ...over,
})
const approved = row({ id: 'p2', status: 'approved', approved_wage: '325.250000', decided_at: '2026-11-05T08:00:00Z', employee: { id: 'e2', code: 1044, name_ar: 'عامل جديد ثان', name_en: null } })
const refused = row({ id: 'p3', status: 'refused', refusal_reason: 'الأجر أعلى من المعتاد', decided_at: '2026-11-05T08:00:00Z', employee: { id: 'e3', code: 1045, name_ar: 'عامل جديد ثالث', name_en: null } })
const render = (path = '/my-wage-proposals', abilities = ['employee.propose_laborer.own']) =>
  renderWithAuth(<Routes><Route path="my-wage-proposals" element={<MyWageProposalsPage />} /></Routes>, abilities, { path })
const empty = () => json(200, { data: [], meta: { pagination } })

test('the list shows each proposal with its status and no wage figure; the opened request has the figures (Youssef, 2026-11-19)', async () => {
  const strip = ({ proposed_wage: _p, approved_wage: _a, refusal_reason: _r, ...rest }: ReturnType<typeof row>) => rest
  mockFetch({
    'GET /api/v1/employees/wage-proposals/mine': () => json(200, { data: [row(), approved, refused].map(strip), meta: { pagination: { ...pagination, total: 3 } } }),
    'GET /api/v1/employees/wage-proposals/mine/p2': () => json(200, { data: approved }),
  })
  render()
  const pending = (await screen.findByText('عامل جديد أول')).closest('tr')!
  expect(pending).toHaveTextContent('قيد المراجعة')
  const ok = screen.getByText('عامل جديد ثان').closest('tr')!
  expect(ok).toHaveTextContent('معتمد')
  expect(screen.getByText('عامل جديد ثالث').closest('tr')!).toHaveTextContent('مرفوض')
  const table = screen.getByRole('table')
  expect(table).not.toHaveTextContent('350.50')
  expect(table).not.toHaveTextContent('325.25')
  await user.click(screen.getByRole('link', { name: /عامل جديد ثان/ }))
  const dialog = await screen.findByRole('dialog', { name: 'طلب أجر يومي' })
  expect(await within(dialog).findByText('الأجر المعتمد')).toBeInTheDocument()
  expect(dialog).toHaveTextContent('325.25')
  expect(dialog).toHaveTextContent('350.50')
})

test('a refused request shows HR\'s reason, and an approved one its approved wage', async () => {
  mockFetch({
    'GET /api/v1/employees/wage-proposals/mine': () => json(200, { data: [refused, approved], meta: { pagination: { ...pagination, total: 2 } } }),
    'GET /api/v1/employees/wage-proposals/mine/p3': () => json(200, { data: refused }),
    'GET /api/v1/employees/wage-proposals/mine/p2': () => json(200, { data: approved }),
  })
  render('/my-wage-proposals?proposal=p3')
  const dialog = await screen.findByRole('dialog', { name: 'طلب أجر يومي' })
  expect(await within(dialog).findByText('الأجر أعلى من المعتاد')).toBeInTheDocument()
  expect(within(dialog).queryByText('الأجر المعتمد')).toBeNull()
  await user.keyboard('{Escape}')
  await user.click(await screen.findByRole('link', { name: /عامل جديد ثان/ }))
  const second = await screen.findByRole('dialog', { name: 'طلب أجر يومي' })
  expect(await within(second).findByText('الأجر المعتمد')).toBeInTheDocument()
  expect(second).toHaveTextContent('325.25')
})

test('another manager\'s request (a 404) says it is not available', async () => {
  mockFetch({ 'GET /api/v1/employees/wage-proposals/mine': () => json(200, { data: [], meta: { pagination } }) })
  render('/my-wage-proposals?proposal=x9')
  expect(await screen.findByText('هذا الطلب غير متاح.')).toBeInTheDocument()
  expect(screen.getByText('لم تقترح أجرًا بعد.')).toBeInTheDocument()
})

test('the button to propose a wage for an existing laborer shows only with employee.propose_wage', async () => {
  mockFetch({ 'GET /api/v1/employees/wage-proposals/mine': empty })
  render()
  await screen.findByText('لم تقترح أجرًا بعد.')
  expect(screen.queryByRole('button', { name: 'اقتراح أجر لعامل موجود' })).toBeNull()
})

test('a site manager finds a laborer they reach, proposes a wage with Arabic digits and a reason, and sees no current wage (#79)', async () => {
  mockFetch({
    'GET /api/v1/employees/wage-proposals/mine': empty,
    'GET /api/v1/employees': () => json(200, { data: [{ id: 'e7', code: 77, name_ar: 'عامل قديم', name_en: null }], meta: { pagination } }),
    'POST /api/v1/employees/e7/wage-proposals': () => json(201, { data: { id: 'p9', status: 'pending' } }),
  })
  render('/my-wage-proposals', ['employee.propose_laborer.own', 'employee.propose_wage.own'])
  await user.click(await screen.findByRole('button', { name: 'اقتراح أجر لعامل موجود' }))
  const dialog = await screen.findByRole('dialog', { name: 'اقتراح أجر لعامل موجود' })
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الاقتراح' }))
  expect(dialog).toHaveTextContent('اختر العامل أولاً')
  await user.type(within(dialog).getByLabelText('ابحث بالكود أو الاسم'), '٧٧')
  await user.click(await within(dialog).findByRole('button', { name: /عامل قديم/ }))
  await user.type(within(dialog).getByLabelText('الأجر المقترح'), '٤٢٠')
  await user.type(within(dialog).getByLabelText(/السبب/), 'يعمل منذ سنة')
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الاقتراح' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  const [url, init] = vi.mocked(fetch).mock.calls.find(([u, i]) => String(u).includes('/employees/e7/wage-proposals') && i?.method === 'POST')!
  expect(String(url)).toContain('/api/v1/employees/e7/wage-proposals')
  const body = JSON.parse(String(init!.body))
  expect(body).toMatchObject({ daily_wage: '420', reason: 'يعمل منذ سنة' })
  expect(body.client_request_id).toMatch(/^[0-9a-f-]{36}$/)
  const search = vi.mocked(fetch).mock.calls.map(([u]) => String(u)).find((u) => u.includes('/api/v1/employees?'))!
  expect(new URL(search, 'http://x').searchParams.get('search')).toBe('77')
})

test('an approved wage change shows its kind, its date and the manager\'s reason, and the list still shows no figure', async () => {
  const change = row({ id: 'p4', kind: 'wage_change', status: 'approved', approved_wage: '420.000000', proposed_wage: '420.000000', reason: 'يعمل منذ سنة', effective_from: '2026-11-16', decided_at: '2026-11-15T08:00:00Z' })
  mockFetch({
    'GET /api/v1/employees/wage-proposals/mine': () => json(200, { data: [change], meta: { pagination: { ...pagination, total: 1 } } }),
    'GET /api/v1/employees/wage-proposals/mine/p4': () => json(200, { data: change }),
  })
  render('/my-wage-proposals?proposal=p4')
  const dialog = await screen.findByRole('dialog', { name: 'طلب أجر يومي' })
  expect(await within(dialog).findByText('تعديل أجر')).toBeInTheDocument()
  expect(dialog).toHaveTextContent('2026-11-16')
  expect(dialog).toHaveTextContent('يعمل منذ سنة')
  expect(screen.getByRole('table', { hidden: true })).not.toHaveTextContent('420')
})

test('the server\'s refusal (not a day laborer) shows in the form, which stays open', async () => {
  mockFetch({
    'GET /api/v1/employees/wage-proposals/mine': empty,
    'GET /api/v1/employees': () => json(200, { data: [{ id: 'e8', code: 88, name_ar: 'عامل مثبت', name_en: null }], meta: { pagination } }),
    'POST /api/v1/employees/e8/wage-proposals': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'employee', message: 'هذا الموظف ليس عامل يومية اليوم، فلا يُقترح له أجر يومي.' }] } }),
  })
  render('/my-wage-proposals', ['employee.propose_wage.own'])
  await user.click(await screen.findByRole('button', { name: 'اقتراح أجر لعامل موجود' }))
  const dialog = await screen.findByRole('dialog', { name: 'اقتراح أجر لعامل موجود' })
  await user.type(within(dialog).getByLabelText('ابحث بالكود أو الاسم'), '88')
  await user.click(await within(dialog).findByRole('button', { name: /عامل مثبت/ }))
  await user.type(within(dialog).getByLabelText('الأجر المقترح'), '400')
  await user.click(within(dialog).getByRole('button', { name: 'إرسال الاقتراح' }))
  expect(await within(dialog).findByText('هذا الموظف ليس عامل يومية اليوم، فلا يُقترح له أجر يومي.')).toBeInTheDocument()
  expect(screen.getByRole('dialog', { name: 'اقتراح أجر لعامل موجود' })).toBeInTheDocument()
})
