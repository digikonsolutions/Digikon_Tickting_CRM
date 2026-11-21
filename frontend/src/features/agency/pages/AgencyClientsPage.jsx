import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { formatShortDate, pluralize } from '../../../lib/format'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Drawer, EmptyState, Input, Modal, PageHeader, Pagination, Select, Skeleton, StatusBadge, Table, useToast } from '../../../components/ui'
import { ClientFormModal } from '../components/ClientFormModal'

const PER_PAGE = 25
const METHOD_LABELS = { email: 'Email', phone: 'Phone', whatsapp: 'WhatsApp' }

function Detail({ label, children }) {
  if (children === null || children === undefined || children === '') return null
  return <div><dt>{label}</dt><dd>{children}</dd></div>
}

function ClientDrawer({ clientId, onClose, onEdit, onChanged }) {
  const { showToast } = useToast()
  const load = useCallback(() => (clientId ? authService.getAgencyClient(clientId) : Promise.resolve(null)), [clientId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)
  const client = data?.client

  async function run(action, success) {
    setBusy(true)
    setActionError(null)
    try {
      await action()
      showToast(success)
      setConfirm(null)
      onChanged()
      return true
    } catch (reason) {
      setActionError(reason)
      return false
    } finally {
      setBusy(false)
    }
  }

  const impact = client?.impact
  const consequence = impact && impact.open_projects > 0
    ? `This cancels ${impact.open_projects} open ${pluralize(impact.open_projects, 'project')} and releases ${impact.booked_hours} h of booked work.`
    : 'No open projects are affected.'
  const heldText = impact?.held_employees > 0 ? ` ${impact.held_employees} ${pluralize(impact.held_employees, 'employee')} held by this client will be released.` : ''

  async function confirmAction() {
    if (confirm === 'delete') {
      if (await run(() => authService.deleteAgencyClient(client.id), `${client.name} was permanently deleted.`)) onClose()
    } else {
      await run(() => authService.updateAgencyClient(client.id, { is_active: false }), `${client.name} was deactivated.`)
      reload()
    }
  }

  return (
    <>
      <Drawer
        open={Boolean(clientId) && !confirm}
        onClose={onClose}
        title={client?.name ?? 'Client'}
        description={client?.company_name ?? client?.email}
        width="lg"
        footer={client && (
          <>
            <Button type="button" variant="secondary" onClick={() => onEdit(client)}>Edit</Button>
            {client.is_active && (
              <Button type="button" variant="secondary" loading={busy && !confirm} loadingText="Sending…" onClick={() => run(() => authService.inviteAgencyClient(client.id), `Invitation sent to ${client.email}.`).then(reload)}>
                {client.invited_at ? 'Resend invitation' : 'Send invitation'}
              </Button>
            )}
            {client.is_active
              ? <Button type="button" variant="secondary" onClick={() => { setActionError(null); setConfirm('deactivate') }}>Deactivate</Button>
              : <Button type="button" variant="secondary" loading={busy && !confirm} loadingText="Reactivating…" onClick={() => run(() => authService.updateAgencyClient(client.id, { is_active: true }), `${client.name} was reactivated.`).then(reload)}>Reactivate</Button>}
            <Button type="button" variant="danger" onClick={() => { setActionError(null); setConfirm('delete') }}>Delete</Button>
          </>
        )}
      >
        {isLoading && !client && <Skeleton />}
        {error && <><ApiErrorMessage error={error} /><Button type="button" variant="secondary" size="sm" onClick={reload}>Try again</Button></>}
        {!confirm && actionError && <ApiErrorMessage error={actionError} />}
        {client && (
          <div className="client-detail">
            <p><Badge variant={client.is_active ? 'success' : 'slate'}>{client.is_active ? 'Active' : 'Inactive'}</Badge> {client.invited_at ? `Invited ${formatShortDate(client.invited_at)}` : 'Not invited yet'}</p>
            <dl className="client-detail-grid">
              <Detail label="Email"><a href={`mailto:${client.email}`}>{client.email}</a></Detail>
              <Detail label="Phone">{client.phone}</Detail>
              <Detail label="Preferred contact">{METHOD_LABELS[client.preferred_contact_method]}</Detail>
              <Detail label="Company">{client.company_name}</Detail>
              <Detail label="Job title">{client.job_title}</Detail>
              <Detail label="Website">{client.website && <a href={client.website} target="_blank" rel="noreferrer noopener">{client.website}</a>}</Detail>
              <Detail label="Time zone">{client.timezone}</Detail>
              <Detail label="Country">{client.country}</Detail>
              <Detail label="Address">{client.address}</Detail>
            </dl>
            {client.notes && <><h3>Internal notes</h3><p className="client-notes">{client.notes}</p></>}
            <h3>Projects</h3>
            {client.projects.length === 0
              ? <p className="ui-muted">No projects yet.</p>
              : (
                <ul className="client-project-list">
                  {client.projects.map((project) => (
                    <li key={project.id}>
                      <Link to={`/agency/projects/${project.id}`}>{project.title}</Link>
                      <StatusBadge status={project.status} />
                      <span>{project.employee_name ?? 'No employee'}{project.due_date ? ` · due ${formatShortDate(project.due_date)}` : ''}</span>
                    </li>
                  ))}
                </ul>
              )}
            {Object.keys(client.ticket_counts ?? {}).length > 0 && (
              <>
                <h3>Tickets</h3>
                <p>{Object.entries(client.ticket_counts).map(([status, count]) => `${count} ${status.replace(/_/g, ' ')}`).join(' · ')}</p>
              </>
            )}
          </div>
        )}
      </Drawer>
      <Modal
        open={Boolean(confirm)}
        onClose={() => !busy && setConfirm(null)}
        title={confirm === 'delete' ? 'Permanently delete this client?' : 'Deactivate this client?'}
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={busy} onClick={() => setConfirm(null)}>Cancel</Button>
            <Button type="button" variant="danger" loading={busy} loadingText="Working…" onClick={confirmAction}>{confirm === 'delete' ? 'Delete permanently' : 'Deactivate'}</Button>
          </>
        )}
      >
        <ApiErrorMessage error={actionError} />
        <p>{consequence}{heldText}</p>
        <p>{confirm === 'delete' ? `${client?.name} and their sign-in are removed for good. This cannot be undone.` : `${client?.name} can no longer sign in. You can reactivate them later.`}</p>
      </Modal>
    </>
  )
}

