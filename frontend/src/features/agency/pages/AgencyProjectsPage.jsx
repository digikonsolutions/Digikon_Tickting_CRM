import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, Input, PageHeader, Select, StatusBadge, Table, Textarea, useToast } from '../../../components/ui'
import { DEFAULT_CAPACITY } from '../../../lib/constants'
import { formatDate, formatHours, toDateStr, todayStr } from '../../../lib/format'

const CAPACITY_DEBOUNCE_MS = 300
const PROJECTS_PER_PAGE = 25
const STATUS_FILTERS = [
  { value: '', label: 'All statuses' },
  { value: 'open', label: 'Open' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

function scheduleLabel(schedule = []) {
  return schedule.map(({ work_date, hours }) => `${formatDate(work_date)}: ${formatHours(hours)}`).join(', ') || 'No schedule'
}

export function AgencyProjectsPage({ viewAs }) {
  const { user } = useAuth()
  const isClient = (viewAs ?? user.role) === 'agency_client'
  const [today] = useState(todayStr)
  const [formValues, setFormValues] = useState(() => {
    const due = new Date()
    due.setDate(due.getDate() + 6)
    return { start_date: todayStr(), due_date: toDateStr(due), estimated_hours: '6' }
  })
  const [selectedAssignment, setSelectedAssignment] = useState('')
  const [createError, setCreateError] = useState(null)
  const { showToast } = useToast()
  const [isCreating, setIsCreating] = useState(false)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const loadAssignments = useCallback(() => authService.listMyAssignments(), [])
  const loadProjects = useCallback(
    () => authService.listAgencyProjects({ page, perPage: PROJECTS_PER_PAGE, status: statusFilter || undefined }),
    [page, statusFilter],
  )
  const assignmentsState = useAsync(loadAssignments)
  const projectsState = useAsync(loadProjects)
  const error = assignmentsState.error ?? projectsState.error
  const isLoading = assignmentsState.isLoading
  const isLoadingProjects = projectsState.isLoading
  const reloadProjects = projectsState.reload
  const projects = projectsState.data?.projects ?? []
  const pagination = projectsState.data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const assignments = assignmentsState.data?.assignments ?? []
  const selectedHold = assignments.find((assignment) => String(assignment.id) === String(selectedAssignment)) ?? assignments[0]
  const holdCapacity = selectedHold?.capacity ?? DEFAULT_CAPACITY
  // Capacity comes from the server (same rules as the scheduler). Debounced, and
  // responses for an outdated assignment/date selection are ignored.
  const capacityKey = selectedAssignment && formValues.start_date && formValues.due_date
    && formValues.due_date >= formValues.start_date
    ? `${selectedAssignment}|${formValues.start_date}|${formValues.due_date}`
    : null
  const [capacity, setCapacity] = useState({ key: null, hours: null, error: null })
  useEffect(() => {
    if (!capacityKey) return undefined
    const [assignmentId, from, to] = capacityKey.split('|')
    let active = true
    const timer = setTimeout(async () => {
      try {
        const response = await authService.getCapacity({ assignmentId, from, to })
        if (active) setCapacity({ key: capacityKey, hours: Number(response.total_available_hours), error: null })
      } catch (reason) {
        if (active) setCapacity({ key: capacityKey, hours: null, error: reason })
      }
    }, CAPACITY_DEBOUNCE_MS)
    return () => {
      active = false
      clearTimeout(timer)
    }
  }, [capacityKey])
  const isCheckingCapacity = Boolean(capacityKey) && capacity.key !== capacityKey
  const capacityResult = capacityKey && capacity.key === capacityKey ? capacity : null
  const remaining = capacityResult?.hours ?? null
  const canSchedule = selectedAssignment
    && remaining !== null
    && Number(formValues.estimated_hours) > 0
    && Number(formValues.estimated_hours) <= remaining
    && formValues.due_date >= formValues.start_date
  const fieldError = (field) => createError?.errors?.[field]?.[0]

  function updateFormValue(field, value) {
    setFormValues((current) => ({ ...current, [field]: value }))
    setCreateError(null)
  }

  async function createProject(event) {
    event.preventDefault()
    if (isCreating || !canSchedule) return
    const form = event.currentTarget
    const formData = new FormData(form)
    setCreateError(null)
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
      showToast('Project created and work hours scheduled.')
      form.reset()
      setSelectedAssignment('')
      setCapacity({ key: null, hours: null, error: null })
      if (page !== 1) setPage(1)
      else await reloadProjects()
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
    { key: 'estimated_hours', label: 'Hours', render: formatHours },
    { key: 'due_date', label: 'Due date', render: formatDate },
    { key: 'status', label: 'Status', render: (status) => <StatusBadge status={status} /> },
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
      <ApiErrorMessage error={error ?? (createError?.status === 422 ? null : createError)} />
      <Card title="Create a project" description={`Your assignment has a shared weekly budget of ${holdCapacity.weekly_hours} hours, with at most ${holdCapacity.daily_hours} hours scheduled each working day.`}>
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
          {selectedAssignment && isCheckingCapacity && (
            <p className="assignment-range-capacity" role="status">Checking employee capacity…</p>
          )}
          {selectedAssignment && capacityResult?.error && (
            <p className="form-error" role="alert">{capacityResult.error.message ?? 'Could not check employee capacity.'}</p>
          )}
          {selectedAssignment && remaining !== null && (
            <p className={Number(formValues.estimated_hours) > remaining ? 'form-error' : 'assignment-range-capacity'} role="status">
              {formatHours(remaining)} available for {selectedHold?.employee?.name ?? 'this employee'} between these dates.
            </p>
          )}
          {createError?.status === 422 && createError.message && <p className="form-error" role="alert">{createError.message}</p>}
          <Button type="submit" loading={isCreating} loadingText="Scheduling project…" disabled={isLoading || !canSchedule}>
            Create project
          </Button>
        </form>
      </Card>
      <Card title={isClient ? 'My projects' : 'Agency projects'} description="Select a project to view and manage its tickets.">
        <div className="admin-users-search">
          <Select
            id="project-status-filter"
            label="Status"
            value={statusFilter}
            onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }}
            disabled={isLoadingProjects}
          >
            {STATUS_FILTERS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </Select>
        </div>
        {error && (
          <Button type="button" variant="secondary" onClick={() => { assignmentsState.reload(); projectsState.reload() }}>Try again</Button>
        )}
        <Table
          columns={columns}
          rows={projects}
          loading={isLoadingProjects}
          emptyTitle={statusFilter ? 'No matching projects' : 'No projects yet'}
          emptyText={statusFilter ? 'Try a different status filter.' : 'Create a project above to start assigning work.'}
        />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page} · {pagination.total} project{pagination.total === 1 ? '' : 's'}</span>
          <div>
            <Button type="button" variant="secondary" size="sm" disabled={isLoadingProjects || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button>
            <Button type="button" variant="secondary" size="sm" disabled={isLoadingProjects || page >= pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button>
          </div>
        </div>
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
