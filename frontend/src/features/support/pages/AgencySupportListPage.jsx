import { useCallback } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, EmptyState, Input, LifecyclePriorityBadge, LifecycleStatusBadge, PageHeader, Pagination, PRIORITY_OPTIONS, RelativeTime, SavedViewTabs, Select, Skeleton, TypeBadge, LIFECYCLE_STATUSES } from '../../../components/ui'
import '../support.css'

const TABS = [{ id: 'open', label: 'Open' }, { id: 'waiting', label: 'Waiting on me' }, { id: 'resolved', label: 'Resolved' }, { id: 'all', label: 'All' }]

// The agency's tickets: tabs with counts, search and filters kept in the address, cards on a phone.
export function AgencySupportListPage() {
  const [params, setParams] = useSearchParams()
  const query = Object.fromEntries(params.entries())
  const tab = query.tab ?? 'open'
  const page = Number(query.page ?? 1)
  const load = useCallback(() => authService.listSupportTickets({ ...query, tab, page }), [query.q, query.status, query.priority, query.type, tab, page]) // eslint-disable-line react-hooks/exhaustive-deps
  const { data, error, isLoading, reload } = useAsync(load)
  const set = (patch) => setParams((current) => {
    const next = new URLSearchParams(current)
    Object.entries({ page: undefined, ...patch }).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
    return next
  }, { replace: true })
  const tickets = data?.tickets ?? []
  const filtered = Boolean(query.q || query.status || query.priority || query.type)

  return (
    <section className="page">
      <PageHeader eyebrow="Support" title="Your tickets" subtitle="Ask for help, report a problem or request work. We show you the status and what happens next." action={<span className="sup-detail-head"><Link className="ui-button ui-button-secondary" to="/agency/support/recurring">Recurring</Link><Link className="ui-button ui-button-primary" to="/agency/support/new">New ticket</Link></span>} />
      <SavedViewTabs views={TABS} active={tab} onChange={(next) => set({ tab: next === 'open' ? undefined : next })} counts={data?.counts ?? {}} label="Ticket groups" />
      <div className="sup-filters" role="search">
        <Input label="Search" type="search" placeholder="ID, title or text" defaultValue={query.q ?? ''} onChange={(event) => set({ q: event.target.value || undefined })} />
        <Select label="Status" value={query.status ?? ''} onChange={(event) => set({ status: event.target.value })}>
          <option value="">Any status</option>{LIFECYCLE_STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
        </Select>
        <Select label="Priority" value={query.priority ?? ''} onChange={(event) => set({ priority: event.target.value })}>
          <option value="">Any priority</option>{PRIORITY_OPTIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
        </Select>
        {filtered && <Button type="button" variant="ghost" onClick={() => setParams({ tab }, { replace: true })}>Clear filters</Button>}
      </div>
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {isLoading && !data
        ? <div role="status" aria-label="Loading tickets"><Skeleton variant="row" /><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : tickets.length === 0
          ? (
            <EmptyState title={filtered ? 'No tickets match' : 'No tickets yet'} action={filtered ? null : <Link className="ui-button ui-button-primary" to="/agency/support/new">Create your first ticket</Link>}>
              {filtered ? 'Try a different search or clear the filters.' : 'Need help? Create your first ticket and we will take it from there.'}
            </EmptyState>
          )
          : (
            <ul className="sup-list" aria-label="Tickets">
              {tickets.map((ticket) => (
                <li className="sup-row" key={ticket.id}>
                  <span className="sup-ref">{ticket.ref}</span>
                  <div>
                    <Link className="sup-row-title" to={ticket.is_legacy ? `/tickets/${ticket.key}` : `/agency/support/${ticket.ref}`}>{ticket.title}</Link>
                    <div className="sup-row-meta"><TypeBadge label={ticket.type_label} />{ticket.end_client && <span>{ticket.end_client.name}</span>}<span>Updated <RelativeTime date={ticket.updated_at} /></span></div>
                  </div>
                  <div className="sup-row-meta"><LifecycleStatusBadge status={ticket.status} label={ticket.status_label} /><LifecyclePriorityBadge priority={ticket.priority} label={ticket.priority_label} /></div>
                </li>
              ))}
            </ul>
          )}
      {data?.pagination && data.pagination.last_page > 1 && (
        <Pagination page={data.pagination.current_page} lastPage={data.pagination.last_page} total={data.pagination.total} noun="ticket" onPrevious={() => set({ page: String(page - 1) })} onNext={() => set({ page: String(page + 1) })} />
      )}
    </section>
  )
}
