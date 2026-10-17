import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { canDo } from '@/lib/auth'
import { json, me, mockFetch, pagination, renderApp } from '@/test-utils'

const user = userEvent.setup()

beforeEach(() => localStorage.clear())
afterEach(() => vi.unstubAllGlobals())

test('signs in, stores the token and opens the app', async () => {
  mockFetch({
    'POST /api/v1/auth/login': () => json(201, { data: { token: 't'.repeat(64), user: me(['employee.view.all']) } }),
    'GET /api/v1/auth/me': () => json(200, { data: me(['employee.view.all']) }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
  })
  renderApp('/login')
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'hr@smart.test')
  await user.type(screen.getByLabelText('كلمة المرور'), 'secret123')
  await user.click(screen.getByRole('button', { name: 'دخول' }))
  expect(await screen.findByRole('link', { name: 'الموظفون' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBe('t'.repeat(64))
})

test('shows the translated refusal', async () => {
  mockFetch({ 'POST /api/v1/auth/login': () => json(423, { error: { code: 'account_locked', message: 'x' } }) })
  renderApp('/login')
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'hr@smart.test')
  await user.type(screen.getByLabelText('كلمة المرور'), 'secret123')
  await user.click(screen.getByRole('button', { name: 'دخول' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('الحساب مقفل بعد محاولات فاشلة كثيرة. تواصل مع المطور.')
})

test('without a token any page redirects to login', async () => {
  renderApp('/employees')
  expect(await screen.findByRole('button', { name: 'دخول' })).toBeInTheDocument()
  expect(screen.getByRole('heading', { name: 'تسجيل الدخول إلى حسابك' })).toBeInTheDocument()
})

test('nav hides what the user cannot do', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(['site.view.own']) }), 'GET /api/v1/sites': () => json(200, { data: [], meta: { pagination } }) })
  renderApp('/sites')
  expect(await screen.findByRole('link', { name: 'المواقع' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'الموظفون' })).toBeNull()
})

test('a stored token refused by /auth/me shows the login form and clears the token', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(401, { error: { code: 'unauthenticated', message: 'x' } }) })
  renderApp('/employees')
  expect(await screen.findByRole('button', { name: 'دخول' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBeNull()
})

test('sign out calls the API, clears the token and shows the form', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({
    'GET /api/v1/auth/me': () => json(200, { data: me(['site.view.all']) }),
    'GET /api/v1/sites': () => json(200, { data: [], meta: { pagination } }),
    'POST /api/v1/auth/logout': () => new Response(null, { status: 204 }),
  })
  renderApp('/sites')
  await user.click(await screen.findByRole('button', { name: 'قائمة الحساب' }))
  await user.click(await screen.findByRole('menuitem', { name: 'خروج' }))
  expect(await screen.findByRole('button', { name: 'دخول' })).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.some(([u, i]) => u === '/api/v1/auth/logout' && i?.method === 'POST')).toBe(true)
  expect(localStorage.getItem('hrms.auth.token.v1')).toBeNull()
})

test('canDo matches a whole action prefix or an exact permission', () => {
  expect(canDo(['employee.viewer.x'], 'employee.view')).toBe(false)
  expect(canDo(['employee.view.all'], 'employee.view.all')).toBe(true)
  expect(canDo([], 'employee.view')).toBe(false)
})
