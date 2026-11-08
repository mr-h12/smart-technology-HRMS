import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, me, mockFetch, pagination, renderApp, renderWithAuth } from '@/test-utils'
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
const scope = (group: string, option: string) => within(screen.getByRole('group', { name: group })).getByRole('button', { name: option })
const pressed = (b: HTMLElement) => expect(b).toHaveAttribute('aria-pressed', 'true')
const sentIds = (patch: { mock: { calls: unknown[][] } }) => JSON.parse((patch.mock.calls[0] as [string, RequestInit])[1].body as string).permission_ids.sort()
const wideScreen = (wide: boolean) => vi.stubGlobal('matchMedia', (media: string) => ({ media, matches: wide && media === '(min-width: 80rem)' }))
/** A response held back until `open()`. */
const held = (res: () => Response) => {
  let open!: () => void
  const gate = new Promise<void>((r) => { open = r })
  return { open, route: () => gate.then(res) as unknown as Response }
}
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
  pressed(scope('الموظفون: عرض', 'الخاص به'))
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
  pressed(scope('الموظفون: عرض', 'الخاص به'))
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
  expect(screen.getAllByRole('group')).toHaveLength(2)
  const scopes = screen.getAllByRole('group').flatMap((g) => within(g).getAllByRole('button'))
  expect(scopes).toHaveLength(6)
  for (const c of [...screen.getAllByRole('checkbox'), ...scopes]) expect(c).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
})

test('an action outside the known list still gets a column, after the known ones', async () => {
  const assignSite = { id: 'p-assign-site', resource: 'employee', action: 'assign_site', scope: 'all', key: 'employee.assign_site.all' }
  mockFetch({
    'GET /api/v1/roles': () => json(200, { data: [{ ...role, permissions: [...role.permissions, assignSite.key] }] }),
    'GET /api/v1/permissions': () => json(200, { data: [assignSite, ...perms] }),
  })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  expect(screen.getAllByRole('columnheader').at(-1)).toHaveTextContent('تعيين الموقع الأساسي')
  expect(screen.getByRole('checkbox', { name: 'الموظفون: تعيين الموقع الأساسي' })).toBeChecked()
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

test('Add account is the solid header action and opens the create dialog', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all', 'role.view.all'])
  const add = await screen.findByRole('button', { name: 'إضافة حساب' })
  expect(add).toHaveClass('bg-primary')
  await user.click(add)
  expect(await screen.findByRole('dialog', { name: 'إضافة حساب' })).toBeInTheDocument()
})

test('the accounts list is a DataList: email keeps ltr and the active pill is the status cell', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }) })
  renderWithAuth(<AccountsPage />, ['user.view.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  expect(screen.getByRole('table', { name: 'حسابات المستخدمين' })).toBeInTheDocument()
  expect(within(row).getByText(account.email).closest('[dir="ltr"]')).not.toBeNull()
  expect(within(row).getAllByRole('cell').at(-1)).toHaveTextContent('نشط')
})

test('the sessions list keeps ltr ip and user agent, the agent truncated with its full text as a title', async () => {
  const ua = 'Mozilla/5.0 (Macintosh) Safari/605.1.15'
  const del = vi.fn(() => json(200, { data: {} }))
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }),
    'GET /api/v1/users/u2/sessions': () => json(200, { data: [{ id: 's1', ip_address: '10.0.0.7', user_agent: ua, last_activity_at: '2026-11-01T08:00:00Z' }] }),
    'DELETE /api/v1/users/u2/sessions/s1': del })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  const row = await screen.findByRole('row', { name: /سارة/ })
  await user.click(within(row).getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الجلسات النشطة' }))
  const dialog = await screen.findByRole('dialog')
  const session = await within(dialog).findByRole('row', { name: /10\.0\.0\.7/ })
  expect(within(session).getByText('10.0.0.7').closest('[dir="ltr"]')).not.toBeNull()
  const agent = within(session).getByTitle(ua)
  expect(agent).toHaveAttribute('dir', 'ltr')
  expect(agent).toHaveClass('truncate')
  await user.click(within(session).getByRole('button', { name: 'إنهاء الجلسة' }))
  await waitFor(() => expect(del).toHaveBeenCalledOnce())
})

test('Add role is the solid header action until a role is open, then a ghost beside the solid Save', async () => {
  rolesApi()
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  expect(await screen.findByRole('button', { name: 'إضافة دور' })).toHaveClass('bg-primary')
  await pickRole()
  expect(screen.getByRole('button', { name: 'إضافة دور' })).not.toHaveClass('bg-primary')
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toHaveClass('bg-primary')
  await user.click(screen.getByRole('button', { name: 'إضافة دور' }))
  expect(await screen.findByRole('dialog', { name: 'إضافة دور' })).toBeInTheDocument()
})

