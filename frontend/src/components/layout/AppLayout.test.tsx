import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { canDo } from '@/lib/auth'
import { json, me, mockFetch, pagination, renderApp } from '@/test-utils'
import { homePath } from './AppLayout'

const user = userEvent.setup()
const signedIn = (permissions: string[]) => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(permissions) }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
  })
}

beforeEach(() => {
  localStorage.clear()
  document.documentElement.classList.remove('dark')
})
afterEach(() => vi.unstubAllGlobals())

test('collapsing the sidebar flips aria-expanded and keeps link names', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  const collapse = await screen.findByRole('button', { name: 'طي القائمة الجانبية' })
  expect(collapse).toHaveAttribute('aria-expanded', 'true')
  await user.click(collapse)
  expect(screen.getByRole('button', { name: 'توسيع القائمة الجانبية' })).toHaveAttribute('aria-expanded', 'false')
  expect(screen.getByRole('link', { name: 'الموظفون' })).toBeInTheDocument()
})

test('nav groups appear only when the user may open one of their pages', async () => {
  signedIn(['employee.view.all', 'user.view.all'])
  renderApp('/employees')
  expect(await screen.findByRole('link', { name: 'حسابات المستخدمين' })).toBeInTheDocument()
  expect(screen.getByText('إدارة النظام')).toBeInTheDocument()
  expect(screen.queryByText('الإعدادات')).toBeNull()
  expect(screen.queryByRole('link', { name: 'الأدوار والصلاحيات' })).toBeNull()
})

test('the breadcrumb names the group and the current page', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  const crumb = await screen.findByRole('navigation', { name: 'مسار التنقل' })
  expect(crumb).toHaveTextContent('الموارد البشرية')
  expect(crumb).toHaveTextContent('الموظفون')
})

test('the breadcrumb shows one crumb when the group and the page share a name', async () => {
  signedIn(['setting.view.all'])
  renderApp('/settings')
  const crumb = await screen.findByRole('navigation', { name: 'مسار التنقل' })
  expect(crumb).toHaveTextContent(/^الإعدادات$/)
})

test('the profile menu shows the account and signs out from there', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  expect(await screen.findByRole('menuitem', { name: 'تسجيل الخروج' })).toBeInTheDocument()
  expect(screen.getAllByText('hr@smart.test').length).toBeGreaterThan(0)
  // The header hides the role below lg, so the menu names it at every width.
  expect(within(screen.getByRole('menu')).getByText('الموارد البشرية')).toBeInTheDocument()
})

test('the theme item in the profile menu switches the whole app theme', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الوضع الداكن' }))
  expect(document.documentElement.classList.contains('dark')).toBe(true)
  expect(screen.getByRole('button', { name: 'الوضع الداكن' })).toHaveAttribute('aria-pressed', 'true')
  await user.click(screen.getByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'الوضع الفاتح' }))
  expect(document.documentElement.classList.contains('dark')).toBe(false)
})

test('the Attendance link shows for attendance.view', async () => {
  signedIn(['employee.view.all'])
  const { unmount } = renderApp('/employees')
  await screen.findByRole('link', { name: 'الموظفون' })
  expect(screen.queryByRole('link', { name: 'الحضور والانصراف' })).toBeNull()
  unmount()
  signedIn(['attendance.view.all'])
  renderApp('/settings')
  expect(await screen.findByRole('link', { name: 'الحضور والانصراف' })).toBeInTheDocument()
})

test('the Settings link shows for setting.view alone', async () => {
  signedIn(['setting.view.all'])
  renderApp('/settings')
  expect(await screen.findByRole('link', { name: 'الإعدادات' })).toBeInTheDocument()
})

test('the mobile nav header keeps the close button lane clear of the brand', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'فتح القائمة' }))
  const sheet = await screen.findByRole('dialog')
  expect(sheet).toHaveClass('w-72')
  expect(within(sheet).getByRole('link', { name: 'سمارت تكنولوجي لإدارة الموارد البشرية' }).parentElement).toHaveClass('pe-14')
})

