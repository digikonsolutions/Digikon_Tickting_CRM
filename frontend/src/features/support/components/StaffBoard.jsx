import { useState } from 'react'
import { Link } from 'react-router-dom'
import { LifecyclePriorityBadge, LIFECYCLE_STATUSES, Select, SlaIndicator, TypeBadge } from '../../../components/ui'

const COLUMNS = LIFECYCLE_STATUSES.filter((item) => item.value !== 'rejected')

// The queue as columns by status. A card can be dragged to a column it is allowed to move to (the server lists them in
// `allowed_to`), or moved with the "Move to" menu on the card, which works with a keyboard. Either way the move opens
// the same dialog that asks for what the step needs.
export function StaffBoard({ tickets, onMove }) {
  const [dragging, setDragging] = useState(null)
  const byStatus = Object.fromEntries(COLUMNS.map((column) => [column.value, tickets.filter((ticket) => ticket.status === column.value)]))
  const canDrop = (column) => dragging && dragging.allowed_to?.includes(column)

  return (
    <div className="sup-board" role="group" aria-label="Board">
      {COLUMNS.map((column) => (
        <section
          key={column.value}
          className={`sup-column${canDrop(column.value) ? ' is-target' : ''}`}
          aria-label={`${column.label}, ${byStatus[column.value].length} tickets`}
          onDragOver={(event) => { if (canDrop(column.value)) event.preventDefault() }}
          onDrop={(event) => { event.preventDefault(); if (canDrop(column.value)) onMove(dragging, column.value); setDragging(null) }}
        >
          <h3>{column.label} <span className="sup-view-count">{byStatus[column.value].length}</span></h3>
          <ul className="sup-cards">
            {byStatus[column.value].map((ticket) => (
              <li key={ticket.id} className={`sup-card${ticket.priority === 'urgent' ? ' is-critical' : ''}`} draggable={!ticket.is_legacy && (ticket.allowed_to ?? []).length > 0} onDragStart={() => setDragging(ticket)} onDragEnd={() => setDragging(null)}>
                <Link className="sup-ref" to={`/staff/tickets/${ticket.ref}`}>{ticket.ref}</Link>
                <Link className="sup-row-title" to={`/staff/tickets/${ticket.ref}`}>{ticket.title}</Link>
                <div className="sup-row-meta"><LifecyclePriorityBadge priority={ticket.priority} label={ticket.priority_label} /><TypeBadge label={ticket.type_label ?? 'Project work'} /></div>
                {ticket.sla && <SlaIndicator clock={ticket.sla.response?.state !== 'met' ? ticket.sla.response : ticket.sla.resolution} kind={ticket.sla.response?.state !== 'met' ? 'response' : 'resolution'} />}
                <span className="ui-field-help">{ticket.assigned_to?.name ?? 'Unassigned'}</span>
                {(ticket.allowed_to ?? []).length > 0 && (
                  <Select label="Move to" value="" onChange={(event) => event.target.value && onMove(ticket, event.target.value)}>
                    <option value="">Choose…</option>{ticket.allowed_to.map((to) => <option key={to} value={to}>{COLUMNS.find((item) => item.value === to)?.label ?? to}</option>)}
                  </Select>
                )}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}
