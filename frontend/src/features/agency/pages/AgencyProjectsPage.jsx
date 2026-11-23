import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, FilterChips, Input, PageHeader, Pagination, ProjectCardList, useToast } from '../../../components/ui'
import { ProjectFormModal } from '../components/ProjectFormModal'
import { RushRequestModal } from '../../queue/components/RushRequestModal'

const PROJECTS_PER_PAGE = 25
const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

// The list comes first; creating and editing a project both happen in a dialog (the same pattern as Clients).
export function AgencyProjectsPage({ viewAs }) {
  const { user } = useAuth()
  const isClient = (viewAs ?? user.role) === 'agency_client'
  const isOwner = user.role === 'agency_owner'
  const { showToast } = useToast()
  const [params, setParams] = useSearchParams()
  const statusFilter = params.get('status') ?? ''
  const search = params.get('q') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [searchInput, setSearchInput] = useState(search)
  const [form, setForm] = useState({ open: params.get('new') === '1', project: null })
  const [opening, setOpening] = useState(false)
  const [openError, setOpenError] = useState(null)
  const [rush, setRush] = useState(null)

  const update = useCallback((changes) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
      return next
    }, { replace: true })
  }, [setParams])

  useEffect(() => {
    if (searchInput === search) return undefined
    const timer = window.setTimeout(() => update({ q: searchInput.trim(), page: '' }), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, search, update])

  const loadAssignments = useCallback(() => authService.listMyAssignments(), [])
  const loadProjects = useCallback(
    () => authService.listAgencyProjects({ page, perPage: PROJECTS_PER_PAGE, status: statusFilter || undefined, search: search || undefined }),
    [page, statusFilter, search],
  )
  const assignmentsState = useAsync(loadAssignments)
  const projectsState = useAsync(loadProjects)
  const error = assignmentsState.error ?? projectsState.error
  const projects = projectsState.data?.projects ?? []
  const pagination = projectsState.data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const assignments = assignmentsState.data?.assignments ?? []
  const loading = projectsState.isLoading && !projectsState.data
  const hasFilters = Boolean(statusFilter || search)

  async function openEdit(summary) {
    setOpening(true)
    setOpenError(null)
    try {
      const { project } = await authService.getAgencyProject(summary.id)
      setForm({ open: true, project })
    } catch (reason) {
      setOpenError(reason)
    } finally {
      setOpening(false)
    }
  }

  function closeForm() {
    setForm({ open: false, project: null })
    update({ new: '' })
  }

  function saved(project, { created, uploadNotice }) {
    closeForm()
    if (uploadNotice) showToast(`Project created, but the files were not uploaded: ${uploadNotice} Add them from the project page.`, 'error')
    else showToast(created ? 'Project created and work hours scheduled.' : 'Project updated.')
    if (created && page !== 1) update({ page: '' })
    else projectsState.reload()
  }

  return (
    <section className="page agency-projects-page">
      <PageHeader
        eyebrow="Agency workspace"
        title={isClient ? 'My projects' : 'Projects'}
        subtitle="Select a project to see its tickets, hours and files."
        action={<Button type="button" onClick={() => setForm({ open: true, project: null })}>Create project</Button>}
      />
      <ApiErrorMessage error={openError} />
      <div className="client-filters" role="search">
        <Input id="project-search" label="Search projects" type="search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Title, client or employee" />
        {hasFilters && <Button type="button" variant="secondary" size="sm" onClick={() => { setSearchInput(''); setParams({}, { replace: true }) }}>Clear filters</Button>}
      </div>
      <FilterChips label="Filter by status" options={STATUS_FILTERS} value={statusFilter} disabled={projectsState.isLoading} onChange={(value) => update({ status: value, page: '' })} />

      {error ? (
        <div role="alert">
          <ApiErrorMessage error={error} />
          <Button type="button" variant="secondary" onClick={() => { assignmentsState.reload(); projectsState.reload() }}>Try again</Button>
        </div>
      ) : loading ? (
        <p role="status">Loading projects…</p>
      ) : (
        <ProjectCardList
          projects={projects}
          routeFor={(project) => `/agency/projects/${project.id}`}
          actionsFor={isOwner ? (project) => (
            <>
              {['scheduled', 'in_progress'].includes(project.status) && (
                <Button type="button" variant="secondary" size="sm" disabled={opening} onClick={() => openEdit(project)}>Edit project</Button>
              )}
              {project.status !== 'cancelled' && project.assignment_status === 'active' && (
                <Link className="ui-button ui-button-primary ui-button-sm" to={`/agency/projects/${project.id}?newTicket=1`}>Add ticket</Link>
              )}
            </>
          ) : undefined}
          showClient
          emptyTitle={hasFilters ? 'No matching projects' : 'No projects yet'}
          emptyText={hasFilters ? 'Try a different status or search.' : 'Create your first project to start assigning work.'}
        />
      )}
      {!error && projects.length > 0 && (
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="project" loading={projectsState.isLoading} onPrevious={() => update({ page: String(page - 1) })} onNext={() => update({ page: String(page + 1) })} />
      )}
      {!assignmentsState.isLoading && assignments.length === 0 && !error && (
        <Alert variant="info">You have no employee on your agency yet, so projects cannot be created. <Link to="/agency/employees">Go to Employees</Link>.</Alert>
      )}
      {form.open && (
        <ProjectFormModal project={form.project} assignments={assignments} assignmentsLoading={assignmentsState.isLoading} onClose={closeForm} onSaved={saved} onRequestUrgent={(values) => { closeForm(); setRush(values) }} />
      )}
      {rush && (
        <RushRequestModal assignments={assignments} defaultAssignmentId={rush.assignmentId} defaults={rush.defaults} onClose={() => setRush(null)} onSubmitted={() => { setRush(null); showToast('Request sent. You will get a quote before anything is charged.') }} />
      )}
    </section>
  )
}

export function AgencyClientProjectsPage() {
  return <AgencyProjectsPage viewAs="agency_client" />
}

export function AgencyOwnerProjectsPage() {
  return <AgencyProjectsPage viewAs="agency_owner" />
}
