import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../../../auth/authService'
import { useAsync } from '../../../../../hooks/useAsync'
import { ApiErrorMessage } from '../../../../auth/components/ApiErrorMessage'
import { Button, Card, DueText, EmptyState, Icon, PageHeader, Spinner, StatusBadge } from '../../../../../components/ui'
import { getDueInfo } from '../../../../../lib/format'

const PROJECT_FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'in_progress', label: 'In progress' },
  { id: 'scheduled', label: 'Scheduled' },
  { id: 'completed', label: 'Completed' },
]
const EMPTY_WEEK_HOURS = Array.from({ length: 7 }, () => 0)
const EMPTY_COUNTS = { total: 0, scheduled: 0, in_progress: 0, completed: 0, cancelled: 0 }

// One request feeds the whole dashboard: counts, ticket total, scheduled hours and the recent projects.
function loadDashboard() {
  return authService.getAdminDashboard()
}
function projectStatus(project) {
  if (project.status === 'scheduled' || project.status === 'in_progress') {
    if (!project.due_date) return project.status
    const { tone } = getDueInfo(project.due_date, project.status)
    if (tone === 'danger') return 'overdue'
    if (tone === 'warning') return 'at_risk'
  }
  return project.status
}

function employeeInitials(name) {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
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

function ProjectRow({ project }) {
  const status = projectStatus(project)
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
      <span className="admin-project-due"><DueText dueDate={project.due_date} status={project.status} /></span>
      <StatusBadge status={status} />
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
  const dashboard = useAsync(loadDashboard)
  // The status chips re-request only the recent projects (one extra call per non-"All" chip).
  const loadFiltered = useCallback(
    () => (activeFilter === 'all' ? Promise.resolve(null) : authService.getAdminDashboard({ status: activeFilter })),
    [activeFilter],
  )
  const filtered = useAsync(loadFiltered)
  const counts = dashboard.data?.projects ?? EMPTY_COUNTS
  const projectsTotal = counts.total
  const scheduledCount = counts.scheduled
  const inProgressCount = counts.in_progress
  const completedCount = counts.completed
  const activeCount = scheduledCount + inProgressCount
  const completionPercent = projectsTotal > 0
    ? Math.round((completedCount / projectsTotal) * 100)
    : 0
  const scheduledHours = dashboard.data
    ? dashboard.data.scheduled_hours.map((day) => Number(day.hours))
    : EMPTY_WEEK_HOURS
  const totalScheduledHours = scheduledHours.reduce((sum, hours) => sum + hours, 0)
  const scheduledHoursPath = sparklinePath(scheduledHours)
  const isFilterLoading = activeFilter !== 'all' && (filtered.isLoading || !filtered.data)
  const visibleProjects = activeFilter === 'all'
    ? (dashboard.data?.recent_projects ?? [])
    : (filtered.data?.recent_projects ?? [])
  const filteredTotal = activeFilter === 'all' ? projectsTotal : counts[activeFilter] ?? 0
  return (
    <section className="page dashboard admin-dashboard">
      <PageHeader
        eyebrow="Administration"
        title="Workspace overview"
        subtitle="Monitor projects and delivery across every agency."
      />

      <ApiErrorMessage error={dashboard.error ?? filtered.error} />
      {dashboard.error && (
        <Button type="button" variant="secondary" onClick={dashboard.reload}>Try again</Button>
      )}

      {!dashboard.error && (
        <Card className="admin-hero-card">
          {dashboard.isLoading
            ? <div className="admin-dashboard-loading" role="status"><Spinner /> Loading workspace data…</div>
            : (
            <div className="admin-hero-content">
              <div>
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
              value={dashboard.data?.tickets.total ?? 0}
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
                    {filter.label} {filter.id === 'all' ? projectsTotal : counts[filter.id] ?? 0}
                  </button>
                ))}
              </nav>
              {isFilterLoading
                ? <div className="admin-dashboard-loading" role="status"><Spinner /> Loading projects…</div>
                : filteredTotal
                ? (
                  <>
                    <ul className="admin-project-list">
                      {visibleProjects.map((project) => <ProjectRow key={project.id} project={project} />)}
                    </ul>
                    {filteredTotal > visibleProjects.length && (
                      <Link className="admin-list-more" to="/admin/projects">
                        View {filteredTotal - visibleProjects.length} more projects
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
