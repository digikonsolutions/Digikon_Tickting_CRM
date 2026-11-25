import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, EmptyState, FieldRow, FormActions, FormSection, Input, Modal, PageHeader, PriorityBadge, ProjectStatusBadge, ScheduleSummary, Select, StatusBadge, TicketKey, Textarea, useToast } from '../../../components/ui'
import { formatDate, formatDateRange, formatHours, formatTicketProgress } from '../../../lib/format'
import { getAgencyLabels } from '../../../lib/labels'
import { can } from '../../../lib/permissions'
import { WaitingChip } from '../../tickets'
import { ProjectBriefFields, ProjectBriefView, ProjectFiles, readProjectBrief } from '../../briefs'
import { ActivityTimeline } from '../../activity'
import { TimelinePanel } from '../../timeline'
import { ProjectServicesView } from '../components/projectCreate/ProjectServicesView'
import { BriefForm } from '../../workorder/BriefForm'
import { GhlBriefView } from '../../workorder/BriefView'
import { useProjectBrief } from '../../workorder/useProjectBrief'
import { WorkOrderBadges } from '../../workorder/WorkOrderView'
import { TicketForm } from '../components/TicketForm'
import { ProjectHoursPanel } from '../components/ProjectHoursPanel'
import { EmployeeProfileDrawer } from '../components/EmployeeProfileDrawer'


