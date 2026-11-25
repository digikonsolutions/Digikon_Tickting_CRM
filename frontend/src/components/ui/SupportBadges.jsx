import { Icon } from './Icon'

// Status, priority and type badges for the support module. Every state has an icon and a word, never colour alone.
const STATUS = {
  new: ['New', 'info', 'plus'], triage: ['Triage', 'violet', 'search'], assigned: ['Assigned', 'info', 'user'], in_progress: ['In progress', 'primary', 'play'],
  waiting_agency: ['Waiting for agency', 'warning', 'clock'], waiting_third_party: ['Waiting for third party', 'warning hollow', 'clock'], qa: ['QA / testing', 'violet', 'shield'],
  resolved: ['Resolved', 'success', 'check'], closed: ['Closed', 'slate', 'lock'], rejected: ['Rejected', 'slate struck', 'close'],
}
const PRIORITY = { urgent: ['P1 Critical', 'danger', 'alert'], high: ['P2 High', 'orange', 'arrow'], normal: ['P3 Normal', 'primary', 'info'], low: ['P4 Low', 'slate', 'collapse'] }

function Pill({ label, tone, icon, title }) {
  const [variant, ...extra] = tone.split(' ')
  return (
    <span className={`ui-badge ui-badge-${variant} sup-pill ${extra.map((name) => `sup-pill-${name}`).join(' ')}`.trim()} title={title}>
      <Icon name={icon} size={12} />
      <span>{label}</span>
    </span>
  )
}

export function LifecycleStatusBadge({ status, label }) {
  const [text, tone, icon] = STATUS[status] ?? [status, 'slate', 'info']
  return <Pill label={label ?? text} tone={tone} icon={icon} />
}

export function LifecyclePriorityBadge({ priority, label }) {
  const [text, tone, icon] = PRIORITY[priority] ?? [priority, 'slate', 'info']
  return <Pill label={label ?? text} tone={tone} icon={icon} />
}

export function TypeBadge({ label }) {
  if (!label) return null
  return <span className="ui-badge ui-badge-slate sup-type">{label}</span>
}
