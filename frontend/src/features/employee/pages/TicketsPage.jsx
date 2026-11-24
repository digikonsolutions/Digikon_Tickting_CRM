import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Button, Card, EmptyState, Input, PageHeader, Select } from '../../../components/ui'
import { getTicketStatusLabel } from '../../../lib/ticketStatus'
import { TicketList } from '../components/TicketItem'
import { filterTickets, SORTS, sortTickets } from '../workspace'
import { useWorkspace } from '../useWorkspace'

const STATUSES = ['todo', 'in_progress', 'in_review', 'waiting_on_client', 'changes_requested', 'blocked', 'done', 'cancelled']
const PRIORITIES = ['urgent', 'high', 'normal', 'low']
const FLAGS = { '': 'Everything', blocked: 'Blocked', waiting: 'Waiting on the client', late: 'Overdue or carried over' }
const PAGE = 30

// /my-work/tickets: every ticket assigned to me, searchable and filterable. Filters live in the URL so a view can be
// bookmarked or shared and survives a reload.
export function TicketsPage() {
  const { workspace } = useWorkspace()
  const [params, setParams] = useSearchParams()
  const get = (key) => params.get(key) ?? ''
  const filters = { q: get('q'), status: get('status'), priority: get('priority'), project: get('project'), flag: get('flag'), closed: get('closed') === '1' }
  const sort = SORTS[get('sort')] ? get('sort') : 'due'
  const [search, setSearch] = useState(filters.q)
  const [shown, setShown] = useState(PAGE)

  const update = (changes) => {
    setShown(PAGE)
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
      return next
    }, { replace: true })
  }

  // Typing updates the URL after a short pause, not on every key.
  useEffect(() => {
    if (search === filters.q) return undefined
    const timer = window.setTimeout(() => update({ q: search.trim() }), 250)
    return () => window.clearTimeout(timer)
  }, [search, filters.q]) // eslint-disable-line react-hooks/exhaustive-deps

  const list = sortTickets(filterTickets(workspace.tickets, filters), sort)
  const closedCount = workspace.tickets.filter((ticket) => ['done', 'cancelled'].includes(ticket.status)).length
  const anyFilter = Boolean(filters.q || filters.status || filters.priority || filters.project || filters.flag)

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="Tickets" subtitle="Everything assigned to you. Done and cancelled tickets are hidden unless you ask for them." />
      <Card>
        <div className="ew-filters" role="search" aria-label="Filter tickets">
          <Input id="ew-search" label="Search" type="search" placeholder="Title, key, project or agency" value={search} onChange={(event) => setSearch(event.target.value)} />
          <Select id="ew-status" label="Status" value={filters.status} onChange={(event) => update({ status: event.target.value })}>
            <option value="">Any status</option>
            {STATUSES.map((status) => <option key={status} value={status}>{getTicketStatusLabel(status, 'employee')}</option>)}
          </Select>
          <Select id="ew-priority" label="Priority" value={filters.priority} onChange={(event) => update({ priority: event.target.value })}>
            <option value="">Any priority</option>
            {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority[0].toUpperCase() + priority.slice(1)}</option>)}
          </Select>
          <Select id="ew-project" label="Project" value={filters.project} onChange={(event) => update({ project: event.target.value })}>
            <option value="">Any project</option>
            {workspace.projects.map((project) => <option key={project.id} value={project.id}>{project.title}</option>)}
          </Select>
          <Select id="ew-flag" label="Show" value={filters.flag} onChange={(event) => update({ flag: event.target.value })}>
            {Object.entries(FLAGS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
          <Select id="ew-sort" label="Sort by" value={sort} onChange={(event) => update({ sort: event.target.value === 'due' ? '' : event.target.value })}>
            {Object.entries(SORTS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
        </div>
        <div className="ew-filter-foot">
          <label className="ew-toggle">
            <input type="checkbox" checked={filters.closed} onChange={(event) => update({ closed: event.target.checked ? '1' : '' })} />
            <span>Show closed ({closedCount})</span>
          </label>
          <p className="ew-muted" aria-live="polite">{list.length} {list.length === 1 ? 'ticket' : 'tickets'}</p>
          {anyFilter && <Button type="button" size="sm" variant="ghost" onClick={() => { setSearch(''); setParams(filters.closed ? { closed: '1' } : {}, { replace: true }) }}>Clear filters</Button>}
        </div>
      </Card>

      {list.length === 0 ? (
        <EmptyState title={workspace.tickets.length ? 'No tickets match' : 'No tickets yet'}>
          {workspace.tickets.length ? 'Try another search or clear the filters.' : 'Tickets assigned to you will appear here.'}
        </EmptyState>
      ) : (
        <>
          <TicketList tickets={list.slice(0, shown)} label="Tickets" />
          {list.length > shown && (
            <div className="ew-more"><Button type="button" variant="secondary" onClick={() => setShown((count) => count + PAGE)}>Show {Math.min(PAGE, list.length - shown)} more</Button></div>
          )}
        </>
      )}
    </section>
  )
}
