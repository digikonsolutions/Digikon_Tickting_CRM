import { Link } from 'react-router-dom'
import { Avatar, Badge, Button, CapacityBar, DropdownMenu, Table } from '../../../components/ui'
import { formatShortDate } from '../../../lib/format'
import { StarRating } from '../../feedback'
import '../../feedback/feedback.css'

// The team as one scannable table: who, what they are on now, what waits, how full today and this week, and when they
// are free. A row opens the employee's own page; the quick actions sit in the row menu.
// `rows` are { id, assignment, queue }; `menuItemsFor(assignment)` gives the menu entries.
export function TeamTable({ rows, menuItemsFor, onAddTask, feedback = null }) {
  const columns = [
    {
      key: 'employee',
      label: 'Employee',
      render: (_value, row) => (
        <span className="tt-person">
          <Avatar name={row.assignment.employee.name} src={row.queue.employee?.photo_url} size="md" />
          <span className="tt-person-text">
            <Link to={`/agency/employees/${row.assignment.id}`} className="tt-name">{row.assignment.employee.name}</Link>
            <small className="queue-muted">{row.assignment.employee.email}</small>
          </span>
        </span>
      ),
    },
    {
      key: 'now',
      label: 'Now',
      render: (_value, row) => {
        const now = row.queue.now
        if (!now) return <span className="queue-muted">Nothing in progress</span>
        return <span className="tt-now"><strong>{now.ticket_key ? `${now.ticket_key} · ` : ''}{now.title}</strong><small className="queue-muted">{now.project_title ?? 'Scheduled for today'}</small></span>
      },
    },
    {
      key: 'next',
      label: 'Up next',
      render: (_value, row) => {
        const next = row.queue.up_next ?? []
        if (next.length === 0) return <span className="queue-muted">Nothing waiting</span>
        return (
          <span className="tt-now">
            <strong>{next[0].title}</strong>
            <small className="queue-muted">{next.length === 1 ? '1 waiting' : `${next.length} waiting`}{next.some((item) => item.awaiting_schedule) ? ' · schedule to be confirmed' : ''}{next.some((item) => item.fits === false && !item.awaiting_schedule) && <> <Badge variant="warning">May finish late</Badge></>}</small>
          </span>
        )
      },
    },
    {
      key: 'hours',
      label: 'Today',
      render: (_value, row) => {
        const capacity = row.queue.capacity ?? {}
        return (
          <span className="tt-bars">
            <CapacityBar used={Number(capacity.hours_today_used ?? 0)} budget={Number(capacity.daily_hours ?? 6)} label="today" ariaLabel={`Hours booked today, ${row.assignment.employee.name}`} />
          </span>
        )
      },
    },
    {
      key: 'free',
      label: 'Free from',
      render: (_value, row) => {
        const free = row.queue.capacity?.free_from
        return free ? formatShortDate(free.date) : <span className="queue-muted">No time in 4 months</span>
      },
    },
    // A small average per employee when the page has loaded the agency's feedback summaries.
    ...(feedback ? [{
      key: 'feedback',
      label: 'Feedback',
      render: (_value, row) => {
        const mine = feedback[String(row.assignment.employee.id)]
        return mine
          ? <Link className="fb-chip-avg" to={`/agency/employees/${row.assignment.id}?tab=feedback`} aria-label={`Feedback average ${mine.average} out of 5 from ${mine.count} reviews`}><StarRating value={mine.average} size="sm" /><small className="queue-muted">({mine.count})</small></Link>
          : <Link className="queue-muted" to={`/agency/employees/${row.assignment.id}?tab=feedback`}>No feedback yet</Link>
      },
    }] : []),
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, row) => (
        <span className="tt-actions">
          <Button type="button" size="sm" variant="secondary" onClick={() => onAddTask(row.assignment.id)}>Add task</Button>
          <DropdownMenu label={`More actions for ${row.assignment.employee.name}`} items={menuItemsFor(row.assignment)} />
        </span>
      ),
    },
  ]
  return <Table columns={columns} rows={rows} emptyTitle="No employees yet" emptyText="Once an employee joins your team they appear here." />
}
