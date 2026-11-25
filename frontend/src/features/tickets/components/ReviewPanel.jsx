import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, FormActions, Input, Modal, RelativeTime, Skeleton, Textarea } from '../../../components/ui'
import { LinkOrText } from '../../workorder/BriefView'
import './reviewPanel.css'

const minutesLabel = (minutes) => (minutes ? `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min` : '—')
const DECISIONS = { approved: { label: 'Approved', variant: 'success' }, changes_requested: { label: 'Changes requested', variant: 'orange' } }

// The employee's review package. Everything a reviewer needs to check the work without asking: what was done, where to
// look, what changed in GoHighLevel, how to test it. Never passwords: access is described, not shared.
export function SubmitPackageDialog({ ticket, onClose, onDone }) {
  const [form, setForm] = useState({ summary: '', links: '', ghl_changes: '', test_steps: '', limitations: '', access_note: '', hours: ticket.logged_hours ? String(ticket.logged_hours) : '' })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const errors = error?.errors ?? {}
  const linkList = form.links.split(/\s+/).map((item) => item.trim()).filter(Boolean)
  const linkError = errors.links?.[0] ?? Object.entries(errors).find(([key]) => key.startsWith('links.'))?.[1]?.[0]

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.submitTicketForReview(ticket.id, {
        summary: form.summary.trim(), links: linkList, test_steps: form.test_steps.trim(),
        ...(form.ghl_changes.trim() ? { ghl_changes: form.ghl_changes.trim() } : {}), ...(form.limitations.trim() ? { limitations: form.limitations.trim() } : {}),
        ...(form.access_note.trim() ? { access_note: form.access_note.trim() } : {}), ...(form.hours !== '' ? { time_minutes: Math.round(Number(form.hours) * 60) } : {}),
      })
      await onDone()
    } catch (reason) {
      setError(reason)
      setSaving(false)
    }
  }

  const blocked = form.summary.trim().length < 10 ? 'Say what you did (a sentence or two).' : linkList.length === 0 ? 'Add at least one link to the finished work.' : form.test_steps.trim().length < 5 ? 'Say how to test it.' : null
  return (
    <Modal open onClose={() => !saving && onClose()} title={`Send ${ticket.key} for review`} className="rp-modal" footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <p className="ui-field-help">Our team checks this first, then the agency. Write it so someone who did not do the work can check it.</p>
        <Textarea label="What did you do?" required rows={3} maxLength={5000} value={form.summary} onChange={set('summary')} error={errors.summary?.[0]} />
        <Textarea label="Where can they see it?" required rows={3} placeholder="One link per line: the workflow, the funnel, the test page" value={form.links} onChange={set('links')} error={linkError} helperText="Links starting with https://" />
        <Textarea label="What changed in GoHighLevel?" rows={3} maxLength={5000} value={form.ghl_changes} onChange={set('ghl_changes')} error={errors.ghl_changes?.[0]} helperText="Workflows, pipelines, fields, tags, calendars you created or edited." />
        <Textarea label="How do we test it?" required rows={3} maxLength={5000} value={form.test_steps} onChange={set('test_steps')} error={errors.test_steps?.[0]} helperText="Steps, and the test contact to use." />
        <Textarea label="Known limits (optional)" rows={2} maxLength={3000} value={form.limitations} onChange={set('limitations')} error={errors.limitations?.[0]} />
        <Textarea label="Access needed to check it (optional)" rows={2} maxLength={1000} value={form.access_note} onChange={set('access_note')} error={errors.access_note?.[0]} helperText="Say what access is needed. Never type a password here." />
        <Input label="Time spent, in hours" type="number" min="0" step="0.25" inputMode="decimal" value={form.hours} onChange={set('hours')} error={errors.time_minutes?.[0]} helperText="Filled in from your timer. Change it if it is wrong." />
        {error && !error.errors && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Send for review" loadingLabel="Sending…" loading={saving} disabled={Boolean(blocked)} disabledReason={blocked} onCancel={onClose} />
      </form>
    </Modal>
  )
}

function Review({ review }) {
  const meta = DECISIONS[review.decision] ?? { label: review.decision, variant: 'slate' }
  return (
    <li className="rp-review">
      <p><Badge variant="slate">{review.stage === 'internal' ? 'Our review' : 'Agency review'}</Badge> <Badge variant={meta.variant}>{meta.label}</Badge>{review.reviewer && <small> · {review.reviewer}</small>} <small><RelativeTime date={review.created_at} /></small></p>
      {review.feedback && <p className="rp-feedback">{review.feedback}</p>}
    </li>
  )
}

