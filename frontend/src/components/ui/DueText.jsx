import { getDueInfo } from '../../lib/format'

// "Due in 2 days" / "3 days overdue", coloured by urgency.
export function DueText({ dueDate, status, className = '' }) {
  const { label, tone } = getDueInfo(dueDate, status)
  return <span className={`due-tone due-tone-${tone} ${className}`.trim()}>{label}</span>
}
