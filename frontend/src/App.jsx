import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { AppLayout, ProtectedRoute, PublicOnlyRoute, RoleRoute } from './auth/RouteGuards'
import { ROLES } from './auth/roles'
import { DashboardHome } from './pages/dashboard/DashboardHome'
import { PlaceholderPage } from './pages/dashboard/PlaceholderPage'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { SignupPage } from './pages/auth/SignupPage'
import './App.css'

function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route element={<PublicOnlyRoute />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/signup" element={<SignupPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
        </Route>
        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            <Route path="/dashboard" element={<DashboardHome />} />
            <Route path="/clients" element={<PlaceholderPage title="Clients" />} />
            <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN, ROLES.AGENCY_OWNER]} />}>
              <Route path="/team" element={<PlaceholderPage title="Team" />} />
              <Route path="/reports" element={<PlaceholderPage title="Reports" />} />
            </Route>
            <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN]} />}>
              <Route path="/settings" element={<PlaceholderPage title="Workspace settings" />} />
            </Route>
          </Route>
        </Route>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