test('the header shows the notification bell before the account menu', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  const profile = await screen.findByRole('button', { name: 'قائمة الحساب' })
  const bell = await screen.findByRole('button', { name: /^فتح الإشعارات/ })
  expect(bell.compareDocumentPosition(profile) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
})

// The four roles as seeded (read from the local database); on main HR also holds user.manage.all.
const ROLE = {
  developer: ['attendance.edit.all', 'attendance.lock.all', 'attendance.view.all', 'audit_log.view.all', 'employee.approve_wage.all', 'employee.assign_manager.all', 'employee.assign_site.all', 'employee.create.all', 'employee.delete.all', 'employee.edit.all', 'employee.edit_code.all', 'employee.view.all', 'employee_document.edit.all', 'employee_document.view.all', 'fare.manage.all', 'holiday.manage.all', 'leave.adjust.all', 'leave.decide.all', 'leave.decide_escalated.all', 'leave.request.all', 'leave.view.all', 'role.manage.all', 'role.view.all', 'setting.manage.all', 'setting.view.all', 'site.assign_manager.all', 'site.manage.all', 'site.view.all', 'site.view.own', 'system_log.view.all', 'user.impersonate.all', 'user.manage.all', 'user.view.all'],
  hr: ['attendance.edit.all', 'attendance.lock.all', 'attendance.view.all', 'employee.approve_wage.all', 'employee.assign_manager.all', 'employee.assign_site.all', 'employee.create.all', 'employee.delete.all', 'employee.edit.all', 'employee.edit_code.all', 'employee.view.all', 'employee_document.edit.all', 'employee_document.view.all', 'fare.manage.all', 'holiday.manage.all', 'leave.adjust.all', 'leave.decide.all', 'leave.request.all', 'leave.view.all', 'setting.manage.all', 'setting.view.all', 'site.assign_manager.all', 'site.manage.all', 'site.view.all', 'user.manage.all', 'user.view.all'],
  ceo: ['attendance.view.all', 'audit_log.view.all', 'employee.view.all', 'employee_document.view.all', 'leave.decide_escalated.all', 'leave.view.all', 'setting.view.all', 'site.view.all'],
  manager: ['attendance.record.own', 'employee.crew.own', 'employee.propose_laborer.own', 'employee.view.own', 'leave.request.own', 'leave.view.own', 'site.view.own'],
}
test.each([
  ['the developer', '/employees', ROLE.developer],
  ['HR', '/employees', ROLE.hr],
  ['the CEO', '/employees', ROLE.ceo],
  ['a site manager', '/site-day', ROLE.manager],
  ['an account recording site attendance at both scopes', '/employees', [...ROLE.manager, 'attendance.record.all']],
  ['an account with no permissions', '/employees', []],
])('%s lands on %s', (_, path, permissions) => {
  expect(homePath((a) => canDo(permissions, a))).toBe(path)
})

test('a site manager lands on Site attendance', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(ROLE.manager) }),
    'GET /api/v1/sites': () => json(200, { data: [], meta: { pagination } }),
  })
  renderApp('/')
  await waitFor(() => expect(screen.getByRole('link', { name: 'حضور الموقع' })).toHaveAttribute('aria-current', 'page'))
})

test('a site manager opening /employees by URL stays there: a deep link wins over the landing page', async () => {
  signedIn(ROLE.manager)
  renderApp('/employees')
  await waitFor(() => expect(screen.getByRole('link', { name: 'الموظفون' })).toHaveAttribute('aria-current', 'page'))
  expect(screen.getByRole('link', { name: 'حضور الموقع' })).not.toHaveAttribute('aria-current')
})

