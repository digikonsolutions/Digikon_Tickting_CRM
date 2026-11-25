import { useCallback } from 'react'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { Alert, Button, EmptyState, PageHeader, Skeleton } from '../../components/ui'
import { FeedbackList } from './FeedbackList'
import { FeedbackSummary } from './FeedbackSummary'
import './feedback.css'

// What agencies said about the signed-in employee: the averages, each piece of feedback, and one reply to each.
export function MyFeedbackPage() {
  const load = useCallback(() => authService.getMyFeedback(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  return (
    <section className="page">
      <PageHeader eyebrow="My work" title="My feedback" subtitle="What the agencies you work with said about your work. You can reply to each one once." />
      {error && <Alert variant="error" title="Could not load your feedback">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>}
      {isLoading && !data && <div role="status" aria-label="Loading feedback"><Skeleton variant="card" /><Skeleton variant="row" /></div>}
      {data && data.feedback.length === 0 && <EmptyState title="No feedback yet">When an agency shares feedback about your work, it appears here and you are notified.</EmptyState>}
      {data && data.feedback.length > 0 && (
        <div className="fb-panel">
          <FeedbackSummary summary={data.summary} title="All agencies" />
          <FeedbackList items={data.feedback} showAgency canReply onChanged={reload} />
        </div>
      )}
    </section>
  )
}
