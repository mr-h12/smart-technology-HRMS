import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { CertificateDocuments, FormApprovalsPage } from './hr-forms'

afterEach(() => vi.unstubAllGlobals())

test('HR generates an unsigned certificate from the employee Documents tab', async () => {
  const user = userEvent.setup()
  const post = vi.fn((_url: string, _init?: RequestInit) => json(202, { data: { id: 'form-1' } }))
  const list = vi.fn((_url: string) => json(200, {
    data: post.mock.calls.length ? [{ id: 'form-1', serial_number: 1, stage: 'unsigned', status: 'pending', actions: [] }] : [],
    meta: { pagination },
  }))
  mockFetch({ 'GET /api/v1/hr-form-exports': list, 'POST /api/v1/hr-form-exports': post })
  renderWithAuth(<CertificateDocuments employee="e1" />, ['hr_forms.view.all', 'hr_forms.generate.all'])
  await user.type(screen.getByLabelText('المسمى الوظيفي'), 'مهندسة اختبار')
  await user.click(screen.getByRole('button', { name: 'إنشاء الملف' }))
  expect(await screen.findByText(/جارٍ إنشاء الملف/)).toBeInTheDocument()
  expect(list.mock.calls[0]?.[0]).toContain('/hr-form-exports?type=experience_certificate&subject_id=e1&page=1')
  expect(JSON.parse(String(post.mock.calls[0]?.[1]?.body))).toEqual({
    type: 'experience_certificate', subject_id: 'e1', idempotency_key: expect.any(String), fields: { job_title: 'مهندسة اختبار' },
  })
})

test('certificate rows show the buttons the API allows and download under the row file name', async () => {
  const user = userEvent.setup()
  const retry = vi.fn(() => json(202, { data: { id: 'failed-1' } }))
  mockFetch({
    'GET /api/v1/hr-form-exports': () => json(200, {
      data: [
        { id: 'ready-1', serial_number: 2, stage: 'unsigned', status: 'ready', file_name: 'experience-certificate-2-unsigned.pdf', actions: ['download'] },
        { id: 'failed-1', serial_number: 1, stage: 'signed', status: 'failed', file_name: 'experience-certificate-1-signed.pdf', actions: ['retry'] },
        { id: 'failed-2', serial_number: 1, stage: 'unsigned', status: 'failed', file_name: 'experience-certificate-1-unsigned.pdf', actions: [] },
      ],
      meta: { pagination },
    }),
    'GET /api/v1/hr-form-exports/ready-1/download': () => new Response('%PDF-1.7', { status: 200 }),
    'POST /api/v1/hr-form-exports/failed-1/retry': retry,
  })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:pdf'); static revokeObjectURL = vi.fn() })
  const saved: string[] = []
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    saved.push(this.download)
  })
  renderWithAuth(<CertificateDocuments employee="e1" />, ['hr_forms.view.all'])
  const download = await screen.findByRole('button', { name: 'تنزيل الملف' })
  expect(screen.getAllByRole('button', { name: 'إعادة المحاولة' })).toHaveLength(1)
  await user.click(download)
  await vi.waitFor(() => expect(saved).toEqual(['experience-certificate-2-unsigned.pdf']))
  await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
  expect(retry).toHaveBeenCalledOnce()
  click.mockRestore()
})

test('the CEO can retry a failed signed export from the approval screen', async () => {
  const user = userEvent.setup()
  const retry = vi.fn(() => json(202, { data: { id: 'signed-1' } }))
  const list = vi.fn((_url: string) => json(200, {
    data: [{ id: 'form-1', employee_id: 'e1', serial_number: 1, file_name: 'experience-certificate-1-unsigned.pdf', actions: ['download', 'approve'] }],
    meta: { pagination },
  }))
  mockFetch({
    'GET /api/v1/hr-form-exports': list,
    'POST /api/v1/hr-form-exports/form-1/approve': () => json(202, { data: { id: 'signed-1' } }),
    'GET /api/v1/hr-form-exports/signed-1': () => json(200, {
      data: { serial_number: 1, status: retry.mock.calls.length ? 'ready' : 'failed' },
    }),
    'POST /api/v1/hr-form-exports/signed-1/retry': retry,
  })
  renderWithAuth(<FormApprovalsPage />, ['hr_forms.view.all', 'hr_forms.approve.all'])
  await user.click(await screen.findByRole('button', { name: 'اعتماد وتوقيع' }))
  expect(list.mock.calls[0]?.[0]).toContain('/hr-form-exports?awaiting_approval=1&type=experience_certificate&page=1')
  await user.click(await screen.findByRole('button', { name: 'إعادة المحاولة' }))
  expect(await screen.findByText(/مسلسل 1 · جاهز/)).toBeInTheDocument()
  expect(retry).toHaveBeenCalledOnce()
})
