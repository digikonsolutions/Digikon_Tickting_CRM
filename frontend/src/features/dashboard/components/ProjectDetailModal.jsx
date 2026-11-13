import { Badge, Modal } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'

export function ProjectDetailModal({ ticket, onClose }) {
  return (
    <Modal open={Boolean(ticket)} onClose={onClose} title={ticket ? `Project #${ticket.id} · ${ticket.title}` : ''}>
      {ticket && (
        <div className="ticket-detail">
          <div className="ticket-badges">
            <Badge variant={ticket.status === 'completed' ? 'success' : ticket.status === 'in_progress' ? 'primary' : 'info'}>
              {ticket.status === 'in_progress' ? 'In progress' : ticket.status}
            </Badge>
          </div>
          <p>{ticket.description || 'No description provided.'}</p>
          <dl>
            <div><dt>Agency</dt><dd>{ticket.agency_name ?? '—'}</dd></div>
            <div><dt>Client</dt><dd>{ticket.client?.name ?? '—'}</dd></div>
            <div><dt>Assignee</dt><dd>{ticket.employee?.name ?? 'Unassigned'}</dd></div>
            <div><dt>Hours</dt><dd>{formatHours(ticket.estimated_hours)}</dd></div>
            <div><dt>Start date</dt><dd>{formatDate(ticket.start_date)}</dd></div>
            <div><dt>Due date</dt><dd>{formatDate(ticket.due_date)}</dd></div>
          </dl>
        </div>
      )}
    </Modal>
  )
}
