import { useCallback } from 'react'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLES } from '../../../../../auth/roles'
import { useAsync } from '../../../../../hooks/useAsync'
import { DEFAULT_CAPACITY } from '../../../../../lib/constants'
import { RoleDashboard } from '../../RoleDashboard'

export const AgencyDashboard = () => {
  const { user } = useAuth()
  const isProjectManager = [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT].includes(user.role)
  const assignments = useAsync(useCallback(
    () => authService.listMyAssignments(),
    [],
  ))
  const assignmentRows = assignments.data?.assignments ?? []
  const additionalStats = isProjectManager && !assignments.error
    ? [
      { id: 'employees-held', label: 'Employees held', value: assignmentRows.length, icon: 'users', tone: 'primary' },
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
      scope="agency"
      additionalStats={additionalStats}
      additionalStatsLoading={assignments.isLoading}
      additionalStatsError={isProjectManager ? assignments.error : null}
      onRetryAdditionalStats={isProjectManager ? assignments.reload : undefined}
      ticketsTitle={isProjectManager ? 'Agency projects' : 'Agency directory'}
      showAgency={false}
      subtitle="Track project requests, the employees you hold, and scheduled work."
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
            label: 'Agency projects',
            description: user.role === ROLES.AGENCY_OWNER
              ? 'Review agency projects and their daily schedules'
              : 'Track your project assignments',
          },
          { to: '/agency/employees', icon: 'users', label: 'My employees', description: 'Employees you hold and their weekly hours' },
        ]
        : [{ to: '/agency/directory', icon: 'users', label: 'Agency directory', description: 'View people in your agency' }]}
    />
  )
}
