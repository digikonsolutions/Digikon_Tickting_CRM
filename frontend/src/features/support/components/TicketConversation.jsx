import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, EmptyState, Icon, PlainText, RelativeTime, Skeleton, Textarea, useToast } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'

// The conversation of one ticket and its composer. Staff get a Public reply / Internal note switch that always starts on
// Public for each ticket (the component is keyed by ticket id, so the mode never carries over). Internal items look
// unmistakably different: amber stripes and a lock, so a screenshot cannot be misread.
export function TicketConversation({ ticket, audience, recipient, onPosted, onAttach, closed = false, internalOnly = false }) {
  const { showToast } = useToast()
  const isStaff = audience === 'staff'
  const [mode, setMode] = useState('public')
  const [filter, setFilter] = useState(internalOnly ? 'internal' : 'all')
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [secret, setSecret] = useState(null)
  const [added, setAdded] = useState([])
  const load = useCallback(() => authService.listTicketComments(ticket.id), [ticket.id])
  const { data, error: loadError, isLoading, reload } = useAsync(load)

  const loaded = data?.comments ?? []
  const ids = new Set(loaded.map((comment) => comment.id))
  const all = [...loaded, ...added.filter((comment) => !ids.has(comment.id))]
  const shown = all.filter((comment) => filter === 'all' || (filter === 'internal') === Boolean(comment.is_internal))

  // A gentle warning while typing a public message that looks like it holds a password or key.
  useEffect(() => {
    if (internalOnly || mode !== 'public' || body.trim().length < 8) return undefined
    let active = true
    const timer = window.setTimeout(() => {
      authService.scanSupportText(body).then((result) => { if (active) setSecret(result.found?.length ? result.message : null) }).catch(() => {})
    }, 600)
    return () => { active = false; window.clearTimeout(timer) }
  }, [body, mode, internalOnly])

  async function send(event) {
    event.preventDefault()
    if (!body.trim() || busy) return
    setBusy(true)
    setError(null)
    try {
      const { comment } = await authService.createTicketComment(ticket.id, body.trim(), { isInternal: writesInternal })
      setAdded((current) => [...current, comment])
      setBody('')
      setSecret(null)
      showToast(writesInternal ? 'Internal note added.' : 'Reply sent.')
      onPosted?.(comment)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }

  const writesInternal = isStaff && (internalOnly || mode === 'internal')
  const internal = writesInternal
  return (
    <div className="sup-conversation">
      {isStaff && !internalOnly && (
        <div className="sup-filter" role="group" aria-label="Show">
          {['all', 'public', 'internal'].map((name) => (
            <Button key={name} type="button" size="sm" variant={filter === name ? 'primary' : 'secondary'} aria-pressed={filter === name} onClick={() => setFilter(name)}>{name[0].toUpperCase() + name.slice(1)}</Button>
          ))}
        </div>
      )}
      {loadError && !all.length
        ? <Alert variant="error" title="Could not load the conversation">{loadError.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
        : isLoading && !all.length
          ? <div role="status" aria-label="Loading the conversation"><Skeleton variant="row" /><Skeleton variant="row" /></div>
          : shown.length === 0
            ? <EmptyState title={internalOnly ? 'No internal notes yet' : 'No messages yet'}>{internalOnly ? 'Notes here are only for staff.' : 'Replies from you and the support team will show up here.'}</EmptyState>
            : (
              <ul className="sup-thread" aria-live="polite">
                {shown.map((comment) => (
                  <li key={comment.id} className={comment.is_internal ? 'sup-internal-card' : 'sup-public-card'}>
                    <header>
                      {comment.is_internal && <Icon name="lock" size={14} />}
                      <strong>{comment.user?.name ?? 'Someone'}</strong>
                      {comment.is_internal ? <span>Internal note, staff only</span> : <span>Visible to the agency</span>}
                      <RelativeTime date={comment.created_at} />
                    </header>
                    <PlainText text={comment.body} />
                  </li>
                ))}
              </ul>
            )}

      {closed
        ? <Alert variant="info">This ticket is closed. Reopen it to add a message.</Alert>
        : (
          <form className={`sup-composer${internal ? ' is-internal' : ''}`} onSubmit={send} noValidate aria-label={internal ? 'Add an internal note' : 'Reply'}>
            {isStaff && !internalOnly && (
              <div className="sup-mode" role="group" aria-label="Message type">
                <button type="button" aria-pressed={mode === 'public'} onClick={() => setMode('public')}><Icon name="chat" size={15} /> Public reply</button>
                <button type="button" className="is-internal" aria-pressed={mode === 'internal'} onClick={() => setMode('internal')}><Icon name="lock" size={15} /> Internal note</button>
              </div>
            )}
            <p className="sup-note-label">
              {internal ? <><Icon name="lock" size={15} /> Staff only. The agency cannot see this.</> : isStaff ? <>Visible to the agency{recipient ? `. Sends to ${recipient}.` : '.'}</> : 'Your reply goes to the support team.'}
            </p>
            <Textarea id={`composer-${ticket.id}`} label={internal ? 'Internal note' : 'Message'} rows={4} maxLength={10000} value={body} onChange={(event) => setBody(event.target.value)} error={error?.errors?.body?.[0]} />
            {secret && !internal && <p className="sup-secret" role="alert">{secret}</p>}
            <ApiErrorMessage error={error && !error.errors ? error : null} />
            <div className="sup-composer-actions">
              {onAttach && <Button type="button" variant="ghost" size="sm" onClick={onAttach}><Icon name="paperclip" size={15} /> Attach a file or link</Button>}
              <Button type="submit" variant={internal ? 'secondary' : 'primary'} loading={busy} disabled={!body.trim()}>{internal ? 'Add internal note' : 'Send reply'}</Button>
            </div>
          </form>
        )}
    </div>
  )
}
