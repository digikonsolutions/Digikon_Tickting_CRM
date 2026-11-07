import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Alert, Badge, Button, Card, Input, PageHeader, Select, Table, Textarea } from '../../components/ui'

function localDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function scheduleLabel(schedule = []) {
  return schedule.map(({ work_date, hours }) => `${work_date}: ${hours}h`).join(', ') || 'No schedule'
}

export function AgencyProjectsPage({ viewAs }) {
  const { user } = useAuth()
  const isClient = (viewAs ?? user.role) === 'agency_client'
  const [today] = useState(() => localDate(new Date()))
  const [projects, setProjects] = useState([])
  const [availability, setAvailability] = useState({ key: '', employees: [] })
  const [availabilityVersion, setAvailabilityVersion] = useState(0)
  const [isLoadingProjects, setIsLoadingProjects] = useState(true)
  const [isLoadingEmployees, setIsLoadingEmployees] = useState(true)
  const [projectsError, setProjectsError] = useState(null)
  const [employeesError, setEmployeesError] = useState(null)
  const [createError, setCreateError] = useState(null)
  const [success, setSuccess] = useState('')
  const [isCreating, setIsCreating] = useState(false)
  const [selectedEmployee, setSelectedEmployee] = useState('')
  const [formValues, setFormValues] = useState(() => {
    const today = new Date()
    const due = new Date(today)
    due.setDate(due.getDate() + 6)
    return {
      start_date: localDate(today),
      due_date: localDate(due),
      estimated_hours: '6',
    }
  })

  const fieldError = (field) => createError?.errors?.[field]?.[0]
  const availabilityKey = `${formValues.start_date}|${formValues.due_date}|${formValues.estimated_hours}|${availabilityVersion}`
  const employees = availability.key === availabilityKey ? availability.employees : []
  const loadProjects = useCallback(async () => {
    try {
      const response = await authService.listAgencyProjects()
      setProjects(response.projects)
    } catch (error) {
      setProjectsError(error)
    } finally {
      setIsLoadingProjects(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAgencyProjects()
      .then(({ projects: agencyProjects }) => {
        if (active) setProjects(agencyProjects)
      })
      .catch((error) => {
        if (active) setProjectsError(error)
      })
      .finally(() => {
        if (active) setIsLoadingProjects(false)
      })

    return () => {
      active = false
    }
  }, [])

  const validAvailabilityRange = (
    formValues.start_date
    && formValues.due_date
    && formValues.due_date >= formValues.start_date
    && Number(formValues.estimated_hours) > 0
  )

  useEffect(() => {
    if (!validAvailabilityRange) return undefined

    let active = true
    authService.listAgencyEmployees({
      start_date: formValues.start_date,
      due_date: formValues.due_date,
      hours: formValues.estimated_hours,
    })
      .then(({ employees: availableEmployees }) => {
        if (!active) return
        setAvailability({ key: availabilityKey, employees: availableEmployees })
        setSelectedEmployee((selected) => (
          availableEmployees.some((employee) => String(employee.id) === selected && employee.available)
            ? selected
            : ''
        ))
      })
      .catch((error) => {
        if (active) setEmployeesError(error)
      })
      .finally(() => {
        if (active) setIsLoadingEmployees(false)
      })

    return () => {
      active = false
    }
  }, [availabilityKey, formValues.due_date, formValues.estimated_hours, formValues.start_date, validAvailabilityRange])

  function updateFormValue(field, value) {
    const updatedValues = { ...formValues, [field]: value }
    setFormValues(updatedValues)
    setEmployeesError(null)
    setIsLoadingEmployees(
      Boolean(updatedValues.start_date
        && updatedValues.due_date >= updatedValues.start_date
        && Number(updatedValues.estimated_hours) > 0)
    )
  }

  async function createProject(event) {
    event.preventDefault()
    if (isCreating) return
    const form = event.currentTarget
    const data = new FormData(form)
    setCreateError(null)
    setProjectsError(null)
    setSuccess('')
    setIsCreating(true)

    try {
      await authService.createAgencyProject({
        title: String(data.get('title')).trim(),
        description: String(data.get('description')).trim(),
        estimated_hours: Number(data.get('estimated_hours')),
        start_date: String(data.get('start_date')),
        due_date: String(data.get('due_date')),
        employee_id: Number(data.get('employee_id')),
      })
      setSuccess('Project created and work hours scheduled.')
      form.reset()
      setSelectedEmployee('')
      setIsLoadingEmployees(true)
      setAvailabilityVersion((version) => version + 1)
      await loadProjects()
    } catch (error) {
      setCreateError(error)
    } finally {
      setIsCreating(false)
    }
  }

  const columns = [
    { key: 'title', label: 'Project' },
    { key: 'description', label: 'Description' },
    ...(!isClient ? [{ key: 'client', label: 'Created by', render: (client) => client?.name ?? 'Account removed' }] : []),
    { key: 'employee', label: 'Assigned employee', render: (employee) => employee?.name ?? 'Unassigned' },
    { key: 'estimated_hours', label: 'Hours', render: (hours) => `${hours}h` },
    { key: 'due_date', label: 'Due date' },
    { key: 'status', label: 'Status', render: (status) => <Badge variant={status === 'completed' ? 'success' : 'primary'}>{status.replace('_', ' ')}</Badge> },
    { key: 'schedule', label: 'Daily schedule', render: scheduleLabel },
  ]
  const selectedAvailability = employees.find((employee) => String(employee.id) === selectedEmployee)

  return (
    <section className="page">
      <PageHeader
        eyebrow="Agency workspace"
        title={isClient ? 'My projects' : 'Agency projects'}
        subtitle={isClient
          ? 'Create projects and see your own assignments and schedules.'
          : 'Create projects and review every employee assignment and client request in your agency.'}
      />
      {success && <Alert variant="success">{success}</Alert>}
      <Card title="Create a project" description="Work is scheduled Monday–Saturday, up to 6 hours per employee per day.">
        <form className="agency-user-form" onSubmit={createProject}>
          <Input id="project-title" label="Project title" name="title" maxLength="160" required error={fieldError('title')} />
          <Textarea id="project-description" label="Description" name="description" rows="4" maxLength="5000" required error={fieldError('description')} />
          <Input
            id="project-hours"
            label="Estimated work hours"
            name="estimated_hours"
            type="number"
            min="0.01"
            max="1890"
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
            id="project-employee"
            label="Assign employee"
            name="employee_id"
            value={selectedEmployee}
            onChange={(event) => setSelectedEmployee(event.target.value)}
            required
            disabled={isLoadingEmployees || employees.length === 0}
            error={fieldError('employee_id')}
            helperText="Unavailable employees remain visible but cannot be selected for this date range."
          >
            <option value="">{isLoadingEmployees ? 'Checking availability…' : 'Select an employee'}</option>
            {employees.map((employee) => (
              <option key={employee.id} value={employee.id} disabled={!employee.available}>
                {employee.name} · {employee.available_hours}h available{employee.available ? '' : ' · insufficient capacity'}
              </option>
            ))}
          </Select>
          {selectedAvailability?.available && (
            <p role="status">Planned schedule: {scheduleLabel(selectedAvailability.schedule)}</p>
          )}
          <ApiErrorMessage error={employeesError} />
          <ApiErrorMessage error={createError?.status === 422 ? null : createError} />
          <Button type="submit" loading={isCreating} loadingText="Scheduling project…" disabled={isLoadingEmployees || !selectedEmployee}>
            Create project
          </Button>
        </form>
      </Card>
      <Card title="Agency projects" description="Projects and scheduled hours for your agency.">
        <ApiErrorMessage error={projectsError} />
        {projectsError && <Button type="button" variant="secondary" onClick={() => { setProjectsError(null); loadProjects() }}>Try again</Button>}
        <Table
          columns={columns}
          rows={projects}
          loading={isLoadingProjects}
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
