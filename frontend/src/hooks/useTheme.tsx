import { useSyncExternalStore } from 'react'

type Theme = 'light' | 'dark'

// One theme for the whole app: the Toaster, the header toggle and the profile menu read the same store.
let current: Theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light'
const listeners = new Set<() => void>()

const subscribe = (listener: () => void) => {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

const setTheme = (t: Theme) => {
  current = t
  document.documentElement.classList.toggle('dark', t === 'dark')
  try {
    localStorage.setItem('hrms-theme', t)
  } catch {
    /* storage unavailable: theme still applies for this session */
  }
  listeners.forEach((l) => l())
}

export function useTheme() {
  const theme = useSyncExternalStore(subscribe, () => current)
  return { theme, setTheme }
}
