import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Card, EmptyState, Modal, Pagination, Skeleton } from '../../../components/ui'
import { formatDateTime } from '../../../lib/format'
import './snapshots.css'

// Pictures of the browser tab in front, taken now and then while an employee worked a ticket (only when switched on, only
// on approved work sites). Administrators only, read-only; every picture opened is written to the audit log.
export function SnapshotGallery({ employeeId = '', ticketId = '', from = '', to = '' }) {
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(null)
  const load = useCallback(() => authService.getWorkSnapshots({ user_id: employeeId, ticket_id: ticketId, from, to }, { page }), [employeeId, ticketId, from, to, page])
  const { data, error, isLoading, reload } = useAsync(load)
  const items = data?.snapshots ?? []

  return (
    <Card title="Work snapshots" description="A small picture of the browser tab in front, only on approved work sites and only while a ticket is in progress. Opening a picture is recorded.">
      {error && <Alert variant="error" title="Could not load snapshots">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>}
      {isLoading && !data && <div className="sn-grid" role="status" aria-label="Loading snapshots"><Skeleton variant="card" /><Skeleton variant="card" /><Skeleton variant="card" /></div>}
      {data && items.length === 0 && <EmptyState title="No snapshots">None match these filters. Snapshots are only taken when the feature is switched on and employees have accepted the current notice.</EmptyState>}
      {items.length > 0 && (
        <>
          <ul className="sn-grid">
            {items.map((item) => (
              <li key={item.id}>
                <button type="button" className="sn-card" onClick={() => setOpen(item)} aria-label={`Open snapshot of ${item.employee.name} on ${item.domain}`}>
                  <img src={authService.workSnapshotUrl(item.id)} alt="" loading="lazy" crossOrigin="use-credentials" />
                  <span className="sn-meta"><strong>{item.employee.name}</strong><small>{item.ticket ? `${item.ticket.key} · ` : ''}{item.domain}</small><small>{formatDateTime(item.captured_at)}</small></span>
                </button>
              </li>
            ))}
          </ul>
          <Pagination page={data.pagination.current_page} lastPage={data.pagination.last_page} total={data.pagination.total} noun="snapshot" loading={isLoading} onPrevious={() => setPage(page - 1)} onNext={() => setPage(page + 1)} />
        </>
      )}
      {open && (
        <Modal open onClose={() => setOpen(null)} title={`${open.employee.name} · ${open.domain}`} className="sn-modal" footer={null}>
          <img className="sn-full" src={authService.workSnapshotUrl(open.id)} alt={`Snapshot of ${open.domain}${open.title ? `: ${open.title}` : ''}`} crossOrigin="use-credentials" />
          <p className="sn-caption">{formatDateTime(open.captured_at)}{open.ticket ? ` · ${open.ticket.key} ${open.ticket.title}` : ''}{open.title ? ` · ${open.title}` : ''}</p>
        </Modal>
      )}
    </Card>
  )
}