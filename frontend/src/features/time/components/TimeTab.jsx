import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, EmptyState, Input, Modal, Skeleton, Textarea, useToast } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { formatDate } from '../../../lib/format'
import { formatMinutes } from '../timeEvents'
import { LogTimeModal } from './LogTimeModal'
import { TimeProgress } from './TimeProgress'

const SOURCE_LABEL = { timer: 'Timer', manual: 'Manual' }

// Admin edits and deletes need a reason; the specialist's own changes (first 48 hours) do not.
function EntryDialog({ entry, mode, isAdmin, onClose, onDone }) {
  const [minutes, setMinutes] = useState(String(entry.minutes ?? ''))
  const [note, setNote] = useState(entry.note ?? '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [isSending, setIsSending] = useState(false)
  const isDelete = mode === 'delete'

  async function submit(event) {
    event.preventDefault()
    setIsSending(true)
    setError(null)
    try {
      if (isDelete) await authService.deleteTimeEntry(entry.id, reason.trim())
      else await authService.updateTimeEntry(entry.id, { minutes: Number(minutes), note: note.trim() || null, ...(isAdmin ? { reason: reason.trim() } : {}) })
      await onDone()
    } catch (reasonError) {
      setError(reasonError)
      setIsSending(false)
    }
  }

  return (
    <Modal open onClose={() => !isSending && onClose()} title={isDelete ? 'Remove time entry' : 'Edit time entry'}>
      <form className="time-form" onSubmit={submit}>
        {error && <Alert variant="error">{error.errors?.reason?.[0] ?? error.errors?.minutes?.[0] ?? error.message}</Alert>}
        {isDelete
          ? <p>Remove {formatMinutes(entry.minutes)} logged on {formatDate(entry.entry_date)}?</p>
          : (
            <>
              <Input label="Minutes" type="number" min="1" max="720" value={minutes} onChange={(event) => setMinutes(event.target.value)} disabled={isSending} required />
              <Textarea label="Note" rows="2" maxLength="500" value={note} onChange={(event) => setNote(event.target.value)} disabled={isSending} />
            </>
          )}
        {isAdmin && (
          <Textarea label="Reason for this change" rows="2" maxLength="255" value={reason} onChange={(event) => setReason(event.target.value)} disabled={isSending} required />
        )}
        <div className="project-modal-actions">
          <Button type="button" variant="secondary" disabled={isSending} onClick={onClose}>Cancel</Button>
          <Button type="submit" variant={isDelete ? 'danger' : 'primary'} loading={isSending} disabled={isAdmin && reason.trim().length < 3}>
            {isDelete ? 'Remove' : 'Save'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

// Time tab on the ticket page. Everyone who can see the ticket sees the totals; the entries list is hidden
// from agency users when the server's `agency_sees_entries` flag is off (`entries_visible: false`).
export function TimeTab({ ticket, role, isAssignedEmployee, onChanged, version = 0 }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getTicketTime(ticket.id), [ticket.id, version]) // eslint-disable-line react-hooks/exhaustive-deps
  const { data, error, isLoading, reload } = useAsync(load)
  const [dialog, setDialog] = useState(null)
  const [isLogging, setIsLogging] = useState(false)
  const isAdmin = role === 'admin'
  const canLog = isAssignedEmployee && ticket.status !== 'cancelled'

  const changed = useCallback(async () => {
    setDialog(null)
    setIsLogging(false)
    showToast('Time updated.')
    await reload()
    await onChanged?.()
  }, [reload, onChanged, showToast])

  if (isLoading && !data) return <div role="status" aria-label="Loading time"><Skeleton variant="row" /><Skeleton variant="row" /></div>
  if (error && !data) {
    return (
      <Alert variant="error" title="Could not load time">
        {error.message}
        <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
      </Alert>
    )
  }

  const { entries, entries_visible: visible, totals } = data

  return (
    <div className="time-tab">
      <div className="time-tab-summary">
        <TimeProgress logged={totals.logged_hours} estimated={totals.estimated_hours} />
        {canLog && <Button type="button" size="sm" onClick={() => setIsLogging(true)}>Log time</Button>}
      </div>

      {!visible
        ? <p className="time-tab-note">Individual time entries are not shared with your agency. Totals are shown above.</p>
        : entries.length === 0
          ? <EmptyState title="No time logged yet">Time appears here once the specialist starts a timer or logs hours.</EmptyState>
          : (
            <ul className="time-entries">
              {entries.map((entry) => (
                <li key={entry.id} className="time-entry">
                  <div className="time-entry-main">
                    <strong>{formatMinutes(entry.minutes)}</strong>
                    <span>{formatDate(entry.entry_date)} · {entry.user.name ?? 'Specialist'} · {SOURCE_LABEL[entry.source] ?? entry.source}</span>
                    {entry.auto_stopped && <span className="time-entry-flag">Auto-stopped after the time limit</span>}
                    {entry.note && <p className="time-entry-note">{entry.note}</p>}
                  </div>
                  {entry.can_modify && (
                    <div className="time-entry-actions">
                      <Button type="button" size="sm" variant="ghost" onClick={() => setDialog({ mode: 'edit', entry })}>Edit</Button>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setDialog({ mode: 'delete', entry })}>Remove</Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}

      {isLogging && <LogTimeModal ticket={ticket} onClose={() => setIsLogging(false)} onLogged={changed} />}
      {dialog && <EntryDialog entry={dialog.entry} mode={dialog.mode} isAdmin={isAdmin} onClose={() => setDialog(null)} onDone={changed} />}
    </div>
  )
}
