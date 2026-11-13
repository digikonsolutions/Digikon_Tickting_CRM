import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, Input, Modal, Textarea } from '../../../components/ui'
import { addDays, todayStr } from '../../../lib/format'

const MAX_DAYS_BACK = 14

// Manual time entry. The server enforces every limit (1 min to 12 h, no future dates, 14 days back,
// 16 h per day); the form only mirrors them so most mistakes are caught before sending.
export function LogTimeModal({ ticket, onClose, onLogged }) {
  const today = todayStr()
  const [date, setDate] = useState(today)
  const [hours, setHours] = useState('')
  const [minutes, setMinutes] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState(null)
  const [isSending, setIsSending] = useState(false)

  const total = (Number(hours) || 0) * 60 + (Number(minutes) || 0)

  async function submit(event) {
    event.preventDefault()
    if (total < 1 || isSending) return
    setIsSending(true)
    setError(null)
    try {
      await authService.logTime(ticket.id, { entryDate: date, minutes: total, note: note.trim() })
      await onLogged()
    } catch (reason) {
      setError(reason)
      setIsSending(false)
    }
  }

  const fieldError = (name) => error?.errors?.[name]?.[0]

  return (
    <Modal open onClose={() => !isSending && onClose()} title={`Log time on ${ticket.key}`}>
      <form className="time-form" onSubmit={submit}>
        {error && !fieldError('entry_date') && !fieldError('minutes') && <Alert variant="error">{error.message}</Alert>}
        <Input
          label="Date"
          type="date"
          value={date}
          min={addDays(today, -MAX_DAYS_BACK)}
          max={today}
          onChange={(event) => setDate(event.target.value)}
          error={fieldError('entry_date')}
          disabled={isSending}
          required
        />
        <div className="time-form-duration">
          <Input label="Hours" type="number" inputMode="numeric" min="0" max="12" value={hours} onChange={(event) => setHours(event.target.value)} disabled={isSending} />
          <Input label="Minutes" type="number" inputMode="numeric" min="0" max="59" value={minutes} onChange={(event) => setMinutes(event.target.value)} error={fieldError('minutes')} disabled={isSending} />
        </div>
        <Textarea label="Note (optional)" rows="2" maxLength="500" value={note} onChange={(event) => setNote(event.target.value)} disabled={isSending} />
        <div className="project-modal-actions">
          <Button type="button" variant="secondary" disabled={isSending} onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={isSending} loadingText="Saving…" disabled={total < 1}>Log time</Button>
        </div>
      </form>
    </Modal>
  )
}
