import { ROLES } from '../auth/roles'
import { authService } from '../auth/authService'

async function loadWorkItems(user) {
  switch (user.role) {
    case ROLES.ADMIN: {
      const { projects } = await authService.listAdminProjects({ perPage: 100 })
      return projects
    }
    case ROLES.AGENCY_OWNER:
    case ROLES.AGENCY_CLIENT: {
      const { projects } = await authService.listAgencyProjects()
      return projects
    }
    case ROLES.EMPLOYEE:
    case ROLES.AGENCY_EMPLOYEE: {
      const { projects } = await authService.listMyWork()
      return projects
    }
    default:
      return null
  }
}

function getProjectStats(projects) {
  if (!projects) return []

  const count = (status) => projects.filter((project) => project.status === status).length

  return [
    { id: 'projects', label: 'Projects shown', value: projects.length, icon: 'ticket', tone: 'primary' },
    { id: 'scheduled', label: 'Scheduled', value: count('scheduled'), icon: 'clock', tone: 'info' },
    { id: 'in-progress', label: 'In progress', value: count('in_progress'), icon: 'queue', tone: 'warning' },
    { id: 'completed', label: 'Completed', value: count('completed'), icon: 'check', tone: 'success' },
  ]
}

export const dashboardService = {
  getStats: getProjectStats,
  async getTickets({ scope = 'all', user } = {}) {
    const projects = await loadWorkItems(user)
    if (!projects) return []

    const scoped = projects.filter((project) => {
      if (scope === 'mine') return String(project.employee?.id) === String(user.id)
      if (scope === 'queue') return project.status !== 'completed'
      if (scope === 'qa') return false
      if (scope === 'agency' && user.role === ROLES.AGENCY_EMPLOYEE) {
        return project.agency_id === user.agency_id
      }
      return true
    })
    return scoped
  },
}
