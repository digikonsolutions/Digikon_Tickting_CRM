import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { formatShortDate } from '../../../lib/format'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, EmptyState, Input, PageHeader, Pagination, PriorityBadge, Select, StatusBadge, Table, TicketKey, useToast } from '../../../components/ui'
import { TicketFormModal } from '../components/TicketFormModal'
import { PRIORITIES } from '../../briefs/briefData'

const STATUSES = [['todo', 'To do'], ['in_progress', 'In progress'], ['in_review', 'In review'], ['waiting_on_client', 'Waiting on you'], ['changes_requested', 'Changes requested'], ['blocked', 'Blocked'], ['done', 'Done'], ['cancelled', 'Cancelled']]
const BOARD = [
  { id: 'todo', label: 'To do', statuses: ['todo'] },
  { id: 'in_progress', label: 'In progress', statuses: ['in_progress', 'changes_requested', 'blocked'] },
  { id: 'in_review', label: 'In review', statuses: ['in_review', 'waiting_on_client'] },
  { id: 'done', label: 'Done', statuses: ['done'] },
]
const PER_PAGE = 25

function BoardCard({ ticket }) {
  return (
    <li className="tk-card">
      <div className="tk-card-top"><TicketKey ticketKey={ticket.key} /><PriorityBadge priority={ticket.priority} /></div>
      <Link to={`/tickets/${ticket.key}`} className="tk-card-title">{ticket.title}</Link>
      <small>{ticket.project_title}{ticket.due_date ? ` · due ${formatShortDate(ticket.due_date)}` : ''}</small>
      {['waiting_on_client', 'in_review'].includes(ticket.status) && <StatusBadge status={ticket.status} />}
    </li>
  )
}

