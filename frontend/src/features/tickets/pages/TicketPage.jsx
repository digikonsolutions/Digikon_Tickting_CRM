import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Button, Card, CommentForm, DayStrip, EmptyState, Modal, NeedsInputBanner, PlainText,
  PriorityBadge, RelativeTime, Skeleton, StatusBadge, StatusStepper, Tabs, TicketKey, Textarea, Timeline, useToast,
} from '../../../components/ui'
import { formatDate, formatElapsed, formatHours } from '../../../lib/format'
import { getAgencyLabels } from '../../../lib/labels'
import { getWaitingLabel } from '../../../lib/ticketStatus'
import { FilesTab } from '../components/FilesTab'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'activity', label: 'Activity' },
  { id: 'files', label: 'Files' },
  { id: 'schedule', label: 'Schedule' },
]

// Wording for actions that need a comment. The backend decides which actions exist (allowed_actions);
// this only chooses the question shown in the dialog.
const COMMENT_PROMPTS = {
  mark_blocked: { title: 'Mark as blocked', question: 'Why is this blocked?' },
  request_client_input: { title: 'Ask the client', question: 'What do you need from the client?' },
  provide_input: { title: 'Reply to your specialist', question: 'What is your answer?' },
  request_changes: { title: 'Request changes', question: 'What should change?' },
}

const PRIMARY_ACTIONS = ['approve', 'start', 'restart_work', 'send_to_review', 'provide_input']

function projectPath(role, projectId) {
  if (role === 'admin') return `/admin/projects/${projectId}`
  if (role === 'employee') return '/my-work'
  return `/agency/projects/${projectId}`
}

// Banner shown when the ticket needs the viewer, or a muted one when it is waiting on someone else.
function describeBanner(ticket, role) {
  const hasActions = ticket.allowed_actions.length > 0
  if (hasActions) {
    switch (ticket.status) {
      case 'in_review':
        return { title: `${ticket.key} is waiting for your review`, body: 'Approve it if it looks right, or tell us what should change.', variant: 'warning' }
      case 'waiting_on_client':
        return { title: 'Your specialist needs an answer', body: ticket.status_reason, variant: 'warning' }
      case 'changes_requested':
        return { title: 'Changes requested', body: ticket.status_reason, variant: 'warning' }
      case 'blocked':
        return { title: 'This ticket is blocked', body: ticket.status_reason, variant: 'danger' }
      default:
        return null
    }
  }
  const who = getWaitingLabel(ticket.waiting_on, role)
  if (!who || !ticket.waiting_since) return null
  return {
    title: `Waiting on ${who} for ${formatElapsed(ticket.waiting_since)}`,
    body: ticket.status_reason,
    variant: 'muted',
  }
}

function ActionButtons({ actions, busyAction, onRun }) {
  return actions.map((action, index) => (
    <Button
      key={action.action}
      type="button"
      size="sm"
      variant={PRIMARY_ACTIONS.includes(action.action) || (index === 0 && !actions.some((a) => PRIMARY_ACTIONS.includes(a.action))) ? 'primary' : 'secondary'}
      loading={busyAction === action.action}
      disabled={Boolean(busyAction)}
      onClick={() => onRun(action)}
    >
      {action.label}
    </Button>
  ))
}

function ActionDialog({ ticket, action, onClose, onDone }) {
  const prompt = COMMENT_PROMPTS[action.action] ?? { title: action.label, question: 'Add a comment' }
  const [comment, setComment] = useState('')
  const [error, setError] = useState(null)
  const [isSending, setIsSending] = useState(false)

  async function submit(event) {
    event.preventDefault()
    if (!comment.trim() || isSending) return
    setIsSending(true)
    setError(null)
    try {
      await authService.transitionTicket(ticket.id, action.action, comment.trim())
      await onDone(action)
    } catch (reason) {
      setError(reason)
      setIsSending(false)
    }
  }

  return (
    <Modal open onClose={() => !isSending && onClose()} title={prompt.title}>
      <form className="ticket-action-form" onSubmit={submit}>
        {error && <Alert variant="error">{error.errors?.comment?.[0] ?? error.message}</Alert>}
        <Textarea
          label={prompt.question}
          rows="4"
          maxLength="10000"
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          disabled={isSending}
          required
        />
        <div className="project-modal-actions">
          <Button type="button" variant="secondary" disabled={isSending} onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={isSending} loadingText="Sending…" disabled={!comment.trim()}>{action.label}</Button>
        </div>
      </form>
    </Modal>
  )
}

