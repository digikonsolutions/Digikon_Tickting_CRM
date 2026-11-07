import { RoleDashboard } from '../../RoleDashboard'

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
