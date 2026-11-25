import { Icon } from './Icon'

// Staff-only SLA state with icon and words: "Response due in 2h 15m", "At risk, due in 25m", "Paused, waiting for the
// agency", "Breached by 3h". `clock` is one entry of the ticket's `sla` snapshot. Agency screens never render this.
const duration = (minutes) => {
  const total = Math.abs(Math.round(minutes ?? 0))
  const hours = Math.floor(total / 60)
  const rest = total % 60
  if (hours && rest) return `${hours}h ${rest}m`
  return hours ? `${hours}h` : `${rest}m`
}
const NAMES = { response: 'Response', resolution: 'Resolution' }

export function SlaIndicator({ clock, kind = 'response' }) {
  if (!clock) return null
  const name = NAMES[kind] ?? kind
  const left = clock.minutes_left
  const view = {
    on_track: { icon: 'clock', tone: 'ok', text: `${name} due in ${duration(left)}` },
    at_risk: { icon: 'alert', tone: 'warn', text: `At risk, ${name.toLowerCase()} due in ${duration(left)}` },
    paused: { icon: 'clock', tone: 'paused', text: 'Paused, waiting for the agency' },
    breached: { icon: 'alert', tone: 'bad', text: `${name} breached by ${duration(left)}` },
    met: { icon: 'check', tone: 'done', text: `${name} met` },
  }[clock.state] ?? { icon: 'info', tone: 'ok', text: name }

  return <span className={`sup-sla sup-sla-${view.tone}`} role="status"><Icon name={view.icon} size={14} /><span>{view.text}</span></span>
}