function Submission({ item, latest }) {
  return (
    <li className="rp-submission">
      <header><strong>Version {item.version}</strong>{latest && <Badge variant="primary">Latest</Badge>}<small>{item.submitted_by} · <RelativeTime date={item.created_at} /> · {minutesLabel(item.time_minutes)}</small></header>
      <dl>
        <div><dt>What was done</dt><dd>{item.summary}</dd></div>
        <div><dt>Where to see it</dt><dd><ul>{item.links.map((link) => <li key={link}><LinkOrText value={link} /></li>)}</ul></dd></div>
        {item.ghl_changes && <div><dt>Changed in GoHighLevel</dt><dd>{item.ghl_changes}</dd></div>}
        {item.test_steps && <div><dt>How to test</dt><dd>{item.test_steps}</dd></div>}
        {item.limitations && <div><dt>Known limits</dt><dd>{item.limitations}</dd></div>}
        {item.access_note && <div><dt>Access needed</dt><dd>{item.access_note}</dd></div>}
        {item.checklist_total > 0 && <div><dt>Checklist</dt><dd>{item.checklist_done} of {item.checklist_total} done{item.checklist_done < item.checklist_total && <> <Badge variant="warning">Not all done</Badge></>}</dd></div>}
      </dl>
      {item.reviews.length > 0 && <ul className="rp-reviews">{item.reviews.map((review) => <Review key={review.id} review={review} />)}</ul>}
    </li>
  )
}

// The decision form for one stage: our team's check (admin) or the agency's. `stage` decides which endpoint role it is.
function ReviewForm({ ticket, stage, onDone }) {
  const [decision, setDecision] = useState('approved')
  const [feedback, setFeedback] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.reviewTicket(ticket.id, { stage, decision, ...(feedback.trim() ? { feedback: feedback.trim() } : {}) })
      await onDone()
    } catch (reason) {
      setError(reason)
      setSaving(false)
    }
  }
  return (
    <form className="rp-form ui-form" onSubmit={submit} noValidate>
      <h3>{stage === 'internal' ? 'Your review of this work' : 'Your decision'}</h3>
      <fieldset className="rp-choice">
        <legend className="visually-hidden">Decision</legend>
        <label><input type="radio" name="decision" checked={decision === 'approved'} onChange={() => setDecision('approved')} /> {stage === 'internal' ? 'Approve and send to the agency' : 'Approve'}</label>
        <label><input type="radio" name="decision" checked={decision === 'changes_requested'} onChange={() => setDecision('changes_requested')} /> Request changes</label>
      </fieldset>
      <Textarea label={decision === 'changes_requested' ? 'What should change?' : 'Note (optional)'} required={decision === 'changes_requested'} rows={4} maxLength={5000} value={feedback} onChange={(event) => setFeedback(event.target.value)} error={error?.errors?.feedback?.[0]} />
      {error && !error.errors && <ApiErrorMessage error={error} />}
      <FormActions submitLabel={decision === 'approved' ? 'Approve' : 'Send back'} loadingLabel="Saving…" loading={saving} disabled={decision === 'changes_requested' && !feedback.trim()} disabledReason="Say what should change." onCancel={null} />
    </form>
  )
}

// The thread of review packages and the reviews on them, with the review form for whoever's turn it is.
export function ReviewPanel({ ticket, role, onChanged }) {
  const load = useCallback(() => authService.getTicketSubmissions(ticket.id), [ticket.id])
  const { data, error, isLoading } = useAsync(load)
  const submissions = data?.submissions ?? []
  const ourTurn = ticket.allowed_actions?.some((action) => action.action === 'internal_review')
  const agencyTurn = ticket.status === 'in_review' && (role === 'agency_owner' || role === 'agency_client') && ticket.allowed_actions?.some((action) => action.action === 'approve')

  if (isLoading && !data) return <div role="status" aria-label="Loading reviews"><Skeleton variant="row" /><Skeleton variant="row" /></div>
  return (
    <div className="rp-panel">
      {error && <Alert variant="error">{error.message}</Alert>}
      {ourTurn && (
        <Alert variant="warning" title="Waiting for our review">Check the latest package below, then approve it for the agency or send it back to the employee.</Alert>
      )}
      {submissions.length === 0
        ? <p className="ui-muted">Nothing has been sent for review yet.</p>
        : <ol className="rp-list">{[...submissions].reverse().map((item, index) => <Submission key={item.id} item={item} latest={index === 0} />)}</ol>}
      {ourTurn && <ReviewForm ticket={ticket} stage="internal" onDone={onChanged} />}
      {agencyTurn && <ReviewForm ticket={ticket} stage="agency" onDone={onChanged} />}
    </div>
  )
}
