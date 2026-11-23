import { useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, EmptyState } from '../../../components/ui'
import { formatDate, formatDateTime } from '../../../lib/format'
import { daysLate, plannedRange, RUSH_STATUS, RUSH_TYPE_LABELS } from '../queueFormat'

/**
 * The owner's accept / decline step for a quoted request: the price, how long the quote holds, and what a priority
 * insert would do to the queue. If other projects would pass their due dates, accepting needs an explicit tick.
 */
export function QuotePanel({ request, isOwner, onDone }) {
  const [detail, setDetail] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [confirmed, setConfirmed] = useState(false)
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (!isOwner) return undefined
    let active = true
    authService.getRushRequest(request.id)
      .then((response) => { if (active) setDetail(response.rush_request) })
      .catch((reason) => { if (active) setLoadError(reason) })
    return () => { active = false }
  }, [request.id, isOwner])

  const impact = detail?.impact ?? error?.payload?.impact ?? null
  const needsConfirm = Boolean(impact?.requires_confirmation)
  const quote = detail?.quote ?? request.quote

  if (!isOwner) {
    return <p className="queue-muted">Your account owner will review the quote and decide.</p>
  }
  if (!quote) return null

  async function run(kind) {
    setBusy(kind)
    setError(null)
    try {
      const response = kind === 'accept'
        ? await authService.acceptRushRequest(request.id, { confirmImpact: confirmed })
        : await authService.declineRushRequest(request.id)
      onDone?.(response.rush_request, kind)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="queue-quote" aria-label={`Quote for ${request.title}`}>
      <dl className="queue-quote-facts">
        <div><dt>Price</dt><dd><strong>{quote.amount_display}</strong></dd></div>
        <div><dt>Valid until</dt><dd>{quote.expires_at ? formatDateTime(quote.expires_at) : '—'}</dd></div>
      </dl>
      {quote.note && <p className="queue-muted">{quote.note}</p>}
      {loadError && <ApiErrorMessage error={loadError} />}
      {impact && request.type === 'priority_insert' && (
        <div className="queue-impact">
          <p>
            It would be worked on {plannedRange(impact.new_project.planned_start, impact.new_project.planned_end)}, ahead of the other waiting projects.
          </p>
          {impact.affected.length === 0
            ? <p>No other project would pass its due date.</p>
            : (
              <Alert variant="warning" title="Other projects would finish late">
                <ul>
                  {impact.affected.map((row) => (
                    <li key={row.work_item_id}>{row.title}: due {formatDate(row.due_date)}, would finish {plannedRange(row.planned_end)} ({daysLate(row.slips_by_days)})</li>
                  ))}
                </ul>
                <label className="queue-confirm">
                  <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                  <span>I understand these projects will move past their due dates.</span>
                </label>
              </Alert>
            )}
        </div>
      )}
      {error && <Alert variant="error" title="Could not complete this">{error.message}</Alert>}
      <div className="queue-quote-actions">
        <Button type="button" loading={busy === 'accept'} loadingText="Accepting…" disabled={busy !== null || (needsConfirm && !confirmed) || (!impact && request.type === 'priority_insert' && !loadError)} onClick={() => run('accept')}>
          Accept quote
        </Button>
        <Button type="button" variant="secondary" loading={busy === 'decline'} loadingText="Declining…" disabled={busy !== null} onClick={() => run('decline')}>Decline</Button>
      </div>
      <p className="queue-muted">Accepting adds {quote.amount_display} to your account as an unbilled charge. Nothing is collected now.</p>
    </div>
  )
}

export function RushRequestsPanel({ requests, isOwner, onChanged, onCancel }) {
  if (!requests.length) {
    return <EmptyState title="No urgent requests">When something cannot wait, use Request urgent help on a team member’s card.</EmptyState>
  }
  return (
    <div className="queue-rush-list">
      {requests.map((request) => {
        const status = RUSH_STATUS[request.status] ?? { label: request.status, variant: 'slate' }
        const open = request.status === 'pending' || request.status === 'quoted'
        return (
          <Card key={request.id} className="queue-rush-card">
            <div className="queue-rush-head">
              <div>
                <h3 className="queue-card-title">{request.title}</h3>
                <p className="queue-muted">
                  {RUSH_TYPE_LABELS[request.type]}{request.employee ? ` · ${request.employee.name}` : ''} · {request.estimated_hours} h · needed by {formatDate(request.needed_by)}
                </p>
              </div>
              <Badge variant={status.variant}>{status.label}</Badge>
            </div>
            {request.message && <p>{request.message}</p>}
            {request.status === 'quoted' && <QuotePanel request={request} isOwner={isOwner} onDone={onChanged} />}
            {request.status === 'fulfilled' && request.quote && <p className="queue-muted">Accepted at {request.quote.amount_display}.</p>}
            {open && (
              <div className="queue-quote-actions">
                <Button type="button" size="sm" variant="ghost" onClick={() => onCancel?.(request)}>Cancel request</Button>
              </div>
            )}
          </Card>
        )
      })}
    </div>
  )
}
