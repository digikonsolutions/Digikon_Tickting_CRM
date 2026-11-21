import { Badge } from '../../components/ui'

// Plain-language labels for the five timeline states, and what each one means for the person looking at it.
export const TIMELINE = {
  proposed: { label: 'Proposed · awaiting review', variant: 'warning', explain: 'A schedule has been proposed and is waiting for your project manager to review it. The hours are already reserved, but work starts once it is confirmed.' },
  in_review: { label: 'In review', variant: 'info', explain: 'Your project manager is reviewing the schedule. You will be told as soon as it is confirmed.' },
  changes_requested: { label: 'Changes requested', variant: 'danger', explain: 'Your project manager asked for changes before confirming the schedule. Reply below to send it back for review.' },
  confirmed: { label: 'Confirmed', variant: 'success', explain: 'The schedule is confirmed. Work follows these dates.' },
}

export function TimelineChip({ status, overdue = false, urgent = false }) {
  const meta = TIMELINE[status]
  if (!meta) return null
  return (
    <span className="tl-chip-wrap">
      <Badge variant={meta.variant}>{meta.label}</Badge>
      {overdue && <Badge variant="danger">Overdue review</Badge>}
      {urgent && <Badge variant="danger">Urgent</Badge>}
    </span>
  )
}
