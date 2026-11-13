import { Badge } from './Badge'

// Single source of truth for ticket/project status and priority badges.
// Labels and colours come from the Badge PRESETS.

export function StatusBadge({ status }) {
  if (!status) return null
  return <Badge status={status} />
}

export function PriorityBadge({ priority }) {
  if (!priority) return null
  return <Badge status={priority} />
}
