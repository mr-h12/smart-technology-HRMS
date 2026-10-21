import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import '@/i18n'
import { AppRoutes } from '@/App'
import { AuthContext, AuthProvider, canDo, type Me } from '@/lib/auth'
import { queryClient } from '@/lib/query'

export const pagination = { page: 1, per_page: 20, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false }
export const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status })

export function mockFetch(routes: Record<string, (url: string, init?: RequestInit) => Response>) {
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url.split('?')[0]}`
    return routes[key]?.(url, init) ?? json(404, { error: { code: 'nf', message: key } })
  }))
}

export const me = (permissions: string[]): Me => ({
  id: 'u1',
  name: 'منى',
  email: 'hr@smart.test',
  role: { id: 'r1', slug: 'hr', name: 'HR', name_ar: 'الموارد البشرية' },
  permissions,
})

const withCache = (ui: React.ReactNode) => <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>

export const renderApp = (path: string) =>
  render(
    withCache(
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>,
    ),
  )

/** Renders `ui` signed in with exactly these permissions, without the /auth/me round trip; `path` seeds the router, `router: false` drops it. */
export const renderWithAuth = (ui: React.ReactNode, permissions: string[], { path = '/', router = true } = {}) => {
  const auth = <AuthContext.Provider value={{ me: me(permissions), loading: false, login: async () => {}, logout: async () => {}, can: (a) => canDo(permissions, a) }}>{ui}</AuthContext.Provider>
  return render(withCache(router ? <MemoryRouter initialEntries={[path]}>{auth}</MemoryRouter> : auth))
}
