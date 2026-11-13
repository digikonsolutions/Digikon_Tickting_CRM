import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Button, Card, CapacityBar, Drawer, DueText, EmptyState,
  KpiCard, PageHeader, PriorityBadge, Skeleton, StatusBadge, Tabs,
} from '../../../components/ui'
import { TicketComments } from '../../comments'
import { DEFAULT_CAPACITY, WORKING_WEEKDAYS } from '../../../lib/constants'
import { addDays, formatDate, formatDateRange, formatHours, mondayOf, toDateStr, todayStr } from '../../../lib/format'

/* ── helpers ─────────────────────────────────────────────────── */

function nextAction(status) {
  if (status === 'todo') return ['in_progress', 'Start']
  if (status === 'in_progress') return ['in_review', 'Send to review']
  if (status === 'in_review') return ['done', 'Mark done']
  return null
}

const PRIORITY_DOT = { urgent: '#dc2626', high: '#f59e0b', normal: '#0d9488', low: '#94a3b8' }

function weekDates() {
  const mon = toDateStr(mondayOf())
  return WORKING_WEEKDAYS.map((label, i) => {
    const date = addDays(mon, i)
    return { date, label, dayNum: date.slice(8) }
  })
}

/* ── component ───────────────────────────────────────────────── */

export function MyWorkPage() {
  const [actionError, setActionError] = useState(null)
  const [busyTicket, setBusyTicket] = useState(null)
  const [postedComments, setPostedComments] = useState({})
  const [activeTab, setActiveTab] = useState('today')
  const [drawerTicket, setDrawerTicket] = useState(null)

  const loadData = useCallback(async () => {
    const [projects, tickets] = await Promise.all([
      authService.listMyWork(),
      authService.listMyTickets(),
    ])
    return { projects: projects.projects, tickets: tickets.tickets, capacity: projects.capacity }
  }, [])

  const { data, error, isLoading, reload } = useAsync(loadData)
  const projects = data?.projects ?? []
  const tickets = data?.tickets ?? []
  const capacity = data?.capacity ?? DEFAULT_CAPACITY

  /* ── derived data ──────────────────────────────────────────── */
  const today = todayStr()
  const week = weekDates()
  const weekDateSet = new Set(week.map((d) => d.date))

  const statusCounts = { todo: 0, in_progress: 0, in_review: 0, done: 0 }
  let weekHours = 0

  for (const t of tickets) {
    if (statusCounts[t.status] !== undefined) statusCounts[t.status]++
    for (const s of t.schedule ?? []) {
      if (weekDateSet.has(s.work_date)) weekHours += Number(s.hours) || 0
    }
  }

  const todayTickets = tickets.filter((t) =>
    (t.schedule ?? []).some((s) => s.work_date === today),
  )

  const ticketsByProject = tickets.reduce((groups, ticket) => {
    const key = String(ticket.work_item_id)
    groups[key] ??= []
    groups[key].push(ticket)
    return groups
  }, {})

  /* ── actions ───────────────────────────────────────────────── */
  async function changeStatus(ticket, status) {
    setBusyTicket(ticket.id)
    setActionError(null)
    try {
      await authService.updateTicketStatus(ticket.id, status)
      if (drawerTicket?.id === ticket.id) setDrawerTicket(null)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyTicket(null)
    }
  }

  // Comments posted from the drawer, so card counts stay right without refetching tickets.
  function countComments(ticket) {
    const base = Number(ticket.comments_count) || 0
    const posted = postedComments[ticket.id]
    return base + (posted && posted.base === base ? posted.delta : 0)
  }

  /* ── sub-views ─────────────────────────────────────────────── */
  function renderTicketCard(ticket, project) {
    const action = nextAction(ticket.status)
    const canAct = project && project.status !== 'cancelled' && project.status !== 'completed'
    return (
      <article
        className="mw-ticket-card"
        key={ticket.id}
        onClick={() => setDrawerTicket(ticket)}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => e.key === 'Enter' && setDrawerTicket(ticket)}
      >
        <div className="mw-ticket-left">
          <div className="mw-ticket-title-row">
            <span
              className="mw-priority-dot"
              style={{ background: PRIORITY_DOT[ticket.priority] || PRIORITY_DOT.normal }}
              title={ticket.priority}
              aria-label={`Priority: ${ticket.priority}`}
            />
            <h3 className="mw-ticket-title">{ticket.title}</h3>
          </div>
          {ticket.description && ticket.description !== ticket.title && (
            <p className="mw-ticket-desc">{ticket.description}</p>
          )}
          <div className="mw-ticket-meta">
            <span>{formatDateRange(ticket.start_date, ticket.due_date)}</span>
            <DueText dueDate={ticket.due_date} status={ticket.status} />
            <span>{formatHours(ticket.estimated_hours)}</span>
            <span className="mw-comment-count" title="Comments">{countComments(ticket)} comment{countComments(ticket) === 1 ? '' : 's'}</span>
            <StatusBadge status={ticket.status} />
            <PriorityBadge priority={ticket.priority} />
          </div>
        </div>
        <div className="mw-ticket-actions" onClick={(e) => e.stopPropagation()}>
          {action && canAct && (
            <Button
              type="button"
              size="sm"
              loading={busyTicket === ticket.id}
              onClick={() => changeStatus(ticket, action[0])}
            >
              {action[1]}
            </Button>
          )}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setDrawerTicket(ticket)}
          >
            Comment
          </Button>
        </div>
      </article>
    )
  }

  function renderToday() {
    if (!todayTickets.length) {
      return (
        <EmptyState
          title="Nothing scheduled today"
          action={<Button variant="secondary" onClick={() => setActiveTab('all')}>View all tickets</Button>}
        >
          Your schedule is clear for today.
        </EmptyState>
      )
    }
    return (
      <div className="mw-ticket-list">
        {todayTickets.map((t) => {
          const proj = projects.find((p) => p.id === t.work_item_id)
          return renderTicketCard(t, proj)
        })}
      </div>
    )
  }

  function renderWeek() {
    return (
      <div className="mw-week-grid">
        {week.map((day) => {
          const dayTickets = tickets.filter((t) =>
            (t.schedule ?? []).some((s) => s.work_date === day.date),
          )
          const dayHours = dayTickets.reduce((sum, t) => {
            const alloc = (t.schedule ?? []).find((s) => s.work_date === day.date)
            return sum + (Number(alloc?.hours) || 0)
          }, 0)
          const isToday = day.date === today

          return (
            <div className={`mw-week-col${isToday ? ' is-today' : ''}`} key={day.date}>
              <div className="mw-week-col-header">
                <span className="mw-week-day-label">{day.label}</span>
                <span className="mw-week-day-num">{day.dayNum}</span>
              </div>
              <CapacityBar used={dayHours} budget={capacity.daily_hours} showRemaining={false} />
              <div className="mw-week-blocks">
                {dayTickets.length ? dayTickets.map((t) => {
                  const alloc = (t.schedule ?? []).find((s) => s.work_date === day.date)
                  return (
                    <button
                      key={t.id}
                      type="button"
                      className="mw-week-block"
                      onClick={() => setDrawerTicket(t)}
                    >
                      <span className="mw-week-block-title">{t.title}</span>
                      <span className="mw-week-block-hours">{formatHours(alloc?.hours ?? 0)}</span>
                    </button>
                  )
                }) : <span className="mw-week-empty">—</span>}
              </div>
            </div>
          )
        })}
      </div>
    )
  }

  function renderAllTickets() {
    if (!projects.length) {
      return (
        <EmptyState title="No projects yet">
          Projects assigned to you will appear here.
        </EmptyState>
      )
    }
    return projects.map((project) => {
      const projectTickets = ticketsByProject[String(project.id)] ?? []
      return (
        <Card
          key={project.id}
          header={
            <div className="mw-project-header">
              <div>
                <h2 className="mw-project-title">{project.title}</h2>
                <p className="mw-project-meta">
                  {project.agency_name ?? 'Agency'} · {formatDateRange(project.start_date, project.due_date)}
                </p>
              </div>
              <StatusBadge status={project.status} />
            </div>
          }
          className="mw-project-card"
        >
          {projectTickets.length ? (
            <div className="mw-ticket-list">
              {projectTickets.map((ticket) => renderTicketCard(ticket, project))}
            </div>
          ) : (
            <p className="mw-no-tickets">No tickets for this project.</p>
          )}
        </Card>
      )
    })
  }

  /* ── drawer ────────────────────────────────────────────────── */
  function renderDrawer() {
    if (!drawerTicket) return null
    const t = drawerTicket
    const proj = projects.find((p) => p.id === t.work_item_id)
    const action = nextAction(t.status)
    const canAct = proj && proj.status !== 'cancelled' && proj.status !== 'completed'

    return (
      <Drawer
        open
        onClose={() => setDrawerTicket(null)}
        title={t.title}
        description={proj ? `${proj.title} · ${proj.agency_name ?? 'Agency'}` : undefined}
      >
        <div className="mw-drawer-badges">
          <StatusBadge status={t.status} />
          <PriorityBadge priority={t.priority} />
        </div>

        {t.description && (
          <div className="mw-drawer-section">
            <h4>Description</h4>
            <p>{t.description}</p>
          </div>
        )}

        <div className="mw-drawer-section">
          <h4>Schedule</h4>
          {(t.schedule ?? []).length ? (
            <div className="mw-drawer-schedule">
              {(t.schedule ?? []).map((s) => (
                <div key={s.work_date} className="mw-drawer-schedule-row">
                  <span>{formatDate(s.work_date)}</span>
                  <span>{formatHours(s.hours)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="mw-drawer-empty">No schedule allocated.</p>
          )}
        </div>

        <div className="mw-drawer-section">
          <h4>Meta</h4>
          <p>{formatDateRange(t.start_date, t.due_date)} · <DueText dueDate={t.due_date} status={t.status} /> · {formatHours(t.estimated_hours)} estimated</p>
        </div>

        {action && canAct && (
          <Button
            type="button"
            loading={busyTicket === t.id}
            onClick={() => changeStatus(t, action[0])}
          >
            {action[1]}
          </Button>
        )}

        <div className="mw-drawer-section">
          <h4>Comments</h4>
          <TicketComments
            key={t.id}
            ticketId={t.id}
            onPosted={() => setPostedComments((current) => {
              const base = Number(t.comments_count) || 0
              const delta = current[t.id]?.base === base ? current[t.id].delta : 0
              return { ...current, [t.id]: { base, delta: delta + 1 } }
            })}
          />
        </div>
      </Drawer>
    )
  }

  /* ── main render ───────────────────────────────────────────── */
  const tabItems = [
    { id: 'today', label: 'Today', count: todayTickets.length },
    { id: 'week', label: 'This week' },
    { id: 'all', label: 'All tickets', count: tickets.length },
  ]

  return (
    <section className="page mw-page">
      <PageHeader
        eyebrow="Employee"
        title="My work"
        subtitle="Tickets assigned to you across projects. Update each ticket as you work."
      />

      {/* error banner */}
      <div aria-live="polite">
        {(actionError || error) && (
          <Alert variant="error" title="Something went wrong">
            {String(actionError?.message ?? error?.message ?? actionError ?? error)}
            <div style={{ marginTop: 8 }}>
              <Button type="button" variant="secondary" size="sm" onClick={() => { setActionError(null); reload() }}>
                Try again
              </Button>
            </div>
          </Alert>
        )}
      </div>

      {isLoading ? (
        <div className="mw-skeleton-grid">
          <Skeleton variant="card" />
          <Skeleton variant="card" />
          <Skeleton variant="card" />
          <Skeleton variant="card" />
        </div>
      ) : (
        <>
          {/* KPI row */}
          <div className="mw-kpi-row">
            <KpiCard
              hero
              title="Hours this week"
              value={`${weekHours.toFixed(weekHours % 1 === 0 ? 0 : 1)} h`}
              subtext={`of ${capacity.weekly_hours} h budget`}
              icon="clock"
              progress={<CapacityBar used={weekHours} budget={capacity.weekly_hours} showRemaining={false} />}
            />
            <KpiCard icon="arrow" title="In progress" value={statusCounts.in_progress} tone="teal" />
            <KpiCard icon="clock" title="In review" value={statusCounts.in_review} tone="teal" />
            <KpiCard icon="inbox" title="To do" value={statusCounts.todo} tone="teal" />
            <KpiCard icon="check" title="Done" value={statusCounts.done} tone="teal" />
          </div>

          {/* Tabs */}
          <Tabs tabs={tabItems} activeTab={activeTab} onChange={setActiveTab} />

          <div className="mw-tab-panel" role="tabpanel">
            {activeTab === 'today' && renderToday()}
            {activeTab === 'week' && renderWeek()}
            {activeTab === 'all' && renderAllTickets()}
          </div>
        </>
      )}

      {renderDrawer()}
    </section>
  )
}

export function InternalEmployeeWorkPage() {
  return <MyWorkPage />
}
