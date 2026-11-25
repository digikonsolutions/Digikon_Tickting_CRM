import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FormActions, Input, Modal, Select, Textarea } from '../../../components/ui'

// Asks for exactly what a status change needs (the server lists it in `needs`) and shows its 422 messages next to the
// field. Focus returns to the control that opened it when the dialog closes (Modal does that).
export function TransitionModal({ ticket, transition, meta, onClose, onDone }) {
  const [values, setValues] = useState(() => ({ follow_up_date: new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10) }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const needs = transition.needs
  const people = meta?.people ?? []
  const set = (key) => (event) => setValues((current) => ({ ...current, [key]: event.target.value }))
  const err = (key) => error?.errors?.[key]?.[0]
  const qaPeople = people.filter((person) => person.capabilities.includes('qa'))
  const waiting = ['waiting_agency', 'waiting_third_party'].includes(transition.to)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = { to: transition.to }
      Object.entries(values).forEach(([key, value]) => { if (value !== '' && value != null) body[key] = value })
      for (const key of ['reviewer_id', 'assigned_to_id']) if (body[key]) body[key] = Number(body[key])
      const result = await authService.transitionStaffTicket(ticket.ref, body)
      onDone(result.ticket)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={() => !busy && onClose()} title={transition.label} footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        {waiting && <Alert variant="info">{transition.to === 'waiting_agency' ? 'The SLA clock pauses while we wait for the agency.' : 'Say which vendor or system we are waiting on.'}</Alert>}
        {needs.includes('assigned_to_id') && (
          <Select label="Owner" required value={values.assigned_to_id ?? ''} onChange={set('assigned_to_id')} error={err('assigned_to_id')}>
            <option value="">Choose a person</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
          </Select>
        )}
        {waiting && (
          <>
            <Textarea label={transition.to === 'waiting_agency' ? 'What do we need from the agency?' : 'What are we waiting for, and from whom?'} required rows={3} maxLength={500} value={values.waiting_reason ?? ''} onChange={set('waiting_reason')} error={err('waiting_reason')} />
            <Input label="Follow up on" type="date" required value={values.follow_up_date ?? ''} onChange={set('follow_up_date')} error={err('follow_up_date')} />
          </>
        )}
        {needs.includes('reviewer_id') && (
          <>
            <Select label="QA reviewer" required value={values.reviewer_id ?? ''} onChange={set('reviewer_id')} error={err('reviewer_id')} helperText="Someone with the QA capability. On a critical ticket it cannot be the builder.">
              <option value="">Choose a reviewer</option>{qaPeople.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
            </Select>
            {ticket.priority === 'urgent' && <Textarea label="Admin override reason (only if the reviewer must be the builder)" rows={2} maxLength={1000} value={values.override_reason ?? ''} onChange={set('override_reason')} />}
          </>
        )}
        {needs.includes('failure_note') && <Textarea label="What failed?" required rows={4} maxLength={5000} value={values.failure_note ?? ''} onChange={set('failure_note')} error={err('failure_note')} helperText="Internal. The builder sees this; the agency does not." />}
        {needs.includes('resolution_summary') && (
          <>
            <Textarea label="Resolution summary" required rows={4} maxLength={5000} value={values.resolution_summary ?? ticket.resolution?.summary ?? ''} onChange={set('resolution_summary')} error={err('resolution_summary')} helperText="Written for the agency. What was done?" />
            <Select label="Resolution category" required value={values.resolution_category ?? ticket.resolution?.category ?? ''} onChange={set('resolution_category')} error={err('resolution_category')}>
              <option value="">Choose a category</option>{(meta?.resolution_categories ?? []).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
            </Select>
            {transition.to === 'resolved' && <Textarea label="Root cause (recommended)" rows={2} maxLength={5000} value={values.root_cause ?? ''} onChange={set('root_cause')} helperText="Internal. Helps us prevent it happening again." />}
          </>
        )}
        {needs.includes('reason') && <Textarea label="Reason" required rows={3} maxLength={2000} value={values.reason ?? ''} onChange={set('reason')} error={err('reason')} />}
        {err('status') && <Alert variant="error">{err('status')}</Alert>}
        <ApiErrorMessage error={error && !error.errors ? error : null} />
        <FormActions submitLabel={transition.label} loadingLabel="Saving…" loading={busy} onCancel={onClose} />
      </form>
    </Modal>
  )
}
