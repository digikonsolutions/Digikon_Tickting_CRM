import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import {
  Alert, Button, Card, FieldRow, FilterChips, FormActions, FormSection, Input, PageHeader, Pagination,
  ProjectCardList, Select, Textarea, useToast,
} from '../../../components/ui'
import { DEFAULT_CAPACITY } from '../../../lib/constants'
import { formatHours, toDateStr, todayStr } from '../../../lib/format'
import { FilePicker, ProjectBriefFields, readProjectBrief, uploadErrorMessage } from '../../briefs'

const CAPACITY_DEBOUNCE_MS = 300
const PROJECTS_PER_PAGE = 25
const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

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
  const [briefFiles, setBriefFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const { showToast } = useToast()
  const [isCreating, setIsCreating] = useState(false)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const loadAssignments = useCallback(() => authService.listMyAssignments(), [])
  const loadProjects = useCallback(
    () => authService.listAgencyProjects({ page, perPage: PROJECTS_PER_PAGE, status: statusFilter || undefined, search: search || undefined }),
    [page, statusFilter, search],
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
  const hours = Number(formValues.estimated_hours)
  const datesInvalid = Boolean(formValues.start_date && formValues.due_date && formValues.due_date < formValues.start_date)
  const canSchedule = selectedAssignment
    && remaining !== null
    && hours > 0
    && hours <= remaining
    && !datesInvalid
  const fieldError = (field) => createError?.errors?.[field]?.[0]

  // Why the submit button is disabled, said in words next to it.
  let disabledReason = null
  if (!isLoading && assignments.length === 0) disabledReason = 'Add an employee first, then you can create a project.'
  else if (!selectedAssignment) disabledReason = 'Choose an employee to check their availability.'
  else if (datesInvalid) disabledReason = 'The due date must be on or after the start date.'
  else if (isCheckingCapacity) disabledReason = 'Checking the employee’s capacity…'
  else if (capacityResult?.error) disabledReason = 'Capacity could not be checked. Try changing the dates.'
  else if (remaining !== null && hours > remaining) disabledReason = `Reduce the hours to ${formatHours(remaining)} or fewer, or extend the dates.`
  else if (!(hours > 0)) disabledReason = 'Enter the estimated hours.'

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
      const created = await authService.createAgencyProject({
        title: String(formData.get('title')).trim(),
        description: String(formData.get('description')).trim(),
        estimated_hours: Number(formData.get('estimated_hours')),
        start_date: String(formData.get('start_date')),
        due_date: String(formData.get('due_date')),
        assignment_id: Number(selectedAssignment),
        ...readProjectBrief(formData),
      })
      let uploadProblem = null
      if (briefFiles.length) {
        try {
          await authService.uploadProjectAttachments(created.project.id, briefFiles)
        } catch (reason) {
          uploadProblem = uploadErrorMessage(reason)
        }
      }
      if (uploadProblem) showToast(`Project created, but the files were not uploaded: ${uploadProblem} Add them from the project page.`, 'error')
      else showToast('Project created and work hours scheduled.')
      form.reset()
      setBriefFiles([])
      setFileNotice(null)
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

  function submitSearch(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
    setPage(1)
  }

  const capacityCallout = (() => {
    if (!selectedAssignment) return null
    if (isCheckingCapacity) return <Alert variant="info" className="ui-form-callout">Checking {selectedHold?.employee?.name ?? 'the employee'}’s availability…</Alert>
    if (capacityResult?.error) return <Alert variant="error" className="ui-form-callout">{capacityResult.error.message ?? 'Could not check employee capacity.'}</Alert>
    if (remaining === null) return null
    const name = selectedHold?.employee?.name ?? 'This employee'
    return hours > remaining
      ? <Alert variant="warning" className="ui-form-callout" title="Not enough capacity">{name} has {formatHours(remaining)} available between these dates, but the project needs {formatHours(hours)}. Reduce the hours or extend the dates.</Alert>
      : <Alert variant="info" className="ui-form-callout">{name} has <strong>{formatHours(remaining)}</strong> available between these dates.</Alert>
  })()

  return (
    <section className="page agency-projects-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Agency projects"
        subtitle={isClient
          ? 'View agency projects and statuses. Create projects using your employees.'
          : 'Create projects using your employees and review agency project schedules.'}
      />
      <ApiErrorMessage error={error ?? (createError?.status === 422 ? null : createError)} />
      <Card
        className="ui-form-card"
        title="Create a project"
        description={`Your assignment has a shared weekly budget of ${holdCapacity.weekly_hours} hours, with at most ${holdCapacity.daily_hours} hours scheduled each working day.`}
      >
        {!isLoading && assignments.length === 0 && (
          <Alert variant="info" className="ui-form-callout">
            You need to add an employee before creating a project. <Link to="/agency/employees">Go to My employees</Link>.
          </Alert>
        )}
        <form className="ui-form" onSubmit={createProject}>
          <FormSection title="Basics" description="What is the work, in a sentence or two?">
            <Input id="project-title" label="Project title" name="title" maxLength="160" required error={fieldError('title')} />
            <Textarea id="project-description" label="Description" name="description" rows="4" maxLength="5000" required error={fieldError('description')} />
          </FormSection>

          <FormSection title="Schedule" description="Hours are scheduled earliest-first, Monday to Saturday.">
            <FieldRow columns={3}>
              <Input
                id="project-hours"
                label="Estimated work hours"
                name="estimated_hours"
                type="number"
                inputMode="decimal"
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
                error={fieldError('due_date') ?? (datesInvalid ? 'Must be on or after the start date.' : undefined)}
              />
            </FieldRow>
          </FormSection>

          <FormSection title="Assignment" description="Only employees you have added can be used.">
            <Select
              id="project-assignment"
              label="Employee"
              value={selectedAssignment}
              onChange={(event) => setSelectedAssignment(event.target.value)}
              required
              disabled={isLoading || assignments.length === 0}
              error={fieldError('assignment_id')}
            >
              <option value="">{isLoading ? 'Loading your employees…' : 'Choose an employee'}</option>
              {assignments.map((assignment) => (
                <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>
              ))}
            </Select>
            {capacityCallout}
          </FormSection>

          <FormSection title="Brief and files" description="Optional. Add anything that helps your employee get started.">
            <ProjectBriefFields errors={createError?.errors} />
            <FilePicker files={briefFiles} onChange={setBriefFiles} notice={fileNotice} onNotice={setFileNotice} disabled={isCreating} />
          </FormSection>

          {createError?.status === 422 && createError.message && !createError.errors && <Alert variant="error">{createError.message}</Alert>}
          <FormActions
            submitLabel="Create project"
            loadingLabel="Scheduling project…"
            loading={isCreating}
            disabled={isLoading || !canSchedule}
            disabledReason={disabledReason}
          />
        </form>
      </Card>

      <Card title={isClient ? 'My projects' : 'Agency projects'} description="Select a project to view and manage its tickets.">
        <form className="ui-toolbar" onSubmit={submitSearch} role="search">
          <Input id="project-search" label="Search projects" type="search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Title, client or employee" />
          <Button type="submit" variant="secondary" loading={isLoadingProjects && Boolean(searchInput)} loadingText="Searching…">Search</Button>
        </form>
        <FilterChips label="Filter by status" options={STATUS_FILTERS} value={statusFilter} disabled={isLoadingProjects} onChange={(value) => { setStatusFilter(value); setPage(1) }} />
        {error && (
          <Button type="button" variant="secondary" onClick={() => { assignmentsState.reload(); projectsState.reload() }}>Try again</Button>
        )}
        {isLoadingProjects && !projectsState.data
          ? <p role="status">Loading projects…</p>
          : (
            <ProjectCardList
              projects={projects}
              routeFor={(project) => `/agency/projects/${project.id}`}
              actionsFor={user.role === 'agency_owner' ? (project) => (
                <>
                  <Link className="ui-button ui-button-secondary ui-button-sm" to={`/agency/projects/${project.id}?edit=1`}>Edit project</Link>
                  {project.status !== 'cancelled' && project.assignment_status === 'active' && (
                    <Link className="ui-button ui-button-primary ui-button-sm" to={`/agency/projects/${project.id}?newTicket=1`}>Add ticket</Link>
                  )}
                </>
              ) : undefined}
              showClient
              emptyTitle={statusFilter || search ? 'No matching projects' : 'No projects yet'}
              emptyText={statusFilter || search ? 'Try a different status or search.' : 'Create your first project above to start assigning work.'}
            />
          )}
        <Pagination
          page={pagination.current_page}
          lastPage={pagination.last_page}
          total={pagination.total}
          noun="project"
          loading={isLoadingProjects}
          onPrevious={() => setPage((value) => value - 1)}
          onNext={() => setPage((value) => value + 1)}
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
