import { Badge } from '../../../components/ui'
import { getWaitingLabel, isWaitingOnViewer } from '../../../lib/ticketStatus'

// "Waiting on you" / "Waiting on specialist" chip, worded for the viewer. Highlighted when the ball
// is in the viewer's court; hidden for closed tickets.
export function WaitingChip({ ticket, role }) {
  // The status badge already says it for waiting_on_client, and closed tickets are not waiting.
  if (['done', 'cancelled', 'waiting_on_client'].includes(ticket.status)) return null
  const who = getWaitingLabel(ticket.waiting_on, role)
  if (!who) return null
  return (
    <Badge variant={isWaitingOnViewer(ticket.waiting_on, role) ? 'warning' : 'slate'}>
      Waiting on {who}
    </Badge>
  )
}
