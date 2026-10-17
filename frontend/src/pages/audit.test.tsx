import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AuditPage } from './audit'

const user = userEvent.setup()

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})

test('filters become the query string and times show in Cairo', async () => {
  const list = vi.fn<(url: string) => Response>(() => json(200, { data: [{ id: 'a1', event: 'CREATED', entity_type: 'sites', entity_id: 's1', occurred_at: '2026-10-16T22:30:00Z', user: { id: 'u1', name: 'منى' }, old_values: null, new_values: { name_ar: 'سموحة' } }], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/audit-log': list, 'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'منى' }], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all', 'user.view.all'])
  await i18n.changeLanguage('en')
  expect(await screen.findByText(/10\/17\/26, 1:30:00/)).toBeInTheDocument()
  await user.selectOptions(screen.getByLabelText('User'), 'u1')
  await user.type(screen.getByLabelText('From'), '2026-10-17')
  await waitFor(() => expect(String(list.mock.lastCall?.[0])).toContain('user_id=u1'))
  expect(String(list.mock.lastCall?.[0])).toContain('from=2026-10-17')
})

test('without user.view there is no actor filter and no account list call', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  expect(await screen.findByLabelText('نوع السجل')).toBeInTheDocument()
  expect(screen.queryByLabelText('المنفِّذ')).toBeNull()
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/users'))).toBe(false)
})
