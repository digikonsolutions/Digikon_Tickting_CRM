import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Button, Card, describeTimelineItem, EmptyState, PageHeader, PlainText, ProjectStatusBadge, RelativeTime, TicketKey } from '../../../components/ui'
import { formatDate, formatDateRange, formatHours } from '../../../lib/format'
import { ProjectActions } from '../components/ProjectActions'
import { TicketList } from '../components/TicketItem'
import { daysLeftLabel, projectSummary } from '../workspace'
import { useWorkspace } from '../useWorkspace'

const ACTIVITY_TICKETS = 10

// The project's recent activity, from its tickets' own timelines (the newest tickets first, up to ten). Loaded only
// when asked for, so opening the page stays fast.
function ProjectActivity({ tickets }) {
  const [state, setState] = useState({ status: 'idle', items: [], error: null })
  const load = useCallback(async () => {
    setState({ status: 'loading', items: [], error: null })
    try {
      const chosen = tickets.slice(0, ACTIVITY_TICKETS)
      const results = await Promise.all(chosen.map((ticket) => authService.getTicketTimeline(ticket.id).then((response) => (response.events ?? []).map((event) => ({ ...event, ticket })))))
      const items = results.flat().sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')).slice(0, 30)
      setState({ status: 'done', items, error: null })
    } catch (error) {
      setState({ status: 'error', items: [], error })
    }
  }, [tickets])

  if (!tickets.length) return <p className="ew-muted">Activity appears here once the project has tickets.</p>
  if (state.status === 'idle') return <Button type="button" size="sm" variant="secondary" onClick={load}>Show activity</Button>
  if (state.status === 'loading') return <p role="status" className="ew-muted">Loading activity…</p>
  if (state.status === 'error') return <p role="alert">{state.error.message} <Button type="button" size="sm" variant="ghost" onClick={load}>Try again</Button></p>
  if (!state.items.length) return <p className="ew-muted">No activity recorded yet.</p>
  return (
    <ol className="ew-activity" aria-label="Project activity">
      {state.items.map((item) => (
        <li key={`${item.ticket.id}-${item.id}`}>
          <TicketKey ticketKey={item.ticket.key} />
          <span>{describeTimelineItem(item, 'employee')}</span>
          <RelativeTime date={item.created_at} className="ew-muted" />
        </li>
      ))}
    </ol>
  )
}

// /my-work/projects/:id: one of my projects in full. Only projects in my own list can be opened.
export function ProjectDetailPage() {
  const { id } = useParams()
  const { workspace, clock } = useWorkspace()
  const project = workspace.projectsById.get(Number(id))

  if (!project) {
    return (
      <section className="page ew-page">
        <EmptyState title="Project not found" action={<Link className="ui-button ui-button-secondary" to="/my-work/projects">Back to projects</Link>}>
          It is not one of your projects, or it no longer exists.
        </EmptyState>
      </section>
    )
  }

  const summary = projectSummary(project, workspace, clock.today)
  const tickets = workspace.ticketsByProject.get(project.id) ?? []
  const byPosition = [...tickets].sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || a.id - b.id)
  const newestFirst = [...tickets].sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))
  const left = daysLeftLabel(summary.daysLeft)

  return (
    <section className="page ew-page">
      <nav className="ew-breadcrumb" aria-label="Breadcrumb"><Link to="/my-work/projects">Projects</Link> / <span aria-current="page">{project.title}</span></nav>
      <PageHeader eyebrow={project.agency_name ?? 'Project'} title={project.title} action={<ProjectStatusBadge project={project} />} />
      <ProjectActions project={project} />

      <div className="ew-columns">
        <div className="ew-column">
          <Card title="About this project">
            <dl className="ew-facts ew-facts-wide">
              <div><dt>Agency</dt><dd>{project.agency_name ?? '—'}</dd></div>
              <div><dt>Client</dt><dd>{project.client?.name ?? '—'}</dd></div>
              <div><dt>Dates</dt><dd>{formatDateRange(project.start_date, project.due_date)}{left && ` · ${left}`}</dd></div>
              <div><dt>Hours</dt><dd>{formatHours(summary.hoursLogged)} logged of {formatHours(summary.hoursEstimated)}</dd></div>
              <div><dt>Tickets</dt><dd>{summary.ticketsTotal ? `${summary.ticketsDone} of ${summary.ticketsTotal} done` : 'None'}</dd></div>
            </dl>
            {project.description && <div className="ew-description"><PlainText text={project.description} /></div>}
          </Card>

          <section className="ew-section" aria-label="Tickets in queue order">
            <header className="ew-section-head"><h2>Tickets<span className="ew-count">{tickets.length}</span></h2><p className="ew-muted">In the order they are planned.</p></header>
            {byPosition.length ? <TicketList tickets={byPosition} label="Tickets in this project" showProject={false} /> : <EmptyState title="No tickets">You can work on this project directly and log time on it.</EmptyState>}
          </section>
        </div>
        <aside className="ew-column ew-side" aria-label="Schedule and activity">
          <Card title="Planned days">
            {(project.schedule ?? []).length ? (
              <ul className="ew-schedule">
                {project.schedule.map((day) => <li key={day.work_date} className={day.work_date === clock.today ? 'is-today' : undefined}><span>{formatDate(day.work_date)}</span><span>{formatHours(day.hours)}</span></li>)}
              </ul>
            ) : <p className="ew-muted">No days planned yet.</p>}
          </Card>
          <Card title="Activity"><ProjectActivity tickets={newestFirst} /></Card>
        </aside>
      </div>
    </section>
  )
}
