import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, EmptyState, FilterChips, Icon, PriorityBadge, ScheduleSummary, StatusBadge, TicketKey } from '../../../components/ui'
import { formatDateRange, formatHours } from '../../../lib/format'
import { WorkOrderBadges } from '../../workorder/WorkOrderView'
import './projectTickets.css'

const FILTERS = [{ value: 'all', label: 'All' }, { value: 'open', label: 'Open' }, { value: 'done', label: 'Done' }]

// Who has the ball, in plain words. The admin payload carries the status but not `waiting_on`, so it is read from the status.
const BALL = {
  todo: 'Employee to start',
  in_progress: 'Employee is working',
  changes_requested: 'Employee to fix',
  blocked: 'Blocked',
  in_internal_review: 'Our team to review',
  in_review: 'Agency to review',
  waiting_on_client: 'Agency to answer',
  done: 'Finished',
  cancelled: 'Cancelled',
}

const isClosed = (ticket) => ['done', 'cancelled'].includes(ticket.status)

// Tickets under the service they are for (in the order the agency listed the services), then everything without one.
function groupTickets(tickets, services) {
  const groups = services.map((service) => ({ key: String(service.id), label: service.label, estimate: service.estimated_hours, tickets: [] }))
  const general = { key: 'general', label: 'General project work', estimate: null, tickets: [] }
  tickets.forEach((ticket) => {
    const group = groups.find((item) => item.key === String(ticket.project_service_id)) ?? general
    group.tickets.push(ticket)
  })
  return [...groups, general].filter((group) => group.tickets.length > 0)
}

function HoursCell({ ticket }) {
  const estimated = Number(ticket.estimated_hours)
  const logged = Number(ticket.logged_hours)
  const share = estimated > 0 ? Math.min(100, (logged / estimated) * 100) : 0
  return (
    <div className="apt-hours" title="Logged of estimated hours">
      <span>{formatHours(logged)} <small>of {formatHours(estimated)}</small></span>
      <span className={`apt-bar${ticket.over_estimate ? ' is-over' : ''}`} aria-hidden="true"><i style={{ width: `${share}%` }} /></span>
      {ticket.over_estimate && <small className="apt-over">Over estimate</small>}
    </div>
  )
}

function TicketRow({ ticket, open, onToggle }) {
  const when = ticket.start_date ? formatDateRange(ticket.start_date, ticket.due_date) : 'Not scheduled'
  return (
    <>
      <tr className={`apt-row${isClosed(ticket) ? ' is-closed' : ''}`}>
        <td data-label="Ticket">
          <div className="apt-title">
            <Link to={`/tickets/${ticket.key}`}><TicketKey ticketKey={ticket.key} /> {ticket.title}</Link>
            <small>{when}</small>
          </div>
        </td>
        <td data-label="Status">
          <StatusBadge status={ticket.status} />
          <small className="apt-ball">{BALL[ticket.status] ?? ''}</small>
        </td>
        <td data-label="Priority"><PriorityBadge priority={ticket.priority} /></td>
        <td data-label="Hours"><HoursCell ticket={ticket} /></td>
        <td data-label="Actions" className="apt-actions">
          <button type="button" className="apt-toggle" aria-expanded={open} aria-label={`${open ? 'Hide' : 'Show'} details of ${ticket.key}`} onClick={onToggle}>
            <Icon name="arrow" size={14} className="apt-caret" />
            <span>Details</span>
          </button>
          <Link className="apt-open" to={`/tickets/${ticket.key}`} aria-label={`Open ${ticket.key}`}>Open</Link>
        </td>
      </tr>
      {open && (
        <tr className="apt-detail">
          <td colSpan={5}>
            <div className="apt-detail-body">
              <p>{ticket.description || 'No description.'}</p>
              <div className="apt-detail-meta">
                <WorkOrderBadges summary={ticket.details_summary} />
                <span>{ticket.comments_count ?? 0} comment{ticket.comments_count === 1 ? '' : 's'}</span>
                {ticket.creator?.name && <span>Created by {ticket.creator.name}</span>}
              </div>
              <ScheduleSummary schedule={ticket.schedule} />
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

// The project's tickets as one table, grouped by the service each one is for, so it is clear what every ticket is about,
// who is waiting on whom, and how many hours it has used. Each row opens for the description and schedule.
export function ProjectTicketsTable({ tickets, services = [] }) {
  const [filter, setFilter] = useState('all')
  const [openIds, setOpenIds] = useState([])
  const toggle = (id) => setOpenIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))

  const visible = useMemo(() => tickets.filter((ticket) => (filter === 'open' ? !isClosed(ticket) : filter === 'done' ? ticket.status === 'done' : true)), [tickets, filter])
  const groups = useMemo(() => groupTickets(visible, services), [visible, services])
  const open = tickets.filter((ticket) => !isClosed(ticket)).length

  if (tickets.length === 0) {
    return <EmptyState icon={<Icon name="ticket" size={22} />} title="No tickets yet">The agency has not broken this project into tickets yet.</EmptyState>
  }

  return (
    <div className="apt">
      <div className="apt-toolbar">
        <FilterChips label="Show tickets" options={FILTERS} value={filter} onChange={setFilter} />
        <span className="apt-count">{open} open · {tickets.length - open} closed</span>
      </div>
      {groups.length === 0 ? (
        <p className="apt-empty">No tickets match this filter.</p>
      ) : (
        <table className="apt-table">
          <thead>
            <tr><th scope="col">Ticket</th><th scope="col">Status</th><th scope="col">Priority</th><th scope="col">Hours</th><th scope="col"><span className="sr-only">Actions</span></th></tr>
          </thead>
          {groups.map((group) => {
            const hours = group.tickets.reduce((sum, ticket) => sum + (ticket.status === 'cancelled' ? 0 : Number(ticket.estimated_hours)), 0)
            return (
              <tbody key={group.key}>
                <tr className="apt-group">
                  <th scope="rowgroup" colSpan={5}>
                    <span className="apt-group-name">{group.key === 'general' ? group.label : <Badge variant="info">{group.label}</Badge>}</span>
                    <span className="apt-group-meta">{group.tickets.length} ticket{group.tickets.length === 1 ? '' : 's'} · {formatHours(hours)}{group.estimate != null ? ` of ${formatHours(group.estimate)} estimated` : ''}</span>
                  </th>
                </tr>
                {group.tickets.map((ticket) => <TicketRow key={ticket.id} ticket={ticket} open={openIds.includes(ticket.id)} onToggle={() => toggle(ticket.id)} />)}
              </tbody>
            )
          })}
        </table>
      )}
    </div>
  )
}
