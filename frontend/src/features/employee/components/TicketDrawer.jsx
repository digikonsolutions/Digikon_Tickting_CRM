import { Link } from 'react-router-dom'
import { Button, Drawer, DueText, PlainText, PriorityBadge, StatusBadge, TicketKey } from '../../../components/ui'
import { formatDate, formatDateRange, formatHours } from '../../../lib/format'
import { TicketComments } from '../../comments'
import { HoursRequestPanel } from '../../tickets/components/HoursRequestPanel'
import { WaitingChip } from '../../tickets'
import { TimeProgress } from '../../time'
import { WorkOrderBadges, WorkOrderPanel } from '../../workorder/WorkOrderView'

// The ticket side panel: facts, the actions the server allows, and the comment thread. The Drawer returns focus to
// the button that opened it when it closes.
export function TicketDrawer({ ticket, project, today, busy, onAction, onClose, onCommentPosted, onChanged, onMessage }) {
  const actions = (ticket.allowed_actions ?? []).filter((action) => !action.requires_comment)
  const needsComment = (ticket.allowed_actions ?? []).some((action) => action.requires_comment)

  return (
    <Drawer open onClose={onClose} title={ticket.title} description={project ? `${project.title} · ${project.agency_name ?? 'Agency'}` : undefined}>
      <div className="mw-drawer-badges">
        <TicketKey ticketKey={ticket.key} />
        <StatusBadge status={ticket.status} role="employee" />
        <WaitingChip ticket={ticket} role="employee" />
        <PriorityBadge priority={ticket.priority} />
      </div>

      <Link className="ui-button ui-button-secondary ui-button-sm mw-drawer-open" to={`/tickets/${ticket.key}`}>Open full ticket</Link>

      {ticket.status_reason && (
        <div className="mw-drawer-section"><h4>Reason</h4><PlainText text={ticket.status_reason} /></div>
      )}
      {ticket.description && (
        <div className="mw-drawer-section"><h4>Description</h4><PlainText text={ticket.description} /></div>
      )}

      {ticket.details_summary && (
        <div className="mw-drawer-section">
          <h4>Work order</h4>
          <WorkOrderBadges summary={ticket.details_summary} />
          {project?.brief_summary && <p className="tk-readonly-note">Client: {[project.brief_summary.client_name, project.brief_summary.company].filter(Boolean).join(' · ') || 'see the project brief'}. The full project brief is on the ticket page.</p>}
          <WorkOrderPanel ticket={ticket} mine />
        </div>
      )}

      <div className="mw-drawer-section">
        <h4>Schedule</h4>
        {(ticket.schedule ?? []).length ? (
          <div className="mw-drawer-schedule">
            {ticket.schedule.map((day) => (
              <div key={day.work_date} className="mw-drawer-schedule-row"><span>{formatDate(day.work_date)}</span><span>{formatHours(day.hours)}</span></div>
            ))}
          </div>
        ) : <p className="mw-drawer-empty">No schedule allocated.</p>}
      </div>

      <div className="mw-drawer-section">
        <h4>Dates and time</h4>
        <p>{formatDateRange(ticket.start_date, ticket.due_date)} · <DueText dueDate={ticket.due_date} status={ticket.status} today={today} /></p>
        <TimeProgress logged={ticket.logged_hours} estimated={ticket.estimated_hours} />
      </div>

      {(actions.length > 0 || needsComment) && (
        <div className="mw-drawer-actions">
          {actions.map((action) => (
            <Button key={action.action} type="button" loading={busy} onClick={() => onAction(ticket, action)}>{action.label}</Button>
          ))}
          {needsComment && <Link className="ui-button ui-button-secondary" to={`/tickets/${ticket.key}`}>More actions</Link>}
        </div>
      )}

      <div className="mw-drawer-section">
        <h4>Hours</h4>
        <HoursRequestPanel ticket={ticket} role="employee" onChanged={onChanged} onMessage={onMessage} />
      </div>

      <div className="mw-drawer-section">
        <h4>Comments</h4>
        <TicketComments key={ticket.id} ticketId={ticket.id} onPosted={onCommentPosted} />
      </div>
    </Drawer>
  )
}
