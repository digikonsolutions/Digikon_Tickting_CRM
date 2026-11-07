import { EmptyState, Spinner } from '../../../components/ui'

export function ActivityFeed({ items, isLoading }) {
  if (isLoading) return <div className="ticket-loading"><Spinner size="lg" /></div>
  if (!items?.length) return <EmptyState title="No recent activity">Updates will appear here.</EmptyState>
  return (
    <ol className="activity-feed">
      {items.map((item) => (
        <li key={item.id}>
          <span className={`activity-dot activity-${item.tone}`} aria-hidden="true" />
          <div><p>{item.text}</p><time>{item.time}</time></div>
        </li>
      ))}
    </ol>
  )
}
