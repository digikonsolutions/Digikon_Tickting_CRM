import { Badge } from './Badge'
import { getTicketStatusLabel, getTicketStatusVariant, isTicketStatus } from '../../lib/ticketStatus'

// Single source of truth for ticket/project status and priority badges.
// Ticket statuses take their label and colour from lib/ticketStatus (pass `role` for viewer-relative
// wording such as "Waiting on you"); project statuses use the Badge presets.
export function StatusBadge({ status, role = null }) {
  if (!status) return null
  if (isTicketStatus(status)) {
    return <Badge variant={getTicketStatusVariant(status)}>{getTicketStatusLabel(status, role)}</Badge>
  }
  return <Badge status={status} />
}

export function PriorityBadge({ priority }) {
  if (!priority) return null
  return <Badge status={priority} />
}