test.each([
  [true, 'true'],
  [false, 'false'],
])('the first role opens by default only when the list and matrix sit side by side (wide %s)', async (wide, state) => {
  wideScreen(wide)
  rolesApi()
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  expect(await screen.findByRole('button', { name: /مدير/ })).toHaveAttribute('aria-pressed', state)
  if (wide) expect(await screen.findByRole('group', { name: 'الموظفون: عرض' })).toBeInTheDocument()
  else expect(screen.getByText('اختر دورًا لعرض صلاحياته.')).toBeInTheDocument()
})

test('on a wide screen the first role waits for the permissions, so its matrix never opens empty and dirty', async () => {
  wideScreen(true)
  const permissions = held(() => json(200, { data: perms }))
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': permissions.route })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  expect(await screen.findByRole('button', { name: /مدير/ })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.queryByRole('button', { name: 'حفظ التغييرات' })).toBeNull()
  permissions.open()
  pressed(within(await screen.findByRole('group', { name: 'الموظفون: عرض' })).getByRole('button', { name: 'الخاص به' }))
  expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeDisabled()
})

test('on a wide screen Add role stays the ghost alternative while the roles load, with no pick-a-role note', async () => {
  wideScreen(true)
  const roles = held(() => json(200, { data: [role] }))
  mockFetch({ 'GET /api/v1/roles': roles.route, 'GET /api/v1/permissions': () => json(200, { data: perms }) })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  expect(await screen.findByRole('button', { name: 'إضافة دور' })).not.toHaveClass('bg-primary')
  expect(screen.queryByText('اختر دورًا لعرض صلاحياته.')).toBeNull()
  roles.open()
  expect(await screen.findByRole('button', { name: 'حفظ التغييرات' })).toHaveClass('bg-primary')
  expect(screen.getByRole('button', { name: 'إضافة دور' })).not.toHaveClass('bg-primary')
})

test('on a wide screen a click on another role wins over the default', async () => {
  wideScreen(true)
  const hr = { id: 'r2', slug: 'hr', name: 'HR', name_ar: 'الموارد البشرية', permissions: ['audit_log.view.all'], users_count: 1 }
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role, hr] }), 'GET /api/v1/permissions': () => json(200, { data: perms }) })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  expect(await screen.findByRole('button', { name: /مدير/ })).toHaveAttribute('aria-pressed', 'true')
  await user.click(screen.getByRole('button', { name: /الموارد البشرية/ }))
  expect(screen.getByRole('button', { name: /الموارد البشرية/ })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByRole('button', { name: /مدير/ })).toHaveAttribute('aria-pressed', 'false')
  expect(await screen.findByRole('checkbox', { name: 'سجل التدقيق: عرض' })).toBeChecked()
  pressed(within(screen.getByRole('group', { name: 'الموظفون: عرض' })).getByRole('button', { name: 'لا' }))
})

test('a scope control hugs its words and keeps them apart on one line', async () => {
  rolesApi()
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  const group = screen.getByRole('group', { name: 'الموظفون: عرض' })
  expect(group).toHaveClass('inline-flex', 'w-max') // w-max: the auto-layout table sizes the cell from the group's min-content
  expect(group).not.toHaveClass('flex')
  expect(within(group).getByText('الخاص به', { ignore: '.sr-only' })).toHaveClass('px-1.5', 'whitespace-nowrap')
  // min-w-tap replaces a flex item's min-width:auto, so without shrink-0 the table squeezes the pills below their words
  for (const b of within(group).getAllByRole('button')) expect(b).toHaveClass('shrink-0')
})

test('the matrix names the system logs permission in Arabic', async () => {
  const logs = { id: 'p-logs', resource: 'system_log', action: 'view', scope: 'all', key: 'system_log.view.all' }
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': () => json(200, { data: [...perms, logs] }) })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  expect(screen.getByRole('cell', { name: 'سجلات النظام' })).toBeInTheDocument()
  expect(screen.getByRole('checkbox', { name: 'سجلات النظام: عرض' })).not.toBeChecked()
})

