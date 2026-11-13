import { useCallback } from 'react'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLES, ROLE_LABELS } from '../../../../../auth/roles'
import { Link } from 'react-router-dom'
import { WelcomeBanner } from '../../../components'
import { useAsync } from '../../../../../hooks/useAsync'
import { DEFAULT_CAPACITY } from '../../../../../lib/constants'
import { getAgencyLabels } from '../../../../../lib/labels'
import { RoleDashboard } from '../../RoleDashboard'

// Legacy agency members only get a welcome card and the directory: they have no projects or holds.
function AgencyMemberDashboard() {
  const { user } = useAuth()
  const firstName = user.name?.split(' ')[0] ?? 'there'

  return (
    <section className="page dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Find the people in your agency.">
        <Link to="/agency/directory" className="ui-button ui-button-secondary ui-button-sm">Agency directory</Link>
      </WelcomeBanner>
    </section>
  )
}

function AgencyProjectsDashboard() {
  const { user } = useAuth()
  const isProjectManager = [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT].includes(user.role)
  const assignments = useAsync(useCallback(
    () => authService.listMyAssignments(),
    [],
  ))
  const assignmentRows = assignments.data?.assignments ?? []
  const additionalStats = isProjectManager && !assignments.error
    ? [
      { id: 'employees-held', label: getAgencyLabels(user.role).employeesHeld, value: assignmentRows.length, icon: 'users', tone: 'primary' },
      {
        id: 'hours-remaining',
        label: 'Remaining hours this week',
        value: `${assignmentRows.reduce((total, item) => total + Number(item.weekly_usage?.remaining ?? (item.capacity ?? DEFAULT_CAPACITY).weekly_hours), 0).toFixed(2)} h`,
        icon: 'clock',
        tone: 'success',
      },
    ]
    : []

  return (
    <RoleDashboard
      additionalStats={additionalStats}
      additionalStatsLoading={assignments.isLoading}
      additionalStatsError={isProjectManager ? assignments.error : null}
      onRetryAdditionalStats={isProjectManager ? assignments.reload : undefined}
      ticketsTitle={isProjectManager ? 'Agency projects' : 'Agency directory'}
      showAgency={false}
      subtitle="Track project requests, your specialists, and scheduled work."
      ticketsLink={user.role === ROLES.AGENCY_OWNER
        ? '/agency/projects'
        : user.role === ROLES.AGENCY_CLIENT
          ? '/agency/my-projects'
          : '/agency/directory'}
      actions={isProjectManager
        ? [
          {
            to: user.role === ROLES.AGENCY_OWNER ? '/agency/projects' : '/agency/my-projects',
            icon: 'plus',
            label: user.role === ROLES.AGENCY_OWNER ? 'Create agency project' : 'Create project',
            description: user.role === ROLES.AGENCY_OWNER
              ? 'Assign work and manage all agency projects'
              : 'Schedule work with one of your specialists',
          },
          {
            to: user.role === ROLES.AGENCY_OWNER ? '/agency/projects' : '/agency/my-projects',
            icon: 'inbox',
            label: 'Agency projects',
            description: user.role === ROLES.AGENCY_OWNER
              ? 'Review agency projects and their daily schedules'
              : 'Track your project assignments',
          },
          { to: '/agency/employees', icon: 'users', label: getAgencyLabels(user.role).myEmployees, description: 'Your specialists and their weekly hours' },
        ]
        : [{ to: '/agency/directory', icon: 'users', label: 'Agency directory', description: 'View people in your agency' }]}
    />
  )
}

export const AgencyDashboard = () => {
  const { user } = useAuth()
  return user.role === ROLES.AGENCY_MEMBER ? <AgencyMemberDashboard /> : <AgencyProjectsDashboard />
}
