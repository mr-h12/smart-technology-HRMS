import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { MyWageProposalsPage } from './my-wage-proposals'

afterEach(() => vi.unstubAllGlobals())

const user = userEvent.setup()
const row = (over: object = {}) => ({
  id: 'p1',
  status: 'pending',
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
const render = (path = '/my-wage-proposals') =>
  renderWithAuth(<Routes><Route path="my-wage-proposals" element={<MyWageProposalsPage />} /></Routes>, ['employee.propose_laborer.own'], { path })

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
