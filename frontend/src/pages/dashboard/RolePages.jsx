import { useAuth } from '../../auth/useAuth'
import { ROLES } from '../../auth/roles'
import { RoleDashboard } from './RoleDashboard'
import { TicketsView } from './TicketsView'

export const AdminDashboard = () => (
  <RoleDashboard
    scope="all"
    ticketsTitle="Recent projects"
    subtitle="Review real project assignments and progress across the workspace."
    ticketsLink="/admin/projects"
    actions={[
      { to: '/admin/employees/new', icon: 'userPlus', label: 'Create employee', description: 'Invite an employee to the workspace' },
      { to: '/admin/agency-owners/new', icon: 'userPlus', label: 'Create agency owner', description: 'Create an agency and invite its owner' },
      { to: '/admin/projects', icon: 'ticket', label: 'All projects', description: 'View assignments and schedules across agencies' },
    ]}
  />
)

export const AgencyDashboard = () => {
  const { user } = useAuth()
  const isProjectManager = [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT].includes(user.role)
  const isAgencyEmployee = user.role === ROLES.AGENCY_EMPLOYEE

  return (
    <RoleDashboard
      scope="agency"
      ticketsTitle={isProjectManager ? 'Agency projects' : isAgencyEmployee ? 'Assigned work' : 'Agency directory'}
      showAgency={false}
      subtitle="Track project requests, employee assignments, and scheduled work."
      ticketsLink={user.role === ROLES.AGENCY_OWNER
        ? '/agency/projects'
        : user.role === ROLES.AGENCY_CLIENT
          ? '/agency/my-projects'
          : isAgencyEmployee ? '/agency/my-work' : '/agency/directory'}
      actions={isProjectManager
        ? [
          {
            to: user.role === ROLES.AGENCY_OWNER ? '/agency/projects' : '/agency/my-projects',
            icon: 'plus',
            label: user.role === ROLES.AGENCY_OWNER ? 'Create agency project' : 'Create project',
            description: user.role === ROLES.AGENCY_OWNER
              ? 'Assign work and manage all agency projects'
              : 'Schedule work with an available employee',
          },
          {
            to: user.role === ROLES.AGENCY_OWNER ? '/agency/projects' : '/agency/my-projects',
            icon: 'inbox',
            label: user.role === ROLES.AGENCY_OWNER ? 'Agency assignments' : 'My projects',
            description: user.role === ROLES.AGENCY_OWNER
              ? 'Review employee assignments and daily schedules'
              : 'Track your project assignments',
          },
          ...(user.role === ROLES.AGENCY_CLIENT
            ? [{ to: '/agency/employees', icon: 'users', label: 'View employees', description: 'See agency and shared employees' }]
            : []),
        ]
        : isAgencyEmployee
          ? [{ to: '/agency/my-work', icon: 'ticket', label: 'Assigned work', description: 'View your projects and update their status' }]
          : [{ to: '/agency/directory', icon: 'users', label: 'Agency directory', description: 'View people in your agency' }]}
    />
  )
}

export const TeamQueuePage = () => (
  <TicketsView eyebrow="Team lead" title="Team Queue" subtitle="Triage, assign and keep SLAs on track." scope="queue" />
)

export const MyTicketsPage = () => (
  <TicketsView eyebrow="My work" title="My Tickets" subtitle="Everything currently assigned to you." scope="mine" emptyText="You have no tickets assigned." />
)

export const QaQueuePage = () => (
  <TicketsView eyebrow="Quality assurance" title="QA Queue" subtitle="Review finished work before it goes back to the agency." scope="qa" statuses={['QA Review']} emptyText="Nothing waiting for review." />
)
