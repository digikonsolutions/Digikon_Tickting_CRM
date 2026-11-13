import { DueText, EmptyState, Spinner, StatusBadge } from '../../../components/ui'
import { formatHours } from '../../../lib/format'

export function ProjectList({ tickets, isLoading, emptyTitle = 'No assigned projects', emptyText = 'Assignments will appear here when work is scheduled for you.', showAgency = true, onSelect }) {
  if (isLoading) return <div className="ticket-loading"><Spinner size="lg" /></div>
  if (!tickets?.length) return <EmptyState title={emptyTitle}>{emptyText}</EmptyState>
  return (
    <ul className="ticket-list">
      {tickets.map((project) => (
        <li key={project.id}>
          <button type="button" className="ticket-row" onClick={() => onSelect?.(project)}>
            <span className="ticket-main">
              <span className="ticket-id">Project #{project.id}</span>
              <span className="ticket-title">{project.title}</span>
              <span className="ticket-meta">
                {formatHours(project.estimated_hours)}
                {showAgency && project.agency_name && ` · ${project.agency_name}`}
                {` · ${project.employee?.name ?? 'Unassigned'}`}
              </span>
            </span>
            <span className="ticket-badges">
              <StatusBadge status={project.status} />
            </span>
            <span className="ticket-due"><DueText dueDate={project.due_date} status={project.status} /></span>
          </button>
        </li>
      ))}
    </ul>
  )
}
