import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../../../auth/authService'
import { useAsync } from '../../../../../hooks/useAsync'
import { ApiErrorMessage } from '../../../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, EmptyState, Icon, PageHeader, Spinner } from '../../../../../components/ui'

const PROJECT_FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'in_progress', label: 'In progress' },
  { id: 'scheduled', label: 'Scheduled' },
  { id: 'completed', label: 'Completed' },
]
const EMPTY_PROJECTS = []
const EMPTY_WEEK_HOURS = Array.from({ length: 7 }, () => 0)

const STATUS_PRESENTATION = {
  scheduled: { label: 'Scheduled', variant: 'info' },
  in_progress: { label: 'In progress', variant: 'primary' },
  completed: { label: 'Completed', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'slate' },
  at_risk: { label: 'At risk', variant: 'warning' },
  overdue: { label: 'Overdue', variant: 'danger' },
}

function localDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

async function loadAllAdminProjects() {
  const firstPage = await authService.listAdminProjects({ page: 1, perPage: 100 })
  const pages = Array.from(
    { length: Math.max(0, firstPage.pagination.last_page - 1) },
    (_, index) => index + 2,
  )
  const remaining = []

  for (let index = 0; index < pages.length; index += 4) {
    const batch = await Promise.all(pages.slice(index, index + 4).map((page) => (
      authService.listAdminProjects({ page, perPage: 100 })
    )))
    remaining.push(...batch.flatMap((response) => response.projects))
  }

  return {
    projects: [...firstPage.projects, ...remaining],
    total: firstPage.pagination.total,
  }
}

async function loadDashboard() {
  const [projectData, ticketResponse] = await Promise.all([
    loadAllAdminProjects(),
    authService.listAdminTickets({ perPage: 1 }),
  ])
  return {
    ...projectData,
    ticketTotal: ticketResponse.pagination.total,
    today: localDate(new Date()),
  }
}

function projectStatus(project, today) {
  if (project.status === 'scheduled' || project.status === 'in_progress') {
    if (!project.due_date) return project.status
    if (project.due_date < today) return 'overdue'
    const daysUntilDue = Math.floor(
      (new Date(`${project.due_date}T00:00:00`) - new Date(`${today}T00:00:00`)) / 86400000,
    )
    if (daysUntilDue <= 2) return 'at_risk'
  }
  return project.status
}

function statusCount(projects, status) {
  return projects.filter((project) => project.status === status).length
}

function formatDueDate(value) {
  if (!value) return 'No due date'
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' })
    .format(new Date(`${value}T00:00:00`))
}

function employeeInitials(name) {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}

function getNextWeekHours(projects, today) {
  const totals = Array.from({ length: 7 }, () => 0)
  const start = new Date(`${today}T00:00:00`)

  projects
    .filter((project) => !['cancelled', 'completed'].includes(project.status))
    .flatMap((project) => project.schedule ?? [])
    .forEach((allocation) => {
      const date = new Date(`${allocation.work_date}T00:00:00`)
      const offset = Math.floor((date - start) / 86400000)
      if (offset >= 0 && offset < totals.length) totals[offset] += Number(allocation.hours)
    })

  return totals
}

