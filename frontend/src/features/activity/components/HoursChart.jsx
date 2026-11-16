import { formatHours } from '../../../lib/format'

const SERIES = [
  { key: 'scheduled_hours', label: 'Scheduled', className: 'is-scheduled' },
  { key: 'logged_hours', label: 'Logged', className: 'is-logged' },
  { key: 'active_hours', label: 'Active in app', className: 'is-active' },
]

// Grouped bars (scheduled, logged, active) per day or week. The chart is a visual summary only: the
// scheduled-versus-actual table next to it carries the exact numbers, so the bars are hidden from
// assistive technology and described once.
export function HoursChart({ rows, labelOf, title }) {
  const max = Math.max(1, ...rows.flatMap((row) => SERIES.map((series) => Number(row[series.key]) || 0)))
  const total = (key) => rows.reduce((sum, row) => sum + (Number(row[key]) || 0), 0)

  return (
    <figure className="hours-chart">
      <figcaption>{title}</figcaption>
      <p className="sr-only">
        {SERIES.map((series) => `${series.label}: ${formatHours(total(series.key))}`).join('. ')}. See the table below for each day.
      </p>
      <ul className="hours-chart-legend" aria-hidden="true">
        {SERIES.map((series) => <li key={series.key}><span className={`hours-chart-swatch ${series.className}`} />{series.label}</li>)}
      </ul>
      <div className="hours-chart-scroll" aria-hidden="true">
        <div className="hours-chart-bars" style={{ '--bars': rows.length }}>
          {rows.map((row) => (
            <div className="hours-chart-group" key={row.date ?? row.week_start} title={SERIES.map((series) => `${series.label} ${formatHours(row[series.key])}`).join(', ')}>
              <div className="hours-chart-columns">
                {SERIES.map((series) => (
                  <span
                    key={series.key}
                    className={`hours-chart-bar ${series.className}`}
                    style={{ height: `${Math.max(2, ((Number(row[series.key]) || 0) / max) * 100)}%` }}
                  />
                ))}
              </div>
              <span className="hours-chart-label">{labelOf(row)}</span>
            </div>
          ))}
        </div>
      </div>
    </figure>
  )
}
