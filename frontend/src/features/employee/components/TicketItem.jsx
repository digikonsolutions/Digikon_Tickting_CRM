import { useId } from 'react'
import { Link } from 'react-router-dom'
import { WorkOrderBadges } from '../../workorder/WorkOrderView'
import { Badge, Button, DueText, PriorityBadge, StatusBadge, TicketKey } from '../../../components/ui'
import { formatHours } from '../../../lib/format'
import { WaitingChip } from '../../tickets'
import { TimerButton } from '../../time'
import { useWorkspace } from '../useWorkspace'

const LATE = { overdue: ['Overdue', 'danger'], carried_over: ['Carried over', 'warning'] }

/**
 * One ticket as a plain article: its facts, then real buttons (the timer, the actions the server allows, and Open).
 * Nothing interactive is nested inside anything else interactive, so it reads and tabs cleanly.
 */
export function TicketItem({ ticket, showProject = true }) {
  const headingId = useId()
  const { clock, openTicket, runAction, busyTicket } = useWorkspace()
  const actions = (ticket.allowed_actions ?? []).filter((action) => !action.requires_comment)
  const needsComment = (ticket.allowed_actions ?? []).some((action) => action.requires_comment)
  const late = LATE[ticket.late]
  const comments = Number(ticket.comments_count) || 0

  return (
    <article className="ew-ticket" aria-labelledby={headingId}>
      <div className="ew-ticket-main">
        <div className="ew-ticket-head">
          <TicketKey ticketKey={ticket.key} />
          <h3 id={headingId} className="ew-ticket-title">{ticket.title}</h3>
        </div>
        {showProject && (ticket.project_title || ticket.agency_name) && (
          <p className="ew-muted">{[ticket.project_title, ticket.agency_name].filter(Boolean).join(' · ')}</p>
        )}
        <div className="ew-ticket-badges">
          <StatusBadge status={ticket.status} role="employee" />
          <WaitingChip ticket={ticket} role="employee" />
          <PriorityBadge priority={ticket.priority} />
          <WorkOrderBadges summary={ticket.details_summary} />
          {late && <Badge variant={late[1]}>{late[0]}</Badge>}
          {ticket.hours_request && <Badge variant="info">Asked for +{formatHours(ticket.hours_request.hours)}</Badge>}
          <DueText dueDate={ticket.due_date} status={ticket.status} today={clock.today} />
        </div>
        {ticket.status_reason && ['blocked', 'waiting_on_client', 'changes_requested'].includes(ticket.status) && (
          <p className="ew-ticket-reason"><strong>Reason:</strong> {ticket.status_reason}</p>
        )}
        <p className="ew-muted ew-ticket-numbers">
          {formatHours(ticket.logged_hours)} logged of {formatHours(ticket.estimated_hours)}
          {ticket.over_estimate && <> · <strong className="ew-over">Over estimate</strong></>}
          {' · '}{comments} {comments === 1 ? 'comment' : 'comments'}
        </p>
      </div>
      <div className="ew-ticket-actions">
        <TimerButton ticket={ticket} />
        {actions.map((action) => (
          <Button key={action.action} type="button" size="sm" loading={busyTicket === ticket.id} onClick={() => runAction(ticket, action)}>
            {action.label}
          </Button>
        ))}
        {needsComment && <Link className="ui-button ui-button-secondary ui-button-sm" to={`/tickets/${ticket.key}`}>More actions</Link>}
        <Button type="button" size="sm" variant="secondary" aria-label={`Open ${ticket.key ?? ''} ${ticket.title}`.trim()} onClick={() => openTicket(ticket)}>Open</Button>
      </div>
    </article>
  )
}

export function TicketList({ tickets, label, showProject = true }) {
  return (
    <ul className="ew-ticket-list" aria-label={label}>
      {tickets.map((ticket) => <li key={ticket.id}><TicketItem ticket={ticket} showProject={showProject} /></li>)}
    </ul>
  )
}
