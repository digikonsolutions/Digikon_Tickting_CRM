import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, FormActions, Input, Modal, Select, StatusBadge, Textarea, useToast } from '../../../components/ui'
import { formatHours } from '../../../lib/format'
import './projectHours.css'

const pct = (part, total) => (total > 0 ? Math.max(0, Math.min(100, (part / total) * 100)) : 0)

// Project hours against ticket hours: total, given to tickets, left, logged, a line per service, and (for an
// administrator) each ticket's hours with a button to change them. `hours` comes from the project payload.
export function ProjectHoursPanel({ hours, tickets = [], admin = false, projectId, onChanged }) {
  const { showToast } = useToast()
  const [editing, setEditing] = useState(null)
  const [moving, setMoving] = useState(false)
  if (!hours) return null
  const open = tickets.filter((ticket) => ticket.status !== 'cancelled')
  const none = hours.remaining <= 0 && hours.total > 0

  return (
    <Card title="Hours" description="What the project has, what the tickets use, and what is left.">
      <div className="ph-bar" role="img" aria-label={`${formatHours(hours.allocated)} of ${formatHours(hours.total)} given to tickets`}>
        <span className="ph-bar-fill" style={{ width: `${pct(hours.allocated, hours.total)}%` }} />
        {hours.logged != null && <span className="ph-bar-logged" style={{ width: `${pct(hours.logged, hours.total)}%` }} />}
      </div>
      <dl className="ph-figures">
        <div><dt>Project total</dt><dd>{formatHours(hours.total)}</dd></div>
        <div><dt>Given to tickets</dt><dd>{formatHours(hours.allocated)}</dd></div>
        <div><dt>Left to give</dt><dd className={none ? 'ph-none' : undefined}>{formatHours(hours.remaining)}</dd></div>
        {hours.logged != null && <div><dt>Logged so far</dt><dd>{formatHours(hours.logged)}</dd></div>}
      </dl>

      {hours.by_service?.length > 0 && (
        <table className="ph-table">
          <caption>By service</caption>
          <thead><tr><th scope="col">Service</th><th scope="col">Estimate</th><th scope="col">In tickets</th><th scope="col">Left</th></tr></thead>
          <tbody>
            {hours.by_service.map((row) => (
              <tr key={row.id}>
                <th scope="row">{row.label}</th>
                <td>{row.estimate != null ? formatHours(row.estimate) : '—'}</td>
                <td>{formatHours(row.ticket_hours)} <small>({row.tickets} ticket{row.tickets === 1 ? '' : 's'})</small></td>
                <td>{row.remaining != null ? formatHours(row.remaining) : '—'}</td>
              </tr>
            ))}
            {hours.general > 0 && <tr><th scope="row">General project work</th><td>—</td><td>{formatHours(hours.general)}</td><td>—</td></tr>}
          </tbody>
        </table>
      )}

      {admin && open.length > 0 && (
        <>
          <table className="ph-table">
            <caption>Tickets</caption>
            <thead><tr><th scope="col">Ticket</th><th scope="col">Service</th><th scope="col">Hours</th><th scope="col">Logged</th><th scope="col">Left</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Change</span></th></tr></thead>
            <tbody>
              {open.map((ticket) => {
                const closed = ticket.status === 'done'
                return (
                  <tr key={ticket.id}>
                    <th scope="row"><span className="ph-key">{ticket.key}</span> {ticket.title}</th>
                    <td>{ticket.service ? <Badge variant="info">{ticket.service.label}</Badge> : <span className="ph-muted">General</span>}</td>
                    <td>{formatHours(ticket.estimated_hours)}</td>
                    <td>{formatHours(ticket.logged_hours)}</td>
                    <td>{formatHours(ticket.remaining_hours)}</td>
                    <td><StatusBadge status={ticket.status} /></td>
                    <td><Button type="button" size="sm" variant="secondary" disabled={closed} title={closed ? 'A finished ticket keeps its hours' : undefined} onClick={() => setEditing(ticket)} aria-label={`Change hours of ${ticket.key}`}>Change</Button></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          {open.filter((ticket) => ticket.status !== 'done').length > 1 && <p><Button type="button" variant="ghost" size="sm" onClick={() => setMoving(true)}>Move hours between tickets</Button></p>}
        </>
      )}

      {editing && <HoursDialog ticket={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); showToast('Ticket hours updated. The agency and the employee were told.'); onChanged?.() }} />}
      {moving && <MoveDialog tickets={open.filter((ticket) => ticket.status !== 'done')} projectId={projectId} onClose={() => setMoving(false)} onDone={() => { setMoving(false); showToast('Hours moved.'); onChanged?.() }} />}
    </Card>
  )
}

function HoursDialog({ ticket, onClose, onDone }) {
  const [value, setValue] = useState(String(ticket.estimated_hours))
  const [why, setWhy] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.adminSetTicketHours(ticket.id, { estimated_hours: Number(value), reason: why.trim() })
      onDone()
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal open onClose={() => !saving && onClose()} title={`Change hours of ${ticket.key}`} footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <p className="ui-field-help">Now {formatHours(ticket.estimated_hours)}, {formatHours(ticket.logged_hours)} already logged. The days are planned again, and the agency and the employee are told.</p>
        <Input label="New hours" type="number" min="0.25" step="0.25" inputMode="decimal" required value={value} onChange={(event) => setValue(event.target.value)} error={error?.errors?.estimated_hours?.[0]} />
        <Textarea label="Why are the hours changing?" required rows={3} maxLength={500} value={why} onChange={(event) => setWhy(event.target.value)} error={error?.errors?.reason?.[0]} helperText="Shown to the agency in the ticket history." />
        {error && !error.errors && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Change hours" loadingLabel="Saving…" loading={saving} disabled={!value || why.trim().length < 5} onCancel={onClose} />
      </form>
    </Modal>
  )
}

function MoveDialog({ tickets, projectId, onClose, onDone }) {
  const [form, setForm] = useState({ from: '', to: '', hours: '', why: '' })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.adminMoveTicketHours(projectId, { from_ticket_id: Number(form.from), to_ticket_id: Number(form.to), hours: Number(form.hours), reason: form.why.trim() })
      onDone()
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }
  const options = tickets.map((ticket) => <option key={ticket.id} value={ticket.id}>{ticket.key} · {ticket.title} ({formatHours(ticket.estimated_hours)})</option>)
  const others = error?.errors ? Object.entries(error.errors).filter(([key]) => !['hours', 'reason'].includes(key)) : []
  return (
    <Modal open onClose={() => !saving && onClose()} title="Move hours between tickets" footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <Select label="Take hours from" required value={form.from} onChange={set('from')}><option value="">Choose a ticket</option>{options}</Select>
        <Select label="Give them to" required value={form.to} onChange={set('to')}><option value="">Choose a ticket</option>{options}</Select>
        <Input label="Hours to move" type="number" min="0.25" step="0.25" inputMode="decimal" required value={form.hours} onChange={set('hours')} error={error?.errors?.hours?.[0]} />
        <Textarea label="Why?" required rows={3} maxLength={500} value={form.why} onChange={set('why')} error={error?.errors?.reason?.[0]} />
        {others.map(([key, list]) => <p key={key} className="ui-field-error" role="alert">{list[0]}</p>)}
        {error && !error.errors && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Move hours" loadingLabel="Moving…" loading={saving} disabled={!form.from || !form.to || form.from === form.to || !form.hours || form.why.trim().length < 5} onCancel={onClose} />
      </form>
    </Modal>
  )
}
