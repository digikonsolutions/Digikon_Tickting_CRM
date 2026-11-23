import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Card, EmptyState, StatusBadge, TicketKey, useToast } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import { ProjectFormModal } from './ProjectFormModal'
import { TicketFormModal } from './TicketFormModal'
import '../../services/services.css'

const OPEN_PROJECT = ['scheduled', 'in_progress']
const CLOSED_TICKET = ['done', 'cancelled']

/**
 * Edit from the dashboard: open projects (owners) and open tickets (owners and clients). Completed and cancelled
 * work never shows an Edit button, and the server refuses it anyway. Changing hours re-plans the schedule.
 */
export function EditWorkCard({ isOwner, onChanged }) {
  const { showToast } = useToast()
  const [editing, setEditing] = useState(null)
  const [opening, setOpening] = useState(null)
  const [openError, setOpenError] = useState(null)

  const load = useCallback(async () => {
    const [projects, tickets, assignments] = await Promise.all([
      isOwner ? authService.listAgencyProjects({ status: 'open', perPage: 5 }) : Promise.resolve({ projects: [] }),
      authService.listAgencyTickets(new URLSearchParams({ per_page: '30' })),
      isOwner ? authService.listMyAssignments() : Promise.resolve({ assignments: [] }),
    ])
    return {
      projects: (projects.projects ?? []).filter((project) => OPEN_PROJECT.includes(project.status)),
      tickets: (tickets.tickets ?? []).filter((ticket) => !CLOSED_TICKET.includes(ticket.status)).slice(0, 6),
      assignments: assignments.assignments ?? [],
    }
  }, [isOwner])
  const { data, error, isLoading, reload } = useAsync(load)

  async function editProject(summary) {
    setOpening(`p${summary.id}`)
    setOpenError(null)
    try {
      const { project } = await authService.getAgencyProject(summary.id)
      setEditing({ kind: 'project', project })
    } catch (reason) {
      setOpenError(reason)
    } finally {
      setOpening(null)
    }
  }

  function saved(message) {
    setEditing(null)
    showToast(message)
    reload()
    onChanged?.()
  }

  const projects = data?.projects ?? []
  const tickets = data?.tickets ?? []

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Edit work</h2><p>Open projects and tickets. Changing hours re-plans the schedule.</p></div></div>
        {(error || openError) && <Alert variant="error" title="Could not load your work">{(error ?? openError).message}</Alert>}
        {isLoading && !data ? <p role="status">Loading…</p> : (
          <>
            {isOwner && (
              <section aria-label="Projects you can edit">
                <h3 className="dash-work-heading">Projects</h3>
                {projects.length === 0 ? <EmptyState title="No open projects">Completed and cancelled projects cannot be edited.</EmptyState> : (
                  <ul className="dash-work-list">
                    {projects.map((project) => (
                      <li key={project.id} className="dash-work-item">
                        <span className="dash-work-main">
                          <Link to={`/agency/projects/${project.id}`}><strong>{project.title}</strong></Link>
                          <small>Due {formatDate(project.due_date)} · {project.estimated_hours} h</small>
                        </span>
                        <span className="dash-work-actions">
                          <StatusBadge status={project.status} />
                          <Button type="button" size="sm" variant="secondary" aria-label={`Edit project ${project.title}`} loading={opening === `p${project.id}`} onClick={() => editProject(project)}>Edit</Button>
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            )}
            <section aria-label="Tickets you can edit">
              <h3 className="dash-work-heading">Tickets</h3>
              {tickets.length === 0 ? <EmptyState title="No open tickets">Done and cancelled tickets cannot be edited.</EmptyState> : (
                <ul className="dash-work-list">
                  {tickets.map((ticket) => (
                    <li key={ticket.id} className="dash-work-item">
                      <span className="dash-work-main">
                        <Link to={`/tickets/${ticket.key}`}><strong>{ticket.title}</strong></Link>
                        <small><TicketKey ticketKey={ticket.key} /> · {ticket.project_title}{ticket.due_date ? ` · due ${formatDate(ticket.due_date)}` : ''}</small>
                      </span>
                      <span className="dash-work-actions">
                        <StatusBadge status={ticket.status} />
                        <Button type="button" size="sm" variant="secondary" aria-label={`Edit ticket ${ticket.title}`} onClick={() => setEditing({ kind: 'ticket', ticket })}>Edit</Button>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </>
        )}
      </div>
      {editing?.kind === 'project' && (
        <ProjectFormModal project={editing.project} assignments={data?.assignments ?? []} assignmentsLoading={false} onClose={() => setEditing(null)} onSaved={() => saved('Project updated.')} />
      )}
      {editing?.kind === 'ticket' && (
        <TicketFormModal ticket={editing.ticket} projectId={editing.ticket.work_item_id} onClose={() => setEditing(null)} onSaved={() => saved('Ticket updated.')} />
      )}
    </Card>
  )
}
