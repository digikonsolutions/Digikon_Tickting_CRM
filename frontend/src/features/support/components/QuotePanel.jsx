import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { Button, Card, FieldRow, Icon, Input, Textarea, useToast } from '../../../components/ui'
import { formatDate } from '../../../lib/format'

const money = (amount, currency) => new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount)

// Staff: send a price for new scope, see the answers, withdraw an open quote. Delivery managers and finance only.
export function QuotePanel({ ticket, canSend, onChanged }) {
  const { showToast } = useToast()
  const [form, setForm] = useState({ amount: '', currency: 'USD', description: '', valid_until: '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const open = (ticket.quotes ?? []).find((quote) => quote.status === 'pending')
  async function send(event) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await authService.sendTicketQuote(ticket.ref, { ...form, amount: Number(form.amount), valid_until: form.valid_until || undefined })
      showToast('Quote sent.')
      setForm({ amount: '', currency: 'USD', description: '', valid_until: '' })
      onChanged()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card title="Quotes">
      {(ticket.quotes ?? []).length === 0 && <p className="ui-field-help">No quote sent.</p>}
      <ul className="sup-approvals">
        {(ticket.quotes ?? []).map((quote) => <li key={quote.id}><strong>{money(quote.amount, quote.currency)}</strong> {quote.status}{quote.valid_until && `, valid until ${formatDate(quote.valid_until)}`}{quote.decision_note && <span className="ui-field-help"> Note: {quote.decision_note}</span>}</li>)}
      </ul>
      {open && canSend && <Button type="button" variant="ghost" size="sm" onClick={async () => { await authService.withdrawTicketQuote(ticket.ref); onChanged() }}>Withdraw the open quote</Button>}
      {canSend && (
        <form className="ui-form" onSubmit={send} noValidate aria-label="Send a quote">
          <FieldRow><Input label="Amount" type="number" min="0.01" step="0.01" required value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} error={error?.errors?.amount?.[0]} /><Input label="Currency" maxLength={3} value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase() })} error={error?.errors?.currency?.[0]} /></FieldRow>
          <Textarea label="What it covers (the agency reads this)" rows={3} maxLength={5000} required value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} error={error?.errors?.description?.[0]} />
          <Input label="Valid until" type="date" value={form.valid_until} onChange={(event) => setForm({ ...form, valid_until: event.target.value })} error={error?.errors?.valid_until?.[0]} />
          <Button type="submit" variant="secondary" loading={busy} disabled={!form.amount || !form.description.trim()}><Icon name="file" size={15} /> Send quote</Button>
        </form>
      )}
    </Card>
  )
}
