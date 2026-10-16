import { useState } from 'react'

type Theme = 'light' | 'dark'

export function useTheme() {
  const [theme, set] = useState<Theme>(() => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'))
  const setTheme = (t: Theme) => {
    set(t)
    document.documentElement.classList.toggle('dark', t === 'dark')
    try {
      localStorage.setItem('hrms-theme', t)
    } catch {
      /* storage unavailable: theme still applies for this session */
    }
  }
  return { theme, setTheme }
}
