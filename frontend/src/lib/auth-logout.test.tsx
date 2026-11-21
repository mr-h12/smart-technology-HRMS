import { act, render } from '@testing-library/react'
import { useEffect } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { AuthProvider, useAuth } from '@/lib/auth'
import { json, me, mockFetch } from '@/test-utils'

afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  localStorage.clear()
})

test('a deliberate logout clears the site-day drafts and nothing else (Youssef, 2026-11-19)', async () => {
  sessionStorage.setItem('hrms.siteDay.drafts.u1', '{"s1|2026-10-22":{}}')
  sessionStorage.setItem('hrms.siteDay.drafts.u2', '{}')
  sessionStorage.setItem('other.key', 'kept')
  localStorage.setItem('hrms.token', 't')
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me([]) }), 'POST /api/v1/auth/logout': () => json(204, {}) })
  let logout: () => Promise<void> = async () => {}
  function Grab() {
    const auth = useAuth()
    useEffect(() => { logout = auth.logout }, [auth])
    return null
  }
  render(<AuthProvider><Grab /></AuthProvider>)
  await act(async () => { await logout() })
  expect(sessionStorage.getItem('hrms.siteDay.drafts.u1')).toBeNull()
  expect(sessionStorage.getItem('hrms.siteDay.drafts.u2')).toBeNull()
  expect(sessionStorage.getItem('other.key')).toBe('kept')
})
