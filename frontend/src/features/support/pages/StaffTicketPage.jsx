import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, CopyButton, EmptyState, LifecyclePriorityBadge, LifecycleStatusBadge, PageHeader, PlainText, PRIORITY_OPTIONS, RelativeTime, Select, Skeleton, SlaIndicator, Tabs, TypeBadge, useToast } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import { FilesTab } from '../../tickets/components/FilesTab'
import { TicketActivityTab } from '../components/TicketActivityTab'
import { TicketConversation } from '../components/TicketConversation'
import { ConvertModal } from '../components/ConvertModal'
import { ScopePanel } from '../components/ScopePanel'
import { TimeTab } from '../components/TimeTab'
import { TransitionModal } from '../components/TransitionModal'
import '../support.css'

const TABS = [{ id: 'conversation', label: 'Conversation' }, { id: 'internal', label: 'Internal notes' }, { id: 'activity', label: 'Activity' }, { id: 'files', label: 'Files' }, { id: 'time', label: 'Time' }]

// One ticket for staff: the status control lists only valid next steps, owner and reviewer are pickers, and the composer
// switches between a public reply and an internal note (always starting on public).
export function StaffTicketPage() {
  const { ref } = useParams()
  const { user } = useAuth()
  const { showToast } = useToast()
  const [tab, setTab] = useState('conversation')
  const [transition, setTransition] = useState(null)
  const [converting, setConverting] = useState(false)
  const [error, setError] = useState(null)
  const load = useCallback(async () => {
    const [ticket, people] = await Promise.all([authService.getStaffTicket(ref), authService.listStaffPeople().catch(() => ({ people: [] }))])
    return { ticket: ticket.ticket, meta: people }
  }, [ref])
  const { data, error: loadError, isLoading, reload } = useAsync(load)
  const ticket = data?.ticket
  const meta = data?.meta

  if (isLoading && !data) return <section className="page" role="status" aria-label="Loading ticket"><Skeleton variant="row" /><Skeleton variant="card" /></section>
  if (loadError?.status === 404 || (!ticket && !loadError)) return <section className="page"><EmptyState title="Ticket not found" action={<Link className="ui-button ui-button-primary" to="/staff/tickets">Back to the queue</Link>}>We could not find {ref}, or it is not in your queue.</EmptyState></section>
  if (loadError && !ticket) return <section className="page"><Alert variant="error" title="Could not load this ticket">{loadError.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert></section>

  const canRoute = (ticket.allowed_transitions ?? []).length > 0 || user.role === 'admin' || (meta?.people ?? []).find((person) => person.id === user.id)?.capabilities.some((name) => ['triage', 'delivery_manager'].includes(name))
  async function change(call, text) {
    setError(null)
    try {
      await call()
      showToast(text)
      await reload()
    } catch (reason) {
      setError(reason)
    }
  }
  const closed = ['closed', 'rejected'].includes(ticket.status)
  const people = meta?.people ?? []
  const myCapabilities = people.find((person) => person.id === user.id)?.capabilities ?? []

  return (
    <section className="page">
      <PageHeader eyebrow={<Link to="/staff/tickets">Support queue</Link>} title={ticket.title} subtitle={<span className="sup-detail-head"><span className="sup-ref">{ticket.ref}</span><CopyButton value={ticket.ref} label="Copy ID" /></span>} />
      <div className="sup-detail-head" style={{ marginBottom: 'var(--space-3)' }}>
        <LifecycleStatusBadge status={ticket.status} label={ticket.status_label} />
        <LifecyclePriorityBadge priority={ticket.priority} label={ticket.priority_label} />
        <TypeBadge label={ticket.type_label ?? 'Project work'} />
        {ticket.sla?.response && <SlaIndicator clock={ticket.sla.response} kind="response" />}
        {ticket.sla?.resolution && <SlaIndicator clock={ticket.sla.resolution} kind="resolution" />}
        {ticket.secret_flagged &&<span className="sup-secret">Possible password or key in the text. Check before replying.</span>}
      </div>
      <ApiErrorMessage error={error && !error.errors ? error : null} />
      {error?.errors && <Alert variant="error">{Object.values(error.errors).flat()[0]}</Alert>}

      {ticket.is_legacy
        ? <Alert variant="info">This is project work. Open it in the <Link to={`/tickets/${ticket.key}`}>project ticket view</Link> to change its status.</Alert>
        : (
          <div className="sup-detail-head" role="group" aria-label="Next steps" style={{ marginBottom: 'var(--space-3)' }}>
            {(ticket.allowed_transitions ?? []).map((item) => <Button key={item.to} type="button" variant={['resolved', 'closed'].includes(item.to) ? 'primary' : 'secondary'} onClick={() => setTransition(item)}>{item.label}</Button>)}
            {(ticket.allowed_transitions ?? []).length === 0 && <span className="ui-field-help">No status changes are available to you right now.</span>}
            {canRoute && !ticket.converted_to && <Button type="button" variant="ghost" onClick={() => setConverting(true)}>Convert…</Button>}
          </div>
        )}

      <div className="sup-detail">
        <div>
          <Card title="Request">
            <PlainText text={ticket.description} />
            {ticket.expected_result && <><h3>Expected result</h3><PlainText text={ticket.expected_result} /></>}
            {ticket.details && Object.keys(ticket.details).length > 0 && (
              <dl className="sup-side">{Object.entries(ticket.details).filter(([, value]) => value).map(([key, value]) => <div key={key}><dt>{key.replaceAll('_', ' ')}</dt><dd><PlainText text={String(value)} /></dd></div>)}</dl>
            )}
          </Card>
          <Tabs tabs={TABS} activeTab={tab} onChange={setTab} label="Ticket sections" />
          {tab === 'conversation' && <TicketConversation key={`c${ticket.id}`} ticket={ticket} audience="staff" recipient={ticket.submitted_by?.name ? `${ticket.submitted_by.name}, ${ticket.agency?.name}` : ticket.agency?.name} closed={closed} onAttach={() => setTab('files')} onPosted={reload} />}
          {tab === 'internal' && <TicketConversation key={`i${ticket.id}`} ticket={ticket} audience="staff" internalOnly closed={closed} />}
          {tab === 'activity' && <TicketActivityTab ticketId={ticket.id} role={user.role} />}
          {tab === 'files' && <FilesTab ticket={ticket} readOnly={closed} allowInternal />}
          {tab === 'time' && <TimeTab key={ticket.id} ticket={ticket} closed={closed} />}
        </div>
        <aside className="sup-side" aria-label="Ticket details">
          <Card title="Routing">
            <Select label="Owner" value={ticket.assigned_to?.id ?? ''} disabled={!canRoute} onChange={(event) => change(() => authService.assignStaffTicket(ticket.ref, { assigned_to_id: event.target.value ? Number(event.target.value) : null }), 'Owner changed.')}>
              <option value="">Unassigned</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
            </Select>
            <Select label="Reviewer" value={ticket.reviewer?.id ?? ''} disabled={!canRoute} onChange={(event) => change(() => authService.assignStaffTicket(ticket.ref, { reviewer_id: event.target.value ? Number(event.target.value) : null }), 'Reviewer changed.')}>
              <option value="">None</option>{people.filter((person) => person.capabilities.includes('qa')).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
            </Select>
            <Select label="Team" value={ticket.assigned_team ?? ''} disabled={!canRoute} onChange={(event) => change(() => authService.assignStaffTicket(ticket.ref, { assigned_team: event.target.value || null }), 'Team changed.')}>
              <option value="">None</option>{(meta?.teams ?? []).map((team) => <option key={team} value={team}>{team.replaceAll('_', ' ')}</option>)}
            </Select>
            <Select label="Priority" value={ticket.priority} disabled={!canRoute} onChange={(event) => change(() => authService.updateStaffTicket(ticket.ref, { priority: event.target.value }), 'Priority changed.')}>
              {PRIORITY_OPTIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </Select>
          </Card>
          {!ticket.is_legacy && <ScopePanel key={`${ticket.id}-${ticket.scope_classification}-${ticket.scope_proposal ? 'p' : 'n'}`} ticket={ticket} canDecide={user.role === 'admin' || myCapabilities.includes('delivery_manager')} onChanged={reload} />}
          <Card title="Details">
            <dl>
              <div><dt>Agency</dt><dd>{ticket.agency?.name}</dd></div>
              {ticket.end_client && <div><dt>End client</dt><dd>{ticket.end_client.name}</dd></div>}
              {ticket.project && <div><dt>Project</dt><dd>{ticket.project.title}</dd></div>}
              {ticket.service_label && <div><dt>Service</dt><dd>{ticket.service_label}</dd></div>}
              <div><dt>Submitted by</dt><dd>{ticket.submitted_by?.name ?? 'Unknown'}</dd></div>
              {ticket.requested_deadline && <div><dt>Requested deadline</dt><dd>{formatDate(ticket.requested_deadline)}</dd></div>}
              <div><dt>First response</dt><dd>{ticket.first_response_at ? <RelativeTime date={ticket.first_response_at} /> : 'Not yet'}</dd></div>
              {ticket.waiting && <div><dt>Waiting</dt><dd>{ticket.waiting.reason}{ticket.waiting.follow_up_date && `, follow up ${formatDate(ticket.waiting.follow_up_date)}`}</dd></div>}
              {ticket.reopened_count > 0 && <div><dt>Reopened</dt><dd>{ticket.reopened_count} time{ticket.reopened_count === 1 ? '' : 's'}</dd></div>}
              {ticket.resolution && <div><dt>Resolution</dt><dd><PlainText text={ticket.resolution.summary} /></dd></div>}
            </dl>
          </Card>
        </aside>
      </div>
      {converting && <ConvertModal ticket={ticket} onClose={() => setConverting(false)} onDone={() => { setConverting(false); reload() }} />}
      {transition && <TransitionModal ticket={ticket} transition={transition} meta={meta} onClose={() => setTransition(null)} onDone={() => { setTransition(null); showToast('Status updated.'); reload() }} />}
    </section>
  )
}
