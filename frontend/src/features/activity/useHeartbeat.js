import { useEffect } from 'react'
import { authService } from '../../auth/authService'

const INTERACTION_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart', 'wheel']
const DEFAULT_INTERVAL_MS = 60_000
const DEFAULT_IDLE_MINUTES = 5

// Runs silently in the employee layout. About once a minute, while the tab is visible, it tells the
// server whether the person has interacted with the page recently. Only the time of the last
// interaction is remembered: never which key was pressed, never page content, never a screenshot.
export function useHeartbeat(enabled) {
  useEffect(() => {
    if (!enabled) return undefined

    const settings = { idleAfterMs: DEFAULT_IDLE_MINUTES * 60_000, intervalMs: DEFAULT_INTERVAL_MS }
    let timer = null
    const request = { controller: null, stopped: false, lastInteraction: Date.now() }

    const markActive = () => { request.lastInteraction = Date.now() }

    async function beat() {
      if (request.stopped || document.hidden) return
      request.controller?.abort()
      request.controller = new AbortController()
      try {
        const result = await authService.sendHeartbeat({ idle: Date.now() - request.lastInteraction >= settings.idleAfterMs, signal: request.controller.signal })
        if (result?.idle_after_minutes) settings.idleAfterMs = result.idle_after_minutes * 60_000
        if (result?.interval_seconds) settings.intervalMs = result.interval_seconds * 1000
      } catch {
        // Silent by design: a missed heartbeat must never disturb the employee's work.
      }
    }

    function schedule() {
      window.clearTimeout(timer)
      timer = window.setTimeout(async () => {
        await beat()
        if (!request.stopped) schedule()
      }, settings.intervalMs)
    }

    function onVisibility() {
      if (!document.hidden) {
        markActive()
        beat()
      }
    }

    INTERACTION_EVENTS.forEach((name) => window.addEventListener(name, markActive, { passive: true }))
    document.addEventListener('visibilitychange', onVisibility)
    const first = window.setTimeout(beat, 0)
    schedule()

    return () => {
      request.stopped = true
      window.clearTimeout(first)
      window.clearTimeout(timer)
      request.controller?.abort()
      INTERACTION_EVENTS.forEach((name) => window.removeEventListener(name, markActive))
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [enabled])
}
