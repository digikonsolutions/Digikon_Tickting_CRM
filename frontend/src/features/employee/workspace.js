// Everything the employee workspace derives from /work-items/mine and /work-items/mine/tickets. Pure functions, so
// every rule (what is overdue, what needs a reply, what a week holds) is unit tested in one place.
import { addDays, daysBetween, mondayOf, toDateStr, todayIn } from '../../lib/format'
import { DEFAULT_CAPACITY } from '../../lib/constants'

export const CLOSED = ['done', 'cancelled']
export const OPEN_PROJECT = ['scheduled', 'in_progress']
// The employee has to act: changes were asked for, or they marked it blocked themselves.
export const ATTENTION = ['changes_requested', 'blocked']
// Someone else has to act: the client (asked a question, or reviewing the work).
export const WAITING = ['waiting_on_client', 'in_review']
export const PRIORITY_RANK = { urgent: 0, high: 1, normal: 2, low: 3 }
const UPCOMING_DAYS = 7

// The calendar the API sends (today in the app timezone, working days, hour limits), with safe fallbacks.
export function clockFrom(...payloads) {
  const clock = payloads.find((payload) => payload?.clock)?.clock ?? {}
  const capacity = payloads.find((payload) => payload?.capacity)?.capacity ?? DEFAULT_CAPACITY
  return {
    today: clock.today ?? todayIn(clock.timezone),
    timezone: clock.timezone ?? null,
    working_days: clock.working_days?.length ? clock.working_days : [1, 2, 3, 4, 5, 6],
    daily_hours: Number(clock.daily_hours ?? capacity.daily_hours ?? DEFAULT_CAPACITY.daily_hours),
    weekly_hours: Number(clock.weekly_hours ?? capacity.weekly_hours ?? DEFAULT_CAPACITY.weekly_hours),
  }
}

export const isClosed = (ticket) => CLOSED.includes(ticket.status)
const schedule = (item) => item.schedule ?? []

/**
 * Why an open ticket is late, or null: past its due date, or still not started although days planned for it have
 * passed ("carried over").
 */
export function lateReason(ticket, today) {
  if (isClosed(ticket)) return null
  if (ticket.due_date && ticket.due_date < today) return 'overdue'
  if (ticket.status === 'todo' && schedule(ticket).some((day) => day.work_date < today)) return 'carried_over'
  return null
}

export function buildWorkspace(projects = [], tickets = [], today) {
  const projectsById = new Map(projects.map((project) => [project.id, project]))
  const all = tickets.map((ticket) => {
    const project = projectsById.get(ticket.work_item_id)
    return {
      ...ticket,
      project_title: ticket.project_title ?? project?.title ?? null,
      agency_name: ticket.agency_name ?? project?.agency_name ?? null,
      client_name: project?.client?.name ?? null,
      late: lateReason(ticket, today),
    }
  })
  const open = all.filter((ticket) => !isClosed(ticket))

  // Each ticket appears once on the Overview: attention first, then late, then today's plan.
  const attention = open.filter((ticket) => ATTENTION.includes(ticket.status))
  const late = open.filter((ticket) => !ATTENTION.includes(ticket.status) && ticket.late)
  const lateIds = new Set(late.map((ticket) => ticket.id))
  const todays = open.filter((ticket) => !ATTENTION.includes(ticket.status) && !lateIds.has(ticket.id) && schedule(ticket).some((day) => day.work_date === today))

  const ticketsByProject = new Map()
  for (const ticket of all) {
    if (!ticketsByProject.has(ticket.work_item_id)) ticketsByProject.set(ticket.work_item_id, [])
    ticketsByProject.get(ticket.work_item_id).push(ticket)
  }
  const hasTickets = (project) => (ticketsByProject.get(project.id) ?? []).some((ticket) => ticket.status !== 'cancelled')

  const horizon = addDays(today, UPCOMING_DAYS)
  const upcoming = [
    ...open.filter((ticket) => ticket.due_date && ticket.due_date >= today && ticket.due_date <= horizon).map((ticket) => ({ kind: 'ticket', id: ticket.id, title: ticket.title, due_date: ticket.due_date, project_title: ticket.project_title, item: ticket })),
    ...projects.filter((project) => OPEN_PROJECT.includes(project.status) && project.due_date >= today && project.due_date <= horizon)
      .map((project) => ({ kind: 'project', id: project.id, title: project.title, due_date: project.due_date, project_title: null, item: project })),
  ].sort((a, b) => a.due_date.localeCompare(b.due_date) || a.title.localeCompare(b.title))

  return {
    today,
    projects,
    projectsById,
    tickets: all,
    open,
    attention,
    late,
    todays,
    upcoming,
    ticketsByProject,
    hasTickets,
    requests: {
      changes: open.filter((ticket) => ticket.status === 'changes_requested'),
      blocked: open.filter((ticket) => ticket.status === 'blocked'),
      waiting: open.filter((ticket) => ticket.status === 'waiting_on_client'),
      review: open.filter((ticket) => ticket.status === 'in_review'),
    },
  }
}

