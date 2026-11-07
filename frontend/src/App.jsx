import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { AppLayout, HomeRedirect, ProtectedRoute, PublicOnlyRoute, RoleRoute } from './auth/RouteGuards'
import { ROLES } from './auth/roles'
import { Loader } from './components/ui'
import { ToastProvider } from './components/ui/Toast'
import { AdminDashboard, AgencyDashboard, MyTicketsPage, QaQueuePage, TeamQueuePage } from './pages/dashboard/RolePages'
import { PlaceholderPage } from './pages/dashboard/PlaceholderPage'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { ProfilePage } from './pages/auth/ProfilePage'
import { AdminCreateUserPage } from './pages/admin/AdminCreateUserPage'
import { AgencyUsersPage } from './pages/agency/AgencyUsersPage'
import { AgencyDirectoryPage } from './pages/agency/AgencyDirectoryPage'
import { AgencyEmployeesPage } from './pages/agency/AgencyEmployeesPage'
import { AdminUsersPage } from './pages/admin/AdminUsersPage'
import { AdminProjectsPage } from './pages/admin/AdminProjectsPage'
import { AgencyClientProjectsPage, AgencyOwnerProjectsPage } from './pages/agency/AgencyProjectsPage'
import { AgencyEmployeeWorkPage, InternalEmployeeWorkPage } from './pages/dashboard/MyWorkPage'
import { NotFoundPage, UnauthorizedPage } from './pages/dashboard/StatusPages'
import './App.css'

const DevUiPage = import.meta.env.DEV
  ? lazy(() => import('./pages/dev/UiShowcasePage').then((module) => ({ default: module.UiShowcasePage })))
  : null

function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <Routes>
          {import.meta.env.DEV && (
            <Route path="/dev/ui" element={<Suspense fallback={<Loader />}><DevUiPage /></Suspense>} />
          )}
          <Route element={<PublicOnlyRoute />}>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          </Route>
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route element={<ProtectedRoute />}>
            <Route path="/unauthorized" element={<UnauthorizedPage />} />
            <Route element={<AppLayout />}>
              <Route path="/profile" element={<ProfilePage />} />
              <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN]} />}>
                <Route path="/admin/dashboard" element={<AdminDashboard />} />
                <Route path="/admin/projects" element={<AdminProjectsPage />} />
                <Route path="/admin/users/all" element={<AdminUsersPage title="All accounts" />} />
                <Route path="/admin/employees" element={<AdminUsersPage role={ROLES.EMPLOYEE} title="Employees" createTo="/admin/employees/new" createLabel="Create employee" />} />
                <Route path="/admin/employees/new" element={<AdminCreateUserPage role={ROLES.EMPLOYEE} />} />
                <Route path="/admin/agency-owners" element={<AdminUsersPage role={ROLES.AGENCY_OWNER} title="Agency owners" createTo="/admin/agency-owners/new" createLabel="Create agency owner" />} />
                <Route path="/admin/agency-owners/new" element={<AdminCreateUserPage role={ROLES.AGENCY_OWNER} />} />
                <Route path="/admin/agency-clients" element={<AdminUsersPage role={ROLES.AGENCY_CLIENT} title="Agency clients" />} />
                <Route path="/admin/users" element={<Navigate to="/admin/employees" replace />} />
                <Route path="/admin/users/new" element={<Navigate to="/admin/employees/new" replace />} />
                <Route path="/admin/settings" element={<PlaceholderPage title="Settings" />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
                <Route path="/team/queue" element={<TeamQueuePage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.EMPLOYEE]} />}>
                <Route path="/my-work" element={<InternalEmployeeWorkPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
                <Route path="/my-tickets" element={<MyTicketsPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.QA, ROLES.ADMIN]} />}>
                <Route path="/qa/queue" element={<QaQueuePage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_MEMBER, ROLES.AGENCY_EMPLOYEE, ROLES.AGENCY_CLIENT]} />}>
                <Route path="/agency/dashboard" element={<AgencyDashboard />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
                <Route path="/agency/users" element={<AgencyUsersPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
                <Route path="/agency/projects" element={<AgencyOwnerProjectsPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_CLIENT]} />}>
                <Route path="/agency/employees" element={<AgencyEmployeesPage />} />
                <Route path="/agency/my-projects" element={<AgencyClientProjectsPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_EMPLOYEE]} />}>
                <Route path="/agency/my-work" element={<AgencyEmployeeWorkPage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_MEMBER, ROLES.AGENCY_CLIENT]} />}>
                <Route path="/agency/directory" element={<AgencyDirectoryPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="/404" element={<NotFoundPage />} />
          <Route path="/" element={<HomeRedirect />} />
          <Route path="*" element={<HomeRedirect />} />
        </Routes>
      </AuthProvider>
    </ToastProvider>
  )
}

export default App
