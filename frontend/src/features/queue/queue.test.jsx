import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  previewQueueOrder: vi.fn(),
  reorderQueue: vi.fn(),
  createRushRequest: vi.fn(),
  getRushRequest: vi.fn(),
  acceptRushRequest: vi.fn(),
  declineRushRequest: vi.fn(),
  getMyQueue: vi.fn(),
}))
vi.mock('../../auth/authService', () => ({ authService: api }))

const { QueueCard } = await import('./components/QueueCard')
const { RushRequestModal } = await import('./components/RushRequestModal')
const { RushRequestsPanel } = await import('./components/RushRequestsPanel')
const { MyQueueSection } = await import('./components/MyQueueSection')
const { TeamMemberCard } = await import('./components/TeamMemberCard')

const queue = {
  employee: { id: 5, name: 'Emma Alpha', photo_url: null },
  now: { kind: 'ticket', ticket_id: 1, ticket_key: 'NW-4', title: 'Build header', work_item_id: 10, project_title: 'Website', started_at: '2026-12-07T08:00:00Z', hours_logged: 1.5, hours_estimated: 4 },
  up_next: [
    { work_item_id: 11, title: 'Brochure', client_name: 'Cara Client', created_by_id: 2, estimated_hours: '6.00', planned_start: '2026-12-08', planned_end: '2026-12-08', due_date: '2026-12-20', status: 'scheduled', position: 2, fits: true },
    { work_item_id: 12, title: 'Landing page', client_name: 'Olive Owner', created_by_id: 9, estimated_hours: '12.00', planned_start: '2026-12-09', planned_end: '2026-12-10', due_date: '2026-12-10', status: 'scheduled', position: 3, fits: false },
  ],
  pinned_work_item_ids: [10],
  capacity: { daily_hours: 6, weekly_hours: 36, hours_today_used: '6.00', hours_week_used: '24.00', hours_week_remaining: '12.00', free_from: { date: '2026-12-14', available_hours: '6.00' } },
}

const wrap = (node) => render(<MemoryRouter>{node}</MemoryRouter>)

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
})

