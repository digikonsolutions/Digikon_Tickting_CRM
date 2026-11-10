import { useCallback, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, Input, PageHeader, Select, Table, Textarea } from '../../../components/ui'

function localDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function scheduleLabel(schedule = []) {
  return schedule.map(({ work_date, hours }) => `${work_date}: ${hours}h`).join(', ') || 'No schedule'
}

function dateRangeRemaining(projects, assignmentId, start, due) {
  if (!start || !due || due < start) return 0
  const booked = new Map()
  projects
    .filter((project) => String(project.assignment_id) === String(assignmentId)
      && !['completed', 'cancelled'].includes(project.status))
    .flatMap((project) => project.schedule ?? [])
    .forEach(({ work_date, hours }) => booked.set(work_date, (booked.get(work_date) ?? 0) + Number(hours)))

  let total = 0
  const cursor = new Date(`${start}T12:00:00`)
  const end = new Date(`${due}T12:00:00`)
  while (cursor <= end) {
    const date = localDate(cursor)
    if (cursor.getDay() !== 0) total += Math.max(0, 6 - (booked.get(date) ?? 0))
    cursor.setDate(cursor.getDate() + 1)
  }
  return total
}

export function AgencyProjectsPage({ viewAs }) {
  const { user } = useAuth()
  const isClient = (viewAs ?? user.role) === 'agency_client'
  const [today] = useState(() => localDate(new Date()))
  const [formValues, setFormValues] = useState(() => {
    const due = new Date()
    due.setDate(due.getDate() + 6)
    return { start_date: localDate(new Date()), due_date: localDate(due), estimated_hours: '6' }
  })
  const [selectedAssignment, setSelectedAssignment] = useState('')
  const [createError, setCreateError] = useState(null)
  const [success, setSuccess] = useState('')
  const [isCreating, setIsCreating] = useState(false)
  const loadData = useCallback(async () => {
    const [projects, assignmentResponse] = await Promise.all([
      authService.listAgencyProjects(),
      authService.listMyAssignments(),
    ])
    return { projects: projects.projects, assignments: assignmentResponse.assignments }
  }, [])
  const { data, error, isLoading, reload } = useAsync(loadData)
  const projects = data?.projects ?? []
  const assignments = data?.assignments ?? []
  const remaining = selectedAssignment
    ? dateRangeRemaining(projects, selectedAssignment, formValues.start_date, formValues.due_date)
    : 0
  const canSchedule = selectedAssignment
    && Number(formValues.estimated_hours) > 0
    && Number(formValues.estimated_hours) <= remaining
    && formValues.due_date >= formValues.start_date
  const fieldError = (field) => createError?.errors?.[field]?.[0]

  function updateFormValue(field, value) {
    setFormValues((current) => ({ ...current, [field]: value }))
    setCreateError(null)
    setSuccess('')
  }

  async function createProject(event) {
    event.preventDefault()
    if (isCreating || !canSchedule) return
    const form = event.currentTarget
    const formData = new FormData(form)
    setCreateError(null)
    setSuccess('')
    setIsCreating(true)
    try {
      await authService.createAgencyProject({
        title: String(formData.get('title')).trim(),
        description: String(formData.get('description')).trim(),
        estimated_hours: Number(formData.get('estimated_hours')),
        start_date: String(formData.get('start_date')),
        due_date: String(formData.get('due_date')),
        assignment_id: Number(selectedAssignment),
      })
      setSuccess('Project created and work hours scheduled.')
      form.reset()
      setSelectedAssignment('')
      await reload()
    } catch (reason) {
      setCreateError(reason)
    } finally {
      setIsCreating(false)
    }
  }

  const columns = useMemo(() => [
    { key: 'title', label: 'Project', render: (title, project) => <Link to={`/agency/projects/${project.id}`}>{title}</Link> },
    { key: 'description', label: 'Description' },
    { key: 'client', label: isClient ? 'Client' : 'Created by', render: (client) => client?.name ?? 'Account removed' },
    { key: 'employee', label: 'Assigned employee', render: (employee) => employee?.name ?? 'Unassigned' },
    { key: 'estimated_hours', label: 'Hours', render: (hours) => `${hours}h` },
    { key: 'due_date', label: 'Due date' },
    { key: 'status', label: 'Status', render: (status) => <Badge variant={status === 'completed' ? 'success' : status === 'cancelled' ? 'slate' : 'primary'}>{status.replace('_', ' ')}</Badge> },
    { key: 'schedule', label: 'Daily schedule', render: scheduleLabel },
  ], [isClient])

  return (
    <section className="page agency-projects-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Agency projects"
        subtitle={isClient
          ? 'View agency projects and statuses. Create projects using employees you hold.'
          : 'Create projects using your held employees and review agency project schedules.'}
      />
      {success && <Alert variant="success">{success}</Alert>}
      <ApiErrorMessage error={error ?? (createError?.status === 422 ? null : createError)} />
      <Card title="Create a project" description="Your assignment has a shared weekly budget of 36 hours, with at most 6 hours scheduled each working day.">
        {!isLoading && assignments.length === 0 && (
          <Alert variant="info">
            You need to hold an employee before creating a project. <Link to="/agency/employees">Go to My employees</Link>.
          </Alert>
        )}
        <form className="agency-user-form" onSubmit={createProject}>
          <Input id="project-title" label="Project title" name="title" maxLength="160" required error={fieldError('title')} />
          <Textarea id="project-description" label="Description" name="description" rows="4" maxLength="5000" required error={fieldError('description')} />
          <Input
            id="project-hours"
            label="Estimated work hours"
            name="estimated_hours"
            type="number"
            min="0.01"
            max="180"
            step="0.01"
            value={formValues.estimated_hours}
            onChange={(event) => updateFormValue('estimated_hours', event.target.value)}
            required
            error={fieldError('estimated_hours')}
          />
          <Input
            id="project-start"
            label="Start date"
            name="start_date"
            type="date"
            min={today}
            value={formValues.start_date}
            onChange={(event) => updateFormValue('start_date', event.target.value)}
            required
            error={fieldError('start_date')}
          />
          <Input
            id="project-due"
            label="Due date"
            name="due_date"
            type="date"
            min={formValues.start_date || today}
            value={formValues.due_date}
            onChange={(event) => updateFormValue('due_date', event.target.value)}
            required
            error={fieldError('due_date')}
          />
          <Select
            id="project-assignment"
            label="My employee"
            value={selectedAssignment}
            onChange={(event) => setSelectedAssignment(event.target.value)}
            required
            disabled={isLoading || assignments.length === 0}
            error={fieldError('assignment_id')}
            helperText="Only your active employee assignments can be used."
          >
            <option value="">{isLoading ? 'Loading your employees…' : 'Select a held employee'}</option>
            {assignments.map((assignment) => (
              <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>
            ))}
          </Select>
          {selectedAssignment && (
            <p className={Number(formValues.estimated_hours) > remaining ? 'form-error' : 'assignment-range-capacity'} role="status">
              {remaining.toFixed(2)} h remain for this employee between the selected dates.
            </p>
          )}
          {createError?.status === 422 && createError.message && <p className="form-error" role="alert">{createError.message}</p>}
          <Button type="submit" loading={isCreating} loadingText="Scheduling project…" disabled={isLoading || !canSchedule}>
            Create project
          </Button>
        </form>
      </Card>
      <Card title={isClient ? 'My projects' : 'Agency projects'} description="Select a project to view and manage its tickets.">
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table
          columns={columns}
          rows={projects}
          loading={isLoading}
          emptyTitle="No projects yet"
          emptyText="Create a project above to start assigning work."
        />
      </Card>
    </section>
  )
}

export function AgencyClientProjectsPage() {
  return <AgencyProjectsPage viewAs="agency_client" />
}

export function AgencyOwnerProjectsPage() {
  return <AgencyProjectsPage viewAs="agency_owner" />
}
