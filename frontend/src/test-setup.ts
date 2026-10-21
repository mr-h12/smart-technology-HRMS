import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

afterEach(cleanup)

// Radix Popper (dropdown menus, tooltips) measures with ResizeObserver, which jsdom lacks.
globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver

// jsdom does not implement scrolling; AppLayout scrolls to the top on every navigation.
window.scrollTo = () => {}
