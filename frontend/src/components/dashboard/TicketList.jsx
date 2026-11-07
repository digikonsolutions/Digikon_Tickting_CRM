import { Badge, EmptyState, Spinner } from '../ui'

export function TicketList({ tickets, isLoading, emptyTitle = 'No assigned projects', emptyText = 'Assignments will appear here when work is scheduled for you.', showAgency = true, onSelect }) {
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
                {`${project.estimated_hours}h`}
                {showAgency && project.agency_name && ` · ${project.agency_name}`}
                {` · ${project.employee?.name ?? 'Unassigned'}`}
              </span>
            </span>
            <span className="ticket-badges">
              <Badge variant={project.status === 'completed' ? 'success' : project.status === 'in_progress' ? 'primary' : 'info'}>
                {project.status === 'in_progress' ? 'In progress' : project.status}
              </Badge>
            </span>
            <span className="ticket-due">Due {project.due_date}</span>
          </button>
        </li>
      ))}
    </ul>
  )
}
