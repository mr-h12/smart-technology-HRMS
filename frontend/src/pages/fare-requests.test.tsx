import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { queryClient } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { FareRequestsPage } from './fare-requests'
import type { FareRequest } from './site-fares'

const user = userEvent.setup()
const HR = ['fare.manage.all']
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
const body = (fn: { mock: { calls: unknown[][] } }) => JSON.parse((fn.mock.calls[0] as [string, RequestInit])[1].body as string)
const req = (over: Partial<FareRequest> = {}): FareRequest => ({
  id: 'q1', status: 'pending', outcome: 'pending', current_amount: null, requested_amount: '42.750000', approved_amount: null, effective_from: null,
  reason: 'سكن أبعد', refusal_reason: null, created_at: '2026-11-20T08:00:00Z', decided_at: null, version: 1,
  site: { id: 's1', name_ar: 'العامرية', name_en: null }, employee: { id: 'w1', code: 7, name_ar: 'محمود عبد الله', name_en: null },
  requested_by: { id: 'm1', name: 'مدير موقع 1' }, ...over,
})
const list = (rows: FareRequest[]) => () => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })

beforeEach(() => {
  queryClient.clear()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-20T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

test('HR approves the amount asked from today by default, and the current allowance reads unset', async () => {
  const approve = vi.fn(() => json(200, { data: req({ status: 'approved', outcome: 'approved' }) }))
  mockFetch({ 'GET /api/v1/fare-requests': list([req()]), 'POST /api/v1/fare-requests/q1/approve': approve })
  renderWithAuth(<FareRequestsPage />, HR, { path: '/fare-requests?request=q1' })
  const dialog = await screen.findByRole('dialog', { name: 'قرار طلب المواصلات' })
  expect(within(dialog).getByText('مدير موقع 1')).toBeInTheDocument()
  expect(within(dialog).getByText('غير محددة')).toBeInTheDocument()
  expect(within(dialog).getByText('سكن أبعد')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('المبلغ المعتمد')).toHaveValue('42.75')
  expect(within(dialog).getByLabelText('يسري من')).toHaveValue('2026-11-20')
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  await waitFor(() => expect(approve).toHaveBeenCalled())
  expect(body(approve)).toEqual({ version: 1, effective_from: '2026-11-20' })   // no amount: the one asked
})

test('HR revises the amount (Arabic digits) and picks the date', async () => {
  const approve = vi.fn(() => json(200, { data: req() }))
  mockFetch({ 'GET /api/v1/fare-requests': list([req()]), 'POST /api/v1/fare-requests/q1/approve': approve })
  renderWithAuth(<FareRequestsPage />, HR, { path: '/fare-requests?request=q1' })
  const dialog = await screen.findByRole('dialog')
  const amount = within(dialog).getByLabelText('المبلغ المعتمد')
  await user.clear(amount)
  await user.type(amount, '٣٥٫٢٥')
  const from = within(dialog).getByLabelText('يسري من')
  await user.clear(from)
  await user.type(from, '2026-11-21')
  await user.click(within(dialog).getByRole('button', { name: 'اعتماد' }))
  await waitFor(() => expect(approve).toHaveBeenCalled())
  expect(body(approve)).toEqual({ version: 1, effective_from: '2026-11-21', amount: '35.25' })
})

test('a rejection needs a reason, and a decision made meanwhile shows the 409', async () => {
  const refuse = vi.fn(() => json(409, { error: { code: 'concurrency_conflict', message: 'تغيّر الطلب، أعد التحميل.' } }))
  mockFetch({ 'GET /api/v1/fare-requests': list([req()]), 'POST /api/v1/fare-requests/q1/refuse': refuse })
  renderWithAuth(<FareRequestsPage />, HR, { path: '/fare-requests?request=q1' })
  const dialog = await screen.findByRole('dialog')
  await user.click(within(dialog).getByRole('button', { name: 'رفض' }))
  expect(within(dialog).getByRole('button', { name: 'تأكيد الرفض' })).toBeDisabled()
  await user.type(within(dialog).getByLabelText('سبب الرفض (يقرؤه مدير الموقع)'), 'نفس السكن')
  await user.click(within(dialog).getByRole('button', { name: 'تأكيد الرفض' }))
  expect(await within(dialog).findByText('تغيّر الطلب، أعد التحميل.')).toBeInTheDocument()
  expect(body(refuse)).toEqual({ version: 1, reason: 'نفس السكن' })
})

test('the list shows each pending request with its requested amount, and a link to a decided one says so', async () => {
  mockFetch({ 'GET /api/v1/fare-requests': list([req(), req({ id: 'q2', current_amount: '0.000000', requested_amount: '20.000000' })]) })
  renderWithAuth(<FareRequestsPage />, HR, { path: '/fare-requests?request=gone' })
  expect(await screen.findByText('هذا الطلب لم يعد معلقًا.')).toBeInTheDocument()
  expect(screen.getAllByText(fmt(42.75)).length).toBeGreaterThan(0)
  expect(screen.getAllByText(fmt(20)).length).toBeGreaterThan(0)
})
