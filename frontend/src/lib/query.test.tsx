import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, renderHook, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination } from '@/test-utils'
import { ApiError, setToken } from './api'
import { AuthProvider, useAuth } from './auth'
import { queryClient, queryKey, shouldRetry, useApi, useList, useSave } from './query'

const wrapper = ({ children }: { children: React.ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
const list = (rows: unknown[], total = rows.length) => json(200, { data: rows, meta: { pagination: { ...pagination, total } } })
const calls = (path: string) => vi.mocked(fetch).mock.calls.filter(([u, init]) => String(u) === `/api/v1${path}` && (init?.method ?? 'GET') === 'GET').length

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

test('query keys are the path segments plus the query string', () => {
  expect(queryKey('/employees?page=1&type=x')).toEqual(['employees', '?page=1&type=x'])
  expect(queryKey('/employees/123')).toEqual(['employees', '123'])
  expect(queryKey('/employees')).toEqual(['employees'])
})

test('4xx errors are not retried; anything else is retried once', () => {
  expect(shouldRetry(0, new ApiError('forbidden', 'x', 403))).toBe(false)
  expect(shouldRetry(0, new ApiError('server_error', 'x', 500))).toBe(true)
  expect(shouldRetry(1, new ApiError('server_error', 'x', 500))).toBe(false)
  expect(shouldRetry(0, new TypeError('Failed to fetch'))).toBe(true)
})

test('a second mount of the same path within staleTime makes no new request', async () => {
  mockFetch({ 'GET /api/v1/sites': () => list([{ id: 's1' }]) })
  const first = renderHook(() => useList('/sites?page=1'), { wrapper })
  await waitFor(() => expect(first.result.current.rows).toEqual([{ id: 's1' }]))
  expect(first.result.current).toMatchObject({ total: 1, err: undefined, loading: false })
  first.unmount()
  const second = renderHook(() => useList('/sites?page=1'), { wrapper })
  expect(second.result.current.rows).toEqual([{ id: 's1' }])
  expect(second.result.current.loading).toBe(false)
  expect(calls('/sites?page=1')).toBe(1)
})

test('a save refetches every list of that resource and leaves other resources alone', async () => {
  let n = 0
  mockFetch({
    'GET /api/v1/sites': () => list([{ id: `s${++n}` }]),
    'GET /api/v1/residences': () => list([]),
    'POST /api/v1/sites': () => json(201, { data: { id: 'new' } }),
  })
  const { result } = renderHook(() => ({ a: useList('/sites?kind=work'), b: useList('/sites?kind=leave'), r: useList('/residences'), save: useSave() }), { wrapper })
  await waitFor(() => expect(result.current.r.loading).toBe(false))
  await waitFor(() => expect(result.current.b.loading).toBe(false))
  let saved: unknown
  await act(async () => {
    saved = await result.current.save('/sites', { method: 'POST', body: { name_ar: 'x' } })
  })
  expect(saved).toEqual({ id: 'new' })
  await waitFor(() => expect(calls('/sites?kind=work') + calls('/sites?kind=leave')).toBe(4))
  expect(calls('/residences')).toBe(1)
})

test('a save can name the resources it invalidates', async () => {
  mockFetch({ 'GET /api/v1/employees': () => list([]), 'PATCH /api/v1/users/u1/deactivate': () => json(200, { data: {} }) })
  const { result } = renderHook(() => ({ e: useList('/employees'), save: useSave() }), { wrapper })
  await waitFor(() => expect(result.current.e.loading).toBe(false))
  await act(() => result.current.save('/users/u1/deactivate', { method: 'PATCH' }, ['/employees']))
  await waitFor(() => expect(calls('/employees')).toBe(2))
})

test('an older, slower response never overwrites the newer one', async () => {
  const pending: Record<string, (r: Response) => void> = {}
  vi.stubGlobal('fetch', vi.fn((url: string) => new Promise<Response>((resolve) => (pending[url] = resolve))))
  const { result, rerender } = renderHook(({ path }) => useList(path), { wrapper, initialProps: { path: '/audit-log?page=1' } })
  await waitFor(() => expect(pending['/api/v1/audit-log?page=1']).toBeDefined())
  rerender({ path: '/audit-log?page=2' })
  await waitFor(() => expect(pending['/api/v1/audit-log?page=2']).toBeDefined())
  await act(async () => pending['/api/v1/audit-log?page=2'](list([{ id: 'new' }])))
  await waitFor(() => expect(result.current.rows).toEqual([{ id: 'new' }]))
  await act(async () => pending['/api/v1/audit-log?page=1'](list([{ id: 'old' }])))
  expect(result.current.rows).toEqual([{ id: 'new' }])
})

test('an error clears the rows that were showing', async () => {
  let fail = false
  mockFetch({ 'GET /api/v1/sites': () => (fail ? json(500, { error: { code: 'server_error', message: 'Boom' } }) : list([{ id: 's1' }])) })
  const { result } = renderHook(() => useList('/sites'), { wrapper })
  await waitFor(() => expect(result.current.rows).toHaveLength(1))
  fail = true
  act(() => result.current.reload())
  await waitFor(() => expect(result.current.err).toBeInstanceOf(ApiError))
  expect(result.current.rows).toEqual([])
  expect(result.current.total).toBe(0)
})

test('useApi returns one record and shares the resource prefix', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { id: 'e1', name: 'x' } }), 'PATCH /api/v1/employees/e1': () => json(200, { data: {} }) })
  const { result } = renderHook(() => ({ e: useApi<{ id: string }>('/employees/e1'), save: useSave() }), { wrapper })
  await waitFor(() => expect(result.current.e.data).toEqual({ id: 'e1', name: 'x' }))
  expect(result.current.e).toMatchObject({ err: undefined, loading: false })
  await act(() => result.current.save('/employees/e1', { method: 'PATCH', body: {} }))
  await waitFor(() => expect(calls('/employees/e1')).toBe(2))
})