export function AgencyTicketsPage() {
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const { showToast } = useToast()
  const [params, setParams] = useSearchParams()
  const get = (key) => params.get(key) ?? ''
  const view = get('view') === 'board' ? 'board' : 'list'
  const page = Math.max(1, Number(get('page')) || 1)
  const filters = { search: get('q'), status: get('status'), priority: get('priority'), projectId: get('project'), clientId: get('client'), waiting: get('waiting') }
  const [searchInput, setSearchInput] = useState(filters.search)
  const [form, setForm] = useState({ open: params.get('new') === '1', ticket: null })

  const update = useCallback((changes) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
      return next
    }, { replace: true })
  }, [setParams])

  useEffect(() => {
    if (searchInput === filters.search) return undefined
    const timer = window.setTimeout(() => update({ q: searchInput.trim(), page: '' }), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, filters.search, update])

  const load = useCallback(async () => {
    const query = new URLSearchParams({ page: String(view === 'board' ? 1 : page), per_page: String(view === 'board' ? 100 : PER_PAGE) })
    const map = { search: 'search', status: 'status', priority: 'priority', projectId: 'project_id', clientId: 'client_id', waiting: 'waiting' }
    Object.entries(map).forEach(([key, name]) => filters[key] && query.set(name, filters[key]))
    return authService.listAgencyTickets(query)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, page, filters.search, filters.status, filters.priority, filters.projectId, filters.clientId, filters.waiting])
  const { data, error, isLoading, reload } = useAsync(load)
  const loadOptions = useCallback(async () => {
    const [projects, clients] = await Promise.all([authService.listAgencyProjects({ perPage: 100 }), isOwner ? authService.listAgencyClients({ perPage: 100 }) : Promise.resolve({ clients: [] })])
    return { projects: projects.projects ?? [], clients: clients.clients ?? [] }
  }, [isOwner])
  const options = useAsync(loadOptions).data ?? { projects: [], clients: [] }

  const tickets = data?.tickets ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const hasFilters = Object.values(filters).some(Boolean)

  const columns = [
    { key: 'key', label: 'Key', render: (value) => <TicketKey ticketKey={value} /> },
    { key: 'title', label: 'Ticket', render: (title, ticket) => <Link to={`/tickets/${ticket.key}`}>{title}</Link> },
    { key: 'project_title', label: 'Project' },
    ...(isOwner ? [{ key: 'client_name', label: 'Client', render: (value) => value ?? '—' }] : []),
    { key: 'status', label: 'Status', render: (status) => <StatusBadge status={status} role="agency_owner" /> },
    { key: 'priority', label: 'Priority', render: (priority) => <PriorityBadge priority={priority} /> },
    { key: 'due_date', label: 'Due', render: (value) => formatShortDate(value) },
    { key: 'estimated_hours', label: 'Hours', render: (value) => `${Number(value)} h` },
    { key: 'actions', label: 'Actions', render: (_value, ticket) => (['done', 'cancelled'].includes(ticket.status) ? '—' : <Button type="button" variant="secondary" size="sm" onClick={() => setForm({ open: true, ticket })}>Edit</Button>) },
  ]

  function saved(ticket, { created, uploadNotice }) {
    setForm({ open: false, ticket: null })
    update({ new: '' })
    showToast(uploadNotice ? `Ticket saved, but the files were not uploaded: ${uploadNotice}` : created ? 'Ticket created.' : 'Ticket updated.')
    reload()
  }

  return (
    <section className="page agency-tickets-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Tickets"
        subtitle="Every ticket across your projects."
        action={<Button type="button" onClick={() => setForm({ open: true, ticket: null })}>Create ticket</Button>}
      />
      <div className="client-filters" role="search">
        <Input id="ticket-search" label="Search tickets" type="search" placeholder="Title or key" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} />
        <Select id="ticket-filter-project" label="Project" value={filters.projectId} onChange={(event) => update({ project: event.target.value, page: '' })}>
          <option value="">All projects</option>
          {options.projects.map((project) => <option key={project.id} value={project.id}>{project.title}</option>)}
        </Select>
        {isOwner && (
          <Select id="ticket-filter-client" label="Client" value={filters.clientId} onChange={(event) => update({ client: event.target.value, page: '' })}>
            <option value="">All clients</option>
            {options.clients.map((client) => <option key={client.id} value={client.id}>{client.name}</option>)}
          </Select>
        )}
        <Select id="ticket-filter-status" label="Status" value={filters.status} onChange={(event) => update({ status: event.target.value, waiting: '', page: '' })}>
          <option value="">All</option>
          {STATUSES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </Select>
        <Select id="ticket-filter-priority" label="Priority" value={filters.priority} onChange={(event) => update({ priority: event.target.value, page: '' })}>
          <option value="">All</option>
          {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority[0].toUpperCase() + priority.slice(1)}</option>)}
        </Select>
        <div className="tk-toggle" role="group" aria-label="View">
          <button type="button" aria-pressed={view === 'list'} onClick={() => update({ view: '' })}>List</button>
          <button type="button" aria-pressed={view === 'board'} onClick={() => update({ view: 'board', page: '' })}>Board</button>
        </div>
        {hasFilters && <Button type="button" variant="secondary" size="sm" onClick={() => { setSearchInput(''); setParams(view === 'board' ? { view: 'board' } : {}, { replace: true }) }}>Clear filters</Button>}
      </div>
      {filters.waiting === 'you' && <p className="client-limit">Showing tickets waiting for your review or answer.</p>}

      {error ? (
        <div role="alert"><ApiErrorMessage error={error} /><Button type="button" variant="secondary" onClick={reload}>Try again</Button></div>
      ) : view === 'board' ? (
        isLoading && !data ? <p className="client-limit">Loading…</p> : tickets.length === 0 ? <EmptyState title="No tickets">{hasFilters ? 'Try different filters.' : 'Create the first ticket on one of your projects.'}</EmptyState> : (
          <div className="tk-board">
            {BOARD.map((column) => {
              const items = tickets.filter((ticket) => column.statuses.includes(ticket.status))
              return (
                <section key={column.id} className="tk-column" aria-label={`${column.label}, ${items.length} tickets`}>
                  <h2>{column.label} <span>{items.length}</span></h2>
                  <ul>{items.map((ticket) => <BoardCard key={ticket.id} ticket={ticket} />)}</ul>
                </section>
              )
            })}
          </div>
        )
      ) : (
        <>
          <Table columns={columns} rows={tickets} loading={isLoading && !data} emptyTitle="No tickets found" emptyText={hasFilters ? 'Try different filters or clear them.' : 'Create the first ticket on one of your projects.'} />
          {tickets.length > 0 && <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="ticket" loading={isLoading} onPrevious={() => update({ page: String(page - 1) })} onNext={() => update({ page: String(page + 1) })} />}
        </>
      )}
      {form.open && <TicketFormModal ticket={form.ticket} projectId={filters.projectId} onClose={() => { setForm({ open: false, ticket: null }); update({ new: '' }) }} onSaved={saved} />}
    </section>
  )
}
