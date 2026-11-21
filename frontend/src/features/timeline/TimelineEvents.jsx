import { formatDate, formatShortDate } from '../../lib/format'
import { RelativeTime } from '../../components/ui'

const hrs = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`
const daysText = (days = []) => days.map((day) => `${formatShortDate(day.date)} ${hrs(day.hours)}`).join(', ')

function sentence(event) {
  const who = event.actor ?? 'The system'
  const what = event.subject === 'ticket' ? `ticket “${event.after?.ticket ?? ''}”` : 'the project'
  switch (event.type) {
    case 'proposed':
      return event.after?.response ? `${who} replied and sent it back for review` : `${who} proposed a timeline for ${what}`
    case 'review_started': return `${who} started the review and is the project manager`
    case 'edited': return `${who} edited the days${event.before && event.after ? `: ${daysText(event.after.days)}` : ''}`
    case 'changes_requested': return `${who} requested changes`
    case 'confirmed': return `${who} confirmed the timeline for ${what}`
    case 'reopened': return `The timeline was reopened for review`
    case 'due_date_changed': return `${who} moved the due date from ${formatDate(event.before?.due)} to ${formatDate(event.after?.due)}`
    case 'reminder_sent': return 'A reminder was sent to the admins'
    default: return `${who} updated the timeline`
  }
}

// The activity thread of a project's timeline: newest first, with the reason or note under each step.
export function TimelineEvents({ events }) {
  if (!events?.length) return <p className="ui-muted">No activity yet.</p>
  return (
    <ol className="tl-events" aria-label="Timeline activity">
      {events.map((event) => (
        <li key={event.id}>
          <span>{sentence(event)}</span>
          {event.note && !event.note.startsWith('reminder:') && <p className="tl-event-note">{event.note}</p>}
          <RelativeTime date={event.created_at} />
        </li>
      ))}
    </ol>
  )
}