const developer = { ...me(['user.view.all', 'user.impersonate.all']), id: 'u9', name: 'يوسف', role: { id: 'r9', slug: 'developer', name: 'Developer', name_ar: 'المطوّر' } }
const banner = () => screen.queryByText(/أنت الآن تعرض النظام كـ/)

test('a normal session shows no view-as banner', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await screen.findByRole('link', { name: 'الموظفون' })
  expect(banner()).toBeNull()
  expect(screen.queryByRole('button', { name: 'العودة إلى حسابي' })).toBeNull()
})

test('while viewing as an account a banner names it, and Return swaps back to the developer', async () => {
  localStorage.setItem('hrms.auth.token.v1', 'v'.repeat(64))
  const end = vi.fn(() => json(201, { data: { token: 'd'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: developer } }))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: { ...me(['employee.view.all']), view_as: { by: { id: 'u9', name: 'يوسف' }, expires_at: '2026-11-05T10:30:00Z' } } }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
    'GET /api/v1/users': () => json(200, { data: [], meta: { pagination } }),
    'POST /api/v1/auth/view-as/end': end,
  })
  renderApp('/employees')
  const status = (await screen.findByText(/أنت الآن تعرض النظام كـ/)).closest('[role="status"]')!
  expect(status).toHaveTextContent('منى')
  expect(status).toHaveTextContent('الموارد البشرية')
  expect(status).toHaveTextContent('12:30') // Cairo time of 10:30Z
  await user.click(within(status as HTMLElement).getByRole('button', { name: 'العودة إلى حسابي' }))
  await waitFor(() => expect(banner()).toBeNull())
  expect(end).toHaveBeenCalledOnce()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBe('d'.repeat(64))
  await waitFor(() => expect(screen.getByRole('link', { name: 'حسابات المستخدمين' })).toHaveAttribute('aria-current', 'page')) // the developer's own landing page
})

const ALL_NAV = ['employee.view.all', 'attendance.view.all', 'attendance.record.all', 'leave.view.all', 'employee.approve_wage.all', 'fare.manage.all']
test('the HR group lists employees, attendance, site day, leave, wage proposals, then team fares; Settings follows', async () => {
  signedIn(ALL_NAV)
  renderApp('/employees')
  const nav = await screen.findByRole('navigation', { name: 'القائمة الرئيسية' })
  await within(nav).findByRole('link', { name: 'الموظفون' })
  expect(within(nav).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['/employees', '/attendance', '/site-day', '/leave', '/wage-proposals', '/team-fares', '/settings'])
})

const MANAGER = ['employee.view.own', 'site.view.own', 'attendance.record.own', 'leave.view.own', 'leave.request.own', 'employee.crew.own', 'employee.propose_laborer.own']
test.each(['/settings', '/wage-proposals', '/console/accounts', '/console/roles', '/console/audit', '/console/logs', '/employees/new'])('a site manager opening %s by URL sees the not-found page', async (path) => {
  signedIn(MANAGER)
  renderApp(path)
  expect(await screen.findByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
})

test('a site manager has no Settings item in the nav', async () => {
  signedIn(MANAGER)
  renderApp('/employees')
  const nav = await screen.findByRole('navigation', { name: 'القائمة الرئيسية' })
  await within(nav).findByRole('link', { name: 'الموظفون' })
  expect(within(nav).queryByRole('link', { name: 'الإعدادات' })).toBeNull()
})

test('the Settings page opens for a user with any of its abilities', async () => {
  signedIn(['attendance.view.all'])
  renderApp('/settings')
  expect(await screen.findByRole('heading', { level: 1, name: 'الإعدادات' })).toBeInTheDocument()
})

test('HR lands on /employees', async () => {
  signedIn(['employee.view.all', 'fare.manage.all', 'attendance.view.all', 'employee.approve_wage.all'])
  renderApp('/')
  expect(await screen.findByRole('link', { name: 'الموظفون', current: 'page' })).toBeInTheDocument()
})
