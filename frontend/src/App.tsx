import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, homePath } from '@/components/layout/AppLayout'
import { AuthProvider, RequireAuth, useAuth } from '@/lib/auth'
import { AuditPage } from '@/pages/audit'
import { AccountsPage, RolesPage } from '@/pages/console'
import { EmployeeFormPage, EmployeesPage } from '@/pages/employees'
import { LoginPage } from '@/pages/login'
import { FaresPage, ResidencesPage, SitesPage } from '@/pages/sites'
import { NotFoundPage } from '@/pages/pages'

function Home() {
  const { can } = useAuth()
  return <Navigate to={homePath(can)} replace />
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
        <Route index element={<Home />} />
        <Route path="employees" element={<EmployeesPage />} />
        <Route path="employees/new" element={<EmployeeFormPage />} />
        <Route path="employees/:id" element={<EmployeeFormPage />} />
        <Route path="sites" element={<SitesPage />} />
        <Route path="residences" element={<ResidencesPage />} />
        <Route path="fares" element={<FaresPage />} />
        <Route path="console/accounts" element={<AccountsPage />} />
        <Route path="console/roles" element={<RolesPage />} />
        <Route path="console/audit" element={<AuditPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  )
}
