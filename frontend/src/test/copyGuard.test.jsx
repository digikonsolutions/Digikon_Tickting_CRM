import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BrandingProvider } from '../branding/BrandingProvider'
import { ToastProvider } from '../components/ui'

// What an agency owner or client reads must never mention the platform, the admin side, other agencies or a shared pool.
const FORBIDDEN = /clienthub|administrator|\badmin\b|shared|another agency|other agency|\bplatform\b|specialist/i

const mocks = vi.hoisted(() => {
  const dashboard = {
    role: 'agency_owner', agency: { id: 1, name: 'Northwind' }, contract: null,
    limits: {
      employees: { used: 1, allowed: 1, remaining: 0, reached: true, nearly: true },
      hours: { used: 4, allowed: 40, remaining: 36, reached: false, nearly: false, period_start: '2026-11-01', period_end: '2026-11-30', burn_rate_per_day: 0.5, projected_run_out: null },
      open_projects: { used: 1, allowed: null, remaining: null, reached: false, nearly: false },
      client_accounts: { used: 1, allowed: null, remaining: null, reached: false, nearly: false },
    },
    weekly: { week_start: '2026-11-23', week_end: '2026-11-28', budget_per_employee: 36, remaining_hours: 30 },
    usage: { week_hours: 6, month_hours: 4, last_14_days: [] },
    employees: [{ id: 5, name: 'Sam Team', assignment_id: 3, since: '2026-11-01', used_hours: 6, budget_hours: 36, utilization_percent: 17, photo_url: null, headline: 'Builder', availability: 'available', top_skills: ['GoHighLevel'], days: [] }],
    attention: [], recent_projects: [],
    seats: { seats: 1, used: 1, remaining: 0, full: true, custom_plan: false, admin_assigned: true, can_add: false, reason: 'Employees are assigned by your account manager. Use “Request an employee” to ask for one.' },
    kpis: { open_projects: 1, tickets_in_progress: 1, tickets_waiting_on_you: 1, clients: 1 },
    needs_attention: [
      { type: 'tickets_waiting', severity: 'warning', count: 1, label: '1 ticket is waiting for your review or answer', action: { label: 'Open tickets', to: '/agency/tickets?waiting=you' } },
      { type: 'no_employee', severity: 'warning', count: 1, label: 'No employee is assigned to your agency yet', action: { label: 'Request an employee', request: 'employee' } },
    ],
    active_projects: [{ id: 2, title: 'Website', status: 'in_progress', client_name: 'Cara Client', employee: { id: 5, name: 'Sam Team' }, due_date: '2026-12-01', estimated_hours: 6, ticket_hours_total: 2, ticket_hours_done: 1, progress_percent: 50, health: 'on_track' }],
    activity: [{ key: 'e1', type: 'comment', text: 'Northwind Support commented on “Header”', at: '2026-11-23T06:00:00Z', to: null }],
  }
  const handlers = {
    isMockMode: false,
    getAgencyDashboard: () => Promise.resolve({ dashboard }),
    getAgencyEmployeesSummary: () => Promise.resolve({ seats: dashboard.seats, can_add: false, requests: [] }),
    listMyAssignments: () => Promise.resolve({ assignments: [{ id: 3, employee: { id: 5, name: 'Sam Team', email: 'sam@example.test' }, status: 'active', capacity: { weekly_hours: 36, daily_hours: 6 }, weekly_usage: { used: '6.00', days: [] } }] }),
    listAvailableEmployees: () => Promise.resolve({ employees: [{ id: 6, name: 'Pat Team', email: 'pat@example.test', role: 'team_member', headline: null, top_skills: [], availability: 'available' }] }),
    listAgencyDirectory: () => Promise.resolve({ users: [{ id: 5, name: 'Sam Team', email: 'sam@example.test', role: 'team_member', agency_name: 'Northwind', is_active: true }, { id: 9, name: 'Olive Owner', email: 'olive@example.test', role: 'agency_owner', agency_name: 'Northwind', is_active: true }] }),
    listAgencyClients: () => Promise.resolve({ clients: [{ id: 2, name: 'Cara Client', email: 'cara@example.test', status: 'active', is_active: true, projects_count: 1, last_activity_at: null, company_name: 'Cara Co', phone: null }], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, limit: { used: 1, allowed: 3, reached: false } }),
    listAgencyTickets: () => Promise.resolve({ tickets: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }),
    listAgencyProjects: () => Promise.resolve({ projects: [], pagination: { current_page: 1, last_page: 1, total: 0 }, summary: {} }),
    listAgencyRequests: () => Promise.resolve({ requests: [] }),
    getAgencyBranding: () => Promise.resolve({ branding: null }),
    agencyContractDocumentUrl: () => '#',
    requestAttachmentUrl: () => '#',
  }
  const user = { id: 9, name: 'Olive Owner', email: 'olive@example.test', role: 'agency_owner', agency_id: 1, agency_name: 'Northwind', agency: { slug: 'northwind', display_name: 'Northwind' }, branding: null }

  return { dashboard, handlers, user, current: { user } }
})

