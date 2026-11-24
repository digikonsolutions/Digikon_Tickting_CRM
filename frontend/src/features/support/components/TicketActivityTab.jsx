import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Skeleton, Timeline } from '../../../components/ui'

// The append-only activity of a ticket. The server already hides internal items from agency users.
export function TicketActivityTab({ ticketId, role }) {
  const load = useCallback(() => authService.getTicketTimeline(ticketId), [ticketId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [more, setMore] = useState({ events: [], cursor: null, busy: false })
  const cursor = more.cursor ?? data?.next_cursor ?? null
  async function loadMore() {
    setMore((current) => ({ ...current, busy: true }))
    try {
      const page = await authService.getTicketTimeline(ticketId, { cursor })
      setMore((current) => ({ events: [...current.events, ...page.events], cursor: page.next_cursor, busy: false }))
    } catch {
      setMore((current) => ({ ...current, busy: false }))
    }
  }
  if (error && !data) return <Alert variant="error" title="Could not load the activity">{error.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
  if (isLoading && !data) return <div role="status" aria-label="Loading activity"><Skeleton variant="row" /><Skeleton variant="row" /></div>

  return (
    <Timeline items={[...(data?.events ?? []), ...more.events]} viewerRole={role} attachmentUrl={authService.attachmentUrl}>
      {cursor && <Button type="button" variant="secondary" size="sm" loading={more.busy} onClick={loadMore}>Load earlier activity</Button>}
    </Timeline>
  )
}
