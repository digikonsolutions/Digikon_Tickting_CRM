import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, HomeRedirect, ProtectedRoute, PublicOnlyRoute, RoleRoute } from '../auth/RouteGuards'
import { ROLES } from '../auth/roles'
import { Loader } from '../components/ui'
import { AdminCreateUserPage, AdminProjectsPage, AdminUsersPage } from '../features/admin'
import {
  AgencyClientProjectsPage,
  AgencyDirectoryPage,
  AgencyEmployeesPage,
  AgencyOwnerProjectsPage,
  AgencyUsersPage,
} from '../features/agency'
import {
  ForgotPasswordPage,
  LoginPage,
  ProfilePage,
  ResetPasswordPage,
} from '../features/auth'
import {
  AdminDashboard,
  AgencyDashboard,
  InternalEmployeeWorkPage,
  MyTicketsPage,
  NotFoundPage,
  PlaceholderPage,
  QaQueuePage,
  TeamQueuePage,
  UnauthorizedPage,
} from '../features/dashboard'

const DevUiPage = import.meta.env.DEV
  ? lazy(() => import('../features/dev').then((module) => ({ default: module.UiShowcasePage })))
  : null

export function AppRoutes() {
  return (
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
            <Route path="/admin/users/all" element={<AdminUsersPage key="all" title="All accounts" />} />
            <Route path="/admin/employees" element={<AdminUsersPage key={ROLES.EMPLOYEE} role={ROLES.EMPLOYEE} title="Employees" createTo="/admin/employees/new" createLabel="Create employee" />} />
            <Route path="/admin/employees/new" element={<AdminCreateUserPage role={ROLES.EMPLOYEE} />} />
            <Route path="/admin/agency-owners" element={<AdminUsersPage key={ROLES.AGENCY_OWNER} role={ROLES.AGENCY_OWNER} title="Agency owners" createTo="/admin/agency-owners/new" createLabel="Create agency owner" />} />
            <Route path="/admin/agency-owners/new" element={<AdminCreateUserPage role={ROLES.AGENCY_OWNER} />} />
            <Route path="/admin/agency-clients" element={<AdminUsersPage key={ROLES.AGENCY_CLIENT} role={ROLES.AGENCY_CLIENT} title="Agency clients" />} />
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
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_MEMBER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/dashboard" element={<AgencyDashboard />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
            <Route path="/agency/users" element={<AgencyUsersPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
            <Route path="/agency/projects" element={<AgencyOwnerProjectsPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/employees" element={<AgencyEmployeesPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/my-projects" element={<AgencyClientProjectsPage />} />
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
  )
}
