import { ROLES } from '../../../auth/roles'
import { authService } from '../../../auth/authService'

const RECENT_AGENCY_PROJECTS = 5

function getProjectStats(summary) {
  if (!summary) return []
  return [
    { id: 'projects', label: 'Projects', value: summary.total, icon: 'ticket', tone: 'primary' },
    { id: 'scheduled', label: 'Scheduled', value: summary.scheduled, icon: 'clock', tone: 'info' },
    { id: 'in-progress', label: 'In progress', value: summary.in_progress, icon: 'queue', tone: 'warning' },
    { id: 'completed', label: 'Completed', value: summary.completed, icon: 'check', tone: 'success' },
  ]
}
export const dashboardService = {
  getStats: getProjectStats,
  // Agency dashboard data: the recent projects plus server-side totals for the whole agency.
  async getDashboard({ user } = {}) {
    if (![ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT].includes(user?.role)) return { projects: [], summary: null }
    const { projects, summary } = await authService.listAgencyProjects({ perPage: RECENT_AGENCY_PROJECTS })
    return { projects, summary }
  },
}
