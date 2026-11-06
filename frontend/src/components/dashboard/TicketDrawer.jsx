import { Badge, Modal } from '../ui'

export function TicketDetailModal({ ticket, onClose }) {
  return (
    <Modal open={Boolean(ticket)} onClose={onClose} title={ticket ? `${ticket.id} · ${ticket.title}` : ''}>
      {ticket && (
        <div className="ticket-detail">
          <div className="ticket-badges"><Badge preset={ticket.status} /><Badge preset={ticket.priority} /><Badge preset={ticket.sla} /></div>
          <p>{ticket.description}</p>
          <dl>
            <div><dt>Service</dt><dd>{ticket.service}</dd></div>
            <div><dt>Agency</dt><dd>{ticket.agency}</dd></div>
            <div><dt>Assignee</dt><dd>{ticket.assignee ?? 'Unassigned'}</dd></div>
            <div><dt>Due</dt><dd>{ticket.due}</dd></div>
          </dl>
          <p className="ticket-detail-note">Full ticket thread, public/internal notes and attachments arrive with the tickets API.</p>
        </div>
      )}
    </Modal>
  )
}