export function ProjectDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const { showToast } = useToast()
  const labels = getAgencyLabels(user.role)
  const [editor, setEditor] = useState(null)
  const [projectEditor, setProjectEditor] = useState(null)
  const [confirmCancel, setConfirmCancel] = useState(false)
  const [profileId, setProfileId] = useState(null)
  const [mutationError, setMutationError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [briefEditor, setBriefEditor] = useState(false)
  const [briefError, setBriefError] = useState(null)
  const brief = useProjectBrief(null)
  const loadData = useCallback(async () => {
    const [projectResponse, ticketResponse] = await Promise.all([
      authService.getAgencyProject(id),
      authService.listProjectTickets(id),
    ])
    return {
      project: projectResponse.project,
      tickets: ticketResponse.tickets,
    }
  }, [id])
  const { data, error, isLoading, reload } = useAsync(loadData)
  const project = data?.project
  const tickets = useMemo(() => [...(data?.tickets ?? [])].sort((a, b) => a.position - b.position), [data?.tickets])
  // The backend lets any agency owner/client manage tickets while the project's assignment is
  // active, whoever holds it, so the payload's assignment_status is the source of truth.
  const canManage = project?.assignment_status === 'active'
  const canEdit = canManage && ['scheduled', 'in_progress'].includes(project?.status)
  // Follow-up tickets can still be created once a project is completed (it stays completed).
  const canAddTicket = canManage && ['scheduled', 'in_progress', 'completed'].includes(project?.status)
  const isOwner = user.role === 'agency_owner'
  const canSchedule = can(user, 'schedule.set')
  // Dates and planned hours are only shown once the schedule is confirmed; before that the project is awaiting our team.
  const scheduleState = project?.schedule_state ?? 'confirmed'
  const planned = scheduleState !== 'awaiting'
  const isOpen = ['scheduled', 'in_progress'].includes(project?.status)
  const projectTicketHours = tickets
    .filter((ticket) => ticket.status !== 'cancelled')
    .reduce((sum, ticket) => sum + Number(ticket.estimated_hours), 0)
  const progress = project ? Math.min(100, (projectTicketHours / Number(project.estimated_hours)) * 100) : 0
  // Latest delivery date among tickets that are still open.
  const expectedDelivery = tickets
    .filter((ticket) => !['done', 'cancelled'].includes(ticket.status) && ticket.due_date)
    .map((ticket) => ticket.due_date)
    .sort()
    .pop()

  async function mutate(action) {
    setMutationError(null)
    setIsSaving(true)
    try {
      const result = await action()
      // Edits and reorders can move other tickets: tell the user how many were rescheduled.
      const moved = result?.rescheduled?.length ?? 0
      if (moved > 0) showToast(`${moved} ticket${moved === 1 ? ' was' : 's were'} rescheduled.`)
      setEditor(null)
      setConfirmCancel(false)
      await reload()
    } catch (reason) {
      setMutationError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  // After a ticket is saved: report moved tickets, reload.
  async function ticketSaved(result, { created, uploadNotice } = {}) {
    const moved = result?.rescheduled?.length ?? 0
    if (moved > 0) showToast(` ticket${moved === 1 ? ' was' : 's were'} rescheduled.`)
    if (uploadNotice) showToast(`Ticket created, but the files were not uploaded: ${uploadNotice}`, 'error')
    if (created) showToast('Ticket created.')
    setEditor(null)
    await reload()
  }

  async function duplicateTicket(ticket) {
    await mutate(async () => {
      const result = await authService.duplicateTicket(id, ticket.id)
      showToast('Ticket duplicated.')
      return result
    })
  }

  function openBriefEditor() {
    brief.reset(project.ghl_brief)
    setBriefError(null)
    setBriefEditor(true)
  }

  async function saveBrief(event) {
    event.preventDefault()
    setIsSaving(true)
    setBriefError(null)
    try {
      await authService.updateProjectBrief(id, brief.payload({ replaceAll: true }))
      setBriefEditor(false)
      showToast('Brief saved.')
      await reload()
    } catch (reason) {
      setBriefError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  // Links from the projects list (?edit=1 / ?newTicket=1) open the matching dialog once, then clear the link.
  const [searchParams, setSearchParams] = useSearchParams()
  const wanted = searchParams.get('edit') ? 'edit' : searchParams.get('newTicket') ? 'ticket' : null
  useEffect(() => {
    if (!wanted || !project) return
    setSearchParams({}, { replace: true })
    if (wanted === 'edit' && isOwner) openProjectEditor()
    if (wanted === 'ticket' && canAddTicket) { setMutationError(null); setEditor({ ticket: null }) }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wanted, project?.id])

  async function openProjectEditor() {
    setMutationError(null)
    setProjectEditor({ assignments: [] })
    // Only open projects can change employee, so only then is the list needed.
    if (isOpen) {
      try {
        const { assignments } = await authService.listMyAssignments()
        setProjectEditor({ assignments })
      } catch {
        // The employee select then only offers the current one.
      }
    }
  }

  async function saveProject(event) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const payload = {
      title: String(formData.get('title')).trim(),
      description: String(formData.get('description')).trim(),
      ...readProjectBrief(formData),
    }
    // The agency sends an estimate and a preferred employee, never dates: our team plans the days. Only what changed is sent.
    if (isOpen) {
      const rawHours = String(formData.get('estimated_hours') ?? '').trim()
      if (rawHours !== '' && Number(rawHours) !== Number(project.estimated_hours)) payload.estimated_hours = Number(rawHours)
      if (canSchedule) {
        if (String(formData.get('start_date')) !== project.start_date) payload.start_date = String(formData.get('start_date'))
        if (String(formData.get('due_date')) !== project.due_date) payload.due_date = String(formData.get('due_date'))
      }
      const assignment = Number(formData.get('assignment_id'))
      if (!planned && assignment && assignment !== project.assignment_id) payload.assignment_id = assignment
    }
    setMutationError(null)
    setIsSaving(true)
    try {
      await authService.updateAgencyProject(id, payload)
      setProjectEditor(null)
      showToast('Project updated.')
      await reload()
    } catch (reason) {
      setMutationError(reason)
    } finally {
      setIsSaving(false)
    }
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
      navigate('/agency/projects', { replace: true })
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
        action={(
          <div className="project-header-actions">
            {isOwner && <Button type="button" size="sm" onClick={openProjectEditor}>Edit project</Button>}
            <Link className="ui-button ui-button-secondary ui-button-sm" to="/agency/projects">Back to projects</Link>
          </div>
        )}
      />
      <ApiErrorMessage error={mutationError} />
      <Card title="Project overview">
        <div className="project-overview">
          <div className="project-overview-details">
            <p>{project.description}</p>
            <dl>
              <div>
                <dt>Employee</dt>
                <dd>
                  {project.employee?.name ?? '—'}
                  {project.employee && (
                    <button type="button" className="project-profile-link" onClick={() => setProfileId(project.employee.id)}>View profile</button>
                  )}
                </dd>
              </div>
              <div><dt>Estimated hours</dt><dd>{Number(project.estimated_hours) > 0 ? formatHours(project.estimated_hours) : 'Not given'}</dd></div>
              {scheduleState === 'confirmed' && Math.abs(Number(project.planned_hours ?? project.estimated_hours) - Number(project.estimated_hours)) >= 0.005 && (
                <div><dt>Planned hours</dt><dd>{formatHours(project.planned_hours)}</dd></div>
              )}
              <div><dt>Dates</dt><dd>{scheduleState === 'confirmed' ? formatDateRange(project.start_date, project.due_date) : (scheduleState === 'awaiting' ? 'Awaiting schedule' : 'To be confirmed')}</dd></div>
              <div><dt>Status</dt><dd><ProjectStatusBadge project={project} /></dd></div>
              <div><dt>Expected delivery</dt><dd>{expectedDelivery ? formatDate(expectedDelivery) : '—'}</dd></div>
            </dl>
          </div>
          <div className="project-ticket-progress">
            <div><strong>Ticket hours</strong><span>{formatHours(projectTicketHours)} / {formatHours(project.estimated_hours)}</span></div>
            <progress value={progress} max="100" aria-label="Ticket hours versus project hours" />
            <p>{formatTicketProgress(project)}</p>
          </div>
        </div>
      </Card>
      <ProjectServicesView services={project.services} />
      <ProjectHoursPanel hours={project.hours} />
      <ProjectBriefView brief={project.brief} />
      {(project.ghl_brief || canEdit) && (
        <Card title="GoHighLevel brief" description="Give this once; every ticket starts from it." header={(
          <div className="project-tickets-header">
            <div><h2>GoHighLevel brief</h2><p>Give this once; every ticket starts from it.</p></div>
            {canEdit && <Button type="button" variant="secondary" onClick={openBriefEditor}>{project.ghl_brief ? 'Edit brief' : 'Add brief'}</Button>}
          </div>
        )}>
          {project.ghl_brief ? <GhlBriefView brief={project.ghl_brief} /> : <p className="ui-field-help">No brief yet.</p>}
        </Card>
      )}
      <ProjectFiles projectId={project.id} />
      <TimelinePanel projectId={project.id} onChanged={reload} />
      <Card
        title="Tickets"
        description="Tickets are slices of the project's scheduled hours and dates."
        header={(
          <div className="project-tickets-header">
            <div><h2>Tickets</h2><p>Tickets are slices of the project's scheduled hours and dates.</p></div>
            {canAddTicket && <Button type="button" onClick={() => { setMutationError(null); setEditor({ ticket: null }) }}>{project.status === 'completed' ? 'Create ticket' : 'Add ticket'}</Button>}
          </div>
        )}
      >
        {tickets.length
          ? <ol className="project-ticket-list">
            {tickets.map((ticket, index) => (
              <li className="project-ticket-card" key={ticket.id}>
                <div className="project-ticket-main">
                  <div className="project-ticket-title">
                    <span className="project-ticket-position">{index + 1}</span>
                    <div>
                      <TicketKey ticketKey={ticket.key} />
                      <h3><Link className="project-ticket-link" to={`/tickets/${ticket.key}`}>{ticket.title}</Link></h3>
                      <p>{ticket.description}</p>
                    </div>
                  </div>
                  <div className="project-ticket-badges">
                    <PriorityBadge priority={ticket.priority} />
                    <StatusBadge status={ticket.status} role={user.role} />
                    <WaitingChip ticket={ticket} role={user.role} />
                    <strong title="Logged of estimated hours">{formatHours(ticket.logged_hours)} / {formatHours(ticket.estimated_hours)}</strong>
                    {ticket.over_estimate && <span className="time-progress-badge">Over estimate</span>}
                    <WorkOrderBadges summary={ticket.details_summary} />
                  </div>
                  <div className="project-ticket-dates">
                    {ticket.due_date && <p className="project-ticket-due">{labels.dueDate} <strong>{formatDate(ticket.due_date)}</strong></p>}
                    <ScheduleSummary schedule={ticket.schedule} />
                  </div>
                </div>
                {canEdit && ticket.status !== 'cancelled' && ticket.status !== 'done' && (
                  <div className="project-ticket-actions">
                    <Button type="button" variant="ghost" size="sm" disabled={index === 0 || isSaving} aria-label={`Move ${ticket.title} up`} onClick={() => moveTicket(ticket, -1)}>↑</Button>
                    <Button type="button" variant="ghost" size="sm" disabled={index === tickets.length - 1 || isSaving} aria-label={`Move ${ticket.title} down`} onClick={() => moveTicket(ticket, 1)}>↓</Button>
                    <Button type="button" variant="secondary" size="sm" onClick={() => setEditor({ ticket })}>Edit</Button>
                    <Button type="button" variant="ghost" size="sm" disabled={isSaving} onClick={() => duplicateTicket(ticket)}>Duplicate</Button>
                    <Button type="button" variant="danger" size="sm" onClick={() => mutate(() => authService.cancelTicket(ticket.id))}>Cancel</Button>
                  </div>
                )}
                <div className="project-ticket-footer">
                  <Link to={`/tickets/${ticket.key}`}>Open ticket</Link>
                  <span>{ticket.comments_count ?? 0} comment{ticket.comments_count === 1 ? '' : 's'}</span>
                </div>
              </li>
            ))}
          </ol>
          : <EmptyState title="No tickets yet" action={canAddTicket ? <Button type="button" onClick={() => { setMutationError(null); setEditor({ ticket: null }) }}>Add the first ticket</Button> : null}>Break the project’s hours into tickets so work can be planned.</EmptyState>}
      </Card>
      {isOwner && project.employee && <ActivityTimeline projectId={project.id} title="Employee timeline" />}
      {canEdit && ['scheduled', 'in_progress'].includes(project.status) && (
        <div className="project-detail-actions">
          {!['completed', 'cancelled'].includes(project.status) && (
            <Button type="button" variant="danger" onClick={() => setConfirmCancel(true)}>Cancel project</Button>
          )}
        </div>
      )}
      <EmployeeProfileDrawer employeeId={profileId} onClose={() => setProfileId(null)} />
      {editor && <TicketForm key={editor.ticket?.id ?? 'new'} project={project} tickets={tickets} ticket={editor.ticket} onClose={() => setEditor(null)} onSaved={ticketSaved} />}
      <Modal
        open={briefEditor}
        onClose={() => !isSaving && setBriefEditor(false)}
        title="GoHighLevel brief"
        footer={null}
        className="client-modal"
      >
        {briefEditor && (
          <form className="ui-form" onSubmit={saveBrief} noValidate>
            <BriefForm brief={brief} errors={briefError?.errors} />
            {briefError && !briefError.errors && <ApiErrorMessage error={briefError} />}
            <FormActions submitLabel="Save brief" loadingLabel="Saving…" loading={isSaving} onCancel={() => setBriefEditor(false)} />
          </form>
        )}
      </Modal>
      <Modal
        open={Boolean(projectEditor)}
        onClose={() => !isSaving && setProjectEditor(null)}
        title="Edit project"
        footer={null}
      >
        {projectEditor && (
          <form className="ui-form" onSubmit={saveProject}>
            <FormSection title="Basics">
              <Input name="title" label="Title" maxLength="160" defaultValue={project.title} required error={mutationError?.errors?.title?.[0]} />
              <Textarea name="description" label="Description" rows="4" maxLength="5000" defaultValue={project.description} required error={mutationError?.errors?.description?.[0]} />
            </FormSection>
            <FormSection
              title="Estimate and employee"
              description={isOpen ? 'Our team plans the days and confirms the final schedule. A new estimate sends the plan back to us for review.' : 'The estimate and employee are locked once a project is completed or cancelled. The status does not change when you edit.'}
            >
              <FieldRow>
                <Input name="estimated_hours" label="Estimated hours (optional)" type="number" inputMode="decimal" min="0.01" step="0.01" defaultValue={Number(project.estimated_hours) > 0 ? project.estimated_hours : ''} helperText="Our team will confirm the final schedule." disabled={!isOpen} error={mutationError?.errors?.estimated_hours?.[0]} />
                <Select name="assignment_id" label="Preferred employee" defaultValue={String(project.assignment_id)} disabled={!isOpen || planned} helperText={planned ? 'Our team has planned this project. Ask us if the employee needs to change.' : undefined} error={mutationError?.errors?.assignment_id?.[0]}>
                  {!projectEditor.assignments.some((assignment) => assignment.id === project.assignment_id) && <option value={project.assignment_id}>{project.employee?.name ?? 'Current employee'}</option>}
                  {projectEditor.assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>)}
                </Select>
              </FieldRow>
              {canSchedule && (
                <FieldRow>
                  <Input name="start_date" label="Start date" type="date" defaultValue={project.start_date} disabled={!isOpen} error={mutationError?.errors?.start_date?.[0]} />
                  <Input name="due_date" label="Deadline" type="date" defaultValue={project.due_date} disabled={!isOpen} error={mutationError?.errors?.due_date?.[0]} />
                </FieldRow>
              )}
              <p className="project-edit-status">Status: <ProjectStatusBadge project={project} /> — unchanged by editing.</p>
            </FormSection>
            <FormSection title="Brief" description="Optional details, including the project priority.">
              <ProjectBriefFields key={project.id} errors={mutationError?.errors} defaults={project.brief} />
            </FormSection>
            {mutationError && !mutationError.errors?.title && Object.keys(mutationError.errors ?? {}).length === 0 && <Alert variant="error">{mutationError.message}</Alert>}
            {mutationError?.errors?.status && <Alert variant="error">{mutationError.errors.status[0]}</Alert>}
            <FormActions submitLabel="Save changes" loadingLabel="Saving…" loading={isSaving} onCancel={() => setProjectEditor(null)} />
          </form>
        )}
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
