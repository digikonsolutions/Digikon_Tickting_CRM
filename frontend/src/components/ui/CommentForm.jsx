import { useEffect, useRef, useState } from 'react'
import { Button } from './Button'
import { Textarea } from './Fields'

const MAX_COMMENT_LENGTH = 10000

// `onSubmit(body)` must return a promise; throw to report an error, which is shown under the field.
export function CommentForm({ onSubmit, label = 'Add a comment', disabled = false, allowInternal = false, className = '' }) {
  const [body, setBody] = useState('')
  const [isInternal, setIsInternal] = useState(false)
  const [isSending, setIsSending] = useState(false)
  const [error, setError] = useState(null)
  const isMounted = useRef(true)
  useEffect(() => {
    isMounted.current = true
    return () => { isMounted.current = false }
  }, [])

  const trimmed = body.trim()
  const canSend = Boolean(trimmed) && !isSending && !disabled

  async function send() {
    if (!canSend) return
    setIsSending(true)
    setError(null)
    try {
      await onSubmit(trimmed, { isInternal: allowInternal && isInternal })
      if (isMounted.current) setBody('')
    } catch (reason) {
      if (isMounted.current) setError(reason)
    } finally {
      if (isMounted.current) setIsSending(false)
    }
  }

  return (
    <form
      className={`ui-comment-form${allowInternal && isInternal ? ' is-internal' : ''} ${className}`.trim()}
      onSubmit={(event) => { event.preventDefault(); send() }}
    >
      <Textarea
        label={allowInternal && isInternal ? 'Internal note' : label}
        rows="3"
        maxLength={MAX_COMMENT_LENGTH}
        value={body}
        disabled={disabled || isSending}
        error={error ? (error.errors?.body?.[0] ?? error.message ?? 'Could not post the comment.') : undefined}
        helperText={allowInternal && isInternal ? 'Internal: not visible to the client.' : 'Press Ctrl+Enter (or Cmd+Enter) to post.'}
        onChange={(event) => { setBody(event.target.value); if (error) setError(null) }}
        onKeyDown={(event) => {
          if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault()
            send()
          }
        }}
      />
      {allowInternal && (
        <label className="ui-comment-internal-toggle">
          <input type="checkbox" checked={isInternal} onChange={(event) => setIsInternal(event.target.checked)} disabled={disabled || isSending} />
          Internal note (not visible to the client)
        </label>
      )}
      <Button type="submit" size="sm" disabled={!canSend} loading={isSending} loadingText="Posting…">
        Post comment
      </Button>
    </form>
  )
}
