import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { NotFoundPage, TitlePage } from '@/pages/pages'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<AppLayout />}>
          <Route index element={<Navigate to="/employees" replace />} />
          {/* ponytail: placeholders, Tasks 5 and 6 replace them with the real pages */}
          <Route path="employees" element={<TitlePage titleKey="page.employees" />} />
          <Route path="sites" element={<TitlePage titleKey="page.sites" />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
