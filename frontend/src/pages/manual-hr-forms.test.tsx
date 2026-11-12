import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { CandidateFormsPage } from './manual-hr-forms'

afterEach(() => vi.unstubAllGlobals())

test('generates a job offer from the candidate page without an employee', async () => {
  const user = userEvent.setup()
  const generate = vi.fn(() => json(202, { data: { id: 'offer1' } }))
  mockFetch({
    'GET /api/v1/hr-manual-form-definitions': () => json(200, { data: { forms: [{
      type: 'job_offer', fields: [
        { name: 'candidate_name', input: 'text', required: true, max: 160 },
        { name: 'monthly_salary', input: 'text', required: true, max: null },
      ],
    }] } }),
    'POST /api/v1/hr-forms/manual-candidate/job_offer': generate,
  })
  renderWithAuth(<CandidateFormsPage />, ['employee.view.all', 'hr_forms.generate.all'])
  expect(await screen.findByText(/لا تنشئ سجل موظف أو عقدًا أو سجل أجر/)).toBeInTheDocument()
  const action = screen.getByRole('button', { name: 'إنشاء الملف' })
  await user.click(action)
  expect(generate).not.toHaveBeenCalled()
  await user.type(screen.getByLabelText('اسم المرشح'), 'Synthetic Candidate')
  await user.type(screen.getByLabelText('الراتب الشهري'), '12345.60')
  await user.click(action)
  await waitFor(() => expect(generate).toHaveBeenCalledOnce())
  const sent = vi.mocked(fetch).mock.calls.find(([url, opts]) => String(url).endsWith('/manual-candidate/job_offer') && opts?.method === 'POST')
  expect(JSON.parse(String(sent?.[1]?.body))).toEqual({
    candidate_name: 'Synthetic Candidate', monthly_salary: '12345.60', idempotency_key: expect.any(String),
  })
})
