import { screen } from '@testing-library/react'
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
  expect(await screen.findByRole('link', { name: 'الحسابات' })).toBeInTheDocument()
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

test('the profile menu shows the account and signs out from there', async () => {
  signedIn(['employee.view.all'])
  renderApp('/employees')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  expect(await screen.findByRole('menuitem', { name: 'خروج' })).toBeInTheDocument()
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
