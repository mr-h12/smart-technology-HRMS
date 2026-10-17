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
const EmployeeRoute = page(() => import('@/pages/employees'), 'EmployeeRoute')
const SettingsPage = page(() => import('@/pages/settings'), 'SettingsPage')
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
        <Route path="employees/new" element={<EmployeeRoute />} />
        <Route path="employees/:id" element={<EmployeeRoute />} />
        <Route path="settings" element={<SettingsPage />} />
        {/* old links, kept for one release */}
        <Route path="sites" element={<Navigate to="/settings?tab=sites" replace />} />
        <Route path="residences" element={<Navigate to="/settings?tab=residences" replace />} />
        <Route path="fares" element={<Navigate to="/settings?tab=fares" replace />} />
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
