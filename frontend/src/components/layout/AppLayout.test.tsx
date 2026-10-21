import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, me, mockFetch, pagination, renderApp } from '@/test-utils'

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
