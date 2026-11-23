import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import {
  Alert, Badge, Button, Card, EmptyState, FieldRow, FormActions, Input, Modal, PageHeader, Pagination, Select, Table, Tabs, Textarea, useToast,
} from '../../../components/ui'
import { formatDate, formatDateTime, formatShortDate } from '../../../lib/format'
import { EmployeeQueueDrawer } from '../../queue/components/EmployeeQueueDrawer'
import { RUSH_STATUS, RUSH_TYPE_LABELS, toMinorUnits } from '../../queue/queueFormat'
import '../../queue/queue.css'

const STATUS_OPTIONS = [
  ['', 'All statuses'], ['open', 'Open (waiting or quoted)'], ['pending', 'Waiting for a quote'], ['quoted', 'Quoted'],
  ['fulfilled', 'Fulfilled'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled'], ['expired', 'Expired'],
]

function QuoteModal({ request, onClose, onDone }) {
  const [amount, setAmount] = useState(request.quoted_amount_minor ? (request.quoted_amount_minor / 100).toFixed(2) : '')
  const [hours, setHours] = useState('48')
  const [note, setNote] = useState(request.quote_note ?? '')
  const [employeeId, setEmployeeId] = useState(request.proposed_employee ? String(request.proposed_employee.id) : '')
  const [employees, setEmployees] = useState(null)
  const [fieldError, setFieldError] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const needsEmployee = request.type === 'extra_employee'

  useEffect(() => {
    if (!needsEmployee) return undefined
    let active = true
    authService.listAvailableEmployeesAsAdmin().then((response) => { if (active) setEmployees(response.employees) }).catch((reason) => { if (active) setError(reason) })
    return () => { active = false }
  }, [needsEmployee])

  async function submit(event) {
    event.preventDefault()
    const minor = toMinorUnits(amount)
    if (minor === null) { setFieldError('Enter an amount such as 250 or 250.50.'); return }
    if (needsEmployee && !employeeId) { setFieldError(null); setError(new Error('Choose the employee to offer.')); return }
    setFieldError(null)
    setSaving(true)
    setError(null)
    try {
      const { rush_request: quoted } = await authService.quoteRushRequest(request.id, {
        amount_minor: minor, valid_hours: Number(hours) || undefined, note: note.trim() || null, ...(needsEmployee ? { employee_id: Number(employeeId) } : {}),
      })
      onDone(quoted)
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={() => !saving && onClose()} title={`Quote: ${request.title}`}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <p className="queue-muted">{request.agency.name} · {RUSH_TYPE_LABELS[request.type]} · {request.estimated_hours} h by {formatDate(request.needed_by)}</p>
        <FieldRow columns={2}>
          <Input id="quote-amount" label="Price" inputMode="decimal" required value={amount} onChange={(event) => setAmount(event.target.value)} error={fieldError} helperText="In the billing currency, for example 250.00." />
          <Input id="quote-hours" label="Valid for (hours)" type="number" min="1" max="720" value={hours} onChange={(event) => setHours(event.target.value)} />
        </FieldRow>
        {needsEmployee && (
          <Select id="quote-employee" label="Employee to offer" required value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} helperText="The agency never sees who you pick until the work is accepted.">
            <option value="">{employees ? 'Choose an employee' : 'Loading…'}</option>
            {(employees ?? []).map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
          </Select>
        )}
        <Textarea id="quote-note" label="Note for the owner (optional)" rows={2} maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} />
        <ApiErrorMessage error={error} />
        <FormActions submitLabel={request.status === 'quoted' ? 'Send new quote' : 'Send quote'} loadingLabel="Sending…" loading={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

function RejectModal({ request, onClose, onDone }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (!reason.trim()) { setError(new Error('A reason is required.')); return }
    setSaving(true)
    setError(null)
    try {
      const { rush_request: rejected } = await authService.rejectRushRequest(request.id, reason.trim())
      onDone(rejected)
    } catch (failure) {
      setError(failure)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal open onClose={() => !saving && onClose()} title={`Reject: ${request.title}`}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <Textarea id="reject-reason" label="Reason" rows={3} maxLength={1000} required value={reason} onChange={(event) => setReason(event.target.value)}
          helperText={request.type === 'extra_employee' ? 'The agency only sees “No team member is available for this request.”' : 'The agency sees this reason.'} />
        <ApiErrorMessage error={error} />
        <FormActions submitLabel="Reject request" loadingLabel="Rejecting…" loading={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

function RequestsTab({ onChanged }) {
  const { showToast } = useToast()
  const [status, setStatus] = useState('open')
  const [agencyId, setAgencyId] = useState('')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState(null)
  const [queueFor, setQueueFor] = useState(null)
  const load = useCallback(() => authService.listAdminRushRequests({ status: status || undefined, agencyId: agencyId || undefined, page }), [status, agencyId, page])
  const { data, error, isLoading, reload } = useAsync(load)
  const agenciesState = useAsync(useCallback(() => authService.listAdminAgencies({ perPage: 100 }), []))
  const requests = data?.rush_requests ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }

  function done(message) {
    setModal(null)
    showToast(message)
    reload()
    onChanged?.()
  }

  return (
    <>
      <div className="client-filters" role="search">
        <Select id="rush-status" label="Status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
          {STATUS_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </Select>
        <Select id="rush-agency" label="Agency" value={agencyId} onChange={(event) => { setAgencyId(event.target.value); setPage(1) }}>
          <option value="">All agencies</option>
          {(agenciesState.data?.agencies ?? []).map((agency) => <option key={agency.id} value={agency.id}>{agency.name}</option>)}
        </Select>
      </div>
      {error && <Alert variant="error" title="Could not load requests">{error.message}</Alert>}
      <div aria-live="polite" className="queue-rush-list">
        {isLoading && !data && <p role="status">Loading requests…</p>}
        {!isLoading && requests.length === 0 && !error && <EmptyState title="No requests">Nothing matches these filters.</EmptyState>}
        {requests.map((request) => {
          const badge = RUSH_STATUS[request.status] ?? { label: request.status, variant: 'slate' }
          const employee = request.employee ?? request.proposed_employee
          return (
            <Card key={request.id} className="queue-rush-card">
              <div className="queue-rush-head">
                <div className="admin-rush-meta">
                  <h3 className="queue-card-title">{request.title}</h3>
                  <p className="queue-muted">{request.agency.name} · {RUSH_TYPE_LABELS[request.type]} · requested by {request.requested_by.name}</p>
                  <p className="queue-muted">{request.estimated_hours} h · needed by {formatDate(request.needed_by)}{request.employee ? ` · for ${request.employee.name}` : ''}</p>
                </div>
                <Badge variant={badge.variant}>{badge.label}</Badge>
              </div>
              <p>{request.description}</p>
              {request.skills_notes && <p className="queue-muted">Skills: {request.skills_notes}</p>}
              {request.reason && <p className="queue-muted">Why urgent: {request.reason}</p>}
              {request.amount_display && <p><strong>{request.amount_display}</strong>{request.quote_expires_at ? <span className="queue-muted"> · quote valid until {formatDateTime(request.quote_expires_at)}</span> : null}{request.proposed_employee ? <span className="queue-muted"> · offering {request.proposed_employee.name}</span> : null}</p>}
              {request.rejection_reason && <p className="queue-muted">Rejected: {request.rejection_reason}</p>}
              <div className="admin-rush-actions">
                {(request.status === 'pending' || request.status === 'quoted') && <Button type="button" size="sm" onClick={() => setModal({ kind: 'quote', request })}>{request.status === 'quoted' ? 'Re-quote' : 'Quote'}</Button>}
                {(request.status === 'pending' || request.status === 'quoted') && <Button type="button" size="sm" variant="secondary" onClick={() => setModal({ kind: 'reject', request })}>Reject</Button>}
                {employee && <Button type="button" size="sm" variant="ghost" onClick={() => setQueueFor(employee)}>View {employee.name}’s queue</Button>}
              </div>
            </Card>
          )
        })}
      </div>
      {requests.length > 0 && <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="request" loading={isLoading} onPrevious={() => setPage((value) => value - 1)} onNext={() => setPage((value) => value + 1)} />}
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {modal?.kind === 'quote' && <QuoteModal request={modal.request} onClose={() => setModal(null)} onDone={() => done('Quote sent to the agency owner.')} />}
      {modal?.kind === 'reject' && <RejectModal request={modal.request} onClose={() => setModal(null)} onDone={() => done('Request rejected.')} />}
      {queueFor && <EmployeeQueueDrawer employeeId={queueFor.id} name={queueFor.name} onClose={() => setQueueFor(null)} />}
    </>
  )
}

function ChargesTab() {
  const [status, setStatus] = useState('')
  const [agencyId, setAgencyId] = useState('')
  const [page, setPage] = useState(1)
  const load = useCallback(() => authService.listAdminCharges({ status: status || undefined, agencyId: agencyId || undefined, page }), [status, agencyId, page])
  const { data, error, isLoading } = useAsync(load)
  const agenciesState = useAsync(useCallback(() => authService.listAdminAgencies({ perPage: 100 }), []))
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const columns = [
    { key: 'created_at', label: 'Date', render: (value) => formatShortDate(value?.slice(0, 10)) },
    { key: 'agency', label: 'Agency', render: (agency) => agency?.name },
    { key: 'description', label: 'Description' },
    { key: 'amount_display', label: 'Amount' },
    { key: 'status', label: 'Status', render: (value) => <Badge variant={value === 'paid' ? 'success' : value === 'void' ? 'slate' : 'info'}>{value}</Badge> },
  ]
  return (
    <>
      <div className="client-filters" role="search">
        <Select id="charge-status" label="Status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
          {['', 'unbilled', 'invoiced', 'paid', 'void'].map((value) => <option key={value} value={value}>{value || 'All statuses'}</option>)}
        </Select>
        <Select id="charge-agency" label="Agency" value={agencyId} onChange={(event) => { setAgencyId(event.target.value); setPage(1) }}>
          <option value="">All agencies</option>
          {(agenciesState.data?.agencies ?? []).map((agency) => <option key={agency.id} value={agency.id}>{agency.name}</option>)}
        </Select>
      </div>
      {(data?.totals ?? []).length > 0 && (
        <p className="queue-muted" aria-live="polite">{data.totals.map((row) => `${row.status}: ${row.amount_display} (${row.charges})`).join(' · ')}</p>
      )}
      <Table columns={columns} rows={data?.charges ?? []} loading={isLoading && !data} error={error?.message} emptyTitle="No charges" emptyText="Charges appear when an agency owner accepts a quote." />
      <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="charge" loading={isLoading} onPrevious={() => setPage((value) => value - 1)} onNext={() => setPage((value) => value + 1)} />
    </>
  )
}

/** Admin: review urgent requests, quote or reject them, and read the charge ledger. */
export function AdminRushRequestsPage() {
  const [tab, setTab] = useState('requests')
  return (
    <section className="page queue-page">
      <PageHeader eyebrow="Administration" title="Urgent requests" subtitle="Quote what agencies ask for, then watch the charge ledger. Charges are recorded only; nothing is collected." />
      <Tabs tabs={[{ id: 'requests', label: 'Requests' }, { id: 'charges', label: 'Charges' }]} activeTab={tab} onChange={setTab} label="Urgent request sections" />
      {tab === 'requests' ? <RequestsTab /> : <ChargesTab />}
    </section>
  )
}
