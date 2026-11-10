import { useCallback } from 'react'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLES } from '../../../../../auth/roles'
import { useAsync } from '../../../../../hooks/useAsync'
import { RoleDashboard } from '../../RoleDashboard'

export const AgencyDashboard = () => {
  const { user } = useAuth()
  const isProjectManager = [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT].includes(user.role)
  const assignments = useAsync(useCallback(
    () => authService.listMyAssignments(),
    [],
  ))
  const assignmentRows = assignments.data?.assignments ?? []
  const additionalStats = user.role === ROLES.AGENCY_CLIENT && !assignments.error
    ? [
      { id: 'employees-held', label: 'Employees held', value: assignmentRows.length, icon: 'users', tone: 'primary' },
      {
        id: 'hours-remaining',
        label: 'Remaining hours this week',
        value: `${assignmentRows.reduce((total, item) => total + Number(item.weekly_usage?.remaining ?? 36), 0).toFixed(2)} h`,
        icon: 'clock',
        tone: 'success',
      },
    ]
    : []

  return (
    <RoleDashboard
      scope="agency"
      additionalStats={additionalStats}
      additionalStatsLoading={assignments.isLoading}
      additionalStatsError={user.role === ROLES.AGENCY_CLIENT ? assignments.error : null}
      onRetryAdditionalStats={user.role === ROLES.AGENCY_CLIENT ? assignments.reload : undefined}
      ticketsTitle={isProjectManager ? 'Agency projects' : 'Agency directory'}
      showAgency={false}
      subtitle="Track project requests, employee assignments, and scheduled work."
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
          { to: '/agency/employees', icon: 'users', label: 'My employees', description: 'Hold employees and track weekly hours' },
        ]
        : [{ to: '/agency/directory', icon: 'users', label: 'Agency directory', description: 'View people in your agency' }]}
    />
  )
}