test('useApi with a null path makes no request', () => {
  mockFetch({})
  const { result } = renderHook(() => useApi(null), { wrapper })
  expect(result.current.data).toBeUndefined()
  expect(fetch).not.toHaveBeenCalled()
})

test('changing language refetches what is on screen', async () => {
  mockFetch({ 'GET /api/v1/sites': () => list([]) })
  const { result } = renderHook(() => useList('/sites'), { wrapper })
  await waitFor(() => expect(result.current.loading).toBe(false))
  await act(() => i18n.changeLanguage('en'))
  await waitFor(() => expect(calls('/sites')).toBe(2))
  await i18n.changeLanguage('ar')
})

function SignOut() {
  const { logout, login } = useAuth()
  return (
    <>
      <button onClick={() => void logout()}>out</button>
      <button onClick={() => void login('a@b.test', 'x')}>in</button>
    </>
  )
}

const seed = () => queryClient.setQueryData(queryKey('/employees'), { data: [{ id: 'secret' }], meta: { pagination } })

test('logout and login drop every cached answer', async () => {
  mockFetch({ 'POST /api/v1/auth/logout': () => json(200, { data: null }), 'POST /api/v1/auth/login': () => json(200, { data: { token: 'b'.repeat(64), user: { permissions: [] } } }) })
  render(<AuthProvider><SignOut /></AuthProvider>)
  seed()
  await userEvent.click(screen.getByText('out'))
  await waitFor(() => expect(queryClient.getQueryCache().getAll()).toHaveLength(0))
  seed()
  await userEvent.click(screen.getByText('in'))
  await waitFor(() => expect(queryClient.getQueryCache().getAll()).toHaveLength(0))
})

test('a 401 drops every cached answer', async () => {
  setToken('a'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(401, { error: { code: 'unauthenticated', message: 'x' } }) })
  seed()
  render(<AuthProvider><SignOut /></AuthProvider>)
  await waitFor(() => expect(queryClient.getQueryCache().getAll()).toHaveLength(0))
})