export function AgencyClientsPage() {
  const { showToast } = useToast()
  const [params, setParams] = useSearchParams()
  const search = params.get('q') ?? ''
  const status = params.get('status') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const selectedId = params.get('client')
  const [searchInput, setSearchInput] = useState(search)
  const [form, setForm] = useState({ open: false, client: null })

  const update = useCallback((changes) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
      return next
    }, { replace: true })
  }, [setParams])

  // Typing waits a moment before it becomes a filter (and a URL), and always goes back to page 1.
  useEffect(() => {
    if (searchInput === search) return undefined
    const timer = window.setTimeout(() => update({ q: searchInput.trim(), page: '' }), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, search, update])

  const load = useCallback(() => authService.listAgencyClients({ search, status, page, perPage: PER_PAGE }), [search, status, page])
  const { data, error, isLoading, reload } = useAsync(load)
  const clients = data?.clients ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const limit = data?.limit
  const hasFilters = Boolean(search || status)
  const full = Boolean(limit?.reached)

  function saved(client, { created, invited, emailChanged }) {
    setForm({ open: false, client: null })
    if (created) showToast(invited ? `${client.name} was added and invited.` : `${client.name} was added. Send the invitation when you are ready.`)
    else showToast(emailChanged ? `${client.name} was updated and a password setup email was sent.` : `${client.name} was updated.`)
    reload()
  }

  const columns = [
    { key: 'name', label: 'Client', render: (name, client) => <button type="button" className="ui-link-button" onClick={() => update({ client: String(client.id) })}>{name}</button> },
    { key: 'company_name', label: 'Company', render: (value) => value ?? '—' },
    { key: 'email', label: 'Email' },
    { key: 'phone', label: 'Phone', render: (value) => value ?? '—' },
    { key: 'status', label: 'Status', render: (value) => <Badge variant={value === 'active' ? 'success' : 'slate'}>{value === 'active' ? 'Active' : 'Inactive'}</Badge> },
    { key: 'projects_count', label: 'Projects' },
    { key: 'last_activity_at', label: 'Last activity', render: (value) => formatShortDate(value) },
    { key: 'actions', label: 'Actions', render: (_value, client) => (
      <div className="agency-user-actions">
        <Button type="button" variant="secondary" size="sm" onClick={() => update({ client: String(client.id) })}>View</Button>
        <Button type="button" variant="secondary" size="sm" onClick={() => setForm({ open: true, client })}>Edit</Button>
      </div>
    ) },
  ]

  return (
    <section className="page agency-clients-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Clients"
        subtitle="The people you create projects for. Employees are assigned by your account manager."
        action={(
          <Button type="button" onClick={() => setForm({ open: true, client: null })} disabled={full} title={full ? 'Client account limit reached' : undefined}>Create client</Button>
        )}
      />
      {limit && (
        <p className="client-limit" aria-live="polite">
          {limit.allowed === null ? `${limit.used} active ${pluralize(limit.used, 'client')}` : `${limit.used} of ${limit.allowed} client accounts used`}
        </p>
      )}
      {full && <Alert variant="warning">Your plan includes {limit.allowed} client {pluralize(limit.allowed, 'account')} and all are in use. Deactivate a client or ask your account manager for an upgrade.</Alert>}

      <div className="client-filters" role="search">
        <Input id="client-search" label="Search clients" type="search" placeholder="Name, email or company" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} />
        <Select id="client-status" label="Status" value={status} onChange={(event) => update({ status: event.target.value, page: '' })}>
          <option value="">All</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </Select>
        {hasFilters && <Button type="button" variant="secondary" size="sm" onClick={() => { setSearchInput(''); setParams({}, { replace: true }) }}>Clear filters</Button>}
      </div>

      {error
        ? <div role="alert"><ApiErrorMessage error={error} /><Button type="button" variant="secondary" onClick={reload}>Try again</Button></div>
        : !isLoading && clients.length === 0 && !hasFilters
          ? <EmptyState title="No clients yet" action={<Button type="button" onClick={() => setForm({ open: true, client: null })}>Create client</Button>}>Add the first person you will create projects for.</EmptyState>
          : <Table columns={columns} rows={clients} loading={isLoading && !data} emptyTitle="No clients match" emptyText="Try a different search or clear the filters." />}
      {!error && clients.length > 0 && (
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="client" loading={isLoading} onPrevious={() => update({ page: String(page - 1) })} onNext={() => update({ page: String(page + 1) })} />
      )}

      <ClientDrawer
        clientId={selectedId}
        onClose={() => update({ client: '' })}
        // The drawer sits above dialogs, so it closes before the edit dialog opens (otherwise it covers Save).
        onEdit={(client) => { update({ client: '' }); setForm({ open: true, client }) }}
        onChanged={reload}
      />
      {form.open && <ClientFormModal client={form.client} onClose={() => setForm({ open: false, client: null })} onSaved={saved} />}
    </section>
  )
}
