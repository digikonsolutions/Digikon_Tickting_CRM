import { useCallback } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Badge, Card, CapacityBar } from '../../../components/ui'
import { formatDate, formatDateTime, formatShortDate } from '../../../lib/format'
import { plannedRange } from '../queueFormat'
import { DEFAULT_CAPACITY } from '../../../lib/constants'

/** The employee's own queue, read-only: what is on now, what is next and when they are free. No prices, ever. */
export function MyQueueSection({ queue: given }) {
  const load = useCallback(() => (given ? Promise.resolve(given) : authService.getMyQueue()), [given])
  const { data, error, isLoading } = useAsync(load)
  const queue = given ?? data

  if (error) return <Alert variant="error" title="Could not load your queue">{error.message}</Alert>
  if (!queue || (isLoading && !given)) return <p role="status" className="queue-muted">Loading your queue…</p>

  const now = queue.now
  const capacity = queue.capacity ?? {}
  return (
    <Card className="queue-card my-queue" title="My queue" description="The order your projects will be worked on. Your agency sets it.">
      <section aria-label="Now working on">
        <h3 className="queue-section-title">Now</h3>
        {!now && <p className="queue-muted">Nothing in progress right now.</p>}
        {now?.kind === 'ticket' && (
          <div className="queue-now-body">
            <strong>{now.ticket_key ? `${now.ticket_key} · ` : ''}{now.title}</strong>
            <span className="queue-muted">{now.project_title} · started {now.started_at ? formatDateTime(now.started_at) : 'recently'}</span>
            <CapacityBar used={now.hours_logged ?? 0} budget={now.hours_estimated || 1} label="logged" ariaLabel={`Progress on ${now.title}`} />
          </div>
        )}
        {now?.kind === 'scheduled' && <div className="queue-now-body"><strong>{now.title}</strong><span className="queue-muted">Scheduled for today · {now.hours_estimated} h</span></div>}
      </section>
      <section aria-label="Up next">
        <h3 className="queue-section-title">Up next</h3>
        {queue.up_next.length === 0 ? <p className="queue-muted">No projects waiting.</p> : (
          <ol className="queue-list">
            {queue.up_next.map((item, index) => (
              <li className="queue-item" key={item.work_item_id}>
                <span className="queue-item-number" aria-hidden="true">{index + 1}</span>
                <div className="queue-item-main">
                  <strong>{item.title}</strong>
                  <span className="queue-muted">{item.estimated_hours} h · {plannedRange(item.planned_start, item.planned_end)} · due {formatDate(item.due_date)}</span>
                  {!item.fits && <Badge variant="warning">May finish after its due date</Badge>}
                </div>
              </li>
            ))}
          </ol>
        )}
      </section>
      <p className="queue-muted">
        {capacity.free_from ? <>Free from <strong>{formatShortDate(capacity.free_from.date)}</strong></> : 'No free time in the next four months'}
      </p>
      <CapacityBar used={Number(capacity.hours_week_used ?? 0)} budget={Number(capacity.weekly_hours ?? DEFAULT_CAPACITY.weekly_hours)} label="this week" ariaLabel="Hours booked this week" />
    </Card>
  )
}
