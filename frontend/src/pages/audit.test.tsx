import { screen, waitFor, within } from '@testing-library/react'
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
  expect(within(screen.getByLabelText('نوع السجل')).getByRole('option', { name: 'الكل' })).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/users'))).toBe(false)
})

const entry = (over: Record<string, unknown>) => ({ id: 'a1', event: 'UPDATED', entity_type: 'sites', entity_id: 's1', occurred_at: '2026-10-16T22:30:00Z', user: { id: 'u1', name: 'منى' }, old_values: null, new_values: null, ...over })

test('details sheet shows previous and new values side by side', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [entry({ event: 'CREATED', new_values: { name_ar: 'سموحة', meta: { a: 1 } } })], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  const before = within(sheet).getByRole('region', { name: 'القيمة السابقة' })
  const after = within(sheet).getByRole('region', { name: 'القيمة الجديدة' })
  expect(within(before).getByText('name_ar')).toBeInTheDocument()
  expect(within(before).getAllByText('—')).toHaveLength(2)
  expect(within(after).getByText('سموحة')).toBeInTheDocument()
  expect(within(after).getByText('{ "a": 1 }')).toBeInTheDocument()
  expect(sheet).not.toHaveTextContent('null')
  expect(sheet).not.toHaveTextContent('[object Object]')
})

test('details sheet renders null fields as a dash and nested nulls as JSON text', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [entry({ old_values: { name_ar: null, rule: { share: null } }, new_values: { name_ar: 'سموحة', rule: { share: '0.5' }, active: true } })], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  const before = within(sheet).getByRole('region', { name: 'القيمة السابقة' })
  const after = within(sheet).getByRole('region', { name: 'القيمة الجديدة' })
  expect(within(before).getByText('{ "share": null }')).toBeInTheDocument()
  expect(within(before).getAllByText('—')).toHaveLength(2) // name_ar: null, active: missing
  expect(within(after).getByText('{ "share": "0.5" }')).toBeInTheDocument()
  expect(within(after).getByText('true')).toBeInTheDocument()
  expect(sheet).not.toHaveTextContent('[object Object]')
})

test('an empty string value shows as quotes and objects are indented', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [entry({ old_values: { note: '' }, new_values: { note: 'x', perms: ['a', 'b'] } })], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(within(sheet).getByRole('region', { name: 'القيمة السابقة' })).getByText('""')).toBeInTheDocument()
  expect(within(sheet).getByText(/"a",\s*"b"/).textContent).toBe('[\n  "a",\n  "b"\n]')
})

test('an entry with no values on either side still opens, with a dash in each section', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [entry({ event: 'LOGOUT', entity_type: 'users' })], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(sheet).getAllByText('—')).toHaveLength(2)
})

test('without user.view the rows still show and there is no error banner', async () => {
  mockFetch({ 'GET /api/v1/audit-log': () => json(200, { data: [entry({ event: 'DELETED' })], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  expect(await screen.findByText('حذف')).toBeInTheDocument()
  expect(screen.queryByRole('alert')).toBeNull()
})

test('clear filters resets the query and the page', async () => {
  const list = vi.fn<(url: string) => Response>(() => json(200, { data: [entry({})], meta: { pagination: { ...pagination, total: 45, total_pages: 3, has_next_page: true } } }))
  mockFetch({ 'GET /api/v1/audit-log': list })
  renderWithAuth(<AuditPage />, ['audit_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'التالي' }))
  await waitFor(() => expect(String(list.mock.lastCall?.[0])).toContain('page=2'))
  expect(String(list.mock.lastCall?.[0])).toContain('per_page=20')
  expect(screen.queryByRole('button', { name: 'مسح عوامل التصفية' })).toBeNull()
  await user.selectOptions(screen.getByLabelText('نوع السجل'), 'sites')
  await waitFor(() => expect(String(list.mock.lastCall?.[0])).toMatch(/entity_type=sites.*page=1|page=1.*entity_type=sites/))
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(String(list.mock.lastCall?.[0])).not.toContain('entity_type'))
})
