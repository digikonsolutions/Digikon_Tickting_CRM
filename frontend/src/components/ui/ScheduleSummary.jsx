import { formatDate, formatHours, formatShortDate } from '../../lib/format'

const DAY_LETTERS = ['S', 'M', 'T', 'W', 'T', 'F', 'S']

function dayLetter(dateStr) {
  const [year, month, day] = String(dateStr).split('-').map(Number)
  return DAY_LETTERS[new Date(year, month - 1, day).getDay()]
}

// A schedule (`[{work_date: 'YYYY-MM-DD', hours}]`) in one glance: the date range and total on the first
// line, one small bar per scheduled day below it (taller means more hours), and the exact list of days
// behind an expandable "Daily hours" disclosure.
export function ScheduleSummary({ schedule = [], className = '' }) {
  const days = [...schedule]
    .map((item) => ({ date: item.work_date, hours: Number(item.hours) || 0 }))
    .sort((a, b) => a.date.localeCompare(b.date))

  if (!days.length) return <p className={`ui-schedule-empty ${className}`.trim()}>Not scheduled yet</p>

  const total = days.reduce((sum, day) => sum + day.hours, 0)
  const max = Math.max(...days.map((day) => day.hours), 1)
  const first = days[0].date
  const last = days[days.length - 1].date
  const range = first === last ? formatShortDate(first) : `${formatShortDate(first)} – ${formatShortDate(last)}`

  return (
    <div className={`ui-schedule ${className}`.trim()}>
      <p className="ui-schedule-line">
        <strong>{range}</strong>
        <span>{formatHours(total)} over {days.length} day{days.length === 1 ? '' : 's'}</span>
      </p>
      <ol className="ui-schedule-bars" aria-hidden="true">
        {days.map((day) => (
          <li key={day.date} title={`${formatDate(day.date)}: ${formatHours(day.hours)}`}>
            <span className="ui-schedule-bar" style={{ height: `${Math.max(12, (day.hours / max) * 100)}%` }} />
            <span className="ui-schedule-letter">{dayLetter(day.date)}</span>
          </li>
        ))}
      </ol>
      <details className="ui-schedule-details">
        <summary>Daily hours</summary>
        <ul>
          {days.map((day) => (
            <li key={day.date}><span>{formatDate(day.date)}</span><strong>{formatHours(day.hours)}</strong></li>
          ))}
        </ul>
      </details>
    </div>
  )
}