import { DirectionProvider } from '@radix-ui/react-direction'
import { QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { useTranslation } from 'react-i18next'
import App from './App'
import './i18n'
import './index.css'
import { queryClient } from './lib/query'

function Root() {
  const { i18n } = useTranslation()
  return (
    <DirectionProvider dir={i18n.language === 'ar' ? 'rtl' : 'ltr'}>
      <App />
    </DirectionProvider>
  )
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <Root />
    </QueryClientProvider>
  </StrictMode>,
)
