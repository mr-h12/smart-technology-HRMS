import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { EmployeeFormPage, EmployeesPage } from '@/pages/employees'
import { FaresPage, ResidencesPage, SitesPage } from '@/pages/sites'
import { NotFoundPage } from '@/pages/pages'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<AppLayout />}>
          <Route index element={<Navigate to="/employees" replace />} />
          <Route path="employees" element={<EmployeesPage />} />
          <Route path="employees/new" element={<EmployeeFormPage />} />
          <Route path="employees/:id" element={<EmployeeFormPage />} />
          <Route path="sites" element={<SitesPage />} />
          <Route path="residences" element={<ResidencesPage />} />
          <Route path="fares" element={<FaresPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
