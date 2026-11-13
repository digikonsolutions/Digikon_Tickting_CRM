import { RelativeTime } from '../../../components/ui'

// One notification row. `onOpen(notification)` marks it read and navigates to its ticket.
export function NotificationItem({ notification, onOpen, itemRef }) {
  const { data, read_at: readAt, created_at: createdAt } = notification
  const count = Number(data.count) || 1

  return (
    <button
      type="button"
      ref={itemRef}
      role="menuitem"
      className={`notification-item${readAt ? '' : ' is-unread'}`}
      onClick={() => onOpen(notification)}
    >
      <span className="notification-dot" aria-hidden="true" />
      <span className="notification-main">
        <span className="notification-title">
          {data.ticket_key && <span className="notification-key">{data.ticket_key}</span>}
          {data.title}
          {count > 1 && <span className="notification-count" aria-label={`${count} updates`}>×{count}</span>}
        </span>
        <span className="notification-body">{data.body}</span>
        <RelativeTime date={createdAt} className="notification-time" />
      </span>
      {!readAt && <span className="sr-only">Unread</span>}
    </button>
  )
}
