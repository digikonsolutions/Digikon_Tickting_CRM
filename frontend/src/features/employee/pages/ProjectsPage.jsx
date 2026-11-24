import { Link } from 'react-router-dom'
import { CapacityBar, Card, EmptyState, PageHeader, ProjectStatusBadge } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'
import { daysLeftLabel, OPEN_PROJECT, projectSummary } from '../workspace'
import { useWorkspace } from '../useWorkspace'

function ProjectRow({ project }) {
  const { workspace, clock } = useWorkspace()
  const summary = projectSummary(project, workspace, clock.today)
  const left = daysLeftLabel(summary.daysLeft)
  return (
    <li className="ew-project-card">
      <div className="ew-project-card-head">
        <Link to={`/my-work/projects/${project.id}`} className="ew-project-link"><strong>{project.title}</strong></Link>
        <ProjectStatusBadge project={project} />
      </div>
      <p className="ew-muted">{[project.agency_name, project.client?.name].filter(Boolean).join(' · ') || 'Agency'}</p>
      <dl className="ew-facts">
        <div><dt>Due</dt><dd>{formatDate(project.due_date)}{left && <span className={summary.daysLeft < 0 ? ' ew-late' : ''}> · {left}</span>}</dd></div>
        <div><dt>Tickets</dt><dd>{summary.ticketsTotal ? `${summary.ticketsDone} of ${summary.ticketsTotal} done` : 'No tickets'}</dd></div>
        <div><dt>Hours</dt><dd>{formatHours(summary.hoursLogged)} logged of {formatHours(summary.hoursEstimated)}</dd></div>
      </dl>
      <CapacityBar used={summary.hoursLogged} budget={summary.hoursEstimated || 1} showRemaining={false} ariaLabel={`${project.title}: hours logged of estimated`} />
    </li>
  )
}

// /my-work/projects: open projects first, then finished ones.
export function ProjectsPage() {
  const { workspace } = useWorkspace()
  const open = workspace.projects.filter((project) => OPEN_PROJECT.includes(project.status))
  const closed = workspace.projects.filter((project) => !OPEN_PROJECT.includes(project.status))

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="Projects" subtitle="Projects assigned to you, with tickets, hours and time left." />
      {workspace.projects.length === 0 && <EmptyState title="No projects yet">Projects assigned to you will appear here.</EmptyState>}
      {open.length > 0 && (
        <section className="ew-section" aria-label="Open projects">
          <header className="ew-section-head"><h2>Open<span className="ew-count">{open.length}</span></h2></header>
          <ul className="ew-project-grid">{open.map((project) => <ProjectRow key={project.id} project={project} />)}</ul>
        </section>
      )}
      {closed.length > 0 && (
        <Card>
          <details className="ew-details">
            <summary>Finished and cancelled ({closed.length})</summary>
            <ul className="ew-project-grid">{closed.map((project) => <ProjectRow key={project.id} project={project} />)}</ul>
          </details>
        </Card>
      )}
    </section>
  )
}
