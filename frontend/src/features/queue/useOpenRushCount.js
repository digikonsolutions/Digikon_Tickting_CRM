import { useEffect, useState } from 'react'
import { authService } from '../../auth/authService'

// Announce that rush requests changed so the sidebar badge refreshes without waiting for the next poll.
export const RUSH_CHANGED_EVENT = 'rush-requests-changed'

// How many of the agency's urgent requests are still open (waiting for a quote or for an answer). Polled gently.
export function useOpenRushCount(enabled, intervalMs = 60000) {
  const [count, setCount] = useState(0)
  useEffect(() => {
    if (!enabled) return undefined
    let active = true
    const refresh = () => authService.listRushRequests()
      .then((response) => {
        if (!active) return
        setCount((response.rush_requests ?? []).filter((request) => request.status === 'pending' || request.status === 'quoted').length)
      })
      .catch(() => {})
    refresh()
    const timer = window.setInterval(refresh, intervalMs)
    window.addEventListener(RUSH_CHANGED_EVENT, refresh)
    return () => { active = false; window.clearInterval(timer); window.removeEventListener(RUSH_CHANGED_EVENT, refresh) }
  }, [enabled, intervalMs])
  return count
}
