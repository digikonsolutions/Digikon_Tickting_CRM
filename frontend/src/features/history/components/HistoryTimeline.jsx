import { useState } from 'react'
import { Alert, Avatar, Badge, Button, Card, EmptyState, Icon, Modal, Pagination, Skeleton } from '../../../components/ui'
import { formatDate, formatDateTime } from '../../../lib/format'
import { EVENT_ICONS, EVENT_LABELS, formatClock, formatDuration, groupByDay } from '../historyData'
import { SiteIcon } from './SiteIcon'

// Read-only list of recorded tab activity, grouped by day. Clicking an entry opens its details. There is
// no edit or delete control anywhere: administrators can only look.
export function HistoryTimeline({ data, error, isLoading, reload, onPage }) {
  const [selected, setSelected] = useState(null)
  const events = data?.events ?? []
  const pagination = data?.pagination
  const groups = groupByDay(events)

  return (
    <Card title="Activity timeline" description={pagination ? `${pagination.total} event${pagination.total === 1 ? '' : 's'} match your filters.` : undefined}>
      {error && (
        <Alert variant="error" title="Could not load history">
          {error.message}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {isLoading && !data && <div role="status" aria-label="Loading history"><Skeleton variant="row" /><Skeleton variant="row" /><Skeleton variant="row" /></div>}
      {data && events.length === 0 && (
        <EmptyState title="No browser activity">
          Nothing was recorded for these filters. Activity appears here after an employee accepts the monitoring notice and the browser extension syncs.
        </EmptyState>
      )}
      {groups.map((group) => (
        <section className="history-day" key={group.key} aria-label={formatDate(group.key)}>
          <h3>{formatDate(group.key)} <span>{group.events.length}</span></h3>
          <ol className="history-list">
            {group.events.map((event) => (
              <li key={event.id}>
                <button type="button" className="history-item" onClick={() => setSelected(event)}>
                  <SiteIcon domain={event.domain} src={event.favicon_url} />
                  <span className="history-item-main">
                    <span className="history-item-title">{event.title || event.domain}</span>
                    <span className="history-item-meta">
                      <span>{event.domain}</span>
                      <Badge variant={event.event_type === 'navigated' ? 'primary' : 'slate'}>{EVENT_LABELS[event.event_type] ?? event.event_type}</Badge>
                      <span className="history-item-employee"><Avatar name={event.employee.name} src={event.employee.photo_url} size="sm" />{event.employee.name}</span>
                      {(event.ticket || event.project) && <Badge variant="info">{event.ticket ? event.ticket.key : event.project.title}</Badge>}
                    </span>
                  </span>
                  <span className="history-item-time">
                    {formatClock(event.occurred_at)}
                    {event.duration_seconds ? <small>{formatDuration(event.duration_seconds)}</small> : null}
                  </span>
                </button>
              </li>
            ))}
          </ol>
        </section>
      ))}
      {pagination && pagination.last_page > 1 && (
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="event" loading={isLoading} onPrevious={() => onPage(pagination.current_page - 1)} onNext={() => onPage(pagination.current_page + 1)} />
      )}

      <Modal open={Boolean(selected)} onClose={() => setSelected(null)} title="Activity details" footer={<Button type="button" variant="secondary" onClick={() => setSelected(null)}>Close</Button>}>
        {selected && (
          <dl className="history-details">
            <div className="history-details-site"><SiteIcon domain={selected.domain} src={selected.favicon_url} /><strong>{selected.title || selected.domain}</strong></div>
            <div><dt>Activity</dt><dd><Icon name={EVENT_ICONS[selected.event_type] ?? 'info'} size={14} /> {EVENT_LABELS[selected.event_type] ?? selected.event_type}</dd></div>
            <div><dt>Employee</dt><dd>{selected.employee.name}</dd></div>
            <div><dt>Working on</dt><dd>{selected.ticket ? `${selected.ticket.key} · ${selected.ticket.title}` : (selected.project?.title ?? '—')}{selected.project && selected.ticket ? ` (${selected.project.title})` : ''}</dd></div>
            <div><dt>Website</dt><dd>{selected.domain}</dd></div>
            <div><dt>Address</dt><dd className="history-details-url">{selected.url}</dd></div>
            <div><dt>When</dt><dd>{formatDateTime(selected.occurred_at)} ({formatClock(selected.occurred_at)})</dd></div>
            <div><dt>Time on page</dt><dd>{formatDuration(selected.duration_seconds)}</dd></div>
            <div><dt>Tab session</dt><dd className="history-details-url">{selected.tab_session_id}</dd></div>
            <p className="history-details-note">Addresses are stored without anything after “?” or “#”. This record is read-only.</p>
          </dl>
        )}
      </Modal>
    </Card>
  )
}
