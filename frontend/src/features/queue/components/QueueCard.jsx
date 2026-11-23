import { useId, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Avatar, Badge, Button, CapacityBar, Card } from '../../../components/ui'
import { formatDate, formatDateTime, formatShortDate } from '../../../lib/format'
import { daysLate, plannedRange } from '../queueFormat'

function NowBlock({ now }) {
  if (!now) return <p className="queue-now-empty">Nothing in progress right now.</p>
  if (now.kind === 'scheduled') {
    return (
      <div className="queue-now-body">
        <strong>{now.title}</strong>
        <span className="queue-muted">Scheduled for today · {now.hours_estimated} h</span>
      </div>
    )
  }
  return (
    <div className="queue-now-body">
      <strong>{now.ticket_key ? `${now.ticket_key} · ` : ''}{now.title}</strong>
      <span className="queue-muted">{now.project_title} · started {now.started_at ? formatDateTime(now.started_at) : 'recently'}</span>
      <CapacityBar used={now.hours_logged ?? 0} budget={now.hours_estimated || 1} label="logged" ariaLabel={`Progress on ${now.title}`} />
    </div>
  )
}

/**
 * One employee's queue: what they are doing now, what is next with planned dates, the room they have left, and
 * (for the owner) a reorder flow: move, preview the impact, then apply.
 */