// The working days of the week that contains `dateStr`, as `YYYY-MM-DD`.
export function weekDays(dateStr, workingDays = [1, 2, 3, 4, 5, 6]) {
  const monday = toDateStr(mondayOf(dateStr))
  return [...workingDays].sort((a, b) => a - b).map((isoDay) => addDays(monday, isoDay - 1))
}

/**
 * Planned hours per day: ticket allocations, plus the project's own allocations for projects worked without tickets
 * (a project with tickets plans its hours through them, so it is never counted twice).
 *
 * @returns {Map<string, Array<{ kind: 'ticket'|'project', item: object, hours: number }>>}
 */
export function plannedByDay(workspace, dates) {
  const wanted = new Set(dates)
  const byDay = new Map(dates.map((date) => [date, []]))
  for (const ticket of workspace.tickets) {
    if (ticket.status === 'cancelled') continue
    for (const day of schedule(ticket)) {
      if (wanted.has(day.work_date)) byDay.get(day.work_date).push({ kind: 'ticket', item: ticket, hours: Number(day.hours) || 0 })
    }
  }
  for (const project of workspace.projects) {
    if (project.status === 'cancelled' || workspace.hasTickets(project)) continue
    for (const day of schedule(project)) {
      if (wanted.has(day.work_date)) byDay.get(day.work_date).push({ kind: 'project', item: project, hours: Number(day.hours) || 0 })
    }
  }
  return byDay
}

export const sumHours = (entries) => Math.round(entries.reduce((sum, entry) => sum + entry.hours, 0) * 100) / 100

// The KPI row: planned hours this week, and counts that need someone.
export function workspaceKpis(workspace, clock) {
  const dates = weekDays(clock.today, clock.working_days)
  const byDay = plannedByDay(workspace, dates)
  return {
    weekHours: sumHours([...byDay.values()].flat()),
    inProgress: workspace.open.filter((ticket) => ticket.status === 'in_progress').length,
    waiting: workspace.open.filter((ticket) => WAITING.includes(ticket.status)).length,
    blocked: workspace.requests.blocked.length,
    overdue: workspace.late.length + workspace.attention.filter((ticket) => ticket.late).length,
  }
}

/* ── Tickets list: search, filters and sort, all read from the URL ── */

export const SORTS = {
  due: 'Due date',
  priority: 'Priority',
  queue: 'Queue order',
  updated: 'Recently updated',
}

