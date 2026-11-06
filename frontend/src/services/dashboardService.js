import { MOCK_ACTIVITY, MOCK_STATS, MOCK_TICKETS } from '../data/mockDashboard'
import { ROLES } from '../auth/roles'

// API-ready service layer. Each function returns a Promise, so swapping the mock for
// `request('/v1/tickets?...')` later requires no changes in pages or components.
// Backend note: tenant scoping (agency_id) and role filtering must be enforced server-side.

const wait = (value, ms = 400) => new Promise((resolve) => setTimeout(() => resolve(value), ms))

const STATS_BY_ROLE = {
  [ROLES.ADMIN]: MOCK_STATS.admin,
  [ROLES.TEAM_LEAD]: MOCK_STATS.team,
  [ROLES.EMPLOYEE]: MOCK_STATS.team,
  [ROLES.QA]: MOCK_STATS.team,
  [ROLES.AGENCY_OWNER]: MOCK_STATS.agency,
  [ROLES.AGENCY_MEMBER]: MOCK_STATS.agency,
}

const SCOPES = {
  all: () => true,
  queue: (ticket) => ticket.status !== 'Resolved' && ticket.status !== 'Closed',
  mine: (ticket) => ticket.assignee === 'Aisha Khan',
  qa: (ticket) => ticket.status === 'QA Review',
  agency: (ticket) => ticket.agency === 'Northwind Digital',
}

export const dashboardService = {
  getStats: (role) => wait(STATS_BY_ROLE[role] ?? MOCK_STATS.team),
  getTickets: ({ scope = 'all', limit } = {}) => {
    const filtered = MOCK_TICKETS.filter(SCOPES[scope] ?? SCOPES.all)
    return wait(limit ? filtered.slice(0, limit) : filtered)
  },
  getActivity: () => wait(MOCK_ACTIVITY, 300),
}
