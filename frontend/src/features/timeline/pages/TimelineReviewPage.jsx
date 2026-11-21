import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { formatDate, formatShortDate } from '../../../lib/format'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, Modal, PageHeader, Skeleton, StatusBadge, Textarea, useToast } from '../../../components/ui'
import { TIMELINE, TimelineChip } from '../TimelineChip'
import { TimelineEvents } from '../TimelineEvents'
import '../timeline.css'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const hrs = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`

function weeksOf(days) {
  // Group the day list into Monday-to-Saturday rows. Sundays are never shown.
  const weeks = []
  days.forEach((day) => {
    const date = new Date(`${day.date}T00:00:00`)
    if (date.getDay() === 0) return
    const monday = new Date(date)
    monday.setDate(date.getDate() - ((date.getDay() + 6) % 7))
    const key = monday.toISOString().slice(0, 10)
    let week = weeks.find((item) => item.key === key)
    if (!week) { week = { key, days: [] }; weeks.push(week) }
    week.days.push({ ...day, slot: (date.getDay() + 6) % 7 })
  })
  return weeks
}

// The review screen. Admins and the assigned employee edit the day-by-day schedule and confirm it; the admin can also
// ask for changes. Every rule is checked on the server; the warnings here are only a head start.
export function TimelineReviewPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const scope = user.role === 'admin' ? 'admin' : 'employee'
  const { showToast } = useToast()
  const load = useCallback(() => authService.getTimeline(scope, id), [scope, id])
  const { data, error, isLoading, reload } = useAsync(load)
  const [hours, setHours] = useState({})
  const [dueDate, setDueDate] = useState('')
  const [note, setNote] = useState('')
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const [changes, setChanges] = useState(null)
  const started = useRef(false)

  // Fill the editor from the server once, and again after every save.
  useEffect(() => {
    if (!data) return
    setHours(Object.fromEntries(data.days.filter((day) => day.hours > 0).map((day) => [day.date, String(day.hours)])))
    setDueDate(data.project.due_date ?? '')
    setDirty(false)
  }, [data])

  // The first admin to open a pending review becomes its project manager.
  useEffect(() => {
    if (scope !== 'admin' || started.current || !data?.timeline.pending) return
    started.current = true
    authService.startTimelineReview(id).then(reload).catch(() => {})
  }, [scope, id, data, reload])

  const timeline = data?.timeline
  const project = data?.project
  const capacity = data?.capacity
  const weeks = useMemo(() => weeksOf(data?.days ?? []), [data])
  const entries = useMemo(() => Object.entries(hours).filter(([, value]) => value !== '' && Number(value) > 0).map(([date, value]) => ({ date, hours: Number(value) })).sort((a, b) => a.date.localeCompare(b.date)), [hours])
  const planned = entries.reduce((sum, entry) => sum + entry.hours, 0)
  const errors = saveError?.errors ?? {}
  const dayError = (date) => {
    const index = entries.findIndex((entry) => entry.date === date)
    return index >= 0 ? (errors[`days.${index}.hours`]?.[0] ?? errors[`days.${index}.date`]?.[0]) : null
  }

  function setDay(date, value) {
    setHours((current) => ({ ...current, [date]: value }))
    setDirty(true)
    setSaveError(null)
  }

  async function save() {
    setBusy('save')
    setSaveError(null)
    try {
      await authService.saveTimeline(scope, id, { days: entries, dueDate: dueDate !== project.due_date ? dueDate : undefined, note: note.trim() })
      showToast('Timeline saved.')
      setNote('')
      await reload()
      return true
    } catch (reason) {
      setSaveError(reason)
      return false
    } finally {
      setBusy(null)
    }
  }

  async function confirm() {
    if (dirty && !(await save())) return
    setBusy('confirm')
    try {
      await authService.confirmTimeline(scope, id)
      showToast('Timeline confirmed. Everyone has been told.')
      await reload()
    } catch (reason) {
      setSaveError(reason)
    } finally {
      setBusy(null)
    }
  }

  async function requestChanges(message) {
    setBusy('changes')
    try {
      await authService.requestTimelineChanges(id, message)
      showToast('Changes requested. The owner has been told.')
      setChanges(null)
      await reload()
    } catch (reason) {
      setChanges((current) => ({ ...current, error: reason }))
    } finally {
      setBusy(null)
    }
  }

  const back = scope === 'admin' ? '/admin/timeline-reviews' : '/my-work'

  if (error) return <section className="page"><ApiErrorMessage error={error} /><Link to={back}>Back</Link></section>
  if (!data) return <section className="page"><Skeleton variant="card" /></section>

  const locked = !timeline.can_edit
  const overCap = (week) => week.days.reduce((sum, day) => sum + Number(hours[day.date] || 0) + day.other_booked_hours, 0) > capacity.weekly_hours

  return (
    <section className="page tl-page">
      <PageHeader
        eyebrow={scope === 'admin' ? 'Timeline review' : 'Your timeline'}
        title={project.title}
        subtitle={`${project.agency.name}${project.client ? ` · ${project.client.name}` : ''} · ${project.employee?.name ?? 'No employee'}`}
        action={<Link className="ui-button ui-button-secondary" to={back}>{scope === 'admin' ? 'All reviews' : 'My work'}</Link>}
      />
      <Card>
        <div className="tl-facts">
          <div><small>Status</small><TimelineChip status={timeline.status} overdue={timeline.overdue} urgent={timeline.urgent} /></div>
          <div><small>Hours</small><strong>{hrs(project.estimated_hours)}</strong></div>
          <div><small>Dates</small><strong>{formatShortDate(project.start_date)} – {formatShortDate(project.due_date)}</strong></div>
          <div><small>Waiting</small><strong>{timeline.status === 'confirmed' ? '—' : `${timeline.age_hours} h`}</strong></div>
          <div><small>Project manager</small><strong>{timeline.project_manager?.name ?? 'Not assigned yet'}</strong></div>
          {timeline.confirmed_at && <div><small>Confirmed</small><strong>{formatShortDate(timeline.confirmed_at)} by {timeline.confirmed_by}</strong></div>}
        </div>
        <p className="tl-explain">{TIMELINE[timeline.status].explain}</p>
      </Card>

      {timeline.status === 'changes_requested' && (
        <Alert variant="warning" title="Changes were requested">The owner has been asked to reply. {scope === 'employee' ? 'You can confirm again once they have replied.' : 'You can still confirm directly if you prefer.'}</Alert>
      )}
      {saveError && !saveError.errors && <ApiErrorMessage error={saveError} />}
      {errors.days && <Alert variant="error" title="The days do not work yet">{errors.days[0]}</Alert>}
      {errors.due_date && <Alert variant="error">{errors.due_date[0]}</Alert>}

      <Card title="Day by day" description={`Up to ${hrs(capacity.daily_hours)} a day and ${hrs(capacity.weekly_hours)} a week. Other bookings of ${project.employee?.name ?? 'the employee'} are shown so you can see what is free.`}>
        <div className="tl-summary" aria-live="polite">
          <span className={Math.abs(planned - project.estimated_hours) < 0.005 ? 'is-ok' : 'is-bad'}>Planned {hrs(planned)} of {hrs(project.estimated_hours)}</span>
          <label className="tl-due">Due date <input type="date" value={dueDate} disabled={locked} onChange={(event) => { setDueDate(event.target.value); setDirty(true); setSaveError(null) }} aria-label="Due date" /></label>
        </div>
        {weeks.map((week) => (
          <div key={week.key} className="tl-week" role="group" aria-label={`Week of ${formatDate(week.key)}`}>
            <div className="tl-week-head">Week of {formatDate(week.key)}{overCap(week) && <span className="tl-warn"> · over {hrs(capacity.weekly_hours)}</span>}</div>
            <div className="tl-grid">
              {WEEKDAYS.map((name, slot) => {
                const day = week.days.find((item) => item.slot === slot)
                if (!day) return <div key={name} className="tl-cell is-empty"><span className="tl-name">{name}</span></div>
                const value = hours[day.date] ?? ''
                const off = !day.working_day || day.past
                const over = Number(value) > day.free_hours || Number(value) > capacity.daily_hours
                const afterDue = dueDate && day.date > dueDate && Number(value) > 0
                const message = dayError(day.date)
                const reason = day.reason === 'non_working_day' ? 'Non-working day' : day.past ? 'In the past' : day.reason === 'past_cutoff' ? 'Today is past the cut-off' : null
                return (
                  <div key={name} className={`tl-cell${off ? ' is-off' : ''}${over || afterDue || message ? ' is-bad' : ''}`}>
                    <span className="tl-name">{name} {new Date(`${day.date}T00:00:00`).getDate()}</span>
                    <div className="tl-bar" aria-hidden="true"><i className="tl-other" style={{ height: `${Math.min(100, (day.other_booked_hours / capacity.daily_hours) * 100)}%` }} /><i className="tl-mine" style={{ height: `${Math.min(100, (Number(value || 0) / capacity.daily_hours) * 100)}%` }} /></div>
                    <input type="number" min="0" max={capacity.daily_hours} step="0.25" inputMode="decimal" value={value} disabled={locked || off} onChange={(event) => setDay(day.date, event.target.value)} aria-label={`Hours on ${formatDate(day.date)}`} aria-invalid={Boolean(over || afterDue || message)} />
                    <small>{reason ?? `${hrs(day.free_hours)} free${day.other_booked_hours ? ` · ${hrs(day.other_booked_hours)} other work` : ''}`}</small>
                    {over && !message && <small className="tl-warn">{Number(value) > capacity.daily_hours ? `Over ${hrs(capacity.daily_hours)} a day` : `Only ${hrs(day.free_hours)} free`}</small>}
                    {afterDue && <small className="tl-warn">After the due date</small>}
                    {message && <small className="tl-warn" role="alert">{message}</small>}
                  </div>
                )
              })}
            </div>
          </div>
        ))}
        {!locked && (
          <>
            <Textarea id="tl-note" label="Note or reason (optional)" rows={2} maxLength={2000} value={note} onChange={(event) => setNote(event.target.value)} helperText="Shown in the activity thread." />
            <div className="tl-actions">
              <Button type="button" variant="secondary" loading={busy === 'save'} loadingText="Saving…" disabled={!dirty || busy !== null} onClick={save}>Save draft</Button>
              {timeline.can_request_changes && <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setChanges({ message: '' })}>Request changes</Button>}
              {timeline.can_confirm && <Button type="button" loading={busy === 'confirm'} loadingText="Confirming…" disabled={busy !== null} onClick={confirm}>Confirm timeline</Button>}
            </div>
          </>
        )}
      </Card>

      <div className="tl-cols">
        <Card title="Tickets" description="Planned across the days above, in this order.">
          {data.tickets.length === 0 ? <p className="ui-muted">No tickets yet.</p> : (
            <ol className="tl-tickets">
              {data.tickets.map((ticket) => (
                <li key={ticket.id}>
                  <span><strong>{ticket.key ?? 'Ticket'}</strong> {ticket.title}</span>
                  <small>{hrs(ticket.estimated_hours)}{ticket.start_date ? ` · ${formatShortDate(ticket.start_date)}${ticket.due_date && ticket.due_date !== ticket.start_date ? ` – ${formatShortDate(ticket.due_date)}` : ''}` : ''}</small>
                  <span><StatusBadge status={ticket.status} />{ticket.timeline_status !== 'confirmed' && <TimelineChip status={ticket.timeline_status} />}</span>
                </li>
              ))}
            </ol>
          )}
        </Card>
        <Card title="Owner's notes and files">
          <p className="tl-notes">{project.description || 'No notes.'}</p>
          {data.attachments.length > 0 && <ul className="tl-files">{data.attachments.map((file) => <li key={file.id}><a href={authService.projectAttachmentUrl(file.id)}>{file.name}</a></li>)}</ul>}
        </Card>
      </div>
      <Card title="Activity"><TimelineEvents events={data.events} /></Card>

      <Modal open={Boolean(changes)} onClose={() => busy === null && setChanges(null)} title="Request changes"
        footer={<><Button type="button" variant="secondary" onClick={() => setChanges(null)}>Cancel</Button><Button type="button" loading={busy === 'changes'} loadingText="Sending…" disabled={(changes?.message ?? '').trim().length < 3} onClick={() => requestChanges(changes.message.trim())}>Send to owner</Button></>}>
        <ApiErrorMessage error={changes?.error} />
        <Textarea id="tl-changes" label="What should change?" rows={5} maxLength={2000} required value={changes?.message ?? ''} onChange={(event) => setChanges((current) => ({ ...current, message: event.target.value }))} helperText="The owner is emailed this message and can reply." />
      </Modal>
    </section>
  )
}
