import { describe, expect, it } from 'vitest'
import { buildWorkspace, clockFrom, daysLeftLabel, filterTickets, lateReason, plannedByDay, projectTotals, sortTickets, sumHours, weekDays, workspaceKpis } from './workspace'

const TODAY = '2026-12-09' // a Wednesday
const ticket = (id, overrides = {}) => ({
  id, key: `NW-${id}`, title: `Ticket ${id}`, status: 'todo', priority: 'normal', work_item_id: 1,
  due_date: '2026-12-20', schedule: [], logged_hours: 0, estimated_hours: '2.00', ...overrides,
})
const projects = [
  { id: 1, title: 'Website', agency_name: 'Northwind', client: { name: 'Cara' }, status: 'in_progress', due_date: '2026-12-15', estimated_hours: '20.00', schedule: [{ work_date: TODAY, hours: '4.00' }] },
  { id: 2, title: 'Funnel', agency_name: 'Northwind', status: 'scheduled', due_date: '2026-12-11', estimated_hours: '6.00', schedule: [{ work_date: '2026-12-10', hours: '6.00' }] },
]

describe('clock', () => {
  it('reads today, working days and limits from the API', () => {
    const clock = clockFrom({ clock: { today: TODAY, timezone: 'Asia/Karachi', working_days: [1, 2, 3, 4, 5], daily_hours: 5, weekly_hours: 25 } })
    expect(clock).toEqual({ today: TODAY, timezone: 'Asia/Karachi', working_days: [1, 2, 3, 4, 5], daily_hours: 5, weekly_hours: 25 })
  })

  it('falls back to the capacity key and safe defaults', () => {
    const clock = clockFrom(null, { capacity: { daily_hours: 6, weekly_hours: 36 } })
    expect(clock.working_days).toEqual([1, 2, 3, 4, 5, 6])
    expect(clock.weekly_hours).toBe(36)
    expect(clock.today).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})

describe('late and today', () => {
  it('marks overdue and carried-over work, never closed work', () => {
    expect(lateReason(ticket(1, { due_date: '2026-12-08' }), TODAY)).toBe('overdue')
    expect(lateReason(ticket(2, { schedule: [{ work_date: '2026-12-08', hours: '2' }] }), TODAY)).toBe('carried_over')
    expect(lateReason(ticket(3, { status: 'in_progress', schedule: [{ work_date: '2026-12-08', hours: '2' }] }), TODAY)).toBeNull()
    expect(lateReason(ticket(4, { status: 'done', due_date: '2026-12-01' }), TODAY)).toBeNull()
    expect(lateReason(ticket(5, { due_date: TODAY }), TODAY)).toBeNull()
  })

  it('lists each ticket once: attention before late before today', () => {
    const tickets = [
      ticket(1, { status: 'blocked', due_date: '2026-12-01', schedule: [{ work_date: TODAY, hours: '2' }] }),
      ticket(2, { due_date: '2026-12-01', schedule: [{ work_date: TODAY, hours: '2' }] }),
      ticket(3, { schedule: [{ work_date: TODAY, hours: '2' }] }),
      ticket(4, { status: 'cancelled', schedule: [{ work_date: TODAY, hours: '2' }] }),
    ]
    const workspace = buildWorkspace(projects, tickets, TODAY)
    expect(workspace.attention.map((t) => t.id)).toEqual([1])
    expect(workspace.late.map((t) => t.id)).toEqual([2])
    expect(workspace.todays.map((t) => t.id)).toEqual([3])
    const shown = [...workspace.attention, ...workspace.late, ...workspace.todays].map((t) => t.id)
    expect(new Set(shown).size).toBe(shown.length)
  })

  it('adds project, agency and client names to each ticket', () => {
    const [enriched] = buildWorkspace(projects, [ticket(1)], TODAY).tickets
    expect(enriched).toMatchObject({ project_title: 'Website', agency_name: 'Northwind', client_name: 'Cara' })
  })

  it('collects deadlines in the next 7 days from tickets and projects, soonest first', () => {
    const workspace = buildWorkspace(projects, [ticket(1, { due_date: '2026-12-10' }), ticket(2, { due_date: '2026-12-30' })], TODAY)
    expect(workspace.upcoming.map((row) => `${row.kind}:${row.id}`)).toEqual(['ticket:1', 'project:2', 'project:1'])
  })

  it('groups requests by who has to act', () => {
    const workspace = buildWorkspace(projects, [ticket(1, { status: 'changes_requested' }), ticket(2, { status: 'blocked' }), ticket(3, { status: 'waiting_on_client' }), ticket(4, { status: 'in_review' })], TODAY)
    expect(Object.fromEntries(Object.entries(workspace.requests).map(([key, list]) => [key, list.map((t) => t.id)]))).toEqual({ changes: [1], blocked: [2], waiting: [3], review: [4] })
  })
})

describe('week', () => {
  it('uses the working days of the week, across a month boundary', () => {
    expect(weekDays('2026-12-31', [1, 2, 3, 4, 5, 6])).toEqual(['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'])
    expect(weekDays('2026-12-01', [1, 2, 3, 4, 5])).toEqual(['2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04'])
  })

  it('counts ticket hours, and project hours only for projects worked without tickets', () => {
    const tickets = [ticket(1, { schedule: [{ work_date: TODAY, hours: '2.5' }] })]
    const workspace = buildWorkspace(projects, tickets, TODAY)
    const byDay = plannedByDay(workspace, weekDays(TODAY))
    // Project 1 has a ticket, so its own 4 h are not added; project 2 has none, so its 6 h are.
    expect(sumHours(byDay.get(TODAY))).toBe(2.5)
    expect(sumHours(byDay.get('2026-12-10'))).toBe(6)
    expect(workspaceKpis(workspace, clockFrom({ clock: { today: TODAY } })).weekHours).toBe(8.5)
  })
})

describe('tickets list', () => {
  const tickets = buildWorkspace(projects, [
    ticket(1, { title: 'Header', priority: 'low', due_date: '2026-12-12', updated_at: '2026-12-01T00:00:00Z' }),
    ticket(2, { title: 'Footer', priority: 'urgent', due_date: '2026-12-15', status: 'blocked', updated_at: '2026-12-08T00:00:00Z' }),
    ticket(3, { title: 'Checkout', work_item_id: 2, due_date: '2026-12-05', updated_at: '2026-12-05T00:00:00Z' }),
    ticket(4, { title: 'Old', status: 'done', due_date: '2026-11-01' }),
  ], TODAY).tickets

  it('hides closed tickets unless asked, and searches title, key, project and agency', () => {
    expect(filterTickets(tickets).map((t) => t.id)).toEqual([1, 2, 3])
    expect(filterTickets(tickets, { closed: true })).toHaveLength(4)
    expect(filterTickets(tickets, { q: 'funnel' }).map((t) => t.id)).toEqual([3])
    expect(filterTickets(tickets, { q: 'nw-2' }).map((t) => t.id)).toEqual([2])
    expect(filterTickets(tickets, { q: 'northwind' })).toHaveLength(3)
  })

  it('filters by status, priority, project and flags', () => {
    expect(filterTickets(tickets, { flag: 'blocked' }).map((t) => t.id)).toEqual([2])
    expect(filterTickets(tickets, { flag: 'late' }).map((t) => t.id)).toEqual([3])
    expect(filterTickets(tickets, { priority: 'urgent' }).map((t) => t.id)).toEqual([2])
    expect(filterTickets(tickets, { project: '2' }).map((t) => t.id)).toEqual([3])
    expect(filterTickets(tickets, { status: 'done', closed: true }).map((t) => t.id)).toEqual([4])
  })

  it('sorts by due date, priority, queue order and recent updates', () => {
    const open = filterTickets(tickets)
    expect(sortTickets(open, 'due').map((t) => t.id)).toEqual([3, 1, 2])
    expect(sortTickets(open, 'priority').map((t) => t.id)).toEqual([2, 3, 1])
    expect(sortTickets(open, 'queue').map((t) => t.id)).toEqual([1, 2, 3])
    expect(sortTickets(open, 'updated').map((t) => t.id)).toEqual([2, 3, 1])
  })
})

describe('projects and time', () => {
  it('labels days left', () => {
    expect(daysLeftLabel(3)).toBe('3 days left')
    expect(daysLeftLabel(1)).toBe('1 day left')
    expect(daysLeftLabel(0)).toBe('Due today')
    expect(daysLeftLabel(-2)).toBe('2 days overdue')
    expect(daysLeftLabel(null)).toBeNull()
  })

  it('groups a timesheet week by project', () => {
    const workspace = buildWorkspace(projects, [ticket(1), ticket(2, { work_item_id: 2 })], TODAY)
    const week = {
      tickets: [{ ticket_id: 1, title: 'Ticket 1', logged_hours: 2, scheduled_hours: 3 }, { ticket_id: 2, title: 'Ticket 2', logged_hours: 1, scheduled_hours: 1 }],
      projects: [{ project_id: 1, title: 'Website', logged_hours: 1.5 }],
    }
    expect(projectTotals(week, workspace)).toEqual([
      { id: 1, title: 'Website', logged: 3.5, scheduled: 3 },
      { id: 2, title: 'Funnel', logged: 1, scheduled: 1 },
    ])
  })
})
