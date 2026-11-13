import { useMemo } from 'react'

const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

const UNITS = [
  { unit: 'year', seconds: 31536000 },
  { unit: 'month', seconds: 2592000 },
  { unit: 'week', seconds: 604800 },
  { unit: 'day', seconds: 86400 },
  { unit: 'hour', seconds: 3600 },
  { unit: 'minute', seconds: 60 },
  { unit: 'second', seconds: 1 },
]

function formatRelative(dateInput) {
  if (!dateInput) return ''
  const date = dateInput instanceof Date ? dateInput : new Date(dateInput)
  if (Number.isNaN(date.getTime())) return ''

  const diffInSeconds = Math.round((date.getTime() - Date.now()) / 1000)
  const absDiff = Math.abs(diffInSeconds)

  if (absDiff < 30) return 'just now'

  for (const { unit, seconds } of UNITS) {
    if (absDiff >= seconds || unit === 'second') {
      const value = Math.round(diffInSeconds / seconds)
      return rtf.format(value, unit)
    }
  }

  return 'just now'
}

export function RelativeTime({ date, className = '' }) {
  const parsed = useMemo(() => (date ? new Date(date) : null), [date])
  if (!parsed || Number.isNaN(parsed.getTime())) return null

  const relative = formatRelative(parsed)
  const absolute = parsed.toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  })

  return (
    <time
      dateTime={parsed.toISOString()}
      title={absolute}
      className={`ui-relative-time ${className}`.trim()}
    >
      {relative}
    </time>
  )
}
