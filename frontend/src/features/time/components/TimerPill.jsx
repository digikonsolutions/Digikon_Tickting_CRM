import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Icon, useToast } from '../../../components/ui'
import { announceTimerChanged, formatClock, onTimerChanged } from '../timeEvents'

const RESYNC_MS = 60_000

// Header pill for the specialist's running timer. The clock ticks locally from the server's start time
// (corrected by the server/browser clock offset) and resyncs every minute, on focus, and when any
// Start/Stop button announces a change. The slot keeps a fixed width so the header never shifts.
export function TimerPill() {
  const { showToast } = useToast()
  const [entry, setEntry] = useState(null)
  const [offset, setOffset] = useState(0)
  const [now, setNow] = useState(() => Date.now())
  const [isStopping, setIsStopping] = useState(false)
  const controller = useRef(null)

  const sync = useCallback(async () => {
    controller.current?.abort()
    const current = new AbortController()
    controller.current = current
    try {
      const result = await authService.getActiveTimer({ signal: current.signal })
      if (current.signal.aborted) return
      setEntry(result.entry)
      setOffset(result.server_time ? new Date(result.server_time).getTime() - Date.now() : 0)
    } catch {
      // Keep showing the last known state; the next resync will try again.
    }
  }, [])

  useEffect(() => {
    const first = window.setTimeout(sync, 0)
    const interval = window.setInterval(() => { if (!document.hidden) sync() }, RESYNC_MS)
    const onFocus = () => sync()
    window.addEventListener('focus', onFocus)
    const unsubscribe = onTimerChanged(sync)
    return () => {
      window.clearTimeout(first)
      window.clearInterval(interval)
      window.removeEventListener('focus', onFocus)
      unsubscribe()
      controller.current?.abort()
    }
  }, [sync])

  useEffect(() => {
    if (!entry) return undefined
    const tick = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(tick)
  }, [entry])

  async function stop() {
    setIsStopping(true)
    try {
      await authService.stopTimer()
      setEntry(null)
      announceTimerChanged()
      showToast('Timer stopped and time logged.')
    } catch (error) {
      showToast(error.message, 'error')
    } finally {
      setIsStopping(false)
    }
  }

  const seconds = entry ? (now + offset - new Date(entry.started_at).getTime()) / 1000 : 0

  return (
    <div className="timer-pill-slot">
      {entry && (
        <div className="timer-pill" role="group" aria-label="Running timer">
          <Link to={`/tickets/${entry.ticket?.key}`} className="timer-pill-link" title={entry.ticket?.title}>
            <span className="timer-pill-dot" aria-hidden="true" />
            <span className="timer-pill-key">{entry.ticket?.key}</span>
            <time className="timer-pill-clock">{formatClock(seconds)}</time>
          </Link>
          <button type="button" className="timer-pill-stop" onClick={stop} disabled={isStopping} aria-label={`Stop timer on ${entry.ticket?.key}`} title="Stop timer">
            <Icon name="stop" size={14} />
          </button>
        </div>
      )}
    </div>
  )
}
