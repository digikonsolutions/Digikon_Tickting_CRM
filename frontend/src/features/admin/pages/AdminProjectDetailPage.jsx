import { useCallback } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Icon, PageHeader, PriorityBadge, Spinner, StatusBadge, TicketKey } from '../../../components/ui'
import { formatDate, formatDateRange, formatHours } from '../../../lib/format'
import { WaitingChip } from '../../tickets'

function formatSchedule(schedule = []) {
  if (!schedule.length) return 'No scheduled hours'
  return schedule.map(({ work_date, hours }) => `${formatDate(work_date)}: ${formatHours(hours)}`).join(' · ')
}

export function AdminProjectDetailPage() {
  const { id } = useParams()
  const loadProject = useCallback(() => authService.getAdminProject(id), [id])
  const { data, error, isLoading, reload } = useAsync(loadProject)
  const project = data?.project
  const tickets = data?.tickets ?? []
  const ticketHours = tickets
    .filter((ticket) => ticket.status !== 'cancelled')
    .reduce((sum, ticket) => sum + Number(ticket.estimated_hours), 0)
  const projectHours = Number(project?.estimated_hours ?? 0)
  const ticketProgress = projectHours > 0 ? Math.min(100, (ticketHours / projectHours) * 100) : 0

  if (isLoading) {
    return <section className="page admin-project-detail"><p className="admin-project-loading" role="status"><Spinner /> Loading project details…</p></section>
  }

  if (error || !project) {
    return (
      <section className="page admin-project-detail">
        <PageHeader eyebrow="Administration" title="Project details" />
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        {!error && <p role="status">Project details are unavailable.</p>}
        <Link className="admin-project-back-link" to="/admin/projects"><Icon name="arrow" size={16} /> Back to all projects</Link>
      </section>
    )
  }

  return (
    <section className="page admin-project-detail">
      <PageHeader
        eyebrow={project.agency_name ?? 'Administration'}
        title={project.title}
        subtitle="Read-only project and assigned ticket details."
        action={(
          <Link className="ui-button ui-button-secondary admin-project-back-button" to="/admin/projects">
            <Icon name="arrow" size={16} />
            All projects
          </Link>
        )}
      />

      <Card className="admin-project-overview">
        <div className="admin-project-summary">
          <div className="admin-project-description">
            <p>{project.description || 'No project description provided.'}</p>
            <dl className="admin-project-facts">
              <div><dt>Agency</dt><dd>{project.agency_name ?? '—'}</dd></div>
              <div><dt>Client</dt><dd>{project.client?.name ?? 'Account removed'}</dd></div>
              <div><dt>Assigned employee</dt><dd>{project.employee?.name ?? 'Unassigned'}</dd></div>
              <div><dt>Project dates</dt><dd>{formatDateRange(project.start_date, project.due_date)}</dd></div>
              <div><dt>Project hours</dt><dd>{formatHours(projectHours)}</dd></div>
              <div><dt>Status</dt><dd><StatusBadge status={project.status} /></dd></div>
            </dl>
          </div>
          <div className="admin-project-progress">
            <div className="admin-project-progress-label">
              <strong>Ticket hours</strong>
              <span>{formatHours(ticketHours)} / {formatHours(projectHours)}</span>
            </div>
            <div
              className="admin-project-progress-track"
              role="progressbar"
              aria-label="Assigned ticket hours compared with project hours"
              aria-valuemin="0"
              aria-valuemax={projectHours}
              aria-valuenow={Math.min(ticketHours, projectHours)}
            >
              <span style={{ width: `${ticketProgress}%` }} />
            </div>
            <p>Ticket hours exclude cancelled tickets.</p>
          </div>
        </div>
      </Card>

      <Card
        className="admin-project-tickets"
        header={(
          <div className="admin-project-tickets-heading">
            <div>
              <h2>Assigned tickets</h2>
              <p>{tickets.length} ticket{tickets.length === 1 ? '' : 's'} · read-only</p>
            </div>
            <span className="admin-ticket-count">{tickets.length}</span>
          </div>
        )}
      >
        {tickets.length
          ? (
            <ol className="admin-project-ticket-list">
              {tickets.map((ticket) => (
                <li className="admin-project-ticket" key={ticket.id}>
                  <span className="admin-project-ticket-position">{ticket.position + 1}</span>
                  <div className="admin-project-ticket-content">
                    <div className="admin-project-ticket-title-row">
                      <h3><TicketKey ticketKey={ticket.key} /> <Link className="admin-project-detail-link" to={`/tickets/${ticket.key}`}>{ticket.title}</Link></h3>
                      <div className="admin-project-ticket-badges">
                        <PriorityBadge priority={ticket.priority} />
                        <StatusBadge status={ticket.status} />
                      </div>
                    </div>
                    {ticket.description && <p>{ticket.description}</p>}
                    <div className="admin-project-ticket-meta">
                      <span>{formatHours(ticket.estimated_hours)}</span>
                      <span>{ticket.start_date ? formatDateRange(ticket.start_date, ticket.due_date) : 'Not scheduled'}</span>
                    </div>
                    <p className="admin-project-ticket-schedule">{formatSchedule(ticket.schedule)}</p>
                    <p className="project-ticket-footer"><WaitingChip ticket={ticket} role="admin" /> <Link to={`/tickets/${ticket.key}`}>Open ticket</Link> <span>{ticket.comments_count ?? 0} comment{ticket.comments_count === 1 ? '' : 's'}</span></p>
                  </div>
                </li>
              ))}
            </ol>
          )
          : <p className="admin-project-empty">This project has no tickets yet.</p>}
      </Card>
    </section>
  )
}
