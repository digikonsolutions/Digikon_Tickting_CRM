import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../components/ui'

const TODAY = '2026-12-09'
const projects = [
  { id: 1, title: 'Website', agency_name: 'Northwind', client: { name: 'Cara Client' }, status: 'in_progress', start_date: '2026-12-01', due_date: '2026-12-15', estimated_hours: '20.00', schedule: [], schedule_state: 'confirmed', description: 'Build the site.' },
  { id: 2, title: 'Funnel', agency_name: 'Northwind', status: 'scheduled', start_date: '2026-12-10', due_date: '2026-12-11', estimated_hours: '6.00', schedule: [{ work_date: '2026-12-10', hours: '6.00' }], schedule_state: 'confirmed' },
]
const t = (id, overrides = {}) => ({
  id, key: `NW-${id}`, title: `Ticket ${id}`, status: 'todo', priority: 'normal', work_item_id: 1, position: id,
  due_date: '2026-12-20', start_date: '2026-12-09', schedule: [], logged_hours: 0, estimated_hours: '2.00', comments_count: 0,
  allowed_actions: [], updated_at: `2026-12-0${id}T00:00:00Z`, ...overrides,
})
const tickets = [
  t(1, { title: 'Fix the header', status: 'blocked', status_reason: 'Waiting for the logo.', allowed_actions: [{ action: 'unblock', label: 'Unblock', requires_comment: false }] }),
  t(2, { title: 'Late footer', due_date: '2026-12-05', schedule: [{ work_date: TODAY, hours: '2' }] }),
  t(3, { title: 'Today hero', schedule: [{ work_date: TODAY, hours: '3' }], allowed_actions: [{ action: 'start', label: 'Start', requires_comment: false }] }),
  t(4, { title: 'Shipped page', status: 'done' }),
  t(5, { title: 'Asked the client', status: 'waiting_on_client', status_reason: 'Which font?' }),
]

const api = vi.hoisted(() => ({
  listMyWork: vi.fn(), listMyTickets: vi.fn(), getActiveTimer: vi.fn(), getMyQueue: vi.fn(), transitionTicket: vi.fn(),
  listTicketComments: vi.fn(), getTicketTimeline: vi.fn(), getTimesheetWeek: vi.fn(),
}))
vi.mock('../../auth/authService', () => ({ authService: new Proxy(api, { get: (target, name) => target[name] ?? (() => Promise.resolve({})) }) }))
vi.mock('../../auth/useAuth', () => ({ useAuth: () => ({ user: { id: 7, name: 'Eli Employee', role: 'employee' } }) }))

const { EmployeeWorkspaceLayout, OverviewPage, TicketsPage, ProjectsPage, EmployeeProjectPage, RequestsPage, WeekPage } = await import('./index')

function Where() {
  const location = useLocation()
  return <p data-testid="where">{location.pathname}{location.search}</p>
}

function renderAt(path) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <ToastProvider>
        <Routes>
          <Route path="/my-work" element={<EmployeeWorkspaceLayout />}>
            <Route index element={<OverviewPage />} />
            <Route path="tickets" element={<TicketsPage />} />
            <Route path="projects" element={<ProjectsPage />} />
            <Route path="projects/:id" element={<EmployeeProjectPage />} />
            <Route path="requests" element={<RequestsPage />} />
            <Route path="week" element={<WeekPage />} />
          </Route>
        </Routes>
        <Where />
      </ToastProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.listMyWork.mockResolvedValue({ projects, capacity: { daily_hours: 6, weekly_hours: 36 }, clock: { today: TODAY, timezone: 'Asia/Karachi', working_days: [1, 2, 3, 4, 5, 6], daily_hours: 6, weekly_hours: 36 } })
  api.listMyTickets.mockResolvedValue({ tickets, clock: { today: TODAY, timezone: 'Asia/Karachi', working_days: [1, 2, 3, 4, 5, 6], daily_hours: 6, weekly_hours: 36 } })
  api.getActiveTimer.mockResolvedValue({ entry: null })
  api.getMyQueue.mockResolvedValue({ now: null, up_next: [], capacity: {} })
  api.listTicketComments.mockResolvedValue({ comments: [] })
  api.getTicketTimeline.mockResolvedValue({ events: [{ id: 1, type: 'status_changed', actor: { name: 'Eli' }, payload: { to: 'in_progress' }, created_at: '2026-12-08T10:00:00Z' }] })
})

