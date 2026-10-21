import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { toast } from 'sonner'
import { AccountsPage, RolesPage } from './console'
import { AuditPage } from './audit'

vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))

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
const role = { id: 'r1', slug: 'manager', name: 'Manager', name_ar: 'مدير', permissions: ['employee.view.own', 'site.view.own'], users_count: 2 }
const account = { id: 'u2', name: 'سارة', email: 'sara@smart.test', is_active: true, locked_until: null, failed_login_attempts: 0, role: { id: 'r1', slug: 'manager', name: 'Manager', name_ar: 'مدير' } }

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  vi.clearAllMocks()
})

const pickRole = async () => user.click(await screen.findByRole('button', { name: /مدير/ }))
const scope = (group: string, option: string) => within(screen.getByRole('radiogroup', { name: group })).getByRole('radio', { name: option })
const sentIds = (patch: { mock: { calls: unknown[][] } }) => JSON.parse((patch.mock.calls[0] as [string, RequestInit])[1].body as string).permission_ids.sort()
const rolesApi = (patch?: () => Response) => mockFetch({
  'GET /api/v1/roles': () => json(200, { data: [role] }),
  'GET /api/v1/permissions': () => json(200, { data: perms }),
  ...(patch && { 'PATCH /api/v1/roles/r1/permissions': patch }),
})

test('role matrix saves the checked permission ids', async () => {
  const patch = vi.fn(() => json(200, { data: { ...role, permissions: ['employee.view.own', 'site.view.own', 'audit_log.view.all'] } }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  expect(scope('الموظفون: عرض', 'الخاص به')).toBeChecked()
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeDisabled()
  await user.click(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' }))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-audit', 'p-emp-own', 'p-site-own'])
  await waitFor(() => expect(toast.success).toHaveBeenCalledWith('تم حفظ التغييرات.'))
})

test('choosing a wider scope swaps the own permission for the all permission', async () => {
  const patch = vi.fn(() => json(200, { data: role }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(scope('الموظفون: عرض', 'الكل'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-employee.view.all', 'p-site-own'])
})

test('choosing none removes the permission', async () => {
  const patch = vi.fn(() => json(200, { data: role }))
  rolesApi(patch)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(scope('المواقع: عرض', 'لا'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(sentIds(patch)).toEqual(['p-emp-own'])
})

test('a refused save shows the error and puts the controls back to the role permissions', async () => {
  rolesApi(() => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'permission_ids', code: 'invalid', message: 'آخر مدير للأدوار' }] } }))
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' }))
  await user.click(scope('الموظفون: عرض', 'الكل'))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(await screen.findByText('آخر مدير للأدوار')).toBeInTheDocument()
  expect(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' })).not.toBeChecked()
  expect(scope('الموظفون: عرض', 'الخاص به')).toBeChecked()
})

test('the controls are locked while a save is in flight', async () => {
  rolesApi(() => new Promise<Response>(() => {}) as unknown as Response)
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  await user.click(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' }))
  await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))
  expect(screen.getByRole('checkbox', { name: 'سجل التدقيق: عرض' })).toBeDisabled()
  expect(scope('الموظفون: عرض', 'الكل')).toBeDisabled()
})

test('without role.manage the matrix is read-only', async () => {
  rolesApi()
  renderWithAuth(<RolesPage />, ['role.view.all'])
  await pickRole()
  expect(screen.getAllByRole('checkbox')).toHaveLength(11)
  expect(screen.getAllByRole('radiogroup')).toHaveLength(2)
  for (const c of [...screen.getAllByRole('checkbox'), ...screen.getAllByRole('radio')]) expect(c).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
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
  expect(screen.getByLabelText('كلمة المرور')).toHaveAttribute('type', 'text') // no browser-generated password
  expect(screen.getByLabelText('كلمة المرور')).toHaveAttribute('autocomplete', 'off')
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
  expect(within(row).getAllByText('مدير')).toHaveLength(2) // under the name on phones, its own column from sm
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
  const field = screen.getByLabelText('كلمة المرور')
  expect(field).toHaveAttribute('dir', 'ltr')
  // Visible text, not type=password: Safari fills its own strong password into a new-password field, so what the developer typed was never sent.
  expect(field).toHaveAttribute('type', 'text')
  expect(field).toHaveAttribute('autocomplete', 'off')
  await user.type(field, 'newpass123')
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
  expect(screen.queryByRole('button', { name: 'إضافة حساب' })).toBeNull()
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

test('the roles page shows the skeleton while its first load is pending', async () => {
  mockFetch({ 'GET /api/v1/roles': () => new Promise<Response>(() => {}) as unknown as Response, 'GET /api/v1/permissions': () => json(200, { data: perms }) })
  renderWithAuth(<RolesPage />, ['role.view.all'])
  expect(await screen.findByLabelText('جارٍ التحميل…')).toHaveAttribute('aria-busy', 'true')
})

test('without role.view neither roles nor permissions are fetched', async () => {
  mockFetch({})
  renderWithAuth(<RolesPage />, ['audit_log.view.all'])
  expect(await screen.findByText('اختر دورًا لعرض صلاحياته.')).toBeInTheDocument()
  expect(fetch).not.toHaveBeenCalled()
})

test('saving an account refreshes the account lookup the audit filter shares', async () => {
  const users = [{ ...account, name: 'سارة' }]
  const usersGets = () => vi.mocked(fetch).mock.calls.filter(([u, i]) => String(u).startsWith('/api/v1/users?') && (i?.method ?? 'GET') === 'GET').length
  mockFetch({
    'GET /api/v1/users': () => json(200, { data: users, meta: { pagination } }),
    'GET /api/v1/roles': () => json(200, { data: [role] }),
    'GET /api/v1/audit-log': () => json(200, { data: [], meta: { pagination } }),
    'POST /api/v1/users': () => { users.push({ ...account, id: 'u9', name: 'نور' }); return json(201, { data: {} }) },
  })
  const audit = renderWithAuth(<AuditPage />, ['audit_log.view.all', 'user.view.all'])
  await screen.findByRole('option', { name: 'سارة' })
  audit.unmount()
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all', 'role.view.all'])
  await screen.findByRole('row', { name: /سارة/ })
  expect(usersGets()).toBe(1) // the accounts list and the audit filter are one cached request
  await user.click(screen.getByRole('button', { name: 'إضافة حساب' }))
  await user.type(screen.getByLabelText('الاسم'), 'نور')
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'nour@smart.test')
  await user.selectOptions(await screen.findByLabelText('الدور'), 'r1')
  await user.type(screen.getByLabelText('كلمة المرور'), 'secret123')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByRole('row', { name: /نور/ })
  cleanup()
  renderWithAuth(<AuditPage />, ['audit_log.view.all', 'user.view.all'])
  expect(await screen.findByRole('option', { name: 'نور' })).toBeInTheDocument()
})
