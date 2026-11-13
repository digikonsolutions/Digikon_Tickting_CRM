import { useCallback, useEffect, useRef, useState } from 'react'
import { authService } from '../../auth/authService'

const POLL_MS = 30000
export const NOTIFICATIONS_CHANGED = 'clienthub:notifications-changed'

// Tell every mounted bell to refetch (after marking things read on the notifications page, for example).
export function announceNotificationsChanged() {
  window.dispatchEvent(new Event(NOTIFICATIONS_CHANGED))
}

// Unread count for the header bell: fetched on mount, every 30 s while the tab is visible, when the tab
// becomes visible or the window regains focus, and whenever something announces a change. A newer request
// aborts the previous one, and nothing is applied after unmount.
export function useNotifications({ pollMs = POLL_MS } = {}) {
  const [unread, setUnread] = useState(0)
  const controller = useRef(null)

  const refresh = useCallback(async () => {
    controller.current?.abort()
    const current = new AbortController()
    controller.current = current
    try {
      const { count } = await authService.getNotificationUnreadCount({ signal: current.signal })
      if (!current.signal.aborted) setUnread(Number(count) || 0)
    } catch {
      // Aborted or offline: keep the last known count.
    }
  }, [])

  useEffect(() => {
    // Fetch outside the effect body's synchronous path so state is only set after the request resolves.
    const first = window.setTimeout(refresh, 0)
    const interval = window.setInterval(() => {
      if (!document.hidden) refresh()
    }, pollMs)
    const onVisible = () => {
      if (!document.hidden) refresh()
    }
    window.addEventListener('focus', refresh)
    window.addEventListener(NOTIFICATIONS_CHANGED, refresh)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      window.clearTimeout(first)
      window.clearInterval(interval)
      window.removeEventListener('focus', refresh)
      window.removeEventListener(NOTIFICATIONS_CHANGED, refresh)
      document.removeEventListener('visibilitychange', onVisible)
      controller.current?.abort()
    }
  }, [refresh, pollMs])

  return { unread, setUnread, refresh }
}
