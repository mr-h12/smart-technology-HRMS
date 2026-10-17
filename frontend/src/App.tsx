import { lazy, type ComponentType } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, homePath } from '@/components/layout/AppLayout'
import { AuthProvider, RequireAuth, useAuth } from '@/lib/auth'
import { LoginPage } from '@/pages/login'

// Every page but login loads on demand, so the first download stays small.
const page = <K extends string>(load: () => Promise<Record<K, ComponentType>>, name: K) => lazy(() => load().then((m) => ({ default: m[name] })))
const AuditPage = page(() => import('@/pages/audit'), 'AuditPage')
const AccountsPage = page(() => import('@/pages/console'), 'AccountsPage')
const RolesPage = page(() => import('@/pages/console'), 'RolesPage')
const EmployeesPage = page(() => import('@/pages/employees'), 'EmployeesPage')
const EmployeeFormPage = page(() => import('@/pages/employees'), 'EmployeeFormPage')
const SitesPage = page(() => import('@/pages/sites'), 'SitesPage')
const ResidencesPage = page(() => import('@/pages/sites'), 'ResidencesPage')
const FaresPage = page(() => import('@/pages/sites'), 'FaresPage')
const NotFoundPage = page(() => import('@/pages/pages'), 'NotFoundPage')

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
