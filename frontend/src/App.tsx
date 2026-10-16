import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { EmployeeFormPage, EmployeesPage } from '@/pages/employees'
import { NotFoundPage, TitlePage } from '@/pages/pages'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<AppLayout />}>
          <Route index element={<Navigate to="/employees" replace />} />
          <Route path="employees" element={<EmployeesPage />} />
          <Route path="employees/new" element={<EmployeeFormPage />} />
          <Route path="employees/:id" element={<EmployeeFormPage />} />
          {/* ponytail: placeholder, Task 6 replaces it */}
          <Route path="sites" element={<TitlePage titleKey="page.sites" />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
