import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, FormActions, Input, Modal, Textarea } from '../../../components/ui'
import { formatHours } from '../../../lib/format'

const CLOSED = ['done', 'cancelled']

// What is asked, and why. The server enforces the real limits (the project's hours, one open request per ticket).
export function validateHoursRequest({ hours, reason }) {
  const errors = {}
  const value = Number(hours)
  if (!(value >= 0.25)) errors.hours = 'Ask for at least 0.25 hours.'
  else if (value > 180) errors.hours = 'That is more than a project can hold (180 hours).'
  if (reason.trim().length < 3) errors.reason = 'Tell the agency why the extra time is needed.'
  return errors
}

function AskModal({ ticket, onClose, onSent }) {
  const [values, setValues] = useState({ hours: '', reason: '' })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(false)
  const shown = (field) => errors[field] ?? error?.errors?.[field]?.[0]

  async function submit(event) {
    event.preventDefault()
    if (sending) return
    const found = validateHoursRequest(values)
    setErrors(found)
    setError(null)
    if (Object.keys(found).length) return
    setSending(true)
    try {
      await authService.requestMoreHours(ticket.id, { hours: Number(values.hours), reason: values.reason.trim() })
      onSent()
    } catch (reason) {
      setError(reason)
    } finally {
      setSending(false)
    }
  }

  return (
    <Modal open onClose={() => !sending && onClose()} title="Ask for more hours">
      <form className="ui-form" onSubmit={submit} noValidate>
        <p className="ui-field-help">{ticket.key}: {ticket.title}. It has {formatHours(ticket.estimated_hours)} now. Your agency decides; nothing changes until they approve.</p>
        <Input id="more-hours" label="Extra hours needed" type="number" inputMode="decimal" min="0.25" step="0.25" required value={values.hours} error={shown('hours')}
          onChange={(event) => setValues((current) => ({ ...current, hours: event.target.value }))} />
        <Textarea id="more-reason" label="Why?" rows={3} maxLength={1000} required value={values.reason} error={shown('reason')}
          onChange={(event) => setValues((current) => ({ ...current, reason: event.target.value }))} />
        {error && !Object.keys(error.errors ?? {}).length && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Send request" loadingLabel="Sending…" loading={sending} onCancel={onClose} />
      </form>
    </Modal>
  )
}

/**
 * The "more hours" request on a ticket. The assigned employee asks, sees the open request and can withdraw it; the
 * agency's owner or client approves (the hours are added and the schedule re-planned) or declines with a note.
 */
export function HoursRequestPanel({ ticket, role, employeeName, onChanged, onMessage }) {
  const [asking, setAsking] = useState(false)
  const [busy, setBusy] = useState(null)
  const [declining, setDeclining] = useState(false)
  const [note, setNote] = useState('')
  const [error, setError] = useState(null)
  const open = ticket.hours_request
  const isEmployee = role === 'employee'
  const canAnswer = role === 'agency_owner' || role === 'agency_client'
  if (!isEmployee && !canAnswer) return null
  if (CLOSED.includes(ticket.status) && !open) return null

  async function run(kind, call, message) {
    setBusy(kind)
    setError(null)
    try {
      await call()
      onMessage?.(message)
      setDeclining(false)
      setNote('')
      await onChanged?.()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(null)
    }
  }

  if (!open) {
    if (!isEmployee) return null
    return (
      <>
        <Button type="button" size="sm" variant="secondary" onClick={() => setAsking(true)}>Ask for more hours</Button>
        {asking && <AskModal ticket={ticket} onClose={() => setAsking(false)} onSent={async () => { setAsking(false); onMessage?.('Request sent to your agency.'); await onChanged?.() }} />}
      </>
    )
  }

  return (
    <Alert variant="info" title={isEmployee ? `You asked for ${formatHours(open.hours)} more` : `${employeeName ?? 'Your employee'} asks for ${formatHours(open.hours)} more`} className="hours-request">
      <p>{open.reason}</p>
      {error && <ApiErrorMessage error={error} />}
      {isEmployee && <Button type="button" size="sm" variant="ghost" loading={busy === 'cancel'} onClick={() => run('cancel', () => authService.cancelHoursRequest(open.id), 'Request withdrawn.')}>Withdraw request</Button>}
      {canAnswer && !declining && (
        <div className="hours-request-actions">
          <Button type="button" size="sm" loading={busy === 'approve'} onClick={() => run('approve', () => authService.approveHoursRequest(open.id), `Added ${formatHours(open.hours)} to ${ticket.key}.`)}>Approve and add the hours</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => setDeclining(true)}>Decline</Button>
        </div>
      )}
      {canAnswer && declining && (
        <div className="hours-request-actions">
          <Textarea id="decline-note" label="Note for the employee (optional)" rows={2} maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} />
          <Button type="button" size="sm" variant="secondary" loading={busy === 'decline'} onClick={() => run('decline', () => authService.declineHoursRequest(open.id, note.trim()), 'Request declined.')}>Send answer</Button>
          <Button type="button" size="sm" variant="ghost" onClick={() => setDeclining(false)}>Back</Button>
        </div>
      )}
    </Alert>
  )
}
