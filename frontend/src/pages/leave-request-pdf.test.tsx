import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { LeaveRequestPdf } from './leave-request-pdf'

test('the leave form panel lists its exports and shows Approve and Retry from the row actions', async () => {
  const user = userEvent.setup()
  const post = vi.fn((_url: string, _init?: RequestInit) => json(202, { data: { id: 'form-3' } }))
  const approve = vi.fn(() => json(202, { data: { id: 'signed-2' } }))
  const retry = vi.fn(() => json(202, { data: { id: 'signed-1' } }))
  const list = vi.fn((_url: string) => json(200, {
    data: [
      { id: 'form-2', serial_number: 2, stage: 'unsigned', status: 'ready', file_name: 'leave-request-2-unsigned.pdf', actions: ['download', 'approve'] },
      { id: 'signed-1', serial_number: 1, stage: 'signed', status: 'failed', file_name: 'leave-request-1-signed.pdf', actions: ['retry'] },
      { id: 'form-1', serial_number: 1, stage: 'unsigned', status: 'ready', file_name: 'leave-request-1-unsigned.pdf', actions: ['download'] },
    ],
    meta: { pagination },
  }))
  mockFetch({
    'GET /api/v1/hr-form-exports': list,
    'POST /api/v1/hr-form-exports': post,
    'POST /api/v1/hr-form-exports/form-2/approve': approve,
    'POST /api/v1/hr-form-exports/signed-1/retry': retry,
  })
  renderWithAuth(<LeaveRequestPdf id="leave-1" />, ['hr_forms.view.all', 'hr_forms.generate.all', 'hr_forms.approve_leave_request.all'])

  await user.click(await screen.findByRole('button', { name: 'اعتماد وتوقيع' }))
  expect(approve).toHaveBeenCalledOnce()
  expect(list.mock.calls[0]?.[0]).toContain('/hr-form-exports?type=leave_request&subject_id=leave-1&per_page=100')
  expect(screen.getAllByRole('button', { name: 'تنزيل الملف' })).toHaveLength(2)
  await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
  expect(retry).toHaveBeenCalledOnce()

  await user.type(screen.getByLabelText('المسمى الوظيفي'), 'مهندسة اختبار')
  await user.click(screen.getByRole('button', { name: 'إعادة إنشاء الملف' }))
  expect(JSON.parse(String(post.mock.calls[0]?.[1]?.body))).toEqual({
    type: 'leave_request', subject_id: 'leave-1', idempotency_key: expect.any(String),
    fields: { job_title: 'مهندسة اختبار', notification_method: '', manager_opinion: '', substitute: '' },
  })
})

test('reopening the panel asks the server again, so the sign button follows a decision made meanwhile', async () => {
  let actions = ['download']
  mockFetch({ 'GET /api/v1/hr-form-exports': () => json(200, { data: [{ id: 'form-1', serial_number: 1, stage: 'unsigned', status: 'ready', file_name: 'leave-request-1-unsigned.pdf', actions }], meta: { pagination } }) })
  const permissions = ['hr_forms.view.all', 'hr_forms.approve_leave_request.all']
  const first = renderWithAuth(<LeaveRequestPdf id="leave-1" />, permissions)
  await screen.findByRole('button', { name: 'تنزيل الملف' })
  expect(screen.queryByRole('button', { name: 'اعتماد وتوقيع' })).toBeNull()
  first.unmount()

  actions = ['download', 'approve']
  renderWithAuth(<LeaveRequestPdf id="leave-1" />, permissions)
  expect(await screen.findByRole('button', { name: 'اعتماد وتوقيع' })).toBeInTheDocument()
})
