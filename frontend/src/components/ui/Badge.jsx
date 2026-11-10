const PRESETS = {
  new: ['New', 'info'],
  assigned: ['Assigned', 'primary'],
  'in progress': ['In Progress', 'primary'],
  in_progress: ['In Progress', 'primary'],
  todo: ['To do', 'slate'],
  'to do': ['To do', 'slate'],
  in_review: ['In Review', 'warning'],
  'in review': ['In Review', 'warning'],
  done: ['Done', 'success'],
  scheduled: ['Scheduled', 'info'],
  completed: ['Completed', 'success'],
  cancelled: ['Cancelled', 'slate'],
  active: ['Active', 'success'],
  inactive: ['Inactive', 'slate'],
  'waiting for agency': ['Waiting for Agency', 'warning'],
  blocked: ['Blocked', 'danger'],
  'qa review': ['QA Review', 'violet'],
  resolved: ['Resolved', 'success'],
  closed: ['Closed', 'slate'],
  reopened: ['Reopened', 'orange'],
  low: ['Low', 'slate'],
  normal: ['Normal', 'info'],
  high: ['High', 'warning'],
  urgent: ['Urgent', 'danger'],
  'on track': ['On Track', 'success'],
  'at risk': ['At Risk', 'warning'],
  breached: ['Breached', 'danger'],
  paused: ['Paused', 'slate'],
}

export function Badge({ variant = 'slate', preset, status, children, className = '', ...props }) {
  const key = String(status ?? preset ?? '').toLowerCase().trim()
  const [presetLabel, presetVariant] = PRESETS[key] ?? []
  const resolvedLabel = children ?? presetLabel ?? (key ? key.replace(/_/g, ' ') : null)
  return (
    <span className={`ui-badge ui-badge-${presetVariant ?? variant} ${className}`.trim()} {...props}>
      {resolvedLabel}
    </span>
  )
}
