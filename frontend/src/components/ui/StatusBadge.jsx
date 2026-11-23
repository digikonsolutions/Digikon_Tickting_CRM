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

// A project's status for people who do not plan it. Until the schedule is confirmed an open project is not shown as
// "Scheduled": it is "Awaiting schedule" (nothing planned yet) or "In review" (planned or proposed, not confirmed).
export function ProjectStatusBadge({ project }) {
  const open = ['scheduled', 'in_progress'].includes(project?.status)
  if (open && project.schedule_state === 'awaiting') return <Badge variant="warning">Awaiting schedule</Badge>
  if (open && project.schedule_state === 'in_review') return <Badge variant="info">In review</Badge>
  return <StatusBadge status={project?.status} />
}

export function PriorityBadge({ priority }) {
  if (!priority) return null
  return <Badge status={priority} />
}
