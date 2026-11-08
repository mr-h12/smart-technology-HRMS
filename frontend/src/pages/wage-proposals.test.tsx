import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { plainAmount, WageProposalsPage } from './wage-proposals'

afterEach(() => vi.unstubAllGlobals())

const user = userEvent.setup()
const LIST = 'GET /api/v1/employees/wage-proposals'
const proposal = (over: object = {}) => ({
  id: 'p1',
  version: 1,
  daily_wage: '350.000000',
  created_at: '2026-11-04T07:30:00Z',
  site: { id: 's1', name_ar: 'موقع الشاطبي', name_en: null },
  proposed_by: { id: 'u9', name: 'كريم' },
  employee: { id: 'e1', code: 1043, name_ar: 'عامل جديد', name_en: null, phone: '01012345678', hire_date: '2026-11-04' },
  ...over,
})
const list = (rows: object[], total = rows.length) => () => json(200, { data: rows, meta: { pagination: { ...pagination, total } } })
const render = (path = '/wage-proposals') =>
  renderWithAuth(<Routes><Route path="wage-proposals" element={<WageProposalsPage />} /></Routes>, ['employee.approve_wage.all'], { path })
const bodyOf = (path: string) => JSON.parse(String(vi.mocked(fetch).mock.calls.find(([u, i]) => String(u).includes(path) && i?.method === 'POST')![1]!.body))

test('the stored amount reads as typed', () => {
  expect(plainAmount('350.000000')).toBe('350')
  expect(plainAmount('312.500000')).toBe('312.5')
  expect(plainAmount('300')).toBe('300')
})

test('each waiting wage shows the laborer, the site, who proposed it and the amount', async () => {
  mockFetch({ [LIST]: list([proposal()]) })
  render()
  const row = (await screen.findByText('عامل جديد')).closest('tr')!
  expect(row).toHaveTextContent('موقع الشاطبي')
  expect(row).toHaveTextContent('كريم')
  expect(row).toHaveTextContent('350.00')
  expect(new URL(String(vi.mocked(fetch).mock.calls[0]![0]), 'http://x').searchParams.get('per_page')).toBe('100')
})

test('nothing waiting shows the empty state', async () => {
  mockFetch({ [LIST]: list([]) })
  render()
  expect(await screen.findByText('لا توجد أجور مقترحة تنتظر الاعتماد')).toBeInTheDocument()
})

test('more than one page waiting says so', async () => {
  mockFetch({ [LIST]: list([proposal()], 130) })
  render()
  expect(await screen.findByText(/يظهر أول 1 من 130/)).toBeInTheDocument()
})

test('approving at the proposed wage sends only the version', async () => {
  const approve = vi.fn(() => json(200, { data: { id: 'p1', status: 'approved' } }))
  mockFetch({ [LIST]: list([proposal()]), 'POST /api/v1/employees/wage-proposals/p1/approve': approve })
  render('/wage-proposals?proposal=p1')
  const dialog = await screen.findByRole('dialog')
  expect(within(dialog).getByLabelText('الأجر المعتمد')).toHaveValue('350')
  expect(within(dialog).getByText('2026-11-04')).toBeInTheDocument()
  expect(within(dialog).queryByLabelText('النوع')).not.toBeInTheDocument()   // the site manager entered it with the laborer
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  await waitFor(() => expect(approve).toHaveBeenCalledOnce())
  expect(bodyOf('/p1/approve')).toEqual({ version: 1 })
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

test('HR can approve at another wage, typed with Arabic digits', async () => {
  const approve = vi.fn(() => json(200, { data: { id: 'p1', status: 'approved' } }))
  mockFetch({ [LIST]: list([proposal()]), 'POST /api/v1/employees/wage-proposals/p1/approve': approve })
  render('/wage-proposals?proposal=p1')
  const dialog = await screen.findByRole('dialog')
  const wage = within(dialog).getByLabelText('الأجر المعتمد')
  await user.clear(wage)
  await user.type(wage, '٠')
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  expect(await within(dialog).findByText('اكتب أجراً يومياً أكبر من صفر')).toBeInTheDocument()
  await user.clear(wage)
  await user.type(wage, '٣٤٠')
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  await waitFor(() => expect(approve).toHaveBeenCalledOnce())
  expect(bodyOf('/p1/approve')).toEqual({ version: 1, daily_wage: '340' })
})

test('refusing asks once more, then sends the version', async () => {
  const refuse = vi.fn(() => json(200, { data: { id: 'p1', status: 'refused' } }))
  mockFetch({ [LIST]: list([proposal()]), 'POST /api/v1/employees/wage-proposals/p1/refuse': refuse })
  render('/wage-proposals?proposal=p1')
  const dialog = await screen.findByRole('dialog')
  await user.click(within(dialog).getByRole('button', { name: 'رفض' }))
  expect(within(dialog).getByText(/ينتهي العامل اليوم ويخرج من كل طاقم/)).toBeInTheDocument()
  expect(refuse).not.toHaveBeenCalled()
  await user.click(within(dialog).getByRole('button', { name: 'تأكيد الرفض' }))
  await waitFor(() => expect(refuse).toHaveBeenCalledOnce())
  expect(bodyOf('/p1/refuse')).toEqual({ version: 1 })
})

test('a proposal decided meanwhile shows the conflict and keeps the dialog open', async () => {
  mockFetch({
    [LIST]: list([proposal()]),
    'POST /api/v1/employees/wage-proposals/p1/approve': () => json(409, { error: { code: 'concurrency_conflict', message: 'تغيّر هذا السجل' } }),
  })
  render('/wage-proposals?proposal=p1')
  const dialog = await screen.findByRole('dialog')
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  expect(await within(dialog).findByText('تغيّر هذا السجل')).toBeInTheDocument()
  expect(screen.getByRole('dialog')).toBeInTheDocument()
})

test('a link to a proposal that is no longer waiting says so', async () => {
  mockFetch({ [LIST]: list([]) })
  render('/wage-proposals?proposal=gone')
  expect(await screen.findByText(/لم يعد منتظراً/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})
