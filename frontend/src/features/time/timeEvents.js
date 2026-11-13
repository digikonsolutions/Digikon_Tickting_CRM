import { useEffect, useRef } from 'react'

// Tells the header timer pill (and anything else listening) that the running timer changed,
// so it refetches straight away instead of waiting for its minute-long resync.
const EVENT = 'clienthub:timer-changed'

export function announceTimerChanged() {
  window.dispatchEvent(new Event(EVENT))
}

export function onTimerChanged(callback) {
  window.addEventListener(EVENT, callback)
  return () => window.removeEventListener(EVENT, callback)
}

// Runs callback whenever any timer is started or stopped (use once per page, not per button).
export function useTimerChanged(callback) {
  const ref = useRef(callback)
  useEffect(() => { ref.current = callback })
  useEffect(() => onTimerChanged(() => ref.current?.()), [])
}

export function formatClock(totalSeconds) {
  const seconds = Math.max(0, Math.floor(totalSeconds))
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
}

export function formatMinutes(minutes) {
  const total = Math.round(Number(minutes) || 0)
  const hours = Math.floor(total / 60)
  const rest = total % 60
  if (!hours) return `${rest} min`
  return rest ? `${hours} h ${rest} min` : `${hours} h`
}

// Statuses in which a timer can run. The server enforces this too.
export const TIMER_STATUSES = ['in_progress', 'changes_requested']
