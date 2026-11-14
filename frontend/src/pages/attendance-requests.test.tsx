import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendanceRequestsPage } from './attendance-requests'

afterEach(() => vi.unstubAllGlobals())

test('prints the saved advance revision and records paper approval as one action', async () => {
  const user = userEvent.setup()
  const print = vi.fn()
  const request = {
    id: 'advance1',
    version: 3,
    type: 'advance',
    status: 'pending',
    date: '2026-10-22',
    amount: '250.500000',
    employee: { id: 'worker1', code: 1043, name_ar: 'عامل موقع', name_en: null },
    site: { id: 'site1', name_ar: 'موقع الشاطبي', name_en: null },
  }
  const approve = vi.fn(() => json(200, { data: { id: request.id, status: 'approved' } }))
  mockFetch({
    'GET /api/v1/attendance/requests': () => json(200, { data: [request], meta: { pagination } }),
    'GET /api/v1/attendance/requests/advance1': () => json(200, { data: request }),
    'POST /api/v1/attendance/requests/advance1/approve': approve,
  })
  vi.stubGlobal('print', print)
  renderWithAuth(<AttendanceRequestsPage />, ['attendance_request.decide.all'], { path: '/attendance-requests?request=advance1' })

  const review = await screen.findByRole('dialog', { name: 'سلفة' })
  expect(within(review).getByText(/نسخة الطلب رقم 3/)).toBeInTheDocument()
  await user.click(within(review).getByRole('button', { name: 'طباعة نموذج السلفة' }))
  const preview = await screen.findByRole('dialog', { name: 'نموذج طلب سلفة' })
  expect(document.querySelector('.advance-print')).toHaveTextContent('advance1 / 3')
  await user.click(within(preview).getByRole('button', { name: 'طباعة نموذج السلفة' }))
  expect(print).toHaveBeenCalledOnce()
  expect(approve).not.toHaveBeenCalled()
  await user.keyboard('{Escape}')
  await user.click(within(review).getByRole('button', { name: 'تسجيل الموافقة الورقية' }))
  await waitFor(() => expect(approve).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/advance1/approve') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({ version: 3 })
})

test.each([
  ['overtime', true],
  ['overtime', false],
  ['advance', true],
] as const)('overtime PDF action for a %s request with generate=%s', async (type, canGenerate) => {
  const user = userEvent.setup()
  const request = {
    id: 'request1', version: 1, type, status: 'pending', date: '2026-10-22',
    amount: '250.00', minutes: 60,
    employee: { id: 'worker1', code: 1043, name_ar: 'عامل اختبار', name_en: null },
  }
  const generate = vi.fn((_url: string, _init?: RequestInit) => json(202, { data: { id: 'export1' } }))
  mockFetch({
    'GET /api/v1/attendance/requests': () => json(200, { data: [request], meta: { pagination } }),
    'GET /api/v1/attendance/requests/request1': () => json(200, { data: request }),
    'GET /api/v1/hr-form-exports': () => json(200, { data: [], meta: { pagination } }),
    'POST /api/v1/hr-form-exports': generate,
  })
  renderWithAuth(<AttendanceRequestsPage />, [
    'attendance_request.decide.all', 'employee.view.all', 'hr_forms.view.all', ...(canGenerate ? ['hr_forms.generate.all'] : []),
  ], { path: '/attendance-requests?request=request1' })

  const review = within(await screen.findByRole('dialog'))
  if (type !== 'overtime') {
    expect(review.queryByText('نموذج طلب عمل إضافي')).toBeNull()
    return
  }
  expect(review.getByText('الطباعة لا تغيّر الحضور أو مدخلات الرواتب أو الطلب.')).toBeInTheDocument()
  if (!canGenerate) {
    expect(review.queryByRole('button', { name: 'إنشاء الملف' })).toBeNull()
    return
  }

  const title = review.getByLabelText('المسمى الوظيفي')
  const reason = review.getByLabelText('سبب العمل الإضافي')
  const submit = review.getByRole('button', { name: 'إنشاء الملف' })
  expect(title).toBeRequired()
  expect(reason).toBeRequired()
  await user.click(submit)
  await user.type(title, 'Synthetic technician')
  await user.click(submit)
  expect(generate).not.toHaveBeenCalled()
  await user.type(reason, 'Synthetic reason')
  await user.click(submit)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  expect(JSON.parse(String(generate.mock.calls[0]?.[1]?.body))).toEqual({
    type: 'overtime_request', subject_id: 'request1', idempotency_key: expect.any(String),
    fields: { job_title: 'Synthetic technician', reason: 'Synthetic reason' },
  })
})
