import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Input, PageHeader, PriorityBadge, RelativeTime, Select, StatusBadge, Table, TicketKey } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'
import { WaitingChip } from '../../tickets'

const PER_PAGE = 25
const STATUS_OPTIONS = [
  ['', 'All statuses'],
  ['todo', 'To do'],
  ['in_progress', 'In progress'],
  ['in_review', 'In review'],
  ['blocked', 'Blocked'],
  ['waiting_on_client', 'Waiting on client'],
  ['changes_requested', 'Changes requested'],
  ['done', 'Done'],
  ['cancelled', 'Cancelled'],
]
const WAITING_OPTIONS = [
  ['', 'Anyone'],
  ['client', 'Client'],
  ['employee', 'Employee'],
  ['team', 'Team'],
]

// Agency and employee options for the filter bar (one request each, up to 100 entries).
async function loadFilterOptions() {
  const [owners, employees] = await Promise.all([
    authService.listAdminUsers({ role: 'agency_owner', perPage: 100 }),
    authService.listAdminUsers({ role: 'employee', perPage: 100 }),
  ])
  const agencies = new Map()
  owners.users.forEach((owner) => {
    if (owner.agency_id) agencies.set(owner.agency_id, owner.agency_name ?? `Agency ${owner.agency_id}`)
  })
  return { agencies: [...agencies.entries()], employees: employees.users }
}

export function AdminTicketsPage() {
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState({ status: '', waitingOn: '', agencyId: '', employeeId: '', search: '' })
  const [searchInput, setSearchInput] = useState('')
  const loadTickets = useCallback(
    () => authService.listAdminTickets({ page, perPage: PER_PAGE, ...filters }),
    [page, filters],
  )
  const { data, error, isLoading, reload } = useAsync(loadTickets)
  const options = useAsync(loadFilterOptions)
  const tickets = data?.tickets ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const hasFilters = Object.values(filters).some(Boolean)

  function setFilter(name, value) {
    setFilters((current) => ({ ...current, [name]: value }))
    setPage(1)
  }

  function submitSearch(event) {
    event.preventDefault()
    setFilter('search', searchInput.trim())
  }

  function clearFilters() {
    setFilters({ status: '', waitingOn: '', agencyId: '', employeeId: '', search: '' })
    setSearchInput('')
    setPage(1)
  }

  const columns = [
    { key: 'key', label: 'Key', render: (key) => <Link to={`/tickets/${key}`}><TicketKey ticketKey={key} /></Link> },
    { key: 'title', label: 'Ticket', render: (title, ticket) => <Link className="admin-project-detail-link" to={`/tickets/${ticket.key}`}>{title}</Link> },
    { key: 'project_title', label: 'Project' },
    { key: 'agency_name', label: 'Agency' },
    { key: 'creator', label: 'Created by', render: (creator) => creator?.name ?? 'Account removed' },
    { key: 'estimated_hours', label: 'Hours', render: formatHours },
    { key: 'priority', label: 'Priority', render: (priority) => <PriorityBadge priority={priority} /> },
    { key: 'status', label: 'Status', render: (status) => <StatusBadge status={status} role="admin" /> },
    {
      key: 'waiting_on',
      label: 'Waiting on',
      render: (_value, ticket) => (
        <span>
          <WaitingChip ticket={ticket} role="admin" />
          {!['done', 'cancelled'].includes(ticket.status) && ticket.waiting_since && <> <RelativeTime date={ticket.waiting_since} /></>}
        </span>
      ),
    },
    { key: 'due_date', label: 'Due date', render: formatDate },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="All tickets" subtitle="Read-only view of project tickets across agencies." />
      <Card title="Ticket directory" description={`${pagination.total} ticket${pagination.total === 1 ? '' : 's'}${hasFilters ? ' match these filters' : ' total'}.`}>
        <form className="admin-ticket-filters" onSubmit={submitSearch} aria-label="Filter tickets">
          <Input id="ticket-search" label="Search" type="search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Key or title" />
          <Select id="ticket-status" label="Status" value={filters.status} onChange={(event) => setFilter('status', event.target.value)}>
            {STATUS_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
          <Select id="ticket-waiting" label="Waiting on" value={filters.waitingOn} onChange={(event) => setFilter('waitingOn', event.target.value)}>
            {WAITING_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
          <Select id="ticket-agency" label="Agency" value={filters.agencyId} onChange={(event) => setFilter('agencyId', event.target.value)}>
            <option value="">All agencies</option>
            {(options.data?.agencies ?? []).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
          </Select>
          <Select id="ticket-employee" label="Employee" value={filters.employeeId} onChange={(event) => setFilter('employeeId', event.target.value)}>
            <option value="">All employees</option>
            {(options.data?.employees ?? []).map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
          </Select>
          <div className="admin-ticket-filter-buttons">
            <Button type="submit" variant="secondary" size="sm" disabled={isLoading}>Search</Button>
            {hasFilters && <Button type="button" variant="ghost" size="sm" onClick={clearFilters}>Clear filters</Button>}
          </div>
        </form>
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table columns={columns} rows={tickets} loading={isLoading} emptyTitle="No tickets found" emptyText={hasFilters ? 'Try different filters.' : 'Tickets will appear here when agencies break down project work.'} />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page}</span>
          <div>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page >= pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button>
          </div>
        </div>
      </Card>
    </section>
  )
}
