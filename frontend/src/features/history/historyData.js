import { addDays, todayStr } from '../../lib/format'

export const PRESETS = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: 'custom', label: 'Custom range' },
]

export const EVENT_LABELS = {
  tab_opened: 'Tab opened',
  tab_closed: 'Tab closed',
  tab_activated: 'Tab switched',
  navigated: 'Page visit',
}

export const EVENT_TYPE_OPTIONS = [['', 'All activity'], ...Object.entries(EVENT_LABELS)]

export const EVENT_ICONS = {
  tab_opened: 'plus',
  tab_closed: 'close',
  tab_activated: 'arrow',
  navigated: 'info',
}

// The date range for a preset. `custom` keeps whatever the person typed.
export function rangeFor(preset, custom) {
  const today = todayStr()
  switch (preset) {
    case 'today': return { from: today, to: today }
    case 'yesterday': return { from: addDays(today, -1), to: addDays(today, -1) }
    case '7d': return { from: addDays(today, -6), to: today }
    case '30d': return { from: addDays(today, -29), to: today }
    default: return { from: custom.from, to: custom.to }
  }
}

// "1h 05m", "4m 10s", "35s" — or an em dash when the duration was not measurable.
export function formatDuration(seconds) {
  const total = Number(seconds)
  if (!Number.isFinite(total) || total <= 0) return '—'
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const rest = Math.floor(total % 60)
  if (hours) return `${hours}h ${String(minutes).padStart(2, '0')}m`
  if (minutes) return `${minutes}m ${String(rest).padStart(2, '0')}s`
  return `${rest}s`
}

const dayKey = (iso) => {
  const date = new Date(iso)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

// Groups already newest-first events by local calendar day, keeping their order.
export function groupByDay(events) {
  const groups = []
  for (const event of events) {
    const key = dayKey(event.occurred_at)
    const last = groups[groups.length - 1]
    if (last && last.key === key) last.events.push(event)
    else groups.push({ key, events: [event] })
  }
  return groups
}

export function formatClock(iso) {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(date)
}

// Only an https favicon the extension reported is ever used; anything else shows the first letter.
export function safeFavicon(value) {
  return typeof value === 'string' && value.startsWith('https://') ? value : null
}
