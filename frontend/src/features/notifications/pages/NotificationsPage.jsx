import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, EmptyState, PageHeader, Skeleton, Tabs } from '../../../components/ui'
import { NotificationItem } from '../components/NotificationItem'
import { announceNotificationsChanged } from '../useNotifications'

const TABS = [
  { id: 'all', label: 'All' },
  { id: 'unread', label: 'Unread' },
]

// Full notification history (20 per page, cursor paginated) with All and Unread tabs.
export function NotificationsPage() {
  const navigate = useNavigate()
  const [tab, setTab] = useState('all')
  const [state, setState] = useState({ items: [], cursor: null, unread: 0, isLoading: true, isLoadingMore: false, error: null })
  const requestId = useRef(0)

  const load = useCallback(async (which, cursor = null) => {
    const id = ++requestId.current
    setState((current) => ({ ...current, isLoading: !cursor, isLoadingMore: Boolean(cursor), error: null }))
    try {
      const page = await authService.getNotifications({ unread: which === 'unread', cursor })
      if (id !== requestId.current) return
      setState((current) => ({
        items: cursor ? [...current.items, ...page.notifications] : page.notifications,
        cursor: page.next_cursor,
        unread: page.unread_count,
        isLoading: false,
        isLoadingMore: false,
        error: null,
      }))
    } catch (error) {
      if (id === requestId.current) setState((current) => ({ ...current, isLoading: false, isLoadingMore: false, error }))
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => load(tab), 0)
    return () => window.clearTimeout(timer)
  }, [tab, load])

  async function open(notification) {
    if (!notification.read_at) {
      authService.markNotificationRead(notification.id).then(announceNotificationsChanged).catch(() => {})
    }
    navigate(notification.data.url_path ?? '/notifications')
  }

  async function markAllRead() {
    try {
      await authService.markAllNotificationsRead()
      announceNotificationsChanged()
      await load(tab)
    } catch (error) {
      setState((current) => ({ ...current, error }))
    }
  }

  return (
    <section className="page notifications-page">
      <PageHeader
        eyebrow="Account"
        title="Notifications"
        subtitle="Everything that needed your attention on your tickets."
        action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/settings/notifications">Notification settings</Link>}
      />
      <Tabs tabs={[TABS[0], { ...TABS[1], count: state.unread }]} activeTab={tab} onChange={setTab} />
      <Card>
        <div className="notifications-toolbar">
          <span>{state.unread} unread</span>
          <Button type="button" variant="ghost" size="sm" onClick={markAllRead} disabled={state.unread === 0}>Mark all read</Button>
        </div>
        {state.error && <Alert variant="error">{state.error.message} <Button type="button" size="sm" variant="secondary" onClick={() => load(tab)}>Try again</Button></Alert>}
        {state.isLoading
          ? <div role="status" aria-label="Loading notifications"><Skeleton variant="row" /><Skeleton variant="row" /><Skeleton variant="row" /></div>
          : state.items.length
            ? (
              <div className="notification-list" role="menu" aria-label="Notifications">
                {state.items.map((notification) => <NotificationItem key={notification.id} notification={notification} onOpen={open} />)}
              </div>
            )
            : <EmptyState title={tab === 'unread' ? 'No unread notifications' : 'No notifications yet'}>When something needs your attention it will show up here.</EmptyState>}
        {state.cursor && (
          <div className="notifications-more">
            <Button type="button" variant="secondary" loading={state.isLoadingMore} onClick={() => load(tab, state.cursor)}>Load more</Button>
          </div>
        )}
      </Card>
    </section>
  )
}
