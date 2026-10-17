import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AccountsPage, RolesPage } from './console'

const user = userEvent.setup()

const ids: Record<string, string> = { 'employee.view.own': 'p-emp-own', 'site.view.own': 'p-site-own', 'audit_log.view.all': 'p-audit' }
const perms = [
  'employee.view.own', 'employee.view.all', 'employee.create.all', 'employee.edit.all', 'employee.delete.all',
  'employee.assign_manager.all', 'site.view.own', 'site.view.all', 'site.manage.all', 'site.assign_manager.all',
  'user.view.all', 'user.manage.all', 'role.view.all', 'role.manage.all', 'audit_log.view.all',
].map((key) => {
  const [resource, action, scope] = key.split('.')
  return { id: ids[key] ?? `p-${key}`, resource, action, scope, key }
})
const role = { id: 'r1', slug: 'manager', name: 'Manager', name_ar: 'مدير', permissions: ['employee.view.own', 'site.view.own'] }
const account = { id: 'u2', name: 'سارة', email: 'sara@smart.test', is_active: true, locked_until: null, failed_login_attempts: 0, role: { id: 'r1', slug: 'manager', name: 'Manager', name_ar: 'مدير' } }

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('role matrix saves the checked permission ids', async () => {
  const patch = vi.fn(() => json(200, { data: { ...role, permissions: ['employee.view.own', 'site.view.own', 'audit_log.view.all'] } }))
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': () => json(200, { data: perms }), 'PATCH /api/v1/roles/r1/permissions': patch })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await user.click(await screen.findByRole('button', { name: 'مدير' }))
  await user.click(screen.getByRole('checkbox', { name: /سجل التدقيق.*عرض.*الكل/ }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string).permission_ids.sort()).toEqual(['p-audit', 'p-emp-own', 'p-site-own'])
})

test('a refused save shows the error and puts the ticks back to the role permissions', async () => {
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': () => json(200, { data: perms }),
    'PATCH /api/v1/roles/r1/permissions': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'permission_ids', code: 'invalid', message: 'آخر مدير للأدوار' }] } }) })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await user.click(await screen.findByRole('button', { name: 'مدير' }))
  await user.click(screen.getByRole('checkbox', { name: /سجل التدقيق.*عرض.*الكل/ }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('آخر مدير للأدوار')).toBeInTheDocument()
  // the fixture lists perms grouped by resource, so DOM order matches it
  const ticked = screen.getAllByRole('checkbox').flatMap((b, i) => ((b as HTMLInputElement).checked ? [perms[i].key] : []))
  expect(ticked).toEqual(role.permissions)
})

test('without role.manage the matrix is read-only', async () => {
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': () => json(200, { data: perms }) })
  renderWithAuth(<RolesPage />, ['role.view.all'])
  await user.click(await screen.findByRole('button', { name: 'مدير' }))
  const boxes = screen.getAllByRole('checkbox')
  expect(boxes).toHaveLength(15)
  for (const b of boxes) expect(b).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ' })).toBeNull()
})

test('account create shows a server field error under its field', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'POST /api/v1/users': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'email', code: 'invalid', message: 'البريد مستخدم' }] } }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all', 'role.view.all'])
  // open create dialog, fill, save
  await user.click(await screen.findByRole('button', { name: 'إضافة حساب' }))
  await user.type(screen.getByLabelText('الاسم'), 'سارة')
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'sara@smart.test')
  await user.selectOptions(await screen.findByLabelText('الدور'), 'r1')
  await user.type(screen.getByLabelText('كلمة المرور'), 'secret123')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('البريد مستخدم')).toBeInTheDocument()
})

test('without user.manage there are no account actions', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all'])
  expect(await screen.findByText(account.email)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة حساب' })).toBeNull()
  expect(within(screen.getByRole('table')).queryAllByRole('button')).toHaveLength(0)
})

test('the accounts table shows role, status and locked badges', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [{ ...account, locked_until: '2999-01-01T00:00:00Z' }], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  expect(within(row).getByText('مدير')).toBeInTheDocument()
  expect(within(row).getByText('نشط')).toBeInTheDocument()
  expect(within(row).getByText('مقفل')).toBeInTheDocument()
})

test('the row menu deactivates an account', async () => {
  const deactivate = vi.fn(() => json(200, { data: { ...account, is_active: false } }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'PATCH /api/v1/users/u2/deactivate': deactivate })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعطيل' }))
  await waitFor(() => expect(deactivate).toHaveBeenCalledOnce())
})

test('the row menu sets a new password', async () => {
  const put = vi.fn(() => json(200, { data: {} }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'PUT /api/v1/users/u2/password': put })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعيين كلمة مرور جديدة' }))
  await user.type(screen.getByLabelText('كلمة المرور'), 'newpass123')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((put.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ password: 'newpass123' })
})

test('without role.view the roles list is not fetched and the role field is locked to the current role', async () => {
  const roles = vi.fn(() => json(403, { error: { code: 'forbidden', message: 'ممنوع' } }))
  const patch = vi.fn(() => json(200, { data: account }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': roles, 'PATCH /api/v1/users/u2': patch })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  expect(roles).not.toHaveBeenCalled()
  expect(screen.queryByRole('alert')).toBeNull()
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  const select = screen.getByLabelText('الدور')
  expect(select).toBeDisabled()
  expect(select).toHaveDisplayValue('مدير')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ name: 'سارة', email: 'sara@smart.test', role_id: 'r1' })
})

test('closing a dialog opened from the row menu leaves the page clickable', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'GET /api/v1/users/u2/sessions': () => json(200, { data: [] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all', 'role.view.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الجلسات النشطة' }))
  expect(await screen.findByText('لا توجد جلسات نشطة.')).toBeInTheDocument()
  await user.keyboard('{Escape}')
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(document.body.style.pointerEvents).not.toBe('none')
  await user.click(screen.getByRole('button', { name: 'إضافة حساب' }))
  expect(await screen.findByRole('dialog')).toBeInTheDocument()
})
