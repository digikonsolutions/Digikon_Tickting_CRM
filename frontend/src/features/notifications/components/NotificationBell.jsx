import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Button, Icon, Skeleton } from '../../../components/ui'
import { announceNotificationsChanged, useNotifications } from '../useNotifications'
import { NotificationItem } from './NotificationItem'

const DROPDOWN_SIZE = 8

// Header bell: unread badge, and a dropdown with the latest notifications. Escape closes it and returns
// focus to the bell; arrow keys move between items; a click outside closes it.
export function NotificationBell() {
  const navigate = useNavigate()
  const { unread, setUnread, refresh } = useNotifications()
  const [open, setOpen] = useState(false)
  const [state, setState] = useState({ items: [], isLoading: false, error: null })
  const bellRef = useRef(null)
  const panelRef = useRef(null)

  const load = useCallback(async (signal) => {
    setState((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const page = await authService.getNotifications({ signal })
      if (signal.aborted) return
      setState({ items: page.notifications.slice(0, DROPDOWN_SIZE), isLoading: false, error: null })
      setUnread(page.unread_count)
    } catch (error) {
      if (!signal.aborted) setState((current) => ({ ...current, isLoading: false, error }))
    }
  }, [setUnread])

  useEffect(() => {
    if (!open) return undefined
    const controller = new AbortController()
    const first = window.setTimeout(() => load(controller.signal), 0)

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        bellRef.current?.focus()
      }
    }
    function onPointerDown(event) {
      if (!panelRef.current?.contains(event.target) && !bellRef.current?.contains(event.target)) setOpen(false)
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      window.clearTimeout(first)
      controller.abort()
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open, load])

  async function openNotification(notification) {
    setOpen(false)
    if (!notification.read_at) {
      authService.markNotificationRead(notification.id).then(announceNotificationsChanged).catch(() => {})
    }
    navigate(notification.data.url_path ?? '/notifications')
  }

  async function markAllRead() {
    try {
      await authService.markAllNotificationsRead()
      setState((current) => ({ ...current, items: current.items.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })) }))
      setUnread(0)
      announceNotificationsChanged()
    } catch {
      refresh()
    }
  }

  function onPanelKeyDown(event) {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return
    const items = [...panelRef.current.querySelectorAll('[role="menuitem"]')]
    if (!items.length) return
    event.preventDefault()
    const index = items.indexOf(document.activeElement)
    const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length
    items[next].focus()
  }

  const badge = unread > 99 ? '99+' : String(unread)

  return (
    <div className="notification-bell">
      <button
        ref={bellRef}
        type="button"
        className="header-icon-button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-controls="notification-panel"
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        onClick={() => setOpen((value) => !value)}
      >
        <Icon name="bell" size={19} />
        {unread > 0 && <span className="notification-badge" aria-hidden="true">{badge}</span>}
      </button>

      {open && (
        <div id="notification-panel" ref={panelRef} className="notification-panel" role="menu" aria-label="Latest notifications" onKeyDown={onPanelKeyDown}>
          <div className="notification-panel-header">
            <strong>Notifications</strong>
            <Link to="/settings/notifications" onClick={() => setOpen(false)}>Settings</Link>
          </div>
          <div className="notification-panel-body">
            {state.isLoading && !state.items.length && <div role="status" aria-label="Loading notifications"><Skeleton variant="row" /><Skeleton variant="row" /></div>}
            {state.error && <p className="form-error" role="alert">Could not load notifications.</p>}
            {!state.isLoading && !state.error && !state.items.length && <p className="notification-empty">You are all caught up.</p>}
            {state.items.map((notification) => (
              <NotificationItem key={notification.id} notification={notification} onOpen={openNotification} />
            ))}
          </div>
          <div className="notification-panel-footer">
            <Button type="button" variant="ghost" size="sm" onClick={markAllRead} disabled={unread === 0}>Mark all read</Button>
            <Link className="ui-button ui-button-secondary ui-button-sm" to="/notifications" onClick={() => setOpen(false)}>View all</Link>
          </div>
        </div>
      )}
    </div>
  )
}
