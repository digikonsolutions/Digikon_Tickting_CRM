import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
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
import { AdminCreateUserPage } from './pages/admin/AdminCreateUserPage'
import { AgencyUsersPage } from './pages/agency/AgencyUsersPage'
import { AgencyDirectoryPage } from './pages/agency/AgencyDirectoryPage'
import { AdminUsersPage } from './pages/admin/AdminUsersPage'
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
              <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN]} />}>
                <Route path="/admin/dashboard" element={<AdminDashboard />} />
                <Route path="/admin/users" element={<AdminUsersPage />} />
                <Route path="/admin/users/new" element={<AdminCreateUserPage />} />
                <Route path="/admin/settings" element={<PlaceholderPage title="Settings" />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
                <Route path="/team/queue" element={<TeamQueuePage />} />
              </Route>
              <Route element={<RoleRoute allowedRoles={[ROLES.EMPLOYEE, ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
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
              <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_MEMBER, ROLES.AGENCY_EMPLOYEE, ROLES.AGENCY_CLIENT]} />}>
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
