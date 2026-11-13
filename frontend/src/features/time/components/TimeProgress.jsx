import { formatHours } from '../../../lib/format'

// Logged versus estimated hours. Amber above 90 %, "Over estimate" above 100 %.
// Renders nothing useful without an estimate beyond the logged figure.
export function TimeProgress({ logged = 0, estimated = 0, compact = false }) {
  const loggedHours = Number(logged) || 0
  const estimate = Number(estimated) || 0
  const percent = estimate > 0 ? Math.round((loggedHours / estimate) * 100) : null
  const over = percent !== null && loggedHours > estimate
  const tone = over ? 'over' : percent !== null && percent > 90 ? 'warn' : 'ok'

  return (
    <div className={`time-progress time-progress-${tone}${compact ? ' is-compact' : ''}`}>
      <div className="time-progress-text">
        <span>{formatHours(loggedHours)} of {formatHours(estimate)}</span>
        {over && <span className="time-progress-badge">Over estimate</span>}
      </div>
      {percent !== null && (
        <div
          className="time-progress-track"
          role="progressbar"
          aria-label="Time logged against estimate"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={Math.min(percent, 100)}
          aria-valuetext={`${percent}% of the estimate`}
        >
          <div className="time-progress-fill" style={{ width: `${Math.min(percent, 100)}%` }} />
        </div>
      )}
    </div>
  )
}
