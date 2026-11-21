import { Link } from 'react-router-dom'
import { formatHours, formatTicketProgress } from '../../lib/format'
import { Avatar } from './Avatar'
import { DueText } from './DueText'
import { EmptyState } from './EmptyState'
import { ScheduleSummary } from './ScheduleSummary'
import { StatusBadge } from './StatusBadge'

// One project as a card: title and who it is for on top, then status, employee, hours and due date,
// and the schedule at a glance. Used by every projects list so they all read the same on any screen.
// `to` is the detail route; `showAgency` / `showClient` choose what the second line names.
export function ProjectCard({ project, to, showAgency = false, showClient = true, actions = null }) {
  const parties = [
    showAgency ? project.agency_name : null,
    showClient ? (project.client?.name ?? 'Account removed') : null,
  ].filter(Boolean)
  const employee = project.employee?.name

  return (
    <article className="ui-project-card">
      <header className="ui-project-card-head">
        <div className="ui-project-card-title">
          <h3>{to ? <Link to={to}>{project.title}</Link> : project.title}</h3>
          {parties.length > 0 && <p>{parties.join(' · ')}</p>}
        </div>
        <StatusBadge status={project.status} />
      </header>

      {project.description && <p className="ui-project-card-desc">{project.description}</p>}

      <dl className="ui-project-card-facts">
        <div className="ui-project-card-fact">
          <dt>Employee</dt>
          <dd>
            {employee
              ? <><Avatar name={employee} size="sm" /><span>{employee}</span></>
              : <span className="ui-project-card-muted">Unassigned</span>}
          </dd>
        </div>
        <div className="ui-project-card-fact">
          <dt>Hours</dt>
          <dd>{formatHours(project.estimated_hours)}</dd>
        </div>
        <div className="ui-project-card-fact">
          <dt>Due</dt>
          <dd><DueText dueDate={project.due_date} status={project.status} /></dd>
        </div>
        <div className="ui-project-card-fact">
          <dt>Tickets</dt>
          <dd>{formatTicketProgress(project)}</dd>
        </div>
      </dl>

      <ScheduleSummary schedule={project.schedule} />

      {actions && <footer className="ui-project-card-actions">{actions}</footer>}
    </article>
  )
}

export function ProjectCardList({ projects = [], routeFor, actionsFor, showAgency = false, showClient = true, emptyTitle = 'No projects', emptyText = 'Nothing to show yet.', emptyAction = null }) {
  if (!projects.length) return <EmptyState title={emptyTitle} action={emptyAction}>{emptyText}</EmptyState>
  return (
    <ul className="ui-project-list">
      {projects.map((project) => (
        <li key={project.id}>
          <ProjectCard project={project} to={routeFor?.(project)} showAgency={showAgency} showClient={showClient} actions={actionsFor?.(project)} />
        </li>
      ))}
    </ul>
  )
}