const PRESETS = {
  new: ['New', 'info'],
  assigned: ['Assigned', 'primary'],
  'in progress': ['In Progress', 'primary'],
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

export function Badge({ variant = 'slate', preset, children, className = '', ...props }) {
  const [label, presetVariant] = PRESETS[String(preset ?? '').toLowerCase()] ?? []
  return (
    <span className={`ui-badge ui-badge-${presetVariant ?? variant} ${className}`.trim()} {...props}>
      {label ?? children}
    </span>
  )
}
