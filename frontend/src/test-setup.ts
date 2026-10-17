import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import { queryClient } from '@/lib/query'

afterEach(() => {
  cleanup()
  queryClient.clear()
})

// Retries would wait a second before an error shows; the retry rule itself is tested in lib/query.test.tsx.
queryClient.setDefaultOptions({ queries: { ...queryClient.getDefaultOptions().queries, retry: false } })

// Radix Popper (dropdown menus, tooltips) measures with ResizeObserver, which jsdom lacks.
globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver

// jsdom does not implement scrolling; AppLayout scrolls to the top on every navigation.
window.scrollTo = () => {}