// Loads the first page of the timeline, then more on demand. Keyed by `version` in the parent, so it
// refetches after every action or comment.
function TicketActivity({ ticket, role, onPosted }) {
  const loadFirst = useCallback(() => authService.getTicketTimeline(ticket.id), [ticket.id])
  const { data, error, isLoading, reload } = useAsync(loadFirst)
  const [more, setMore] = useState({ events: [], cursor: null, isLoading: false, error: null })
  const cursor = more.cursor ?? data?.next_cursor ?? null
  const events = [...(data?.events ?? []), ...more.events]
  const allowInternal = role === 'admin' || role === 'employee'

  async function loadMore() {
    setMore((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const page = await authService.getTicketTimeline(ticket.id, { cursor })
      setMore((current) => ({ events: [...current.events, ...page.events], cursor: page.next_cursor, isLoading: false, error: null }))
    } catch (reason) {
      setMore((current) => ({ ...current, isLoading: false, error: reason }))
    }
  }

  // With files, the comment and the files go up together as one multipart request.
  const post = useCallback(async (body, { isInternal, files = [] }) => {
    if (files.length) await authService.uploadTicketAttachments(ticket.id, { files, comment: body, isInternal })
    else await authService.createTicketComment(ticket.id, body, { isInternal })
    await onPosted()
  }, [ticket.id, onPosted])

  return (
    <div className="ticket-activity">
      <CommentForm onSubmit={post} allowInternal={allowInternal} allowAttachments={!['done', 'cancelled'].includes(ticket.status)} label="Add a comment" />
      {error && (
        <Alert variant="error" title="Could not load activity">
          {error.message}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {isLoading && !events.length
        ? <div role="status" aria-label="Loading activity"><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : (
          <Timeline items={events} viewerRole={role} attachmentUrl={authService.attachmentUrl}>
            {cursor && (
              <Button type="button" variant="secondary" size="sm" loading={more.isLoading} onClick={loadMore}>Load earlier activity</Button>
            )}
          </Timeline>
        )}
      {more.error && <p className="form-error" role="alert">{more.error.message}</p>}
    </div>
  )
}

export function TicketPage() {
  const { key } = useParams()
  const { user } = useAuth()
  const { showToast } = useToast()
  const labels = getAgencyLabels(user.role)
  const [tab, setTab] = useState('overview')
  const [version, setVersion] = useState(0)
  const [busyAction, setBusyAction] = useState(null)
  const [dialogAction, setDialogAction] = useState(null)
  const [actionError, setActionError] = useState(null)
  const load = useCallback(() => authService.getTicketByKey(key), [key])
  const { data, error, isLoading, reload } = useAsync(load)

  const refresh = useCallback(async () => {
    setVersion((value) => value + 1)
    await reload()
  }, [reload])

  if (isLoading && !data) {
    return (
      <section className="page ticket-page" role="status" aria-label="Loading ticket">
        <Skeleton variant="row" />
        <Skeleton variant="card" />
      </section>
    )
  }

  if (error?.status === 404 || (!data && !error)) {
    return (
      <section className="page ticket-page">
        <EmptyState title="Ticket not found" action={<Link className="ui-button ui-button-primary" to="/">Go to home</Link>}>
          We could not find {key}. It may not exist, or you may not have access to it.
        </EmptyState>
      </section>
    )
  }

  if (error && !data) {
    return (
      <section className="page ticket-page">
        <Alert variant="error" title="Could not load this ticket">
          {error.message}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      </section>
    )
  }

  const { ticket, project } = data
  const banner = describeBanner(ticket, user.role)
  const waitingLabel = getWaitingLabel(ticket.waiting_on, user.role)

  async function runAction(action) {
    if (action.requires_comment) {
      setDialogAction(action)
      return
    }
    setBusyAction(action.action)
    setActionError(null)
    try {
      await authService.transitionTicket(ticket.id, action.action)
      showToast(`${ticket.key}: ${action.label.toLowerCase()} done.`)
      await refresh()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyAction(null)
    }
  }

  async function dialogDone(action) {
    setDialogAction(null)
    showToast(`${ticket.key}: ${action.label.toLowerCase()} done.`)
    await refresh()
  }

  async function commentPosted() {
    showToast('Comment posted.')
    await refresh()
  }

  const actionButtons = <ActionButtons actions={ticket.allowed_actions} busyAction={busyAction} onRun={runAction} />

  return (
    <section className="page ticket-page">
      <nav className="ticket-breadcrumb" aria-label="Breadcrumb">
        <Link to={projectPath(user.role, project.id)}>{project.title}</Link>
        <span aria-hidden="true">/</span>
        <span>{ticket.key}</span>
      </nav>

      <header className="ticket-header">
        <div className="ticket-header-main">
          <TicketKey ticketKey={ticket.key} />
          <h1>{ticket.title}</h1>
          <div className="ticket-header-badges">
            <StatusBadge status={ticket.status} role={user.role} />
            <PriorityBadge priority={ticket.priority} />
          </div>
        </div>
        {!banner && ticket.allowed_actions.length > 0 && <div className="ticket-header-actions">{actionButtons}</div>}
      </header>

      {actionError && <Alert variant="error">{actionError.message}</Alert>}

      {banner && (
        <NeedsInputBanner
          title={banner.title}
          body={banner.body}
          variant={banner.variant}
          actions={ticket.allowed_actions.length > 0 ? actionButtons : null}
        />
      )}

      <StatusStepper status={ticket.status} />

      <div className="ticket-layout">
        <div className="ticket-main">
          <Tabs tabs={TABS} activeTab={tab} onChange={setTab} />
          <Card>
            {tab === 'overview' && (
              <div className="ticket-overview">
                <PlainText text={ticket.description || 'No description provided.'} />
                <dl className="ticket-facts">
                  <div><dt>{labels.dueDate}</dt><dd>{formatDate(ticket.due_date)}</dd></div>
                  <div><dt>Estimated hours</dt><dd>{formatHours(ticket.estimated_hours)}</dd></div>
                </dl>
                {waitingLabel && ticket.waiting_since && (
                  <p className="ticket-waiting">
                    Waiting on <strong>{waitingLabel}</strong> since <RelativeTime date={ticket.waiting_since} />
                    {ticket.status_reason && <span className="ticket-waiting-reason"> · {ticket.status_reason}</span>}
                  </p>
                )}
              </div>
            )}
            {tab === 'activity' && <TicketActivity key={version} ticket={ticket} role={user.role} onPosted={commentPosted} />}
            {tab === 'files' && <FilesTab ticket={ticket} readOnly={['done', 'cancelled'].includes(ticket.status)} onChanged={() => setVersion((value) => value + 1)} />}
            {tab === 'schedule' && (
              <div className="ticket-schedule">
                <DayStrip schedule={ticket.schedule} />
                {ticket.schedule.length > 0 && (
                  <ul className="ticket-schedule-list">
                    {ticket.schedule.map((item) => (
                      <li key={item.work_date}><span>{formatDate(item.work_date)}</span><strong>{formatHours(item.hours)}</strong></li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </Card>
        </div>

        <aside className="ticket-sidebar" aria-label="Ticket details">
          <Card>
            <dl className="ticket-facts ticket-facts-stacked">
              <div><dt>{labels.specialist}</dt><dd>{project.employee?.name ?? 'Unassigned'}</dd></div>
              <div><dt>Project</dt><dd><Link to={projectPath(user.role, project.id)}>{project.title}</Link></dd></div>
              <div><dt>Created by</dt><dd>{ticket.creator?.name ?? 'Account removed'}</dd></div>
              <div><dt>Priority</dt><dd><PriorityBadge priority={ticket.priority} /></dd></div>
              <div><dt>Estimated hours</dt><dd>{formatHours(ticket.estimated_hours)}</dd></div>
              <div><dt>{labels.dueDate}</dt><dd>{formatDate(ticket.due_date)}</dd></div>
            </dl>
          </Card>
        </aside>
      </div>

      {dialogAction && (
        <ActionDialog ticket={ticket} action={dialogAction} onClose={() => setDialogAction(null)} onDone={dialogDone} />
      )}
    </section>
  )
}
