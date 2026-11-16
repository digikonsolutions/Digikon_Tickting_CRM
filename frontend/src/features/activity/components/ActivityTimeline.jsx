import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, EmptyState, Icon, Pagination, RelativeTime, Select, Skeleton } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { getTicketStatusLabel } from '../../../lib/ticketStatus'

const EVENT_TYPES = [
  ['', 'All events'],
  ['login', 'Sign-ins'],
  ['logout', 'Sign-outs'],
  ['session_start', 'Session starts'],
  ['session_end', 'Session ends'],
  ['ticket_status_changed', 'Ticket status changes'],
  ['ticket_comment_added', 'Ticket comments'],
  ['project_status_changed', 'Project status changes'],
  ['idle_detected', 'Idle periods'],
]

const ICONS = {
  login: 'user', logout: 'logout', session_start: 'clock', session_end: 'clock',
  ticket_status_changed: 'arrow', ticket_comment_added: 'chat', project_status_changed: 'briefcase', idle_detected: 'alert',
}

const END_REASONS = { logout: 'signed out', timeout: 'no activity', superseded: 'signed in again' }

function describe(event) {
  const meta = event.metadata ?? {}
  const status = (value) => getTicketStatusLabel(value) ?? value
  switch (event.event_type) {
    case 'login': return 'Signed in'
    case 'logout': return 'Signed out'
    case 'session_start': return 'Work session started'
    case 'session_end': return `Work session ended (${END_REASONS[meta.reason] ?? meta.reason ?? 'closed'})`
    case 'ticket_status_changed': return `Moved ${meta.ticket_key ?? 'a ticket'} from ${status(meta.from)} to ${status(meta.to)}`
    case 'ticket_comment_added': return `${meta.internal ? 'Added an internal note on' : 'Commented on'} ${meta.ticket_key ?? 'a ticket'}`
    case 'project_status_changed': return `Project ${meta.project_title ?? ''} moved from ${meta.from} to ${meta.to}`.replace('  ', ' ')
    case 'idle_detected': return `No activity for ${meta.idle_after_minutes ?? 'several'} minutes`
    default: return event.event_type
  }
}

// Paged list of recorded events for one employee, with an event-type filter.
export function ActivityTimeline({ employeeId, range }) {
  const [eventType, setEventType] = useState('')
  const [page, setPage] = useState(1)
  const load = useCallback(
    () => authService.getEmployeeActivity(employeeId, { ...range, eventType, page }),
    [employeeId, range, eventType, page],
  )
  const { data, error, isLoading, reload } = useAsync(load)
  const events = data?.events ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }

  return (
    <Card title="Activity timeline" description={`${pagination.total} event${pagination.total === 1 ? '' : 's'} in this range.`}>
      <div className="activity-filter">
        <Select label="Event type" value={eventType} onChange={(event) => { setEventType(event.target.value); setPage(1) }}>
          {EVENT_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </Select>
      </div>
      {error && (
        <Alert variant="error" title="Could not load activity">
          {error.message}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {isLoading && !data && <Skeleton variant="row" />}
      {data && events.length === 0 && <EmptyState title="No activity">Nothing was recorded for these filters.</EmptyState>}
      {events.length > 0 && (
        <ol className="activity-list" aria-label="Employee activity">
          {events.map((event) => (
            <li key={event.id} className="activity-item">
              <span className="activity-icon" aria-hidden="true"><Icon name={ICONS[event.event_type] ?? 'info'} size={14} /></span>
              <div className="activity-main">
                <span className="activity-summary">
                  {describe(event)}
                  {event.ticket && <> · <Link to={`/tickets/${event.ticket.key}`}>{event.ticket.key}</Link></>}
                </span>
                <span className="activity-meta">
                  <RelativeTime date={event.occurred_at} />
                  {event.ip_address && <> · {event.ip_address}</>}
                </span>
              </div>
            </li>
          ))}
        </ol>
      )}
      <Pagination page={pagination.current_page} lastPage={pagination.last_page} noun="event" loading={isLoading} onPrevious={() => setPage((value) => value - 1)} onNext={() => setPage((value) => value + 1)} />
    </Card>
  )
}
