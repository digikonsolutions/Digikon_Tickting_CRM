import { useCallback, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { CommentForm, CommentThread, useToast } from '../../components/ui'

// Loads a ticket's comment thread and lets the viewer reply. Mount it only when the thread is
// visible (an opened drawer or expanded card) and key it by ticket id so state resets per ticket.
// New comments are appended locally instead of refetching the whole thread.
export function TicketComments({ ticketId, onPosted }) {
  const { user } = useAuth()
  const { showToast } = useToast()
  const [added, setAdded] = useState([])
  const load = useCallback(() => authService.listTicketComments(ticketId), [ticketId])
  const { data, error, isLoading, reload } = useAsync(load)

  const loaded = data?.comments ?? []
  const loadedIds = new Set(loaded.map((comment) => comment.id))
  const comments = [...loaded, ...added.filter((comment) => !loadedIds.has(comment.id))]

  const allowInternal = user?.role === 'admin' || user?.role === 'employee'
  const post = useCallback(async (body, { isInternal = false } = {}) => {
    const { comment } = await authService.createTicketComment(ticketId, body, { isInternal })
    setAdded((current) => [...current, comment])
    showToast('Comment posted.')
    onPosted?.(comment)
  }, [ticketId, onPosted, showToast])

  return (
    <div className="ticket-comments">
      <CommentThread
        comments={comments}
        isLoading={isLoading}
        error={error && !comments.length ? error : null}
        onRetry={reload}
        currentUserId={user?.id}
      />
      <CommentForm onSubmit={post} allowInternal={allowInternal} />
    </div>
  )
}
