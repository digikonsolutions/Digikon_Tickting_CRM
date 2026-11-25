import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, EmptyState, Icon, Input, SlaIndicator, LifecyclePriorityBadge, LifecycleStatusBadge, LIFECYCLE_STATUSES, PageHeader, Pagination, PRIORITY_OPTIONS, RelativeTime, SavedViewTabs, Select, Skeleton, TypeBadge } from '../../../components/ui'
import { StaffBoard } from '../components/StaffBoard'
import { TransitionModal } from '../components/TransitionModal'
import '../support.css'

const VIEWS = [
  { id: 'my', label: 'My tickets' }, { id: 'new', label: 'New' }, { id: 'unassigned', label: 'Unassigned' }, { id: 'critical', label: 'Critical' },
  { id: 'waiting_agency', label: 'Waiting for agency' }, { id: 'waiting_third_party', label: 'Waiting for third party' }, { id: 'qa', label: 'In QA' },
  { id: 'resolved', label: 'Resolved' }, { id: 'reopened', label: 'Recently reopened' }, { id: 'all', label: 'All' },
]
const KPIS = [['all', 'All tickets'], ['unassigned', 'Unassigned'], ['critical', 'Critical'], ['waiting_agency', 'Waiting for agency'], ['waiting_third_party', 'Waiting for third party'], ['qa', 'In QA'], ['reopened', 'Reopened']]

// The clock that matters next: the response clock until a first reply is sent, then the resolution clock.
const nextClock = (sla) => (sla.response && sla.response.state !== 'met' ? { clock: sla.response, kind: 'response' } : { clock: sla.resolution, kind: 'resolution' })

