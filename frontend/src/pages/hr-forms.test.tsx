import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { CertificateDocuments, FormApprovalsPage } from './hr-forms'

test('HR generates an unsigned certificate from the employee Documents tab', async () => {
  const user = userEvent.setup()
  const post = vi.fn((_url: string, _init?: RequestInit) => json(202, { data: { id: 'form-1' } }))
  mockFetch({
    'GET /api/v1/employees/e1/hr-form-exports': () => json(200, {
      data: post.mock.calls.length ? [{ id: 'form-1', serial_number: 1, stage: 'unsigned', status: 'pending' }] : [],
      meta: { pagination },
    }),
    'GET /api/v1/employees/e1/hr-forms/experience-certificate/defaults': () => json(200, { data: { praise: 'نص الإشادة الافتراضي' } }),
    'POST /api/v1/employees/e1/hr-forms/experience-certificate': post,
  })
  renderWithAuth(<CertificateDocuments employee="e1" />, ['hr_forms.view.all', 'hr_forms.generate.all'])
  await user.type(screen.getByLabelText('المسمى الوظيفي'), 'مهندسة اختبار')
  const praise = screen.getByLabelText('فقرة الإشادة (اختيارية)')
  expect(praise).toHaveValue('نص الإشادة الافتراضي')
  await user.clear(praise)
  await user.click(screen.getByRole('button', { name: 'إنشاء الملف' }))
  expect(await screen.findByText(/جارٍ إنشاء الملف/)).toBeInTheDocument()
  expect(JSON.parse(String(post.mock.calls[0]?.[1]?.body))).toMatchObject({
    job_title: 'مهندسة اختبار', praise: '', idempotency_key: expect.any(String),
  })
})

test('the CEO can retry a failed signed export from the approval screen', async () => {
  const user = userEvent.setup()
  const retry = vi.fn(() => json(202, { data: { id: 'signed-1' } }))
  mockFetch({
    'GET /api/v1/hr-form-exports/awaiting-approval': () => json(200, {
      data: [{ id: 'form-1', employee_id: 'e1', serial_number: 1 }],
      meta: { pagination },
    }),
    'POST /api/v1/hr-form-exports/form-1/approve': () => json(202, { data: { id: 'signed-1' } }),
    'GET /api/v1/hr-form-exports/signed-1': () => json(200, {
      data: { serial_number: 1, status: retry.mock.calls.length ? 'ready' : 'failed' },
    }),
    'POST /api/v1/hr-form-exports/signed-1/retry': retry,
  })
  renderWithAuth(<FormApprovalsPage />, ['hr_forms.view.all', 'hr_forms.approve.all'])
  await user.click(await screen.findByRole('button', { name: 'اعتماد وتوقيع' }))
  await user.click(await screen.findByRole('button', { name: 'إعادة المحاولة' }))
  expect(await screen.findByText(/مسلسل 1 · جاهز/)).toBeInTheDocument()
  expect(retry).toHaveBeenCalledOnce()
})
