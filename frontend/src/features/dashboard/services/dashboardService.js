import { ROLES } from '../../../auth/roles'
import { authService } from '../../../auth/authService'

const RECENT_AGENCY_PROJECTS = 5
const MAX_PAGE_SIZE = 100

// Resolves to { projects, summary }. `summary` (whole-agency counts) is only provided
// for agency roles; other roles derive counts from the projects they receive.
async function loadWorkItems(user, { limit = MAX_PAGE_SIZE } = {}) {
  switch (user.role) {
    case ROLES.ADMIN: {
      const { projects } = await authService.listAdminProjects({ perPage: 100 })
      return { projects, summary: null }
    }
    case ROLES.AGENCY_OWNER:
    case ROLES.AGENCY_CLIENT: {
      const { projects, summary } = await authService.listAgencyProjects({ perPage: limit })
      return { projects, summary }
    }
    case ROLES.EMPLOYEE: {
      const { projects } = await authService.listMyWork()
      return { projects, summary: null }
    }
    default:
      return { projects: null, summary: null }
  }
}

function getProjectStats(projects, summary) {
  if (summary) {
    return [
      { id: 'projects', label: 'Projects', value: summary.total, icon: 'ticket', tone: 'primary' },
      { id: 'scheduled', label: 'Scheduled', value: summary.scheduled, icon: 'clock', tone: 'info' },
      { id: 'in-progress', label: 'In progress', value: summary.in_progress, icon: 'queue', tone: 'warning' },
      { id: 'completed', label: 'Completed', value: summary.completed, icon: 'check', tone: 'success' },
    ]
  }
  if (!projects) return []

  const count = (status) => projects.filter((project) => project.status === status).length

  return [
    { id: 'projects', label: 'Projects shown', value: projects.length, icon: 'ticket', tone: 'primary' },
    { id: 'scheduled', label: 'Scheduled', value: count('scheduled'), icon: 'clock', tone: 'info' },
    { id: 'in-progress', label: 'In progress', value: count('in_progress'), icon: 'queue', tone: 'warning' },
    { id: 'completed', label: 'Completed', value: count('completed'), icon: 'check', tone: 'success' },
  ]
}

function scopeProjects(projects, { scope, user }) {
  return projects.filter((project) => {
    if (scope === 'mine') return String(project.employee?.id) === String(user.id)
    if (scope === 'queue') return project.status !== 'completed'
    if (scope === 'qa') return false
    return true
  })
}

export const dashboardService = {
  getStats: getProjectStats,
  // Full scoped list (up to 100 agency projects), used by the queue pages.
  async getTickets({ scope = 'all', user } = {}) {
    const { projects } = await loadWorkItems(user)
    if (!projects) return []
    return scopeProjects(projects, { scope, user })
  },
  // Dashboard data: the recent projects plus (for agency roles) server-side totals.
  async getDashboard({ scope = 'all', user } = {}) {
    const { projects, summary } = await loadWorkItems(user, { limit: RECENT_AGENCY_PROJECTS })
    return { projects: projects ? scopeProjects(projects, { scope, user }) : [], summary }
  },
}
