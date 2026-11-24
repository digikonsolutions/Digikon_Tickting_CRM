import { getDueInfo } from '../../lib/format'

// "Due in 2 days" / "3 days overdue", coloured by urgency.
// Pass 	oday (YYYY-MM-DD, from the API) to count days in the app's timezone rather than the browser's.
export function DueText({ dueDate, status, today, className = '' }) {
  const { label, tone } = getDueInfo(dueDate, status, today)
  return <span className={`due-tone due-tone-${tone} ${className}`.trim()}>{label}</span>
}
