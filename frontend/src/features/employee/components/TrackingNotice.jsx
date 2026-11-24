import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert } from '../../../components/ui'

const POLL_MS = 60000

/**
 * Tells the employee, plainly, whether their browser activity is being recorded right now and for which work.
 * It is only recorded while a ticket (or a project without tickets) is in progress, and only by the ClientHub browser
 * extension; when work is in progress but the extension is not connected, it says so and how to fix it.
 */
export function TrackingNotice({ refreshKey }) {
  const [status, setStatus] = useState(null)
  const load = useCallback(async () => {
    try {
      setStatus(await authService.getBrowserTrackingStatus())
    } catch {
      // Keep the last known state: this notice must never block the page.
    }
  }, [])

  useEffect(() => {
    const first = window.setTimeout(load, 0)
    const timer = window.setInterval(() => { if (!document.hidden) load() }, POLL_MS)
    return () => { window.clearTimeout(first); window.clearInterval(timer) }
  }, [load, refreshKey])

  const work = status?.active_work
  if (!work) return null
  const label = work.ticket_key ? `${work.ticket_key} ${work.title}` : work.title

  if (status.recording) {
    return (
      <Alert variant="info" title={`Browser activity is being recorded for ${label}`} className="ew-tracking" role="status">
        While this work is in progress, the ClientHub extension records the web addresses (without anything after “?” or “#”), page titles and time spent in this browser. Never page content, typing or private windows. Kept for {status.retention_days} days; administrators can read it. The extension’s switch pauses it.
      </Alert>
    )
  }

  return (
    <Alert variant="warning" title={`${label} is in progress, but browser activity is not being recorded`} className="ew-tracking" role="status">
      {status.consented
        ? 'The ClientHub browser extension is not connected in this browser. Install it, open its Settings and enter the ClientHub API address, then stay signed in here. This notice changes within a minute once it connects.'
        : 'Accept the current work activity notice first.'}
    </Alert>
  )
}