export function QueueCard({ assignmentId, queue, isOwner = false, userId, onAddTask, onRequestHelp, onChanged, onMessage }) {
  const headingId = useId()
  const [editing, setEditing] = useState(false)
  const [order, setOrder] = useState([])
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [announcement, setAnnouncement] = useState('')

  const items = queue.up_next ?? []
  const byId = new Map(items.map((item) => [item.work_item_id, item]))
  const shown = editing ? order.map((id) => byId.get(id)).filter(Boolean) : items
  const capacity = queue.capacity ?? {}
  const free = capacity.free_from

  function startEditing() {
    setOrder(items.map((item) => item.work_item_id))
    setPreview(null)
    setError(null)
    setEditing(true)
  }

  function stopEditing() {
    setEditing(false)
    setPreview(null)
    setError(null)
  }

  function move(index, delta) {
    const target = index + delta
    if (target < 0 || target >= order.length) return
    const next = [...order]
    ;[next[index], next[target]] = [next[target], next[index]]
    setOrder(next)
    setPreview(null)
    setError(null)
    setAnnouncement(`${byId.get(order[index])?.title} moved to position ${target + 1} of ${next.length}. Preview the impact before applying.`)
  }

  const fullOrder = () => [...(queue.pinned_work_item_ids ?? []), ...order]

  async function runPreview() {
    setBusy('preview')
    setError(null)
    try {
      const result = await authService.previewQueueOrder(assignmentId, fullOrder())
      setPreview(result)
      setAnnouncement(result.fits
        ? 'This order fits. Every project still finishes by its due date.'
        : `This order cannot be applied: ${result.conflicts.length} ${result.conflicts.length === 1 ? 'project would' : 'projects would'} finish after the due date.`)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(null)
    }
  }

  async function apply() {
    setBusy('apply')
    setError(null)
    try {
      const updated = await authService.reorderQueue(assignmentId, fullOrder())
      setEditing(false)
      setPreview(null)
      setAnnouncement('The queue was reordered.')
      onChanged?.(updated)
      onMessage?.('Queue reordered. Dates were updated.')
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(null)
    }
  }

  const conflicts = preview?.conflicts ?? error?.payload?.conflicts ?? []
  const previewRows = (preview?.items ?? []).filter((row) => byId.has(row.work_item_id))

  return (
    <Card className="queue-card" aria-labelledby={headingId}>
      <div className="queue-card-head">
        <Avatar name={queue.employee?.name} src={queue.employee?.photo_url} size="lg" />
        <div>
          <h2 id={headingId} className="queue-card-title">{queue.employee?.name}</h2>
          <p className="queue-muted">
            {free ? <>Free from <strong>{formatShortDate(free.date)}</strong></> : 'No free time in the next four months'}
          </p>
        </div>
        <div className="queue-card-actions">
          <Button type="button" size="sm" onClick={() => onAddTask?.(assignmentId)}>Add task</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => onRequestHelp?.(assignmentId)}>Request urgent help</Button>
        </div>
      </div>

      <section className="queue-now" aria-label={`Now working on, ${queue.employee?.name}`}>
        <h3 className="queue-section-title">Now working on</h3>
        <NowBlock now={queue.now} />
      </section>

      <section aria-label={`Up next, ${queue.employee?.name}`}>
        <div className="queue-section-head">
          <h3 className="queue-section-title">Up next</h3>
          {isOwner && !editing && items.length > 1 && <Button type="button" size="sm" variant="secondary" onClick={startEditing}>Reorder</Button>}
        </div>
        {shown.length === 0 ? (
          <p className="queue-muted">No projects waiting. Use Add task to give {queue.employee?.name} work.</p>
        ) : (
          <ol className="queue-list" aria-label={`Projects waiting for ${queue.employee?.name}`}>
            {shown.map((item, index) => (
              <li className="queue-item" key={item.work_item_id}>
                <span className="queue-item-number" aria-hidden="true">{index + 1}</span>
                <div className="queue-item-main">
                  <strong>{item.title}</strong>
                  <span className="queue-muted">
                    {item.client_name ? `${item.client_name} · ` : ''}{item.estimated_hours} h · {plannedRange(item.planned_start, item.planned_end)} · due {formatDate(item.due_date)}
                  </span>
                  {!item.fits && <Badge variant="warning">May finish after its due date</Badge>}
                  {item.created_by_id === userId && !isOwner && <Badge variant="info">Yours</Badge>}
                </div>
                {editing && (
                  <div className="queue-item-move">
                    <Button type="button" size="sm" variant="ghost" aria-label={`Move ${item.title} up`} disabled={index === 0 || busy !== null} onClick={() => move(index, -1)}>↑</Button>
                    <Button type="button" size="sm" variant="ghost" aria-label={`Move ${item.title} down`} disabled={index === shown.length - 1 || busy !== null} onClick={() => move(index, 1)}>↓</Button>
                  </div>
                )}
              </li>
            ))}
          </ol>
        )}

        {editing && (
          <div className="queue-reorder-bar">
            <Button type="button" size="sm" variant="secondary" loading={busy === 'preview'} loadingText="Checking…" disabled={busy !== null} onClick={runPreview}>Preview impact</Button>
            <Button type="button" size="sm" loading={busy === 'apply'} loadingText="Applying…" disabled={!preview?.fits || busy !== null} onClick={apply}>Apply</Button>
            <Button type="button" size="sm" variant="ghost" disabled={busy !== null} onClick={stopEditing}>Cancel</Button>
          </div>
        )}

        {preview && (
          <div className="queue-preview">
            {preview.fits
              ? <Alert variant="info" title="Every project still fits">{previewRows.some((row) => row.moves) ? 'These dates would change:' : 'No dates would change.'}</Alert>
              : <Alert variant="error" title="This order would break due dates">Nothing is changed. Move things around or extend a due date.</Alert>}
            <ul className="queue-preview-list">
              {previewRows.filter((row) => row.moves || !row.fits).map((row) => (
                <li key={row.work_item_id}>
                  <strong>{row.title}</strong>: {plannedRange(row.current_start, row.current_end)} → {plannedRange(row.planned_start, row.planned_end)}
                  {!row.fits && <Badge variant="danger">{daysLate(row.slips_by_days)}</Badge>}
                </li>
              ))}
            </ul>
          </div>
        )}
        {!preview && error && (
          <Alert variant="error" title={conflicts.length ? 'This order would break due dates' : 'Could not update the queue'}>
            {error.message}
            {conflicts.length > 0 && <ul>{conflicts.map((c) => <li key={c.work_item_id}>{c.title}: {daysLate(c.slips_by_days)}</li>)}</ul>}
          </Alert>
        )}
        <p className="queue-sr-only" role="status" aria-live="polite">{announcement}</p>
      </section>

      <section className="queue-capacity" aria-label={`Capacity, ${queue.employee?.name}`}>
        <CapacityBar used={Number(capacity.hours_today_used ?? 0)} budget={Number(capacity.daily_hours ?? 6)} label="today" ariaLabel="Hours booked today" />
        <CapacityBar used={Number(capacity.hours_week_used ?? 0)} budget={Number(capacity.weekly_hours ?? 36)} label="this week" ariaLabel="Hours booked this week" />
      </section>
    </Card>
  )
}
