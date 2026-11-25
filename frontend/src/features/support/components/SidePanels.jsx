import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Badge, Button, Card, Icon, Input, Select, Skeleton, useToast } from '../../../components/ui'

const KINDS = [['scope', 'Scope'], ['quote', 'Quote'], ['delivery', 'Delivery'], ['complimentary', 'Complimentary']]

// Approvals: anyone on the team can ask; only the right role sees Approve and Reject.
export function ApprovalsPanel({ ticket }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.listTicketApprovals(ticket.ref), [ticket.ref])
  const { data, reload } = useAsync(load)
  const [kind, setKind] = useState('scope')
  const [note, setNote] = useState('')
  async function run(call, text) {
    try { await call(); showToast(text); setNote(''); await reload() } catch (reason) { showToast(reason.message ?? 'That did not work.', 'error') }
  }
  return (
    <Card title={<><Icon name="lock" size={15} /> Approvals</>}>
      {!data ? <Skeleton variant="row" /> : data.approvals.length === 0 ? <p className="ui-field-help">No approvals requested.</p> : (
        <ul className="sup-approvals">
          {data.approvals.map((item) => (
            <li key={item.id}>
              <Badge variant={item.status === 'approved' ? 'success' : item.status === 'rejected' ? 'danger' : 'warning'}>{item.status}</Badge> <strong>{item.kind}</strong>{item.note && <span>: {item.note}</span>}
              {item.can_decide && <span className="sup-approval-actions"><Button type="button" size="sm" onClick={() => run(() => authService.decideTicketApproval(ticket.ref, item.id, { approve: true }), 'Approved.')}>Approve</Button><Button type="button" size="sm" variant="secondary" onClick={() => run(() => authService.decideTicketApproval(ticket.ref, item.id, { approve: false }), 'Rejected.')}>Reject</Button></span>}
            </li>
          ))}
        </ul>
      )}
      <Select label="Ask for approval" value={kind} onChange={(event) => setKind(event.target.value)}>{KINDS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select>
      <Input label="Note" maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} />
      <Button type="button" variant="secondary" onClick={() => run(() => authService.requestTicketApproval(ticket.ref, { kind, note: note || undefined }), 'Approval requested.')}>Request</Button>
    </Card>
  )
}

// Related and duplicate tickets, and whether you follow this one.
export function RelationsPanel({ ticket }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getTicketRelations(ticket.ref), [ticket.ref])
  const { data, reload } = useAsync(load)
  const [ref, setRef] = useState('')
  const [type, setType] = useState('related')
  const [error, setError] = useState(null)
  async function run(call) {
    setError(null)
    try { await call(); await reload() } catch (reason) { setError(reason.errors?.ref?.[0] ?? reason.message) }
  }
  return (
    <Card title="Related tickets">
      {!data ? <Skeleton variant="row" /> : (
        <>
          <ul className="sup-approvals">
            {data.relations.map((item) => <li key={item.id}><Badge>{item.type}</Badge> <Link to={`/staff/tickets/${item.ref}`}>{item.ref}: {item.title}</Link> <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${item.ref}`} onClick={() => run(() => authService.removeTicketRelation(ticket.ref, item.id))}><Icon name="trash" size={14} /></Button></li>)}
            {data.relations.length === 0 && <li className="ui-field-help">None linked.</li>}
          </ul>
          <Input label="Ticket ID" placeholder="DG-1001" value={ref} onChange={(event) => setRef(event.target.value.toUpperCase())} error={error} />
          <Select label="Link as" value={type} onChange={(event) => setType(event.target.value)}><option value="related">Related</option><option value="duplicate">Duplicate</option></Select>
          <Button type="button" variant="secondary" disabled={!ref} onClick={() => run(async () => { await authService.addTicketRelation(ticket.ref, { ref, type }); setRef(''); showToast('Linked.') })}>Link</Button>
          <Button type="button" variant="ghost" onClick={() => run(() => authService.watchTicket(ticket.ref, !data.watching))}>{data.watching ? 'Stop following' : 'Follow this ticket'} ({data.watchers})</Button>
        </>
      )}
    </Card>
  )
}
