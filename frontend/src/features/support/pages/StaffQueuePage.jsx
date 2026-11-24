import { useCallback } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, EmptyState, Icon, Input, SlaIndicator, LifecyclePriorityBadge, LifecycleStatusBadge, LIFECYCLE_STATUSES, PageHeader, Pagination, PRIORITY_OPTIONS, RelativeTime, SavedViewTabs, Select, Skeleton, TypeBadge } from '../../../components/ui'
import '../support.css'

const VIEWS = [
  { id: 'my', label: 'My tickets' }, { id: 'new', label: 'New' }, { id: 'unassigned', label: 'Unassigned' }, { id: 'critical', label: 'Critical' },
  { id: 'waiting_agency', label: 'Waiting for agency' }, { id: 'waiting_third_party', label: 'Waiting for third party' }, { id: 'qa', label: 'In QA' },
  { id: 'resolved', label: 'Resolved' }, { id: 'reopened', label: 'Recently reopened' }, { id: 'all', label: 'All' },
]
const KPIS = [['all', 'Open and closed'], ['unassigned', 'Unassigned'], ['critical', 'Critical'], ['waiting_agency', 'Waiting for agency'], ['waiting_third_party', 'Waiting for third party'], ['qa', 'In QA'], ['reopened', 'Reopened']]

// The clock that matters next: the response clock until a first reply is sent, then the resolution clock.
const nextClock = (sla) => (sla.response && sla.response.state !== 'met' ? { clock: sla.response, kind: 'response' } : { clock: sla.resolution, kind: 'resolution' })

// The staff queue: KPI cards that apply a view, saved views with live counts, filters kept in the address, sortable table
// that turns into cards on a phone.
export function StaffQueuePage() {
  const [params, setParams] = useSearchParams()
  const query = Object.fromEntries(params.entries())
  const view = query.view ?? 'all'
  const page = Number(query.page ?? 1)
  const load = useCallback(() => authService.listStaffTickets({ ...query, view, page }), [query.q, query.status, query.priority, query.type, query.sort, view, page]) // eslint-disable-line react-hooks/exhaustive-deps
  const { data, error, isLoading, reload } = useAsync(load)
  const set = (patch) => setParams((current) => {
    const next = new URLSearchParams(current)
    Object.entries({ page: undefined, ...patch }).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
    return next
  }, { replace: true })
  const tickets = data?.tickets ?? []
  const counts = data?.counts ?? {}

  return (
    <section className="page">
      <PageHeader eyebrow="Staff" title="Support queue" subtitle="Everything your agencies have asked for, in one place." />
      <div className="sup-kpis" role="group" aria-label="Quick filters">
        {KPIS.map(([id, label]) => (
          <button key={id} type="button" className="sup-kpi" aria-pressed={view === id} onClick={() => set({ view: id === 'all' ? undefined : id })}>
            <strong>{counts[id] ?? 0}</strong><span>{label}</span>
          </button>
        ))}
      </div>
      <SavedViewTabs views={VIEWS} active={view} onChange={(next) => set({ view: next === 'all' ? undefined : next })} counts={counts} label="Saved views" />
      <div className="sup-filters" role="search">
        <Input label="Search" type="search" placeholder="ID, title or text (notes too)" defaultValue={query.q ?? ''} onChange={(event) => set({ q: event.target.value || undefined })} />
        <Select label="Status" value={query.status ?? ''} onChange={(event) => set({ status: event.target.value })}><option value="">Any status</option>{LIFECYCLE_STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select>
        <Select label="Priority" value={query.priority ?? ''} onChange={(event) => set({ priority: event.target.value })}><option value="">Any priority</option>{PRIORITY_OPTIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select>
        <Select label="Sort" value={query.sort ?? 'updated'} onChange={(event) => set({ sort: event.target.value === 'updated' ? undefined : event.target.value })}><option value="updated">Recently updated</option><option value="sla">Deadline first</option><option value="priority">Priority first</option><option value="created">Newest</option></Select>
      </div>
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {isLoading && !data
        ? <div role="status" aria-label="Loading the queue"><Skeleton variant="row" /><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : tickets.length === 0
          ? <EmptyState title="Nothing here">No tickets match this view. Try another view or clear the filters.</EmptyState>
          : (
            <table className="sup-queue">
              <caption className="visually-hidden">Tickets</caption>
              <thead><tr><th scope="col">ID</th><th scope="col">Title</th><th scope="col">Agency</th><th scope="col">Type</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">SLA</th><th scope="col">Owner</th><th scope="col">Updated</th></tr></thead>
              <tbody>
                {tickets.map((ticket) => (
                  <tr key={ticket.id} className={ticket.priority === 'urgent' ? 'is-critical' : undefined}>
                    <td><Link className="sup-ref" to={`/staff/tickets/${ticket.ref}`}>{ticket.ref}</Link></td>
                    <td><Link className="sup-row-title" to={`/staff/tickets/${ticket.ref}`}>{ticket.title}</Link>{ticket.secret_flagged && <span title="Possible password or key in the text"> <Icon name="shield" size={14} /></span>}</td>
                    <td>{ticket.agency?.name}{ticket.end_client && <><br /><span className="ui-field-help">{ticket.end_client.name}</span></>}</td>
                    <td><TypeBadge label={ticket.type_label ?? 'Project work'} /></td>
                    <td><LifecyclePriorityBadge priority={ticket.priority} label={ticket.priority_label} /></td>
                    <td><LifecycleStatusBadge status={ticket.status} label={ticket.status_label} /></td>
                    <td>{ticket.sla ? <SlaIndicator clock={nextClock(ticket.sla).clock} kind={nextClock(ticket.sla).kind} /> : null}</td>
                    <td>{ticket.assigned_to?.name ?? <span className="ui-field-help">Unassigned</span>}</td>
                    <td><RelativeTime date={ticket.updated_at} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
      {data?.pagination && data.pagination.last_page > 1 && (
        <Pagination page={data.pagination.current_page} lastPage={data.pagination.last_page} total={data.pagination.total} noun="ticket" onPrevious={() => set({ page: String(page - 1) })} onNext={() => set({ page: String(page + 1) })} />
      )}
    </section>
  )
}