describe('QueueCard', () => {
  it('shows what the employee is doing now, what is next, and when they are free', () => {
    wrap(<QueueCard assignmentId={3} queue={queue} isOwner userId={9} />)

    expect(screen.getByRole('heading', { name: 'Emma Alpha' })).toBeInTheDocument()
    const now = screen.getByRole('region', { name: /now working on, emma alpha/i })
    expect(within(now).getByText(/NW-4 · Build header/)).toBeInTheDocument()
    expect(within(now).getByText(/Website · started/)).toBeInTheDocument()
    const next = screen.getByRole('list', { name: /projects waiting for emma alpha/i })
    const items = within(next).getAllByRole('listitem')
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('Brochure')
    expect(items[0]).toHaveTextContent('Cara Client')
    expect(items[1]).toHaveTextContent('May finish after its due date')
    expect(screen.getByText(/Free from/)).toHaveTextContent('Dec 14')
    expect(screen.getByRole('progressbar', { name: 'Hours booked today' })).toBeInTheDocument()
  })

  it('shows an empty queue kindly', () => {
    wrap(<QueueCard assignmentId={3} queue={{ ...queue, now: null, up_next: [], capacity: { ...queue.capacity, free_from: null } }} isOwner userId={9} />)
    expect(screen.getByText('Nothing in progress right now.')).toBeInTheDocument()
    expect(screen.getByText(/No projects waiting/)).toBeInTheDocument()
    expect(screen.getByText(/No free time in the next four months/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reorder' })).not.toBeInTheDocument()
  })

  it('gives the owner reorder controls and clients a read-only list', () => {
    const { unmount } = wrap(<QueueCard assignmentId={3} queue={queue} isOwner userId={9} />)
    expect(screen.getByRole('button', { name: 'Reorder' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add task' })).toBeInTheDocument()
    unmount()

    wrap(<QueueCard assignmentId={3} queue={queue} isOwner={false} userId={2} />)
    expect(screen.queryByRole('button', { name: 'Reorder' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Move /i })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add task' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Request urgent help' })).toBeInTheDocument()
    expect(screen.getByText('Yours')).toBeInTheDocument()
  })

  it('moves, previews the impact, blocks a bad order and applies a good one', async () => {
    const user = userEvent.setup()
    const onChanged = vi.fn()
    api.previewQueueOrder
      .mockResolvedValueOnce({
        fits: false,
        conflicts: [{ work_item_id: 12, title: 'Landing page', slips_by_days: 1, due_date: '2026-12-10' }],
        items: [
          { work_item_id: 12, title: 'Landing page', planned_start: '2026-12-10', planned_end: '2026-12-11', current_start: '2026-12-09', current_end: '2026-12-10', slips_by_days: 1, fits: false, moves: true },
          { work_item_id: 11, title: 'Brochure', planned_start: '2026-12-08', planned_end: '2026-12-08', current_start: '2026-12-08', current_end: '2026-12-08', slips_by_days: 0, fits: true, moves: false },
        ],
      })
      .mockResolvedValueOnce({
        fits: true,
        conflicts: [],
        items: [
          { work_item_id: 11, title: 'Brochure', planned_start: '2026-12-09', planned_end: '2026-12-09', current_start: '2026-12-08', current_end: '2026-12-08', slips_by_days: 0, fits: true, moves: true },
          { work_item_id: 12, title: 'Landing page', planned_start: '2026-12-08', planned_end: '2026-12-09', current_start: '2026-12-09', current_end: '2026-12-10', slips_by_days: 0, fits: true, moves: true },
        ],
      })
    api.reorderQueue.mockResolvedValue({ ...queue })
    wrap(<QueueCard assignmentId={3} queue={queue} isOwner userId={9} onChanged={onChanged} />)

    await user.click(screen.getByRole('button', { name: 'Reorder' }))
    expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move Brochure up' })).toBeDisabled()

    // Brochure down: the Landing page goes first, which breaks its due date.
    await user.click(screen.getByRole('button', { name: 'Move Brochure down' }))
    expect(screen.getByRole('status')).toHaveTextContent('Brochure moved to position 2 of 2')
    await user.click(screen.getByRole('button', { name: 'Preview impact' }))
    expect(await screen.findByText('This order would break due dates')).toBeInTheDocument()
    expect(screen.getByText(/1 day late/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled()
    expect(api.previewQueueOrder).toHaveBeenLastCalledWith(3, [10, 12, 11])

    // Back the other way, then preview again: it fits, and Apply sends the order with the started work first.
    await user.click(screen.getByRole('button', { name: 'Move Brochure up' }))
    expect(screen.queryByText('This order would break due dates')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Preview impact' }))
    expect(await screen.findByText('Every project still fits')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(api.reorderQueue).toHaveBeenCalledWith(3, [10, 11, 12]))
    expect(onChanged).toHaveBeenCalled()
  })
})

describe('RushRequestModal', () => {
  const assignments = [{ id: 3, employee: { id: 5, name: 'Emma Alpha' } }]

  it('says pricing is quoted first and validates before sending', async () => {
    const user = userEvent.setup()
    // With two team members nothing is preselected, so the choice is validated too.
    wrap(<RushRequestModal assignments={[...assignments, { id: 4, employee: { id: 6, name: 'Pat Beta' } }]} onClose={() => {}} />)

    expect(screen.getByText(/Nothing is charged/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Request a quote' }))

    expect(await screen.findByText('Choose which team member this is for.')).toBeInTheDocument()
    expect(screen.getByText('Give the request a short title.')).toBeInTheDocument()
    expect(screen.getByText('Describe what is needed.')).toBeInTheDocument()
    expect(screen.getByText('Enter the hours needed.')).toBeInTheDocument()
    expect(screen.getByText('Choose the date it is needed by.')).toBeInTheDocument()
    expect(api.createRushRequest).not.toHaveBeenCalled()
  })

  it('rejects more than 180 hours and dates in the past', async () => {
    const user = userEvent.setup()
    wrap(<RushRequestModal assignments={assignments} defaultAssignmentId={3} defaults={{ title: 'Fix', description: 'Broken', estimated_hours: 200, needed_by: '2020-01-01' }} onClose={() => {}} />)
    await user.click(screen.getByRole('button', { name: 'Request a quote' }))
    expect(await screen.findByText(/at most 180 hours/)).toBeInTheDocument()
    expect(screen.getByText('The date cannot be in the past.')).toBeInTheDocument()
    expect(api.createRushRequest).not.toHaveBeenCalled()
  })

  it('sends a valid priority request, and the extra team member kind needs no employee', async () => {
    const user = userEvent.setup()
    api.createRushRequest.mockResolvedValue({ rush_request: { id: 1 } })
    const onSubmitted = vi.fn()
    const future = new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10)
    wrap(<RushRequestModal assignments={assignments} defaultAssignmentId={3} defaults={{ title: 'Fix checkout', description: 'It is broken.', estimated_hours: 3, needed_by: future }} onClose={() => {}} onSubmitted={onSubmitted} />)

    await user.click(screen.getByRole('button', { name: 'Request a quote' }))
    await waitFor(() => expect(api.createRushRequest).toHaveBeenCalledWith(expect.objectContaining({ type: 'priority_insert', assignment_id: 3, title: 'Fix checkout', estimated_hours: 3, needed_by: future })))
    expect(onSubmitted).toHaveBeenCalled()

    await user.click(screen.getByRole('radio', { name: /Another team member/ }))
    expect(screen.queryByLabelText(/For which team member/)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/Skills you need/)).toBeInTheDocument()
  })
})

describe('RushRequestsPanel quote step', () => {
  const quoted = {
    id: 7, type: 'priority_insert', title: 'Fix checkout', status: 'quoted', estimated_hours: '3.00', needed_by: '2026-12-10',
    employee: { id: 5, name: 'Emma Alpha' }, message: null,
    quote: { amount_minor: 25000, currency: 'USD', amount_display: '250.00 USD', expires_at: '2026-12-09T10:00:00Z', note: null },
  }
  const impact = {
    fits: true, requires_confirmation: true,
    new_project: { planned_start: '2026-12-08', planned_end: '2026-12-08', due_date: '2026-12-10', slips_by_days: 0 },
    affected: [{ work_item_id: 12, title: 'Landing page', due_date: '2026-12-10', planned_end: '2026-12-11', slips_by_days: 1 }],
  }

  it('shows price, expiry and queue impact, and needs an explicit confirm before accepting', async () => {
    const user = userEvent.setup()
    api.getRushRequest.mockResolvedValue({ rush_request: { ...quoted, impact } })
    api.acceptRushRequest.mockResolvedValue({ rush_request: { id: 7, status: 'fulfilled' } })
    const onChanged = vi.fn()
    wrap(<RushRequestsPanel requests={[quoted]} isOwner onChanged={onChanged} />)

    expect(await screen.findByText('250.00 USD')).toBeInTheDocument()
    expect(await screen.findByText(/Other projects would finish late/)).toBeInTheDocument()
    expect(screen.getByText(/Landing page: due/)).toHaveTextContent('1 day late')
    const accept = screen.getByRole('button', { name: 'Accept quote' })
    expect(accept).toBeDisabled()

    await user.click(screen.getByRole('checkbox', { name: /I understand/ }))
    expect(accept).toBeEnabled()
    await user.click(accept)

    await waitFor(() => expect(api.acceptRushRequest).toHaveBeenCalledWith(7, { confirmImpact: true }))
    expect(onChanged).toHaveBeenCalledWith({ id: 7, status: 'fulfilled' }, 'accept')
  })

  it('accepts straight away when nothing is pushed past a due date, and can decline', async () => {
    const user = userEvent.setup()
    api.getRushRequest.mockResolvedValue({ rush_request: { ...quoted, impact: { ...impact, requires_confirmation: false, affected: [] } } })
    api.declineRushRequest.mockResolvedValue({ rush_request: { id: 7, status: 'cancelled' } })
    wrap(<RushRequestsPanel requests={[quoted]} isOwner onChanged={() => {}} />)

    expect(await screen.findByText(/No other project would pass its due date/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Accept quote' })).toBeEnabled()
    await user.click(screen.getByRole('button', { name: 'Decline' }))
    await waitFor(() => expect(api.declineRushRequest).toHaveBeenCalledWith(7))
  })

  it('never offers a client the accept or decline buttons or the price', () => {
    wrap(<RushRequestsPanel requests={[{ ...quoted, quote: null }]} isOwner={false} onChanged={() => {}} />)

    expect(screen.getByText(/account owner will review the quote/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Accept quote' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Decline' })).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/250|USD/)
    expect(api.getRushRequest).not.toHaveBeenCalled()
  })

  it('shows the generic message when no team member is available', () => {
    wrap(<RushRequestsPanel requests={[{ id: 8, type: 'extra_employee', title: 'More hands', status: 'rejected', estimated_hours: '6.00', needed_by: '2026-12-10', employee: null, quote: null, message: 'No team member is available for this request.' }]} isOwner onChanged={() => {}} />)
    expect(screen.getByText('No team member is available for this request.')).toBeInTheDocument()
  })
})

describe('MyQueueSection (employee)', () => {
  it('is read-only: no controls, no prices', () => {
    wrap(<MyQueueSection queue={queue} />)

    expect(screen.getByRole('heading', { name: 'My queue' })).toBeInTheDocument()
    expect(screen.getByText(/NW-4 · Build header/)).toBeInTheDocument()
    expect(screen.getByText('Brochure')).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/USD|\$|quote|charge/i)
  })
})

describe('TeamMemberCard', () => {
  it('shows now, up next, compact bars and a keyboard-reachable menu', async () => {
    const user = userEvent.setup()
    const onAddTask = vi.fn()
    const onProfile = vi.fn()
    wrap(<TeamMemberCard queue={queue} onAddTask={onAddTask} menuItems={[{ label: 'View profile', onClick: onProfile }, { label: 'Request urgent help' }]} />)

    expect(screen.getByText(/NW-4 · Build header/)).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: /projects waiting for emma alpha/i })).getAllByRole('listitem')).toHaveLength(2)
    expect(screen.getByRole('progressbar', { name: /booked this week/i })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Add task' }))
    expect(onAddTask).toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: /more actions for emma alpha/i }))
    expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['View profile', 'Request urgent help'])
    await user.click(screen.getByRole('menuitem', { name: 'View profile' }))
    expect(onProfile).toHaveBeenCalled()
  })

  it('shows when the employee is free and an empty up next', () => {
    wrap(<TeamMemberCard queue={{ ...queue, now: null, up_next: [] }} onAddTask={() => {}} />)
    expect(screen.getByText(/Free from/)).toHaveTextContent('Dec 14')
    expect(screen.getByText(/Nothing waiting/)).toBeInTheDocument()
  })
})