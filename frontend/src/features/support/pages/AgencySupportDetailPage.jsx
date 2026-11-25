import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, CopyButton, EmptyState, FormActions, LifecyclePriorityBadge, LifecycleStatusBadge, Modal, PageHeader, PlainText, RelativeTime, Skeleton, Tabs, Textarea, TypeBadge, useToast } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import { FilesTab } from '../../tickets/components/FilesTab'
import { TicketActivityTab } from '../components/TicketActivityTab'
import { TicketConversation } from '../components/TicketConversation'
import '../support.css'

const TABS = [{ id: 'conversation', label: 'Conversation' }, { id: 'files', label: 'Files' }, { id: 'activity', label: 'Activity' }]

// An optional 1 to 5 answer after a resolution. A radio group, so it works with the keyboard and a screen reader.
function FeedbackCard({ ticket, onSent }) {
  const { showToast } = useToast()
  const [rating, setRating] = useState('')
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  async function send(event) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await authService.submitSupportFeedback(ticket.ref, { rating: Number(rating), comment: comment.trim() || undefined })
      showToast('Thank you for your feedback.')
      onSent()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card title="How did we do?">
      <form className="ui-form" onSubmit={send} noValidate>
        <fieldset className="ui-checkgroup"><legend className="ui-field-label">Your rating</legend>
          <div className="ui-checkgroup-options">{[1, 2, 3, 4, 5].map((n) => <label key={n} className="ui-checkgroup-option"><input type="radio" name="rating" value={n} checked={rating === String(n)} onChange={() => setRating(String(n))} /><span>{n} {n === 1 ? '(poor)' : n === 5 ? '(great)' : ''}</span></label>)}</div>
        </fieldset>
        <Textarea label="Anything to add? (optional)" rows={2} maxLength={1000} value={comment} onChange={(event) => setComment(event.target.value)} />
        <ApiErrorMessage error={error} />
        <FormActions submitLabel="Send feedback" loadingLabel="Sending…" loading={busy} disabled={!rating} disabledReason="Choose a rating." />
      </form>
    </Card>
  )
}

// A price we put to the agency. Everyone in the agency can read it; only the account owner can answer.
function QuoteCard({ ticket, onAnswered }) {
  const { showToast } = useToast()
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const quote = ticket.quote
  async function answer(accept) {
    setBusy(true)
    setError(null)
    try {
      await authService.answerSupportQuote(ticket.ref, { accept, note: note.trim() || undefined })
      showToast(accept ? 'Quote accepted.' : 'Quote declined.')
      onAnswered()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className={`sup-banner${quote.status === 'pending' ? ' is-waiting' : ''}`} role="region" aria-label="Quote">
      <strong>Quote: {new Intl.NumberFormat(undefined, { style: 'currency', currency: quote.currency }).format(quote.amount)}</strong>
      <PlainText text={quote.description} />
      {quote.valid_until && <span>Valid until {formatDate(quote.valid_until)}.</span>}
      {quote.status !== 'pending' && <span>Status: {quote.status}.</span>}
      {ticket.can_answer_quote && (
        <>
          <Textarea label="Note (optional)" rows={2} maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} />
          <ApiErrorMessage error={error && !error.errors ? error : null} />
          {error?.errors?.quote && <p className="ui-field-error" role="alert">{error.errors.quote[0]}</p>}
          <div><Button type="button" loading={busy} onClick={() => answer(true)}>Accept the quote</Button> <Button type="button" variant="secondary" disabled={busy} onClick={() => answer(false)}>Decline</Button></div>
        </>
      )}
      {quote.status === 'pending' && !ticket.can_answer_quote && <span className="ui-field-help">Only the account owner can answer a quote.</span>}
    </div>
  )
}

