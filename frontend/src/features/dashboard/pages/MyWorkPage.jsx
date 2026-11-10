import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Textarea } from '../../../components/ui'

function nextAction(status) {
  if (status === 'todo') return ['in_progress', 'Start']
  if (status === 'in_progress') return ['in_review', 'Send to review']
  if (status === 'in_review') return ['done', 'Done']
  return null
}

export function MyWorkPage() {
  const [actionError, setActionError] = useState(null)
  const [busyTicket, setBusyTicket] = useState(null)
  const [comments, setComments] = useState({})
  const [commentMessages, setCommentMessages] = useState({})
  const loadData = useCallback(async () => {
    const [projects, tickets] = await Promise.all([
      authService.listMyWork(),
      authService.listMyTickets(),
    ])
    return { projects: projects.projects, tickets: tickets.tickets }
  }, [])
  const { data, error, isLoading, reload } = useAsync(loadData)
  const projects = data?.projects ?? []
  const tickets = data?.tickets ?? []
  const ticketsByProject = tickets.reduce((groups, ticket) => {
    const key = String(ticket.work_item_id)
    groups[key] ??= []
    groups[key].push(ticket)
    return groups
  }, {})

  async function changeStatus(ticket, status) {
    setBusyTicket(ticket.id)
    setActionError(null)
    try {
      await authService.updateTicketStatus(ticket.id, status)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyTicket(null)
    }
  }

  async function addComment(event, ticket) {
    event.preventDefault()
    const body = (comments[ticket.id] ?? '').trim()
    if (!body) return
    setBusyTicket(ticket.id)
    setActionError(null)
    try {
      await authService.createTicketComment(ticket.id, body)
      setComments((current) => ({ ...current, [ticket.id]: '' }))
      setCommentMessages((current) => ({ ...current, [ticket.id]: 'Comment added.' }))
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyTicket(null)
    }
  }

  return (
    <section className="page employee-work-page">
      <PageHeader eyebrow="Employee" title="My work" subtitle="Tickets assigned to you across projects. Update each ticket as you work." />
      <ApiErrorMessage error={actionError ?? error} />
      {(actionError || error) && <Button type="button" variant="secondary" onClick={() => { setActionError(null); reload() }}>Try again</Button>}
      {isLoading
        ? <p role="status">Loading your work…</p>
        : projects.length
          ? projects.map((project) => {
            const projectTickets = ticketsByProject[String(project.id)] ?? []
            return (
              <Card
                key={project.id}
                title={project.title}
                description={`${project.agency_name ?? 'Agency'} · ${project.start_date} – ${project.due_date} · ${project.status.replace('_', ' ')}`}
                className="employee-project-card"
              >
                <p>{project.description}</p>
                {projectTickets.length
                  ? <div className="employee-ticket-list">
                    {projectTickets.map((ticket) => {
                      const action = nextAction(ticket.status)
                      return (
                        <article className="employee-ticket-card" key={ticket.id}>
                          <div className="employee-ticket-heading">
                            <div><h3>{ticket.title}</h3><p>{ticket.description}</p></div>
                            <div className="project-ticket-badges">
                              <Badge variant={ticket.status === 'done' ? 'success' : ticket.status === 'cancelled' ? 'slate' : 'primary'}>{ticket.status.replace('_', ' ')}</Badge>
                              <Badge preset={ticket.priority}>{ticket.priority}</Badge>
                            </div>
                          </div>
                          <p className="project-ticket-dates">
                            {ticket.start_date ?? 'Not scheduled'}{ticket.due_date ? ` – ${ticket.due_date}` : ''} · {ticket.estimated_hours} h
                          </p>
                          {action && project.status !== 'cancelled' && project.status !== 'completed' && (
                            <Button
                              type="button"
                              size="sm"
                              loading={busyTicket === ticket.id}
                              onClick={() => changeStatus(ticket, action[0])}
                            >
                              {action[1]}
                            </Button>
                          )}
                          {ticket.status !== 'cancelled' && (
                            <form className="employee-ticket-comment" onSubmit={(event) => addComment(event, ticket)}>
                              <Textarea
                                label="Add a comment"
                                rows="2"
                                value={comments[ticket.id] ?? ''}
                                onChange={(event) => setComments((current) => ({ ...current, [ticket.id]: event.target.value }))}
                                maxLength="10000"
                              />
                              {commentMessages[ticket.id] && <p className="form-success" role="status">{commentMessages[ticket.id]}</p>}
                              <Button type="submit" variant="secondary" size="sm" disabled={!(comments[ticket.id] ?? '').trim()} loading={busyTicket === ticket.id}>
                                Add comment
                              </Button>
                            </form>
                          )}
                        </article>
                      )
                    })}
                  </div>
                  : <p className="assignment-empty">There are no tickets for this project.</p>}
              </Card>
            )
          })
          : <Card title="My work"><p className="assignment-empty">Projects assigned to you will appear here.</p></Card>}
    </section>
  )
}

export function InternalEmployeeWorkPage() {
  return <MyWorkPage />
}
