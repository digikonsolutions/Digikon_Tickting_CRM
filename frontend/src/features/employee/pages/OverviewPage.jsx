import { Link } from 'react-router-dom'
import { Badge, CapacityBar, Card, EmptyState, KpiCard, PageHeader, ProjectStatusBadge } from '../../../components/ui'
import { formatDate, formatHours, getDueInfo } from '../../../lib/format'
import { MyQueueSection } from '../../queue/components/MyQueueSection'
import { ProjectActions } from '../components/ProjectActions'
import { TicketList } from '../components/TicketItem'
import { OPEN_PROJECT, workspaceKpis } from '../workspace'
import { useWorkspace } from '../useWorkspace'

function Section({ title, count, description, children }) {
  return (
    <section className="ew-section" aria-label={title}>
      <header className="ew-section-head">
        <h2>{title}{count !== undefined && <span className="ew-count">{count}</span>}</h2>
        {description && <p className="ew-muted">{description}</p>}
      </header>
      {children}
    </section>
  )
}

// /my-work: the numbers, what needs me, what is late, today's plan, the week's deadlines and my queue.
export function OverviewPage() {
  const { workspace, clock } = useWorkspace()
  const kpis = workspaceKpis(workspace, clock)
  const openProjects = workspace.projects.filter((project) => OPEN_PROJECT.includes(project.status))
  const nothingToday = !workspace.attention.length && !workspace.late.length && !workspace.todays.length

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="My work" subtitle={`Today is ${formatDate(clock.today)}. Here is what needs you.`} />

      <div className="ew-kpis">
        <KpiCard hero title="Planned this week" value={formatHours(kpis.weekHours)} subtext={`of ${clock.weekly_hours} h budget`} icon="clock"
          progress={<CapacityBar used={kpis.weekHours} budget={clock.weekly_hours} showRemaining={false} ariaLabel="Hours planned this week" />} />
        <Link className="ew-kpi-link" to="/my-work/tickets?status=in_progress"><KpiCard icon="arrow" title="In progress" value={kpis.inProgress} /></Link>
        <Link className="ew-kpi-link" to="/my-work/requests"><KpiCard icon="chat" title="In review or waiting" value={kpis.waiting} /></Link>
        <Link className="ew-kpi-link" to="/my-work/tickets?flag=blocked"><KpiCard icon="alert" title="Blocked" value={kpis.blocked} tone={kpis.blocked ? 'warm' : 'teal'} /></Link>
        <Link className="ew-kpi-link" to="/my-work/tickets?flag=late"><KpiCard icon="calendar" title="Overdue" value={kpis.overdue} tone={kpis.overdue ? 'danger' : 'teal'} /></Link>
      </div>

      <div className="ew-columns">
        <div className="ew-column">
          {nothingToday && (
            <Card><EmptyState title="Nothing needs you today">No tickets are planned for today and nothing is late. <Link to="/my-work/tickets">See all tickets</Link>.</EmptyState></Card>
          )}
          {workspace.attention.length > 0 && (
            <Section title="Needs your attention" count={workspace.attention.length} description="Changes were requested or you marked these blocked.">
              <TicketList tickets={workspace.attention} label="Needs your attention" />
            </Section>
          )}
          {workspace.late.length > 0 && (
            <Section title="Overdue or carried over" count={workspace.late.length} description="Past the due date, or planned days have passed without a start.">
              <TicketList tickets={workspace.late} label="Overdue or carried over" />
            </Section>
          )}
          {workspace.todays.length > 0 && (
            <Section title="Today" count={workspace.todays.length}>
              <TicketList tickets={workspace.todays} label="Planned today" />
            </Section>
          )}
        </div>

        <aside className="ew-column ew-side" aria-label="Deadlines, projects and queue">
          <Card title="Due in the next 7 days">
            {workspace.upcoming.length === 0 ? <p className="ew-muted">No deadlines this week.</p> : (
              <ul className="ew-deadlines">
                {workspace.upcoming.map((row) => {
                  const due = getDueInfo(row.due_date, row.item.status, clock.today)
                  return (
                    <li key={`${row.kind}-${row.id}`}>
                      <span className="ew-deadline-main">
                        {row.kind === 'project'
                          ? <Link to={`/my-work/projects/${row.id}`}>{row.title}</Link>
                          : <Link to={`/tickets/${row.item.key}`}>{row.title}</Link>}
                        <small className="ew-muted">{row.kind === 'project' ? 'Project' : row.project_title}</small>
                      </span>
                      <Badge variant={due.tone === 'danger' ? 'danger' : due.tone === 'warning' ? 'warning' : 'slate'}>{due.label}</Badge>
                    </li>
                  )
                })}
              </ul>
            )}
          </Card>

          {openProjects.length > 0 && (
            <Card title="My projects" description="Start a project when you begin. Tickets are optional.">
              <ul className="ew-project-rows">
                {openProjects.map((project) => (
                  <li key={project.id}>
                    <span className="ew-project-row-main">
                      <Link to={`/my-work/projects/${project.id}`}><strong>{project.title}</strong></Link>
                      <small className="ew-muted">{project.agency_name ?? 'Agency'} · due {formatDate(project.due_date)}</small>
                    </span>
                    <ProjectStatusBadge project={project} />
                    <ProjectActions project={project} />
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <MyQueueSection />
        </aside>
      </div>
    </section>
  )
}
