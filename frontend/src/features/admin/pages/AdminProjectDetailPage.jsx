import { useCallback } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Icon, PageHeader, Spinner, StatusBadge, Tabs } from '../../../components/ui'
import { formatDateRange, formatHours } from '../../../lib/format'
import { GhlBriefView } from '../../workorder/BriefView'
import { ProjectEmployee } from '../../agency/components/ProjectEmployee'
import { ProjectHoursPanel } from '../../agency/components/ProjectHoursPanel'
import { ProjectServicesView } from '../../agency/components/projectCreate/ProjectServicesView'
import { ProjectTicketsTable } from '../components/ProjectTicketsTable'
import '../components/projectTickets.css'

const TAB_IDS = ['tickets', 'hours', 'details']

// Four numbers that answer "where is this project?" before anything else is read.
function SummaryStrip({ project, tickets }) {
  const counted = tickets.filter((ticket) => ticket.status !== 'cancelled')
  const done = counted.filter((ticket) => ticket.status === 'done').length
  const hours = project.hours ?? {}
  const logged = hours.logged ?? tickets.reduce((sum, ticket) => sum + Number(ticket.logged_hours ?? 0), 0)
  const inTickets = hours.allocated ?? counted.reduce((sum, ticket) => sum + Number(ticket.estimated_hours), 0)
  const total = hours.total ?? Number(project.estimated_hours ?? 0)

  return (
    <div className="aps" aria-label="Project at a glance">
      <div className="aps-item">
        <span className="aps-label">Tickets</span>
        <span className="aps-value">{done} of {counted.length} done</span>
        <span className="aps-sub">{counted.length - done} still open</span>
      </div>
      <div className="aps-item">
        <span className="aps-label">Hours</span>
        <span className="aps-value">{formatHours(logged)} logged</span>
        <span className="aps-sub">{formatHours(inTickets)} in tickets, of {formatHours(total)} for the project</span>
      </div>
      <div className="aps-item">
        <span className="aps-label">Dates</span>
        <span className="aps-value">{formatDateRange(project.start_date, project.due_date)}</span>
        <span className="aps-sub"><StatusBadge status={project.status} /></span>
      </div>
      <div className="aps-item">
        <span className="aps-label">Employee</span>
        <span className="aps-value">{project.employee ? <Link to={`/admin/employees/${project.employee.id}`}>{project.employee.name}</Link> : 'Unassigned'}</span>
        <span className="aps-sub">{project.employee?.headline || project.client?.name || ''}</span>
      </div>
    </div>
  )
}

// Read-mostly view of one project for the platform team: the tickets first (grouped by the service each is for), then
// the hours (where an admin can change them) and the details the agency sent.
export function AdminProjectDetailPage() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab')
  const tab = TAB_IDS.includes(requested) ? requested : 'tickets'
  const loadProject = useCallback(() => authService.getAdminProject(id), [id])
  const { data, error, isLoading, reload } = useAsync(loadProject)
  const project = data?.project
  const tickets = data?.tickets ?? []

  if (isLoading && !data) {
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

  const openCount = tickets.filter((ticket) => !['done', 'cancelled'].includes(ticket.status)).length
  const tabs = [
    { id: 'tickets', label: 'Tickets', count: tickets.length },
    { id: 'hours', label: 'Hours' },
    { id: 'details', label: 'Details' },
  ]
  const setTab = (next) => setParams(next === 'tickets' ? {} : { tab: next }, { replace: true })

  return (
    <section className="page admin-project-detail">
      <PageHeader
        eyebrow={[project.agency_name, project.client?.name].filter(Boolean).join(' · ') || 'Administration'}
        title={project.title}
        subtitle={openCount > 0 ? `${openCount} ticket${openCount === 1 ? '' : 's'} still open` : 'No open tickets'}
        action={(
          <Link className="ui-button ui-button-secondary admin-project-back-button" to="/admin/projects">
            <Icon name="arrow" size={16} />
            All projects
          </Link>
        )}
      />

      <SummaryStrip project={project} tickets={tickets} />
      <Tabs tabs={tabs} activeTab={tab} onChange={setTab} label="Project sections" />

      {tab === 'tickets' && (
        <Card title="Tickets" description="Grouped by the service each one is for. Open a row for its description and schedule.">
          <ProjectTicketsTable tickets={tickets} services={project.services ?? []} />
        </Card>
      )}

      {tab === 'hours' && <ProjectHoursPanel hours={project.hours} tickets={tickets} admin projectId={project.id} onChanged={reload} />}

      {tab === 'details' && (
        <>
          <Card title="What the agency asked for">
            <p className="admin-project-description-text">{project.description || 'No project description provided.'}</p>
            <ProjectEmployee employee={project.employee} feedbackTo={project.employee ? `/admin/employees/${project.employee.id}` : null} />
          </Card>
          <ProjectServicesView services={project.services} />
          {project.ghl_brief && <Card title="GoHighLevel brief" description="Internal notes are shown to staff only."><GhlBriefView brief={project.ghl_brief} /></Card>}
        </>
      )}
    </section>
  )
}