vi.mock('../auth/authService', () => ({
  authService: new Proxy({}, { get: (_target, name) => mocks.handlers[name] ?? (() => Promise.resolve({})) }),
  isKnownRole: () => true,
}))
vi.mock('../auth/useAuth', () => ({ useAuth: () => ({ user: mocks.current.user, isLoading: false, signOut: vi.fn(), updateUser: vi.fn(), updateBranding: vi.fn(), refreshSession: vi.fn() }) }))

async function visible(element) {
  const { container } = render(<MemoryRouter><BrandingProvider><ToastProvider>{element}</ToastProvider></BrandingProvider></MemoryRouter>)
  // Let the mocked requests resolve and the page settle.
  await waitFor(() => expect(container.textContent.length).toBeGreaterThan(40))
  await new Promise((resolve) => setTimeout(resolve, 80))
  return container.textContent
}

const PAGES = [
  ['dashboard (owner)', async () => (await import('../features/dashboard/pages/roles/agency/AgencyDashboard')).AgencyDashboard, 'agency_owner'],
  ['dashboard (client)', async () => (await import('../features/dashboard/pages/roles/agency/AgencyDashboard')).AgencyDashboard, 'agency_client'],
  ['employees (owner)', async () => (await import('../features/agency/pages/AgencyEmployeesPage')).AgencyEmployeesPage, 'agency_owner'],
  ['employees (client)', async () => (await import('../features/agency/pages/AgencyEmployeesPage')).AgencyEmployeesPage, 'agency_client'],
  ['directory', async () => (await import('../features/agency/pages/AgencyDirectoryPage')).AgencyDirectoryPage, 'agency_owner'],
  ['clients', async () => (await import('../features/agency/pages/AgencyClientsPage')).AgencyClientsPage, 'agency_owner'],
  ['tickets', async () => (await import('../features/agency/pages/AgencyTicketsPage')).AgencyTicketsPage, 'agency_owner'],
  ['projects', async () => (await import('../features/agency/pages/AgencyProjectsPage')).AgencyOwnerProjectsPage, 'agency_owner'],
  ['profile (owner)', async () => (await import('../features/auth/pages/ProfilePage')).ProfilePage, 'agency_owner'],
  ['profile (client)', async () => (await import('../features/auth/pages/ProfilePage')).ProfilePage, 'agency_client'],
]

describe('agency-facing copy', () => {
  beforeEach(() => {
    mocks.current.user = mocks.user
  })

  it.each(PAGES)('%s never mentions the platform, admins, other agencies or a shared pool', async (_name, load, role) => {
    mocks.current.user = { ...mocks.user, role }
    mocks.dashboard.role = role
    const Page = await load()

    const text = await visible(<Page />)

    expect(text.length).toBeGreaterThan(40)
    expect(text).not.toMatch(FORBIDDEN)
  })

  it('actually renders the agency\'s own content, so the check above is not passing on an empty page', async () => {
    mocks.current.user = mocks.user
    mocks.dashboard.role = 'agency_owner'
    const { AgencyDashboard } = await import('../features/dashboard/pages/roles/agency/AgencyDashboard')

    const text = await visible(<AgencyDashboard />)

    expect(text).toMatch(/Northwind/)
    expect(text).toMatch(/Needs attention/)
    expect(text).toMatch(/Sam Team/)
    expect(screen.queryByText(/Northwind Support commented/)).toBeInTheDocument()
  })

  it('the pattern itself catches the words it is meant to catch', () => {
    for (const bad of ['Welcome to ClientHub', 'Ask your Administrator', 'a Shared employee', 'another agency', 'the platform team', 'Specialists']) {
      expect(bad).toMatch(FORBIDDEN)
    }
    for (const good of ['Request an employee', 'Northwind Support', 'Team member', 'Your account manager']) {
      expect(good).not.toMatch(FORBIDDEN)
    }
  })
})