describe('overview', () => {
  it('shows the numbers and each ticket once, in attention, late or today', async () => {
    renderAt('/my-work')

    expect(await screen.findByRole('heading', { name: 'My work', level: 1 })).toBeInTheDocument()
    expect(screen.getByText(/Today is Wed, Dec 9/)).toBeInTheDocument()
    const attention = screen.getByRole('region', { name: 'Needs your attention' })
    const late = screen.getByRole('region', { name: 'Overdue or carried over' })
    const today = screen.getByRole('region', { name: 'Today' })
    expect(within(attention).getByText('Fix the header')).toBeInTheDocument()
    expect(within(attention).getByText(/Waiting for the logo/)).toBeInTheDocument()
    expect(within(late).getByText('Late footer')).toBeInTheDocument()
    expect(within(today).getByText('Today hero')).toBeInTheDocument()
    expect(screen.getAllByText('Late footer')).toHaveLength(1) // not repeated under Today
    expect(screen.queryByText('Shipped page')).not.toBeInTheDocument()
    // Planned this week: 2 + 3 (tickets) + 6 (Funnel has no tickets).
    expect(screen.getByText('11 h')).toBeInTheDocument()
    expect(screen.getByText('of 36 h budget')).toBeInTheDocument()
  })

  it('has a calm empty state when nothing needs the employee', async () => {
    api.listMyTickets.mockResolvedValue({ tickets: [], clock: { today: TODAY } })
    api.listMyWork.mockResolvedValue({ projects: [], clock: { today: TODAY } })
    renderAt('/my-work')
    expect(await screen.findByText('Nothing needs you today')).toBeInTheDocument()
  })

  it('offers a retry when loading fails', async () => {
    api.listMyWork.mockRejectedValue(new Error('Offline'))
    renderAt('/my-work')
    expect(await screen.findByText('Could not load your work')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })
})

describe('ticket items', () => {
  it('are plain articles with real buttons, and the drawer returns focus to Open', async () => {
    const user = userEvent.setup()
    renderAt('/my-work')
    const article = (await screen.findAllByRole('article')).find((node) => node.textContent.includes('Today hero'))
    expect(article).not.toHaveAttribute('role', 'button')
    expect(article).not.toHaveAttribute('tabindex')
    const open = within(article).getByRole('button', { name: 'Open NW-3 Today hero' })

    open.focus()
    await user.keyboard('{Enter}')
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('heading', { name: 'Today hero' })).toBeInTheDocument()
    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(open).toHaveFocus()

    // Space works too, as on any real button.
    await user.keyboard(' ')
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })

  it('run the action the server allows and announce the result', async () => {
    const user = userEvent.setup()
    api.transitionTicket.mockResolvedValue({})
    renderAt('/my-work')
    await user.click(await screen.findByRole('button', { name: 'Unblock' }))
    await waitFor(() => expect(api.transitionTicket).toHaveBeenCalledWith(1, 'unblock'))
    expect(await screen.findAllByText('NW-1: unblock done.')).not.toHaveLength(0)
  })
})

describe('tickets page', () => {
  it('reads filters from the URL, hides closed tickets and keeps changes in the URL', async () => {
    const user = userEvent.setup()
    renderAt('/my-work/tickets?flag=blocked')
    expect(await screen.findByText('Fix the header')).toBeInTheDocument()
    expect(screen.queryByText('Today hero')).not.toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Show'), '')
    expect(await screen.findByText('Today hero')).toBeInTheDocument()
    expect(screen.queryByText('Shipped page')).not.toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: /Show closed/ }))
    expect(await screen.findByText('Shipped page')).toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent('closed=1')

    await user.type(screen.getByLabelText('Search'), 'footer')
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('q=footer'))
    expect(screen.getByText('Late footer')).toBeInTheDocument()
    expect(screen.queryByText('Today hero')).not.toBeInTheDocument()
    expect(screen.getByText('1 ticket')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Sort by'), 'priority')
    expect(screen.getByTestId('where')).toHaveTextContent('sort=priority')
  })

  it('says so when nothing matches', async () => {
    renderAt('/my-work/tickets?q=nothing-like-this')
    expect(await screen.findByText('No tickets match')).toBeInTheDocument()
  })
})

describe('requests page', () => {
  it('groups tickets by who has to act, with reasons', async () => {
    renderAt('/my-work/requests')
    const blocked = await screen.findByRole('region', { name: 'Blocked' })
    expect(within(blocked).getByRole('button', { name: 'Unblock' })).toBeInTheDocument()
    const waiting = screen.getByRole('region', { name: 'Waiting on the client' })
    expect(within(waiting).getByText(/Which font\?/)).toBeInTheDocument()
  })
})

describe('week planner', () => {
  it('moves between weeks across a month boundary and back to this week', async () => {
    const user = userEvent.setup()
    renderAt('/my-work/week?week=2026-12-28')
    expect(await screen.findByRole('heading', { name: 'Week of Dec 28, 2026' })).toBeInTheDocument()
    expect(screen.getByText('Jan 1, 2027')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Next week' }))
    expect(screen.getByRole('heading', { name: 'Week of Jan 4, 2027' })).toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent('week=2027-01-04')

    await user.click(screen.getByRole('button', { name: 'This week' }))
    expect(screen.getByRole('heading', { name: 'Week of Dec 7, 2026' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'This week' })).not.toBeInTheDocument()
    const today = screen.getByText('Today').closest('li')
    expect(today).toHaveAttribute('aria-current', 'date')
    expect(within(today).getByText('Today hero')).toBeInTheDocument()
  })
})

describe('projects', () => {
  it('lists projects with progress and opens one with its tickets in order and its activity', async () => {
    const user = userEvent.setup()
    renderAt('/my-work/projects')
    await user.click(await screen.findByRole('link', { name: 'Website' }))
    expect(await screen.findByRole('heading', { name: 'Website', level: 1 })).toBeInTheDocument()
    expect(screen.getByText('Build the site.')).toBeInTheDocument()
    expect(screen.getByText('Cara Client')).toBeInTheDocument()
    const list = screen.getByRole('list', { name: 'Tickets in this project' })
    expect(within(list).getAllByRole('heading', { level: 3 }).map((node) => node.textContent)).toEqual(['Fix the header', 'Late footer', 'Today hero', 'Shipped page', 'Asked the client'])

    await user.click(screen.getByRole('button', { name: 'Show activity' }))
    expect(await screen.findByRole('list', { name: 'Project activity' })).toBeInTheDocument()
  })

  it('shows not found for a project that is not mine', async () => {
    renderAt('/my-work/projects/999')
    expect(await screen.findByText('Project not found')).toBeInTheDocument()
  })
})