// One ticket as the agency sees it: status and what happens next on top, only public conversation, files and activity.
export function AgencySupportDetailPage() {
  const { ref } = useParams()
  const { user } = useAuth()
  const { showToast } = useToast()
  const [tab, setTab] = useState('conversation')
  const [reopening, setReopening] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const load = useCallback(() => authService.getSupportTicket(ref), [ref])
  const { data, error: loadError, isLoading, reload } = useAsync(load)
  const ticket = data?.ticket

  if (isLoading && !data) return <section className="page" role="status" aria-label="Loading ticket"><Skeleton variant="row" /><Skeleton variant="card" /></section>
  if (loadError?.status === 404 || (!ticket && !loadError)) return <section className="page"><EmptyState title="Ticket not found" action={<Link className="ui-button ui-button-primary" to="/agency/support">Back to your tickets</Link>}>We could not find {ref}. It may not exist, or you may not have access to it.</EmptyState></section>
  if (loadError && !ticket) return <section className="page"><Alert variant="error" title="Could not load this ticket">{loadError.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert></section>

  async function act(action, successText) {
    setBusy(true)
    setError(null)
    try {
      await action()
      showToast(successText)
      setReopening(false)
      setReason('')
      await reload()
    } catch (failure) {
      setError(failure)
    } finally {
      setBusy(false)
    }
  }
  const closed = ['closed', 'rejected'].includes(ticket.status)

  return (
    <section className="page">
      <PageHeader eyebrow={<Link to="/agency/support">Your tickets</Link>} title={ticket.title} subtitle={<span className="sup-detail-head"><span className="sup-ref">{ticket.ref}</span><CopyButton value={ticket.ref} label="Copy ID" /></span>} />
      <div className="sup-detail-head" style={{ marginBottom: 'var(--space-3)' }}>
        <LifecycleStatusBadge status={ticket.status} label={ticket.status_label} />
        <LifecyclePriorityBadge priority={ticket.priority} label={ticket.priority_label} />
        <TypeBadge label={ticket.type_label} />
        {ticket.respond_by && <span>We aim to respond by {ticket.respond_by}</span>}
      </div>

      {ticket.waiting && (
        <div className="sup-banner is-waiting" role="status">
          <strong>We are waiting for you</strong>
          <span>{ticket.waiting.reason}</span>
          {ticket.waiting.follow_up_date && <span>We will follow up on {formatDate(ticket.waiting.follow_up_date)}. Reply below to continue.</span>}
        </div>
      )}
      {ticket.resolution && (
        <div className="sup-banner is-resolved" role="status">
          <strong>{ticket.status === 'rejected' ? 'This ticket was closed without action' : 'Resolution'}</strong>
          <span>{ticket.resolution.category_label}</span>
          <PlainText text={ticket.resolution.summary} />
          {ticket.can_confirm && <div><Button type="button" loading={busy} onClick={() => act(() => authService.confirmSupportResolution(ticket.ref), 'Thank you. The ticket is closed.')}>Confirm resolved</Button> <Button type="button" variant="secondary" onClick={() => setReopening(true)}>Not fixed, reopen</Button></div>}
          {!ticket.can_confirm && ticket.can_reopen && <div><Button type="button" variant="secondary" onClick={() => setReopening(true)}>Reopen this ticket</Button></div>}
        </div>
      )}
      <ApiErrorMessage error={error && !error.errors ? error : null} />
      {ticket.quote && <QuoteCard ticket={ticket} onAnswered={reload} />}
      {['resolved', 'closed'].includes(ticket.status) && !ticket.feedback_given && <FeedbackCard ticket={ticket} onSent={reload} />}

      <div className="sup-detail">
        <div>
          <Card title="Request">
            <PlainText text={ticket.description} />
            {ticket.expected_result && <><h3>Expected result</h3><PlainText text={ticket.expected_result} /></>}
          </Card>
          <Tabs tabs={TABS} activeTab={tab} onChange={setTab} label="Ticket sections" />
          {tab === 'conversation' && <TicketConversation key={ticket.id} ticket={ticket} audience="agency" closed={closed} onAttach={() => setTab('files')} onPosted={reload} />}
          {tab === 'files' && <FilesTab ticket={ticket} readOnly={closed} />}
          {tab === 'activity' && <TicketActivityTab ticketId={ticket.id} role={user.role} />}
        </div>
        <aside className="sup-side" aria-label="Ticket details">
          <Card title="Details">
            <dl>
              <div><dt>Status</dt><dd>{ticket.status_label}</dd></div>
              <div><dt>Owner</dt><dd>{ticket.owner?.name ?? 'Not assigned yet'}</dd></div>
              {ticket.end_client && <div><dt>End client</dt><dd>{ticket.end_client.name}</dd></div>}
              {ticket.project && <div><dt>Project</dt><dd>{ticket.project.title}</dd></div>}
              {ticket.service_label && <div><dt>Service</dt><dd>{ticket.service_label}</dd></div>}
              {ticket.requested_deadline && <div><dt>Requested deadline</dt><dd>{formatDate(ticket.requested_deadline)}</dd></div>}
              <div><dt>Created</dt><dd><RelativeTime date={ticket.created_at} /></dd></div>
            </dl>
          </Card>
        </aside>
      </div>

      <Modal open={reopening} onClose={() => !busy && setReopening(false)} title="Reopen this ticket" footer={null}>
        <form className="ui-form" onSubmit={(event) => { event.preventDefault(); act(() => authService.reopenSupportTicket(ticket.ref, reason.trim()), 'The ticket was reopened.') }} noValidate>
          <Textarea label="What is still wrong?" required rows={4} maxLength={2000} value={reason} onChange={(event) => setReason(event.target.value)} error={error?.errors?.reason?.[0] ?? error?.errors?.status?.[0]} />
          <FormActions submitLabel="Reopen" loadingLabel="Reopening…" loading={busy} disabled={!reason.trim()} disabledReason="Say what is still wrong." onCancel={() => setReopening(false)} />
        </form>
      </Modal>
    </section>
  )
}
