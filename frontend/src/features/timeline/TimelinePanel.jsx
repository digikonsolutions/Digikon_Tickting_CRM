import { useCallback, useState } from 'react'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { formatDate, formatShortDate } from '../../lib/format'
import { ApiErrorMessage } from '../auth/components/ApiErrorMessage'
import { Alert, Button, Card, Textarea, useToast } from '../../components/ui'
import { FilePicker } from '../briefs/components/FilePicker'
import { TIMELINE, TimelineChip } from './TimelineChip'
import { TimelineEvents } from './TimelineEvents'
import './timeline.css'

const hrs = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`

// "Timeline & tracking" on the project page for owners and clients: status in plain words, the days, who confirmed
// and when, the activity thread, and the reply form when the project manager asked for changes.
export function TimelinePanel({ projectId, onChanged }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getTimeline('agency', projectId), [projectId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [note, setNote] = useState('')
  const [files, setFiles] = useState([])
  const [notice, setNotice] = useState(null)
  const [sending, setSending] = useState(false)
  const [replyError, setReplyError] = useState(null)

  async function reply(event) {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setReplyError(null)
    try {
      await authService.respondToTimeline(projectId, { note: note.trim(), files })
      showToast('Reply sent. Your project manager has been told.')
      setNote('')
      setFiles([])
      await reload()
      onChanged?.()
    } catch (reason) {
      setReplyError(reason)
    } finally {
      setSending(false)
    }
  }

  if (error) return <Card title="Timeline & tracking"><ApiErrorMessage error={error} /><Button type="button" variant="secondary" size="sm" onClick={reload}>Try again</Button></Card>
  if (!data) return <Card title="Timeline & tracking"><p role="status">{isLoading ? 'Loading…' : ''}</p></Card>

  const { timeline, project, days, tickets, events } = data
  const planned = days.filter((day) => day.hours > 0)

  return (
    <Card title="Timeline & tracking" description="When the work is planned, and where it stands.">
      <p><TimelineChip status={timeline.status} /></p>
      <p className="tl-explain">{TIMELINE[timeline.status].explain}</p>
      <dl className="tl-facts">
        <div><small>Project manager</small><strong>{timeline.project_manager?.name ?? 'Not assigned yet'}</strong></div>
        <div><small>Hours you proposed</small><strong>{hrs(project.estimated_hours)}</strong></div>
        {timeline.status === 'confirmed' && <div><small>Confirmed hours</small><strong>{hrs(project.planned_hours ?? project.estimated_hours)}</strong></div>}
        <div><small>Dates</small><strong>{formatShortDate(project.start_date)} – {formatShortDate(project.due_date)}</strong></div>
        {timeline.confirmed_at && <div><small>Confirmed</small><strong>{formatShortDate(timeline.confirmed_at)} by {timeline.confirmed_by}</strong></div>}
      </dl>
      {timeline.status === 'confirmed' && project.hours_differ && (
        <Alert variant="info" className="ui-form-callout" title="Final hours differ from your proposal">
          You proposed {hrs(project.estimated_hours)}. Our team confirmed {hrs(project.planned_hours)} based on the actual work needed.
          {project.planned_note && <> Their note: “{project.planned_note}”</>}
        </Alert>
      )}
      {planned.length > 0 && (
        <>
          <h3>Planned days</h3>
          <ul className="tl-files">{planned.map((day) => <li key={day.date}>{formatDate(day.date)}: {hrs(day.hours)}</li>)}</ul>
        </>
      )}
      {tickets.some((ticket) => ticket.timeline_status !== 'confirmed') && (
        <Alert variant="info" className="ui-form-callout">New tickets on this project are waiting for their own timeline review; the confirmed work is not affected.</Alert>
      )}
      {timeline.can_respond && (
        <form className="ui-form" onSubmit={reply}>
          <h3>Reply to your project manager</h3>
          <Textarea id="tl-reply" label="Your reply" rows={4} maxLength={2000} value={note} onChange={(event) => setNote(event.target.value)} error={replyError?.errors?.note?.[0]} helperText="What changed, or why the schedule should stay as it is." />
          <FilePicker files={files} onChange={setFiles} notice={notice} onNotice={setNotice} disabled={sending} />
          <ApiErrorMessage error={replyError?.status === 422 && replyError.errors ? null : replyError} />
          <div className="tl-panel-actions"><Button type="submit" loading={sending} loadingText="Sending…">Send reply</Button></div>
        </form>
      )}
      <h3>Activity</h3>
      <TimelineEvents events={events} />
    </Card>
  )
}
