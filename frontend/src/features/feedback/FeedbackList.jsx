import { useState } from 'react'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../auth/components/ApiErrorMessage'
import { Badge, Button, Card, FormActions, RelativeTime, Textarea } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { StarRating } from './Stars'

const AGAIN = { yes: { label: 'Would work together again', variant: 'success' }, maybe: { label: 'Maybe again', variant: 'warning' }, no: { label: 'Would not work together again', variant: 'danger' } }

function ReplyForm({ id, onDone }) {
  const [body, setBody] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try { await authService.replyToFeedback(id, body.trim()); await onDone() } catch (reason) { setError(reason); setSaving(false) }
  }
  return (
    <form className="fb-reply-form ui-form" onSubmit={submit} noValidate>
      <Textarea label="Your reply (you can reply once)" rows={3} maxLength={500} required value={body} onChange={(event) => setBody(event.target.value)} error={error?.errors?.body?.[0]} helperText={`${body.length}/500`} />
      {error && !error.errors && <ApiErrorMessage error={error} />}
      <FormActions submitLabel="Send reply" loadingLabel="Sending…" loading={saving} disabled={body.trim().length < 2} onCancel={null} />
    </form>
  )
}

// One piece of feedback. What is shown depends on the viewer (the server removes what they must not see); the
// buttons depend on `actions`: edit (owner, while open), reply (employee, once), remove (admin).
export function FeedbackItem({ item, showAgency = false, canReply = false, onEdit, onRemove, onChanged }) {
  const [replying, setReplying] = useState(false)
  return (
    <Card className={`fb-item${item.status === 'removed' ? ' is-removed' : ''}`}>
      <header className="fb-item-head">
        <StarRating value={item.rating} />
        <Badge variant={AGAIN[item.would_again]?.variant ?? 'slate'}>{AGAIN[item.would_again]?.label ?? item.would_again}</Badge>
        {item.status === 'removed' && <Badge variant="danger">Removed</Badge>}
        <small className="fb-muted fb-item-when">{showAgency && item.agency_name ? `${item.agency_name} · ` : ''}{item.author ? `${item.author} · ` : ''}{formatDate(item.created_at?.slice(0, 10))}</small>
      </header>
      {item.project && <p className="fb-project">On project: <strong>{item.project.title}</strong></p>}
      {(item.highlights.length > 0 || item.concerns.length > 0) && (
        <ul className="fb-chips" aria-label="Highlights and concerns">
          {item.highlights.map((chip) => <li key={chip.key} className="fb-chip is-good">{chip.label}</li>)}
          {item.concerns.map((chip) => <li key={chip.key} className="fb-chip is-concern">{chip.label}</li>)}
        </ul>
      )}
      {item.went_well && <div className="fb-quote"><h4>What went well</h4><p>{item.went_well}</p></div>}
      {item.to_improve && <div className="fb-quote is-improve"><h4>What could be better</h4><p>{item.to_improve}</p></div>}
      {item.private_note && <div className="fb-private"><h4>Private note for our team</h4><p>{item.private_note}</p></div>}
      {item.status === 'removed' && item.removed_reason && <p className="fb-muted">Removed: {item.removed_reason}</p>}
      {item.reply && <div className="fb-reply"><h4>Reply from the employee <small><RelativeTime date={item.reply.created_at} /></small></h4><p>{item.reply.body}</p></div>}
      {canReply && !item.reply && !replying && <Button type="button" size="sm" variant="secondary" onClick={() => setReplying(true)}>Reply</Button>}
      {canReply && !item.reply && replying && <ReplyForm id={item.id} onDone={onChanged} />}
      {(item.editable || (onRemove && item.status === 'published')) && (
        <footer className="fb-item-actions">
          {item.editable && <Button type="button" size="sm" variant="secondary" onClick={() => onEdit(item)}>Edit</Button>}
          {item.editable && <small className="fb-muted">You can edit until {formatDate(item.editable_until?.slice(0, 10))}.</small>}
          {onRemove && item.status === 'published' && <Button type="button" size="sm" variant="ghost" onClick={() => onRemove(item)}>Remove…</Button>}
        </footer>
      )}
    </Card>
  )
}

export function FeedbackList({ items, ...rest }) {
  return <ul className="fb-list">{items.map((item) => <li key={item.id}><FeedbackItem item={item} {...rest} /></li>)}</ul>
}
