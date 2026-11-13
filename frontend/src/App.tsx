import { lazy, type ComponentType, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, homePath, navAbilities, navRequirement } from '@/components/layout/AppLayout'
import { AuthProvider, RequireAuth, useAuth } from '@/lib/auth'
import { LoginPage } from '@/pages/login'

// Every page but login loads on demand, so the first download stays small.
const page = <K extends string>(load: () => Promise<Record<K, ComponentType>>, name: K) => lazy(() => load().then((m) => ({ default: m[name] })))
const AttendancePage = page(() => import('@/pages/attendance'), 'AttendancePage')
const AuditPage = page(() => import('@/pages/audit'), 'AuditPage')
const AccountsPage = page(() => import('@/pages/console'), 'AccountsPage')
const RolesPage = page(() => import('@/pages/console'), 'RolesPage')
const EmployeesPage = page(() => import('@/pages/employees'), 'EmployeesPage')
const EmployeeRoute = page(() => import('@/pages/employees'), 'EmployeeRoute')
const LogsPage = page(() => import('@/pages/logs'), 'LogsPage')
const LeavePage = page(() => import('@/pages/leave'), 'LeavePage')
const SettingsPage = page(() => import('@/pages/settings'), 'SettingsPage')
const SiteDayPage = page(() => import('@/pages/site-day'), 'SiteDayPage')
const AttendanceRequestsPage = page(() => import('@/pages/attendance-requests'), 'AttendanceRequestsPage')
const WageProposalsPage = page(() => import('@/pages/wage-proposals'), 'WageProposalsPage')
const TeamFaresPage = page(() => import('@/pages/team-fares'), 'TeamFaresPage')
const PayrollPage = page(() => import('@/pages/payroll'), 'PayrollPage')
const FormApprovalsPage = page(() => import('@/pages/hr-forms'), 'FormApprovalsPage')
const CandidateFormsPage = page(() => import('@/pages/manual-hr-forms'), 'CandidateFormsPage')
const SignaturePage = page(() => import('@/pages/hr-forms'), 'SignaturePage')
const NotFoundPage = page(() => import('@/pages/pages'), 'NotFoundPage')

function Home() {
  const { can } = useAuth()
  return <Navigate to={homePath(can)} replace />
}

/** A page opened by URL that the user may not use is not found, like a missing one. Inside RequireAuth, so `me` is loaded. */
function Gate({ abilities, requires, children }: { abilities: string[]; requires?: string; children: ReactNode }) {
  const { can } = useAuth()
  return abilities.some(can) && (!requires || can(requires)) ? children : <NotFoundPage />
}
const nav = (path: string, page: ReactNode) => <Gate abilities={navAbilities(path)} requires={navRequirement(path)}>{page}</Gate>

export function AppRoutes() {
  return (
    <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
        <Route index element={<Home />} />
        <Route path="employees" element={<EmployeesPage />} />
        <Route path="employees/new" element={<Gate abilities={['employee.create']}><EmployeeRoute /></Gate>} />
        <Route path="employees/:id" element={<EmployeeRoute />} />
        <Route path="attendance" element={<AttendancePage />} />
        <Route path="leave" element={<LeavePage />} />
        <Route path="site-day" element={<SiteDayPage />} />
        <Route path="attendance-requests" element={nav('/attendance-requests', <AttendanceRequestsPage />)} />
        <Route path="wage-proposals" element={nav('/wage-proposals', <WageProposalsPage />)} />
        <Route path="hr-form-approvals" element={nav('/hr-form-approvals', <FormApprovalsPage />)} />
        <Route path="hr-forms/job-offer" element={nav('/hr-forms/job-offer', <CandidateFormsPage />)} />
        <Route path="my-signature" element={nav('/my-signature', <SignaturePage />)} />
        <Route path="team-fares" element={<TeamFaresPage />} />
        <Route path="payroll" element={nav('/payroll', <PayrollPage />)} />
        <Route path="settings" element={nav('/settings', <SettingsPage />)} />
        {/* old links, kept for one release */}
        <Route path="sites" element={<Navigate to="/settings?tab=sites" replace />} />
        <Route path="residences" element={<Navigate to="/settings?tab=residences" replace />} />
        <Route path="fares" element={<Navigate to="/settings?tab=fares" replace />} />
        <Route path="console/accounts" element={nav('/console/accounts', <AccountsPage />)} />
        <Route path="console/roles" element={nav('/console/roles', <RolesPage />)} />
        <Route path="console/audit" element={nav('/console/audit', <AuditPage />)} />
        <Route path="console/logs" element={nav('/console/logs', <LogsPage />)} />
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
