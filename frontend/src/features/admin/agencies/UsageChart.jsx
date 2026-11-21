import { formatDate } from '../../../lib/format'

// A small SVG bar chart of hours per day (the last 14 days). Bars are teal, the busiest day is labelled, and the
// whole chart has a text description plus a hidden table so screen readers get the same numbers.
export function UsageChart({ days, label = 'Hours booked per day' }) {
  const width = 420
  const height = 120
  const pad = { top: 14, bottom: 22, left: 6, right: 6 }
  const max = Math.max(...days.map((day) => Number(day.hours) || 0), 0)
  const total = days.reduce((sum, day) => sum + (Number(day.hours) || 0), 0)
  const slot = (width - pad.left - pad.right) / Math.max(days.length, 1)
  const barWidth = Math.max(4, slot - 6)
  const peak = days.reduce((best, day) => (Number(day.hours) > Number(best?.hours ?? -1) ? day : best), null)
  const description = max === 0
    ? `${label}: nothing booked in this period.`
    : `${label}: ${total.toFixed(1)} hours in total, busiest day ${formatDate(peak.date)} with ${Number(peak.hours).toFixed(1)} hours.`

  return (
    <figure className="usage-chart">
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={description} preserveAspectRatio="none">
        <line x1={pad.left} x2={width - pad.right} y1={height - pad.bottom} y2={height - pad.bottom} className="usage-axis" />
        {days.map((day, index) => {
          const hours = Number(day.hours) || 0
          const barHeight = max > 0 ? (hours / max) * (height - pad.top - pad.bottom) : 0
          const x = pad.left + index * slot + (slot - barWidth) / 2
          return (
            <g key={day.date}>
              <rect x={x} y={height - pad.bottom - barHeight} width={barWidth} height={Math.max(barHeight, hours > 0 ? 2 : 0)} rx="2" className="usage-bar">
                <title>{`${formatDate(day.date)}: ${hours.toFixed(1)} h`}</title>
              </rect>
              {peak && day.date === peak.date && hours > 0 && <text x={x + barWidth / 2} y={height - pad.bottom - barHeight - 4} textAnchor="middle" className="usage-peak">{hours.toFixed(1)}</text>}
            </g>
          )
        })}
        {days.length > 0 && (
          <>
            <text x={pad.left} y={height - 6} className="usage-tick">{formatDate(days[0].date)}</text>
            <text x={width - pad.right} y={height - 6} textAnchor="end" className="usage-tick">{formatDate(days[days.length - 1].date)}</text>
          </>
        )}
      </svg>
      <table className="sr-only">
        <caption>{label}</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Hours</th></tr></thead>
        <tbody>{days.map((day) => <tr key={day.date}><td>{day.date}</td><td>{Number(day.hours).toFixed(1)}</td></tr>)}</tbody>
      </table>
    </figure>
  )
}
