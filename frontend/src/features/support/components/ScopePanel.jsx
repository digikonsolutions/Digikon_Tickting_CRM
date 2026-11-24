import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, Icon, Input, Select, useToast } from '../../../components/ui'

const CLASSES = [['bug', 'Bug / defect'], ['missed_requirement', 'Missed requirement'], ['included_revision', 'Included revision'], ['new_scope', 'New scope'], ['enhancement', 'Enhancement'], ['warranty', 'Warranty / fix promise']]
const BILLING = [['', 'Use the default'], ['billable', 'Billable'], ['non_billable', 'Non-billable'], ['complimentary', 'Complimentary (needs a reason)']]

// Scope and billing are staff-only. A builder proposes; a delivery manager sets or approves.
export function ScopePanel({ ticket, canDecide, onChanged }) {
  const { showToast } = useToast()
  const [form, setForm] = useState({ scope_classification: ticket.scope_classification ?? '', billing_status: ticket.billing_status ?? '', reason: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const label = (list, value) => list.find(([key]) => key === value)?.[1] ?? 'Not classified'
  async function run(call, done) {
    setBusy(true)
    setError(null)
    try {
      await call()
      showToast(done)
      onChanged()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card title={<><Icon name="lock" size={15} /> Scope and billing</>}>
      <p className="ui-field-help">Staff only. {canDecide ? 'You decide.' : 'You can propose; a delivery manager approves.'}</p>
      <dl><div><dt>Classification</dt><dd>{label(CLASSES, ticket.scope_classification)}</dd></div><div><dt>Billing</dt><dd>{ticket.billing_status?.replace('_', '-') ?? 'Not set'}</dd></div></dl>
      {ticket.scope_proposal && (
        <Alert variant="info">Proposed: {label(CLASSES, ticket.scope_proposal.scope_classification)}, {ticket.scope_proposal.billing_status?.replace('_', '-')}.{canDecide && <div><Button type="button" size="sm" loading={busy} onClick={() => run(() => authService.approveStaffTicketScope(ticket.ref), 'Scope approved.')}>Approve</Button></div>}</Alert>
      )}
      <Select label="Classification" value={form.scope_classification} onChange={(event) => setForm({ ...form, scope_classification: event.target.value })} error={error?.errors?.scope_classification?.[0]}>
        <option value="">Choose…</option>{CLASSES.map(([value, name]) => <option key={value} value={value}>{name}</option>)}
      </Select>
      <Select label="Billing" value={form.billing_status} onChange={(event) => setForm({ ...form, billing_status: event.target.value })}>{BILLING.map(([value, name]) => <option key={value} value={value}>{name}</option>)}</Select>
      {(form.billing_status === 'complimentary' || form.reason) && <Input label="Reason" maxLength={1000} value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} error={error?.errors?.reason?.[0]} />}
      <Button type="button" variant="secondary" loading={busy} disabled={!form.scope_classification} onClick={() => run(() => authService.classifyStaffTicket(ticket.ref, { scope_classification: form.scope_classification, ...(form.billing_status ? { billing_status: form.billing_status } : {}), ...(form.reason ? { reason: form.reason } : {}) }), canDecide ? 'Scope saved.' : 'Proposal sent.')}>{canDecide ? 'Save' : 'Propose'}</Button>
    </Card>
  )
}
