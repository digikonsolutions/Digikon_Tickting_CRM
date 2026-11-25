import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, EmptyState, FieldRow, FormActions, Icon, Input, Select, Skeleton, useToast } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'

const text = (minutes) => `${Math.floor(minutes / 60)}h ${minutes % 60}m`

// Time on a support ticket. Staff only: the agency never sees time or cost. The estimate is information only.
export function TimeTab({ ticket, closed }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getStaffTicketTime(ticket.ref), [ticket.ref])
  const { data, error, isLoading, reload } = useAsync(load)
  const [form, setForm] = useState({ minutes: '', activity: 'work', billable: false, note: '' })
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState(null)
  const time = data?.time

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setFailure(null)
    try {
      await authService.logStaffTicketTime(ticket.ref, { ...form, minutes: Number(form.minutes) })
      setForm({ minutes: '', activity: 'work', billable: false, note: '' })
      showToast('Time logged.')
      await reload()
    } catch (reason) {
      setFailure(reason)
    } finally {
      setBusy(false)
    }
  }
  if (error && !data) return <Alert variant="error" title="Could not load time">{error.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
  if (isLoading && !data) return <Skeleton variant="card" />
  const estimate = time.estimate_hours ? Math.round(time.estimate_hours * 60) : null

  return (
    <div className="sup-time">
      <p className="sup-note-label"><Icon name="lock" size={15} /> Staff only. The agency cannot see time.</p>
      <p>Actual <strong>{text(time.actual_minutes)}</strong> ({text(time.billable_minutes)} billable){estimate ? <> of an estimated <strong>{text(estimate)}</strong></> : ' (no estimate set)'}</p>
      {estimate && <progress value={Math.min(time.actual_minutes, estimate)} max={estimate} aria-label="Actual time compared with the estimate" />}
      {time.entries.length === 0 ? <EmptyState title="No time logged yet">Log time as you work.</EmptyState> : (
        <table className="sup-queue"><thead><tr><th scope="col">Date</th><th scope="col">Who</th><th scope="col">Activity</th><th scope="col">Time</th><th scope="col">Note</th></tr></thead>
          <tbody>{time.entries.map((entry) => <tr key={entry.id}><td>{entry.date}</td><td>{entry.user}</td><td>{entry.activity}{entry.billable ? ', billable' : ''}</td><td>{text(entry.minutes)}</td><td>{entry.note}</td></tr>)}</tbody></table>
      )}
      {!closed && (
        <form className="ui-form" onSubmit={submit} noValidate aria-label="Log time">
          <FieldRow columns={3}>
            <Input label="Minutes" type="number" min="1" max="1440" required value={form.minutes} onChange={(event) => setForm({ ...form, minutes: event.target.value })} error={failure?.errors?.minutes?.[0]} />
            <Select label="Activity" value={form.activity} onChange={(event) => setForm({ ...form, activity: event.target.value })}><option value="work">Work</option><option value="qa">QA</option><option value="support">Support</option></Select>
            <Input label="Note" maxLength={300} value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
          </FieldRow>
          <label className="ui-checkgroup-option"><input type="checkbox" checked={form.billable} onChange={(event) => setForm({ ...form, billable: event.target.checked })} /><span>Billable</span></label>
          <ApiErrorMessage error={failure && !failure.errors ? failure : null} />
          <FormActions submitLabel="Log time" loadingLabel="Saving…" loading={busy} disabled={!form.minutes} disabledReason="Enter the minutes." />
        </form>
      )}
    </div>
  )
}