// The staff queue: KPI cards that apply a view, saved views with live counts, filters kept in the address, sortable table
// that turns into cards on a phone.
export function StaffQueuePage() {
  const [params, setParams] = useSearchParams()
  const query = Object.fromEntries(params.entries())
  const view = query.view ?? 'all'
  const page = Number(query.page ?? 1)
  const board = query.layout === 'board'
  const load = useCallback(() => authService.listStaffTickets({ ...query, view, page, ...(board ? { per_page: 100 } : {}) }), [query.q, query.status, query.priority, query.type, query.sort, query.assigned_to_id, view, page, board]) // eslint-disable-line react-hooks/exhaustive-deps
  const [selected, setSelected] = useState([])
  const [bulk, setBulk] = useState({ action: '', value: '' })
  const [bulkResult, setBulkResult] = useState(null)
  const [views, setViews] = useState([])
  const [viewName, setViewName] = useState('')
  const [people, setPeople] = useState([])
  useEffect(() => {
    authService.listSavedViews().then((result) => setViews(result.views)).catch(() => {})
    authService.listStaffPeople().then((result) => setPeople(result.people)).catch(() => {})
  }, [])
  const toggle = (ref) => setSelected((current) => (current.includes(ref) ? current.filter((item) => item !== ref) : [...current, ref]))
  async function runBulk() {
    const body = { refs: selected, action: bulk.action }
    if (bulk.action === 'assign') body.assigned_to_id = Number(bulk.value)
    if (bulk.action === 'priority') body.priority = bulk.value
    if (bulk.action === 'transition') body.to = bulk.value
    try {
      const result = await authService.bulkStaffTickets(body)
      setBulkResult(result)
      setSelected([])
      reload()
    } catch (reason) {
      setBulkResult({ results: [], done: 0, error: reason.message })
    }
  }
  async function saveView() {
    const filters = Object.fromEntries(['view', 'q', 'status', 'priority', 'sort', 'layout'].map((key) => [key, query[key] ?? '']))
    const result = await authService.saveSavedView({ name: viewName.trim(), filters })
    setViews(result.views)
    setViewName('')
  }
  const [moving, setMoving] = useState(null)
  const [moveError, setMoveError] = useState(null)
  // A move (drag or menu) opens the same dialog as the ticket page, with exactly the fields that step needs.
  async function startMove(ticket, to) {
    setMoveError(null)
    try {
      const [detail, people] = await Promise.all([authService.getStaffTicket(ticket.ref), authService.listStaffPeople().catch(() => ({ people: [] }))])
      const transition = (detail.ticket.allowed_transitions ?? []).find((item) => item.to === to)
      if (!transition) { setMoveError('That move is not available to you.'); return }
      setMoving({ ticket: detail.ticket, transition, meta: people })
    } catch (reason) {
      setMoveError(reason.message ?? 'Could not start that move.')
    }
  }
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
        <div className="sup-layout" role="group" aria-label="Layout">
          <Button type="button" size="sm" variant={board ? 'secondary' : 'primary'} aria-pressed={!board} onClick={() => set({ layout: undefined })}>Table</Button>
          <Button type="button" size="sm" variant={board ? 'primary' : 'secondary'} aria-pressed={board} onClick={() => set({ layout: 'board' })}>Board</Button>
        </div>
        <Select label="Sort" value={query.sort ?? 'updated'} onChange={(event) => set({ sort: event.target.value === 'updated' ? undefined : event.target.value })}><option value="updated">Recently updated</option><option value="sla">Deadline first</option><option value="priority">Priority first</option><option value="created">Newest</option></Select>
      </div>
      <ApiErrorMessage error={error} />
      <div className="sup-views" role="group" aria-label="Saved filters">
        {views.length > 0 && <Select label="My views" value="" onChange={(event) => { const found = views.find((item) => String(item.id) === event.target.value); if (found) setParams(new URLSearchParams(Object.entries(found.filters)), { replace: true }) }}><option value="">Apply a saved view…</option>{views.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select>}
        <Input label="Save these filters as" maxLength={60} value={viewName} onChange={(event) => setViewName(event.target.value)} />
        <Button type="button" variant="secondary" size="sm" disabled={!viewName.trim()} onClick={saveView}>Save view</Button>
        {views.length > 0 && <Button type="button" variant="ghost" size="sm" onClick={async () => setViews((await authService.deleteSavedView(views[views.length - 1].id)).views)}>Delete the last saved view</Button>}
      </div>
      {selected.length > 0 && (
        <div className="sup-bulk" role="region" aria-label="Bulk actions">
          <strong>{selected.length} selected</strong>
          <Select label="Action" value={bulk.action} onChange={(event) => setBulk({ action: event.target.value, value: "" })}><option value="">Choose…</option><option value="assign">Assign to</option><option value="priority">Change priority</option><option value="transition">Change status</option></Select>
          {bulk.action === 'assign' && <Select label="Person" value={bulk.value} onChange={(event) => setBulk({ ...bulk, value: event.target.value })}><option value="">Choose…</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</Select>}
          {bulk.action === 'priority' && <Select label="Priority" value={bulk.value} onChange={(event) => setBulk({ ...bulk, value: event.target.value })}><option value="">Choose…</option>{PRIORITY_OPTIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select>}
          {bulk.action === 'transition' && <Select label="New status" value={bulk.value} onChange={(event) => setBulk({ ...bulk, value: event.target.value })}><option value="">Choose…</option>{['triage', 'assigned', 'in_progress', 'qa'].map((value) => <option key={value} value={value}>{LIFECYCLE_STATUSES.find((item) => item.value === value)?.label}</option>)}</Select>}
          <Button type="button" disabled={!bulk.action || !bulk.value} onClick={runBulk}>Apply to {selected.length}</Button>
          <Button type="button" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
        </div>
      )}
      {bulkResult && <p className="ui-field-help" role="status">{bulkResult.error ?? `Done for ${bulkResult.done} of ${bulkResult.results.length}.`}{bulkResult.results.filter((item) => !item.ok).map((item) => `${item.ref}: ${item.error}.`).join(' ')}</p>}
      {moveError && <p className="ui-field-error" role="alert">{moveError}</p>}
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {isLoading && !data
        ? <div role="status" aria-label="Loading the queue"><Skeleton variant="row" /><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : tickets.length === 0
          ? <EmptyState title="Nothing here">No tickets match this view. Try another view or clear the filters.</EmptyState>
          : board ? <StaffBoard tickets={tickets} onMove={startMove} />
          : (
            <div className="sup-table-scroll"><table className="sup-queue">
              <caption className="visually-hidden">Tickets</caption>
              <thead><tr><th scope="col"><span className="visually-hidden">Select</span></th><th scope="col">ID</th><th scope="col">Title</th><th scope="col">Agency</th><th scope="col">Type</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">SLA</th><th scope="col">Owner</th><th scope="col">Updated</th></tr></thead>
              <tbody>
                {tickets.map((ticket) => (
                  <tr key={ticket.id} className={ticket.priority === 'urgent' ? 'is-critical' : undefined}>
                    <td><input type="checkbox" aria-label={`Select ${ticket.ref}`} checked={selected.includes(ticket.ref)} disabled={ticket.is_legacy} onChange={() => toggle(ticket.ref)} /></td>
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
            </table></div>
          )}
      {data?.pagination && data.pagination.last_page > 1 && (
        <Pagination page={data.pagination.current_page} lastPage={data.pagination.last_page} total={data.pagination.total} noun="ticket" onPrevious={() => set({ page: String(page - 1) })} onNext={() => set({ page: String(page + 1) })} />
      )}
      {moving && <TransitionModal ticket={moving.ticket} transition={moving.transition} meta={moving.meta} onClose={() => setMoving(null)} onDone={() => { setMoving(null); reload() }} />}
    </section>
  )
}
