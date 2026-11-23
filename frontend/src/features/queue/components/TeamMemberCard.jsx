import { useId } from 'react'
import { Avatar, Badge, Button, CapacityBar, Card, DropdownMenu } from '../../../components/ui'
import { formatDate, formatShortDate } from '../../../lib/format'
import { plannedRange } from '../queueFormat'

/**
 * One employee on the Team tab: who they are, what they are doing now, what is next, and how full their day and week are.
 * Everything comes from the queue API (`queue`); reordering stays in the full queue view opened from the menu.
 */
export function TeamMemberCard({ queue, status = 'Active', onAddTask, menuItems = [], onOpenQueue, showReorder = false }) {
  const headingId = useId()
  const employee = queue.employee ?? {}
  const capacity = queue.capacity ?? {}
  const free = capacity.free_from
  const next = (queue.up_next ?? []).slice(0, 2)
  const more = Math.max(0, (queue.up_next ?? []).length - next.length)
  const items = showReorder && (queue.up_next ?? []).length > 1
    ? [...menuItems, { label: 'Manage queue order', onClick: onOpenQueue }]
    : menuItems

  return (
    <Card className="tm-card" aria-labelledby={headingId}>
      <div className="tm-head">
        <Avatar name={employee.name} src={employee.photo_url} size="lg" />
        <div className="tm-who">
          <h3 id={headingId} className="tm-name">{employee.name}</h3>
          {employee.email && <p className="tm-email">{employee.email}</p>}
        </div>
        <Badge variant="success">{status}</Badge>
      </div>

      <section className="tm-block" aria-label={`Now working on, ${employee.name}`}>
        <h4 className="queue-section-title">{queue.now ? 'Now working on' : 'Availability'}</h4>
        {queue.now ? (
          <div className="queue-now-body">
            <strong>{queue.now.ticket_key ? `${queue.now.ticket_key} · ` : ''}{queue.now.title}</strong>
            <span className="queue-muted">{queue.now.project_title ?? 'Scheduled for today'}</span>
            {queue.now.kind !== 'scheduled' && <CapacityBar used={queue.now.hours_logged ?? 0} budget={queue.now.hours_estimated || 1} label="logged" ariaLabel={`Progress on ${queue.now.title}`} />}
          </div>
        ) : (
          <p className="queue-muted">
            {free ? <>Free from <strong>{formatShortDate(free.date)}</strong></> : 'No free time in the next four months'}
          </p>
        )}
      </section>

      <section className="tm-block" aria-label={`Up next, ${employee.name}`}>
        <h4 className="queue-section-title">Up next</h4>
        {next.length === 0 ? (
          <p className="queue-muted">Nothing waiting. Add a task to give {employee.name} work.</p>
        ) : (
          <ol className="tm-next" aria-label={`Projects waiting for ${employee.name}`}>
            {next.map((item, index) => (
              <li key={item.work_item_id} className="tm-next-item">
                <span className="queue-item-number" aria-hidden="true">{index + 1}</span>
                <span className="tm-next-main">
                  <strong>{item.title}</strong>
                  <span className="queue-muted">{plannedRange(item.planned_start, item.planned_end)}{item.due_date ? `  ·  due ${formatDate(item.due_date)}` : ''}</span>
                  {!item.fits && <Badge variant="warning">May finish late</Badge>}
                </span>
              </li>
            ))}
            {more > 0 && <li className="queue-muted tm-more">+{more} more in the queue</li>}
          </ol>
        )}
      </section>

      <div className="tm-bars">
        <CapacityBar used={Number(capacity.hours_today_used ?? 0)} budget={Number(capacity.daily_hours ?? 6)} label="today" ariaLabel={`Hours booked today, ${employee.name}`} />
        <CapacityBar used={Number(capacity.hours_week_used ?? 0)} budget={Number(capacity.weekly_hours ?? 36)} label="this week" ariaLabel={`Hours booked this week, ${employee.name}`} />
      </div>

      <div className="tm-actions">
        <Button type="button" size="sm" onClick={onAddTask}>Add task</Button>
        <DropdownMenu label={`More actions for ${employee.name}`} items={items} />
      </div>
    </Card>
  )
}
