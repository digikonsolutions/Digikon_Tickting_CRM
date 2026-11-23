import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { formatShortDate } from '../../../lib/format'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, EmptyState, Input, PageHeader, Pagination, Select, Table } from '../../../components/ui'
import { TimelineChip } from '../TimelineChip'
import '../timeline.css'

const hrs = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`
const requesterLabel = (requester) => requester?.role === 'agency_client' ? 'Client' : 'Agency owner'

// The admin's review queue: oldest first, with how long each has waited and what is overdue or urgent.
export function AdminTimelineReviewsPage() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [searchInput, setSearchInput] = useState(search)
  const update = useCallback((changes) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
      return next
    }, { replace: true })
  }, [setParams])
  useEffect(() => {
    if (searchInput === search) return undefined
    const timer = window.setTimeout(() => update({ q: searchInput.trim(), page: '' }), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, search, update])

  const load = useCallback(() => authService.listTimelineReviews({ status, search, page }), [status, search, page])
  const { data, error, isLoading, reload } = useAsync(load)
  const rows = data?.reviews ?? []
  const summary = data?.summary
  const hasFilters = Boolean(status || search)

  const columns = [
    { key: 'title', label: 'Project', render: (title, row) => <><Link to={`/admin/timeline-reviews/${row.id}`}>{title}</Link>{row.priority_urgent && <> <Badge variant="danger">Urgent</Badge></>}{row.awaiting_schedule && <small className="ui-muted"> No days planned yet</small>}</> },
    { key: 'agency_name', label: 'Agency' },
    { key: 'requester', label: 'Requested by', render: (requester) => requester ? <span className="tl-requester"><strong>{requester.name}</strong><small>{requester.is_reply ? `${requesterLabel(requester)} reply` : requesterLabel(requester)}</small></span> : <span className="ui-muted">System update</span> },
    { key: 'employee_name', label: 'Employee', render: (value) => value ?? '—' },
    { key: 'estimated_hours', label: 'Proposed hours', render: (value) => hrs(value) },
    { key: 'start_date', label: 'Dates', render: (_value, row) => `${formatShortDate(row.start_date)} – ${formatShortDate(row.due_date)}` },
    { key: 'timeline_status', label: 'Status', render: (value, row) => <><TimelineChip status={value} overdue={row.overdue} urgent={row.urgent} />{row.pending_tickets > 0 && <small className="ui-muted"> {row.pending_tickets} new {row.pending_tickets === 1 ? 'ticket' : 'tickets'}</small>}</> },
    { key: 'age_hours', label: 'Waiting', render: (value) => `${value} h` },
    { key: 'actions', label: 'Actions', render: (_value, row) => <Link className="ui-button ui-button-secondary ui-button-sm" to={`/admin/timeline-reviews/${row.id}`}>Review</Link> },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="Timeline reviews" subtitle="New projects and tickets wait here until a timeline is confirmed. Oldest first." />
      {summary && (
        <ul className="tl-kpis" aria-label="Review summary">
          <li><strong>{summary.pending}</strong><span>Waiting for review</span></li>
          <li className={summary.overdue > 0 ? 'is-bad' : ''}><strong>{summary.overdue}</strong><span>Overdue</span></li>
          <li><strong>{summary.average_review_hours != null ? `${summary.average_review_hours} h` : '—'}</strong><span>Average time to review</span></li>
          <li><strong>{summary.changes_requested_rate != null ? `${summary.changes_requested_rate}%` : '—'}</strong><span>Changes requested</span></li>
        </ul>
      )}
      <div className="client-filters" role="search">
        <Input id="tl-search" label="Search reviews" type="search" placeholder="Project, agency or client" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} />
        <Select id="tl-status" label="Status" value={status} onChange={(event) => update({ status: event.target.value, page: '' })}>
          <option value="">All</option>
          <option value="proposed">Proposed</option>
          <option value="in_review">In review</option>
          <option value="changes_requested">Changes requested</option>
        </Select>
        {hasFilters && <Button type="button" variant="secondary" size="sm" onClick={() => { setSearchInput(''); setParams({}, { replace: true }) }}>Clear filters</Button>}
      </div>
      {error ? (
        <div role="alert"><ApiErrorMessage error={error} /><Button type="button" variant="secondary" onClick={reload}>Try again</Button></div>
      ) : !isLoading && rows.length === 0 && !hasFilters ? (
        <EmptyState title="Nothing is waiting">Every timeline is confirmed.</EmptyState>
      ) : (
        <Table columns={columns} rows={rows} loading={isLoading && !data} emptyTitle="No reviews match" emptyText="Try a different search or clear the filters." />
      )}
      {!error && rows.length > 0 && <Pagination page={data.pagination.current_page} lastPage={data.pagination.last_page} total={data.pagination.total} noun="review" loading={isLoading} onPrevious={() => update({ page: String(page - 1) })} onNext={() => update({ page: String(page + 1) })} />}
    </section>
  )
}
