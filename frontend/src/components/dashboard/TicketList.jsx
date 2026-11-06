import { Badge, EmptyState, Spinner } from '../ui'

export function TicketList({ tickets, isLoading, emptyTitle = 'No tickets here', emptyText = 'Nothing to show right now.', showAgency = true, onSelect }) {
  if (isLoading) return <div className="ticket-loading"><Spinner size="lg" /></div>
  if (!tickets?.length) return <EmptyState title={emptyTitle}>{emptyText}</EmptyState>
  return (
    <ul className="ticket-list">
      {tickets.map((ticket) => (
        <li key={ticket.id}>
          <button type="button" className="ticket-row" onClick={() => onSelect?.(ticket)}>
            <span className={`ticket-priority priority-${ticket.priority.toLowerCase()}`} aria-hidden="true" />
            <span className="ticket-main">
              <span className="ticket-id">{ticket.id}</span>
              <span className="ticket-title">{ticket.title}</span>
              <span className="ticket-meta">
                {ticket.service}
                {showAgency && ` · ${ticket.agency}`}
                {` · ${ticket.assignee ?? 'Unassigned'}`}
              </span>
            </span>
            <span className="ticket-badges">
              <Badge preset={ticket.status} />
              <Badge preset={ticket.priority} />
              <Badge preset={ticket.sla} />
            </span>
            <span className="ticket-due">{ticket.due}</span>
          </button>
        </li>
      ))}
    </ul>
  )
}
