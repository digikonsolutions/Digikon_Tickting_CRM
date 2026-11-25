import { lazy, Suspense } from 'react'
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AppLayout, HomeRedirect, ProtectedRoute, PublicOnlyRoute, RoleRoute } from '../auth/RouteGuards'
import { isAgencyRole, ROLES } from '../auth/roles'
import { useAuth } from '../auth/useAuth'
import { PortalRoute } from '../features/auth/PortalRoute'
import { Loader } from '../components/ui'
import {
  AdminAgenciesPage,
  AdminAgencyDetailPage,
  AdminAssignmentsPage,
  AdminCreateEmployeePage,
  AdminCreateAgencyOwnerPage,
  AdminPlansPage,
  AdminEmployeeProfilePage,
  AdminProjectDetailPage,
  AdminProjectsPage,
  AdminRushRequestsPage,
  AdminTicketsPage,
  AdminUsersPage,
} from '../features/admin'
import {
  AgencyBrandingPage,
  AgencyProjectCreatePage,
  AgencyProjectsPage,
  AgencyDirectoryPage,
  AgencyEmployeePage,
  AgencyEmployeesPage,
  AgencyClientsPage,
  AgencyTicketsPage,
  ProjectDetailPage,
} from '../features/agency'
import { NotificationSettingsPage, NotificationsPage } from '../features/notifications'
import { EmployeeProjectPage, OverviewPage, ProjectsPage, RequestsPage, TicketsPage, TimePage, WeekPage } from '../features/employee'
import { AdminEmployeePage } from '../features/activity'
import { AdminTimelineReviewsPage, TimelineReviewPage } from '../features/timeline'
import { AdminHistoryPage } from '../features/history'
import { TimeSummaryPage } from '../features/time'
import { TicketPage } from '../features/tickets'
import { MyFeedbackPage } from '../features/feedback'
import { AgencyRecurringPage, AgencySupportHelpPage, AgencySupportDetailPage, AgencySupportListPage, AgencySupportNewPage, StaffQueuePage, StaffReportsPage, StaffSettingsPage, StaffTicketPage, StaffWorkloadPage } from '../features/support'
import { AdminReportsPage, AgencyReportsPage } from '../features/reports'
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
  NotFoundPage,
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
      <Route path="/a/:slug" element={<PortalRoute />}>
        <Route element={<PortalGate />}>
          <Route index element={<LoginPage />} />
          <Route path="login" element={<LoginPage />} />
          <Route path="forgot-password" element={<ForgotPasswordPage />} />
        </Route>
        <Route path="reset-password" element={<ResetPasswordPage />} />
      </Route>
      <Route element={<PublicOnlyRoute />}>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      </Route>
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/unauthorized" element={<UnauthorizedPage />} />
        <Route element={<AppLayout />}>
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/settings/notifications" element={<NotificationSettingsPage />} />
          <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN, ROLES.EMPLOYEE]} />}>
            <Route path="/staff/tickets" element={<StaffQueuePage />} />
            <Route path="/staff/tickets/:ref" element={<StaffTicketPage />} />
            <Route path="/staff/reports" element={<StaffReportsPage />} />
            <Route path="/staff/settings" element={<StaffSettingsPage />} />
            <Route path="/staff/workload" element={<StaffWorkloadPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN, ROLES.EMPLOYEE, ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/tickets/:key" element={<TicketPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.ADMIN]} />}>
            <Route path="/admin/dashboard" element={<AdminDashboard />} />
            <Route path="/admin/projects/:id" element={<AdminProjectDetailPage />} />
            <Route path="/admin/projects" element={<AdminProjectsPage />} />
            <Route path="/admin/tickets" element={<AdminTicketsPage />} />
            <Route path="/admin/reports" element={<AdminReportsPage />} />
            <Route path="/admin/assignments" element={<AdminAssignmentsPage />} />
            <Route path="/admin/rush-requests" element={<AdminRushRequestsPage />} />
            <Route path="/admin/time" element={<TimeSummaryPage scope="admin" />} />
            <Route path="/admin/employees" element={<AdminUsersPage key={ROLES.EMPLOYEE} role={ROLES.EMPLOYEE} title="Employees" createTo="/admin/employees/new" createLabel="Create employee" />} />
            <Route path="/admin/employees/new" element={<AdminCreateEmployeePage />} />
            <Route path="/admin/employees/:id" element={<AdminEmployeeProfilePage />} />
            <Route path="/admin/employees/:id/activity" element={<AdminEmployeePage />} />
            <Route path="/admin/history" element={<AdminHistoryPage />} />
            <Route path="/admin/agencies" element={<AdminAgenciesPage />} />
            <Route path="/admin/agencies/:id" element={<AdminAgencyDetailPage />} />
            <Route path="/admin/agency-owners" element={<AdminUsersPage key={ROLES.AGENCY_OWNER} role={ROLES.AGENCY_OWNER} title="Agency owners" createTo="/admin/agency-owners/new" createLabel="Create agency owner" />} />
            <Route path="/admin/agency-owners/new" element={<AdminCreateAgencyOwnerPage />} />
            <Route path="/admin/plans" element={<AdminPlansPage />} />
            <Route path="/admin/timeline-reviews" element={<AdminTimelineReviewsPage />} />
            <Route path="/admin/timeline-reviews/:id" element={<TimelineReviewPage />} />
            <Route path="/admin/agency-clients" element={<AdminUsersPage key={ROLES.AGENCY_CLIENT} role={ROLES.AGENCY_CLIENT} title="Agency clients" />} />
            <Route path="/admin/users" element={<Navigate to="/admin/employees" replace />} />
            <Route path="/admin/users/new" element={<Navigate to="/admin/employees/new" replace />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.EMPLOYEE]} />}>
            <Route path="/my-work" element={<InternalEmployeeWorkPage />}>
              <Route index element={<OverviewPage />} />
              <Route path="tickets" element={<TicketsPage />} />
              <Route path="projects" element={<ProjectsPage />} />
              <Route path="projects/:id" element={<EmployeeProjectPage />} />
              <Route path="requests" element={<RequestsPage />} />
              <Route path="week" element={<WeekPage />} />
              <Route path="time" element={<TimePage />} />
            </Route>
            <Route path="/my-work/timeline/:id" element={<TimelineReviewPage />} />
            <Route path="/my-feedback" element={<MyFeedbackPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_MEMBER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/dashboard" element={<AgencyDashboard />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
            <Route path="/agency/clients" element={<AgencyClientsPage />} />
            <Route path="/agency/users" element={<Navigate to="/agency/clients" replace />} />
            <Route path="/agency/branding" element={<AgencyBrandingPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER]} />}>
            <Route path="/agency/employees/:id/activity" element={<AdminEmployeePage scope="agency" />} />
            <Route path="/agency/time" element={<TimeSummaryPage scope="agency" />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/reports" element={<AgencyReportsPage />} />
            <Route path="/agency/projects" element={<AgencyProjectsPage />} />
            <Route path="/agency/projects/new" element={<AgencyProjectCreatePage />} />
            <Route path="/agency/projects/:id" element={<ProjectDetailPage />} />
            <Route path="/agency/tickets" element={<AgencyTicketsPage />} />
            <Route path="/agency/support" element={<AgencySupportListPage />} />
            <Route path="/agency/support/help" element={<AgencySupportHelpPage />} />
            <Route path="/agency/support/new" element={<AgencySupportNewPage />} />
            <Route path="/agency/support/recurring" element={<AgencyRecurringPage />} />
            <Route path="/agency/support/:ref" element={<AgencySupportDetailPage />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT]} />}>
            <Route path="/agency/employees" element={<AgencyEmployeesPage />} />
            <Route path="/agency/employees/:assignmentId" element={<AgencyEmployeePage />} />
            <Route path="/agency/queue" element={<Navigate to="/agency/employees?tab=rush" replace />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_CLIENT]} />}>
            {/* The client's old address for the project list: same page as the owner's. */}
            <Route path="/agency/my-projects" element={<Navigate to="/agency/projects" replace />} />
          </Route>
          <Route element={<RoleRoute allowedRoles={[ROLES.AGENCY_MEMBER]} />}>
            <Route path="/agency/directory" element={<AgencyDirectoryPage />} />
          </Route>
        </Route>
      </Route>
      <Route path="/404" element={<NotFoundPage />} />
      <Route path="/" element={<HomeRedirect />} />
      <Route path="*" element={<UnknownRoute />} />
    </Routes>
  )
}

// An agency portal's sign-in pages: agency users who are already signed in go home, while platform staff may open the page
// (to check an agency's branding) without being bounced to their own dashboard.
function PortalGate() {
  const { user } = useAuth()
  return user && isAgencyRole(user.role) ? <PublicOnlyRoute /> : <Outlet />
}

// Signed-in users get a real 404; signed-out users are sent to sign in as before.
function UnknownRoute() {
  const { user, isLoading } = useAuth()
  if (isLoading || !user) return <HomeRedirect />
  return <NotFoundPage />
}