test('the matrix has an Edit ID column, so employee.edit_code can be granted', async () => {
  const editCode = { id: 'p-edit-code', resource: 'employee', action: 'edit_code', scope: 'all', key: 'employee.edit_code.all' }
  mockFetch({
    'GET /api/v1/roles': () => json(200, { data: [{ ...role, permissions: [...role.permissions, editCode.key] }] }),
    'GET /api/v1/permissions': () => json(200, { data: [...perms, editCode] }),
  })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await pickRole()
  expect(screen.getByRole('columnheader', { name: 'تعديل الرقم الوظيفي' })).toBeInTheDocument()
  expect(screen.getByRole('checkbox', { name: 'الموظفون: تعديل الرقم الوظيفي' })).toBeChecked()
})

const TOKEN = 'hrms.auth.token.v1'
const accounts = [account, { ...account, id: 'u3', name: 'نور', email: 'nour@smart.test', is_active: false }, { ...account, id: 'u1', name: 'منى', email: 'hr@smart.test' }]
const menuOf = async (name: RegExp) => {
  await user.click(within(await screen.findByRole('row', { name })).getByRole('button', { name: 'الإجراءات' }))
  return screen.findByRole('menu')
}

test('View as shows with user.impersonate only on active accounts other than the signed-in one', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: accounts, meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all', 'user.impersonate.all'])
  expect(within(await menuOf(/سارة/)).getByRole('menuitem', { name: 'عرض كـ' })).toBeInTheDocument()
  await user.keyboard('{Escape}')
  expect(within(await menuOf(/نور/)).queryByRole('menuitem', { name: 'عرض كـ' })).toBeNull()
  await user.keyboard('{Escape}')
  expect(within(await menuOf(/منى/)).queryByRole('menuitem', { name: 'عرض كـ' })).toBeNull()
})

test('without user.impersonate there is no View as', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: accounts, meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  expect(within(await menuOf(/سارة/)).queryByRole('menuitem', { name: 'عرض كـ' })).toBeNull()
})

test('View as alone gives the row a menu holding only View as', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: accounts, meta: { pagination } }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.impersonate.all'])
  expect(within(await menuOf(/سارة/)).getAllByRole('menuitem').map((i) => i.textContent)).toEqual(['عرض كـ'])
  expect(within(screen.getByRole('row', { name: /نور/ })).queryByRole('button')).toBeNull()
})

const developerSignedIn = (start: () => Response) => {
  localStorage.setItem(TOKEN, 'd'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(['user.view.all', 'user.impersonate.all']) }),
    'GET /api/v1/users': () => json(200, { data: accounts, meta: { pagination } }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
    'POST /api/v1/auth/view-as': start,
  })
  renderApp('/console/accounts')
}
const confirmViewAs = async (reason?: string) => {
  await user.click(within(await menuOf(/سارة/)).getByRole('menuitem', { name: 'عرض كـ' }))
  const dialog = await screen.findByRole('dialog', { name: 'عرض كـ: سارة' })
  if (reason) await user.type(within(dialog).getByLabelText('السبب (اختياري)'), reason)
  await user.click(within(dialog).getByRole('button', { name: 'عرض كـ' }))
  return dialog
}

test('confirming View as sends the account and reason, stores the new token and lands on that account home page', async () => {
  const sara = { ...me(['employee.view.all']), id: 'u2', name: 'سارة', view_as: { by: { id: 'u1', name: 'منى' }, expires_at: '2026-11-05T10:30:00Z' } }
  const start = vi.fn(() => json(201, { data: { token: 'v'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: sara } }))
  developerSignedIn(start)
  await confirmViewAs('فحص صلاحيات المدير')
  await waitFor(() => expect(localStorage.getItem(TOKEN)).toBe('v'.repeat(64)))
  expect(JSON.parse((start.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ user_id: 'u2', reason: 'فحص صلاحيات المدير' })
  await waitFor(() => expect(screen.getByRole('link', { name: 'الموظفون' })).toHaveAttribute('aria-current', 'page'))
  expect(screen.queryByRole('link', { name: 'حسابات المستخدمين' })).toBeNull()
  expect(screen.getByText(/أنت الآن تعرض النظام كـ/)).toHaveTextContent('سارة')
})

test('a refused View as shows the API message and keeps the developer signed in', async () => {
  developerSignedIn(() => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'user_id', code: 'identity.view_as.not_allowed', message: 'لا يمكن العرض كحساب يملك صلاحية العرض كـ.' }] } }))
  const dialog = await confirmViewAs()
  expect(await within(dialog).findByText('لا يمكن العرض كحساب يملك صلاحية العرض كـ.')).toBeInTheDocument()
  expect(localStorage.getItem(TOKEN)).toBe('d'.repeat(64))
})