function sparklinePath(values, width = 168, height = 40) {
  if (values.length < 2) return ''
  const max = Math.max(...values, 1)
  const min = Math.min(...values, 0)
  const range = max - min || 1
  const step = width / (values.length - 1)
  return values.map((value, index) => {
    const x = index * step
    const y = height - ((value - min) / range) * (height - 6) - 3
    return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}

function ProjectRow({ project, today }) {
  const status = projectStatus(project, today)
  const presentation = STATUS_PRESENTATION[status] ?? {
    label: status?.replace('_', ' ') ?? 'Unknown',
    variant: 'slate',
  }
  const projectOwner = project.client?.name ?? project.employee?.name ?? 'Project team'
  const employeeName = project.employee?.name
  const initials = employeeInitials(employeeName)

  return (
    <li className="admin-project-row">
      <span
        className={`admin-project-avatar${initials ? '' : ' admin-project-avatar-neutral'}`}
        role="img"
        title={employeeName ?? 'Unassigned employee'}
        aria-label={employeeName ? `Employee: ${employeeName}` : 'Unassigned employee'}
      >
        {initials || <Icon name="user" size={16} />}
      </span>
      <span className="admin-project-main">
        <strong><Link to={`/admin/projects/${project.id}`}>{project.title}</Link></strong>
        <span>{project.agency_name ?? 'Agency'} · {projectOwner}</span>
      </span>
      <span className="admin-project-due">{status === 'overdue' ? 'Past due' : `Due ${formatDueDate(project.due_date)}`}</span>
      <Badge variant={presentation.variant}>{presentation.label}</Badge>
    </li>
  )
}

function StatCard({ label, value, hint, icon, tone, sparkline, sparklineLabel }) {
  return (
    <article className={`admin-stat-card admin-stat-card-${tone}`}>
      <div className="admin-stat-topline">
        <span className="admin-stat-icon"><Icon name={icon} size={19} /></span>
        <span className="admin-stat-label">{label}</span>
      </div>
      <strong className="admin-stat-value">{value}</strong>
      {sparkline && (
        <svg
          className="admin-stat-sparkline"
          viewBox="0 0 168 40"
          role="img"
          aria-label={sparklineLabel}
          preserveAspectRatio="none"
        >
          <path d={sparkline} />
        </svg>
      )}
      <span className="admin-stat-hint">{hint}</span>
    </article>
  )
}

export const AdminDashboard = () => {
  const [activeFilter, setActiveFilter] = useState('all')
  const dashboard = useAsync(useCallback(() => loadDashboard(), []))
  const projects = dashboard.data?.projects ?? EMPTY_PROJECTS
  const today = dashboard.data?.today
  const projectsTotal = dashboard.data?.total ?? 0
  const scheduledCount = statusCount(projects, 'scheduled')
  const inProgressCount = statusCount(projects, 'in_progress')
  const completedCount = statusCount(projects, 'completed')
  const activeCount = scheduledCount + inProgressCount
  const completionPercent = projectsTotal > 0
    ? Math.round((completedCount / projectsTotal) * 100)
    : 0
  const scheduledHours = dashboard.data ? getNextWeekHours(projects, today) : EMPTY_WEEK_HOURS
  const totalScheduledHours = scheduledHours.reduce((sum, hours) => sum + hours, 0)
  const scheduledHoursPath = sparklinePath(scheduledHours)
  const filteredProjects = activeFilter === 'all'
    ? projects
    : projects.filter((project) => project.status === activeFilter)
  const visibleProjects = filteredProjects.slice(0, 4)

  return (
    <section className="page dashboard admin-dashboard">
      <PageHeader
        eyebrow="Administration"
        title="Workspace overview"
        subtitle="Monitor projects and delivery across every agency."
      />

      <ApiErrorMessage error={dashboard.error} />
      {dashboard.error && (
        <Button type="button" variant="secondary" onClick={dashboard.reload}>Try again</Button>
      )}

      {!dashboard.error && (
        <Card className="admin-hero-card">
          {dashboard.isLoading
            ? <div className="admin-dashboard-loading" role="status"><Spinner /> Loading workspace data…</div>
            : (
            <div className="admin-hero-content">
              <div className="admin-hero-copy">
                <p className="admin-hero-eyebrow">Project delivery</p>
                <strong className="admin-hero-number">{projectsTotal}</strong>
                <p className="admin-hero-caption">Projects across all agencies</p>
              </div>
              <div className="admin-hero-progress">
                <div className="admin-hero-progress-heading">
                  <strong>{completedCount} completed · {activeCount} active</strong>
                  <span>{completionPercent}%</span>
                </div>
                <div
                  className="admin-progress-track"
                  role="progressbar"
                  aria-label="Project completion"
                  aria-valuemin="0"
                  aria-valuemax={projectsTotal}
                  aria-valuenow={completedCount}
                >
                  <span style={{ width: `${completionPercent}%` }} />
                </div>
                <div className="admin-hero-substats">
                  <span><strong>{scheduledCount}</strong> scheduled</span>
                  <span>·</span>
                  <span><strong>{inProgressCount}</strong> in progress</span>
                  <span>·</span>
                  <span><strong>{completedCount}</strong> completed</span>
                </div>
              </div>
            </div>
          )}
        </Card>
      )}

      {!dashboard.isLoading && !dashboard.error && (
        <>
          <div className="admin-dashboard-metrics">
            <StatCard
              label="Active projects"
              value={activeCount}
              hint="Scheduled or in progress"
              icon="queue"
              tone="teal"
            />
            <StatCard
              label="Scheduled hours"
              value={`${totalScheduledHours.toFixed(1)} h`}
              hint="Across the next 7 days"
              icon="clock"
              tone="info"
              sparkline={scheduledHoursPath}
              sparklineLabel={`Scheduled hours across the next seven days: ${scheduledHours.map((hours, index) => `day ${index + 1}, ${hours.toFixed(1)} hours`).join('; ')}`}
            />
            <StatCard
              label="Completed"
              value={completedCount}
              hint="Projects delivered"
              icon="check"
              tone="success"
            />
            <StatCard
              label="Tickets"
              value={dashboard.data?.ticketTotal ?? 0}
              hint="Across all projects"
              icon="ticket"
              tone="teal"
            />
          </div>

          <div className="admin-dashboard-content">
            <Card className="admin-recent-card">
              <div className="admin-card-heading">
                <div>
                  <h2>Recent projects</h2>
                  <p>Latest work across your agencies</p>
                </div>
                <Link className="admin-text-link" to="/admin/projects">View all</Link>
              </div>
              <nav className="admin-project-filters" aria-label="Filter projects by status">
                {PROJECT_FILTERS.map((filter) => (
                  <button
                    className={activeFilter === filter.id ? 'admin-project-filter is-active' : 'admin-project-filter'}
                    key={filter.id}
                    type="button"
                    aria-pressed={activeFilter === filter.id}
                    onClick={() => setActiveFilter(filter.id)}
                  >
                    {filter.label} {filter.id === 'all' ? projectsTotal : statusCount(projects, filter.id)}
                  </button>
                ))}
              </nav>
              {filteredProjects.length
                ? (
                  <>
                    <ul className="admin-project-list">
                      {visibleProjects.map((project) => <ProjectRow key={project.id} project={project} today={today} />)}
                    </ul>
                    {filteredProjects.length > visibleProjects.length && (
                      <Link className="admin-list-more" to="/admin/projects">
                        View {filteredProjects.length - visibleProjects.length} more projects
                        <Icon name="arrow" size={16} />
                      </Link>
                    )}
                  </>
                )
                : <EmptyState title="No projects in this status">Projects matching this filter will appear here.</EmptyState>}
            </Card>

            <aside className="admin-dashboard-aside">
              <Card className="admin-quick-actions">
                <div className="admin-card-heading">
                  <div><h2>Quick actions</h2><p>Common administration tasks</p></div>
                </div>
                <Link className="admin-quick-action" to="/admin/agency-owners/new">
                  <span className="admin-quick-action-icon"><Icon name="building" size={18} /></span>
                  <span><strong>Invite agency owner</strong><small>Create an agency and send an invitation.</small></span>
                </Link>
                <Link className="admin-quick-action" to="/admin/employees/new">
                  <span className="admin-quick-action-icon"><Icon name="userPlus" size={18} /></span>
                  <span><strong>Add employee</strong><small>Add an employee to the shared pool.</small></span>
                </Link>
                <Link className="admin-quick-action" to="/admin/assignments">
                  <span className="admin-quick-action-icon"><Icon name="calendar" size={18} /></span>
                  <span><strong>Review assignments</strong><small>See active and ended employee holds.</small></span>
                </Link>
              </Card>
            </aside>
          </div>
        </>
      )}
    </section>
  )
}
