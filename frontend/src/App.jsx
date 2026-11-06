import { Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { AppLayout, HomeRedirect, ProtectedRoute, PublicOnlyRoute, RoleRoute } from './auth/RouteGuards'
import { ROLES } from './auth/roles'
import { DashboardHome } from './pages/dashboard/DashboardHome'
import { PlaceholderPage } from './pages/dashboard/PlaceholderPage'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { AdminCreateUserPage } from './pages/admin/AdminCreateUserPage'
import { NotFoundPage, UnauthorizedPage } from './pages/dashboard/StatusPages'
import './App.css'

function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route element={<PublicOnlyRoute />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        </Route>
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route element={<ProtectedRoute />}>
          <Route path="/unauthorized" element={<UnauthorizedPage />} />
          <Route element={<AppLayout />}>
            <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN]} />}>
              <Route path="/admin/dashboard" element={<DashboardHome />} />
              <Route path="/admin/users/new" element={<AdminCreateUserPage />} />
              <Route path="/admin/settings" element={<PlaceholderPage title="Settings" />} />
            </Route>
            <Route element={<RoleRoute allowedRoles={[ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
              <Route path="/team/queue" element={<PlaceholderPage title="Team Queue" />} />
            </Route>
            <Route element={<RoleRoute allowedRoles={[ROLES.EMPLOYEE, ROLES.TEAM_LEAD, ROLES.ADMIN]} />}>
              <Route path="/my-tickets" element={<PlaceholderPage title="My Tickets" />} />
            </Route>
            <Route element={<RoleRoute allowedRoles={[ROLES.QA, ROLES.ADMIN]} />}>
              <Route path="/qa/queue" element={<PlaceholderPage title="QA Queue" />} />
            </Route>
            <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_MEMBER]} />}>
              <Route path="/agency/dashboard" element={<PlaceholderPage title="Agency Dashboard" />} />
            </Route>
          </Route>
        </Route>
        <Route path="/404" element={<NotFoundPage />} />
        <Route path="/" element={<HomeRedirect />} />
        <Route path="*" element={<HomeRedirect />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
