import { useCallback, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, Input, Modal, PageHeader, Select, Textarea } from '../../../components/ui'
import { formatDate, formatDateRange, formatHours } from '../../../lib/format'

const PRIORITIES = ['low', 'normal', 'high', 'urgent']

function badgeVariant(status) {
  if (['done', 'completed'].includes(status)) return 'success'
  if (['cancelled'].includes(status)) return 'slate'
  if (['urgent', 'in_review'].includes(status)) return status === 'urgent' ? 'danger' : 'violet'
  return status === 'high' ? 'warning' : 'primary'
}

export function ProjectDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [editor, setEditor] = useState(null)
  const [confirmCancel, setConfirmCancel] = useState(false)
  const [mutationError, setMutationError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const loadData = useCallback(async () => {
    const [projectResponse, ticketResponse, assignmentResponse] = await Promise.all([
      authService.listAgencyProjects(),
      authService.listProjectTickets(id),
      authService.listAgencyAssignments(),
    ])
    const project = projectResponse.projects.find((item) => String(item.id) === String(id))
    if (!project) throw new Error('This project is not available in your agency.')
    return {
      project,
      tickets: ticketResponse.tickets,
      assignments: assignmentResponse.assignments,
    }
  }, [id])
  const { data, error, isLoading, reload } = useAsync(loadData)
  const project = data?.project
  const tickets = useMemo(() => [...(data?.tickets ?? [])].sort((a, b) => a.position - b.position), [data?.tickets])
  const assignment = data?.assignments?.find((item) => String(item.id) === String(project?.assignment_id))
  const canManage = assignment?.status === 'active'
  const canEdit = canManage && ['scheduled', 'in_progress'].includes(project?.status)
  const projectTicketHours = tickets
    .filter((ticket) => ticket.status !== 'cancelled')
    .reduce((sum, ticket) => sum + Number(ticket.estimated_hours), 0)
  const progress = project ? Math.min(100, (projectTicketHours / Number(project.estimated_hours)) * 100) : 0

  async function mutate(action) {
    setMutationError(null)
    setIsSaving(true)
    try {
      await action()
      setEditor(null)
      setConfirmCancel(false)
      await reload()
    } catch (reason) {
      setMutationError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  async function saveTicket(event) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const payload = {
      title: String(formData.get('title')).trim(),
      description: String(formData.get('description')).trim(),
      estimated_hours: Number(formData.get('estimated_hours')),
      priority: String(formData.get('priority')),
    }
    await mutate(() => editor.ticket
      ? authService.updateTicket(editor.ticket.id, payload)
      : authService.createProjectTicket(id, payload))
  }

  async function moveTicket(ticket, direction) {
    const index = tickets.findIndex((item) => item.id === ticket.id)
    const target = index + direction
    if (target < 0 || target >= tickets.length) return
    const reordered = [...tickets]
    ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
    await mutate(() => authService.reorderProjectTickets(id, reordered.map((item) => item.id)))
  }

  async function cancelProject() {
    await mutate(async () => {
      await authService.cancelAgencyProject(id)
      navigate(user.role === 'agency_client' ? '/agency/my-projects' : '/agency/projects', { replace: true })
    })
  }

  if (isLoading) return <section className="page"><p role="status">Loading project…</p></section>
  if (error || !project) {
    return (
      <section className="page">
        <PageHeader eyebrow="Agency workspace" title="Project details" />
        <ApiErrorMessage error={error} />
        <Link to="/agency/projects">Back to projects</Link>
      </section>
    )
  }

  return (
    <section className="page project-detail-page">
      <PageHeader
        eyebrow="Project"
        title={project.title}
        subtitle={`${project.employee?.name ?? 'Employee'} · due ${formatDate(project.due_date)}`}
        action={<Link className="ui-button ui-button-secondary ui-button-sm" to={user.role === 'agency_client' ? '/agency/my-projects' : '/agency/projects'}>Back to projects</Link>}
      />
      <ApiErrorMessage error={mutationError} />
      <Card title="Project overview">
        <div className="project-overview">
          <div className="project-overview-details">
            <p>{project.description}</p>
            <dl>
              <div><dt>Employee</dt><dd>{project.employee?.name ?? '—'}</dd></div>
              <div><dt>Project hours</dt><dd>{formatHours(project.estimated_hours)}</dd></div>
              <div><dt>Dates</dt><dd>{formatDateRange(project.start_date, project.due_date)}</dd></div>
              <div><dt>Status</dt><dd><Badge variant={badgeVariant(project.status)}>{project.status.replace('_', ' ')}</Badge></dd></div>
            </dl>
          </div>
          <div className="project-ticket-progress">
            <div><strong>Ticket hours</strong><span>{formatHours(projectTicketHours)} / {formatHours(project.estimated_hours)}</span></div>
            <progress value={progress} max="100" aria-label="Ticket hours versus project hours" />
          </div>
        </div>
      </Card>
      <Card
        title="Tickets"
        description="Tickets are slices of the project's scheduled hours and dates."
        header={(
          <div className="project-tickets-header">
            <div><h2>Tickets</h2><p>Tickets are slices of the project's scheduled hours and dates.</p></div>
            {canEdit && <Button type="button" onClick={() => setEditor({ ticket: null })}>Add ticket</Button>}
          </div>
        )}
      >
        {tickets.length
          ? <ol className="project-ticket-list">
            {tickets.map((ticket, index) => (
              <li className="project-ticket-card" key={ticket.id}>
                <div className="project-ticket-main">
                  <div className="project-ticket-title">
                    <span className="project-ticket-position">{ticket.position + 1}</span>
                    <div><h3>{ticket.title}</h3><p>{ticket.description}</p></div>
                  </div>
                  <div className="project-ticket-badges">
                    <Badge variant={badgeVariant(ticket.priority)}>{ticket.priority}</Badge>
                    <Badge variant={badgeVariant(ticket.status)}>{ticket.status.replace('_', ' ')}</Badge>
                    <strong>{formatHours(ticket.estimated_hours)}</strong>
                  </div>
                  <p className="project-ticket-dates">{ticket.start_date ? formatDateRange(ticket.start_date, ticket.due_date) : 'Not scheduled'}</p>
                </div>
                {canEdit && ticket.status !== 'cancelled' && ticket.status !== 'done' && (
                  <div className="project-ticket-actions">
                    <Button type="button" variant="ghost" size="sm" disabled={index === 0 || isSaving} aria-label={`Move ${ticket.title} up`} onClick={() => moveTicket(ticket, -1)}>↑</Button>
                    <Button type="button" variant="ghost" size="sm" disabled={index === tickets.length - 1 || isSaving} aria-label={`Move ${ticket.title} down`} onClick={() => moveTicket(ticket, 1)}>↓</Button>
                    <Button type="button" variant="secondary" size="sm" onClick={() => setEditor({ ticket })}>Edit</Button>
                    <Button type="button" variant="danger" size="sm" onClick={() => mutate(() => authService.cancelTicket(ticket.id))}>Cancel</Button>
                  </div>
                )}
              </li>
            ))}
          </ol>
          : <p className="assignment-empty">No tickets yet. Add a ticket to break down project hours.</p>}
      </Card>
      {canEdit && ['scheduled', 'in_progress'].includes(project.status) && (
        <div className="project-detail-actions">
          {!['completed', 'cancelled'].includes(project.status) && (
            <Button type="button" variant="danger" onClick={() => setConfirmCancel(true)}>Cancel project</Button>
          )}
        </div>
      )}
      <Modal
        open={Boolean(editor)}
        onClose={() => !isSaving && setEditor(null)}
        title={editor?.ticket ? 'Edit ticket' : 'Create ticket'}
        footer={null}
      >
        <form className="agency-user-form" onSubmit={saveTicket}>
          <Input name="title" label="Title" maxLength="160" defaultValue={editor?.ticket?.title ?? ''} required error={mutationError?.errors?.title?.[0]} />
          <Textarea name="description" label="Description" rows="4" defaultValue={editor?.ticket?.description ?? ''} required error={mutationError?.errors?.description?.[0]} />
          <Input name="estimated_hours" label="Estimated hours" type="number" min="0.01" step="0.01" defaultValue={editor?.ticket?.estimated_hours ?? ''} required error={mutationError?.errors?.estimated_hours?.[0]} />
          <Select name="priority" label="Priority" defaultValue={editor?.ticket?.priority ?? 'normal'}>
            {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority}</option>)}
          </Select>
          {mutationError?.status === 422 && <p className="form-error" role="alert">{mutationError.message}</p>}
          <div className="project-modal-actions">
            <Button type="button" variant="secondary" disabled={isSaving} onClick={() => setEditor(null)}>Cancel</Button>
            <Button type="submit" loading={isSaving} loadingText="Saving…">{editor?.ticket ? 'Save changes' : 'Create ticket'}</Button>
          </div>
        </form>
      </Modal>
      <Modal
        open={confirmCancel}
        onClose={() => !isSaving && setConfirmCancel(false)}
        title="Cancel project?"
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={isSaving} onClick={() => setConfirmCancel(false)}>Keep project</Button>
            <Button type="button" variant="danger" loading={isSaving} loadingText="Cancelling…" onClick={cancelProject}>Cancel project</Button>
          </>
        )}
      >
        <p>This will cancel the project and its tickets and release future scheduled hours.</p>
      </Modal>
    </section>
  )
}