export function filterTickets(tickets, { q = '', status = '', priority = '', project = '', flag = '', closed = false } = {}) {
  const needle = q.trim().toLowerCase()
  return tickets.filter((ticket) => {
    if (!closed && isClosed(ticket)) return false
    if (status && ticket.status !== status) return false
    if (priority && ticket.priority !== priority) return false
    if (project && String(ticket.work_item_id) !== String(project)) return false
    if (flag === 'blocked' && ticket.status !== 'blocked') return false
    if (flag === 'waiting' && !WAITING.includes(ticket.status)) return false
    if (flag === 'late' && !ticket.late) return false
    if (needle) {
      const haystack = [ticket.title, ticket.key, ticket.project_title, ticket.agency_name].filter(Boolean).join(' ').toLowerCase()
      if (!haystack.includes(needle)) return false
    }
    return true
  })
}

export function sortTickets(tickets, sort = 'due') {
  const list = tickets.map((ticket, index) => ({ ticket, index }))
  const byDue = (a, b) => (a.ticket.due_date ?? '9999').localeCompare(b.ticket.due_date ?? '9999')
  const compare = {
    due: (a, b) => byDue(a, b) || a.index - b.index,
    priority: (a, b) => (PRIORITY_RANK[a.ticket.priority] ?? 9) - (PRIORITY_RANK[b.ticket.priority] ?? 9) || byDue(a, b) || a.index - b.index,
    // The API returns tickets in project then queue position order.
    queue: (a, b) => a.index - b.index,
    updated: (a, b) => (b.ticket.updated_at ?? '').localeCompare(a.ticket.updated_at ?? '') || a.index - b.index,
  }[sort] ?? ((a, b) => a.index - b.index)
  return list.sort(compare).map((entry) => entry.ticket)
}

/* ── Projects ── */

export function projectSummary(project, workspace, today) {
  const tickets = (workspace.ticketsByProject.get(project.id) ?? []).filter((ticket) => ticket.status !== 'cancelled')
  const loggedFromTickets = tickets.reduce((sum, ticket) => sum + (Number(ticket.logged_hours) || 0), 0)
  return {
    ticketsTotal: tickets.length,
    ticketsDone: tickets.filter((ticket) => ticket.status === 'done').length,
    hoursLogged: Math.round(loggedFromTickets * 100) / 100,
    hoursEstimated: Number(project.estimated_hours) || 0,
    daysLeft: OPEN_PROJECT.includes(project.status) && project.due_date ? daysBetween(today, project.due_date) : null,
  }
}

// "3 days left", "Due today", "2 days overdue" from projectSummary().daysLeft.
export function daysLeftLabel(daysLeft) {
  if (daysLeft === null) return null
  if (daysLeft < 0) return `${-daysLeft} ${daysLeft === -1 ? 'day' : 'days'} overdue`
  if (daysLeft === 0) return 'Due today'
  return `${daysLeft} ${daysLeft === 1 ? 'day' : 'days'} left`
}

// Logged and scheduled hours per project for the week the timesheet shows: ticket rows are grouped by project, and
// time logged straight on a project (no ticket) is added to it.
export function projectTotals(week, workspace) {
  const rows = new Map()
  const row = (id, title) => {
    if (!rows.has(id)) rows.set(id, { id, title, logged: 0, scheduled: 0 })
    return rows.get(id)
  }
  for (const line of week?.tickets ?? []) {
    const ticket = workspace.tickets.find((item) => item.id === line.ticket_id)
    const project = ticket ? workspace.projectsById.get(ticket.work_item_id) : null
    const entry = row(project?.id ?? `t${line.ticket_id}`, project?.title ?? line.title)
    entry.logged += Number(line.logged_hours) || 0
    entry.scheduled += Number(line.scheduled_hours) || 0
  }
  for (const line of week?.projects ?? []) {
    row(line.project_id, line.title).logged += Number(line.logged_hours) || 0
  }
  return [...rows.values()].map((entry) => ({ ...entry, logged: Math.round(entry.logged * 100) / 100, scheduled: Math.round(entry.scheduled * 100) / 100 }))
    .sort((a, b) => b.logged - a.logged || a.title.localeCompare(b.title))
}
