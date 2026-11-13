import { useEffect, useState } from 'react'
import { ROLE_LABELS } from '../../auth/roles'
import { formatDateTime, formatRelativeTime } from '../../lib/format'
import { Alert } from './Alert'
import { Avatar } from './Avatar'
import { Button } from './Button'
import { PlainText } from './PlainText'
import { Skeleton } from './Skeleton'

// Relative times ("5 min ago") are recomputed on a slow tick so an open thread does not go stale.
function useNow(intervalMs = 60000) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), intervalMs)
    return () => clearInterval(timer)
  }, [intervalMs])
  return now
}

export function CommentThread({ comments, isLoading = false, error = null, onRetry, currentUserId, className = '' }) {
  const now = useNow()

  if (error) {
    return (
      <div className={`ui-comments ${className}`.trim()}>
        <Alert variant="error" title="Could not load comments">
          {error.message ?? 'Something went wrong.'}
          {onRetry && (
            <div className="ui-comments-retry">
              <Button type="button" variant="secondary" size="sm" onClick={onRetry}>Try again</Button>
            </div>
          )}
        </Alert>
      </div>
    )
  }

  if (isLoading && !comments?.length) {
    return (
      <div className={`ui-comments ${className}`.trim()} role="status" aria-label="Loading comments">
        <Skeleton variant="row" />
        <Skeleton variant="row" />
      </div>
    )
  }

  if (!comments?.length) {
    return <p className={`ui-comments-empty ${className}`.trim()}>No comments yet.</p>
  }

  return (
    <ol className={`ui-comments ${className}`.trim()} aria-label="Comments">
      {comments.map((comment) => {
        const isOwn = currentUserId !== undefined && String(comment.user?.id) === String(currentUserId)
        return (
          <li className={`ui-comment${isOwn ? ' ui-comment-own' : ''}`} key={comment.id}>
            <Avatar name={comment.user?.name} size="sm" />
            <div className="ui-comment-main">
              <div className="ui-comment-meta">
                <strong>{isOwn ? `${comment.user?.name ?? 'You'} (you)` : (comment.user?.name ?? 'Deleted user')}</strong>
                {comment.user?.role && <span className="ui-comment-role">{ROLE_LABELS[comment.user.role] ?? comment.user.role}</span>}
                <time dateTime={comment.created_at} title={formatDateTime(comment.created_at)}>
                  {formatRelativeTime(comment.created_at, now)}
                </time>
              </div>
              {/* Rendered as text (never HTML); line breaks are kept and http(s) links are made clickable. */}
              <PlainText className="ui-comment-body" text={comment.body} />
            </div>
          </li>
        )
      })}
    </ol>
  )
}
