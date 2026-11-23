import { useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'

// Counts of urgent requests that need an admin (waiting for a quote, quoted and not yet answered). Polled gently.
export function useUrgentCounts(enabled, intervalMs = 60000) {
  const [counts, setCounts] = useState({ pending: 0, quoted: 0 })
  useEffect(() => {
    if (!enabled) return undefined
    let active = true
    const refresh = () => authService.listAdminRushRequests({ status: 'open', perPage: 1 })
      .then((response) => { if (active && response.counts) setCounts(response.counts) })
      .catch(() => {})
    refresh()
    const timer = window.setInterval(refresh, intervalMs)
    return () => { active = false; window.clearInterval(timer) }
  }, [enabled, intervalMs])
  return counts
}
