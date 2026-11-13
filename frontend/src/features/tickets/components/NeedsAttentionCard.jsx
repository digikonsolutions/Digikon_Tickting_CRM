import { useCallback } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { Button, Card, RelativeTime, Skeleton, StatusBadge, TicketKey } from '../../../components/ui'

// Dashboard card for agency users: tickets that are waiting on them, longest-waiting first.
export function NeedsAttentionCard() {
  const { user } = useAuth()
  const load = useCallback(() => authService.getAgencyAttention(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const tickets = data?.tickets ?? []
  const hidden = Math.max(0, (data?.total ?? 0) - tickets.length)

  return (
    <Card className="needs-attention-card">
      <div className="admin-card-heading">
        <div>
          <h2>Needs your attention</h2>
          <p>Tickets waiting for your review or an answer</p>
        </div>
      </div>
      {error && (
        <p role="alert" className="form-error">
          Could not load this list. <Button type="button" variant="ghost" size="sm" onClick={reload}>Try again</Button>
        </p>
      )}
      {isLoading && !data && <Skeleton variant="row" />}
      {data && !tickets.length && <p className="needs-attention-empty">Nothing is waiting on you right now.</p>}
      {tickets.length > 0 && (
        <ul className="needs-attention-list">
          {tickets.map((ticket) => (
            <li key={ticket.id}>
              <Link to={`/tickets/${ticket.key}`} className="needs-attention-row">
                <TicketKey ticketKey={ticket.key} />
                <span className="needs-attention-main">
                  <strong>{ticket.title}</strong>
                  <small>{ticket.project_title}</small>
                </span>
                <span className="needs-attention-status">
                  <StatusBadge status={ticket.status} role={user.role} />
                  <RelativeTime date={ticket.waiting_since} />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {hidden > 0 && <p className="needs-attention-more">and {hidden} more waiting on you</p>}
    </Card>
  )
}
