import { DEFAULT_CAPACITY } from '../../lib/constants'

export function CapacityBar({
  used = 0,
  budget = DEFAULT_CAPACITY.weekly_hours,
  label,
  remaining,
  showRemaining = true,
  // 'neutral' | 'ok' | 'warm' | 'hot' recolours the fill by load. Omitted keeps the original teal.
  tone,
  // Accessible name for the bar when `label` (which is also printed) should not be.
  ariaLabel,
  className = '',
  ...props
}) {
  const numericUsed = Number(used) || 0
  const numericBudget = Number(budget) || DEFAULT_CAPACITY.weekly_hours
  const numericRemaining = remaining !== undefined ? Number(remaining) : Math.max(0, numericBudget - numericUsed)
  const percent = numericBudget > 0 ? Math.min(100, Math.max(0, (numericUsed / numericBudget) * 100)) : 0

  return (
    <div className={`ui-capacity-bar${tone ? ` ui-capacity-${tone}` : ''} ${className}`.trim()} {...props}>
      <div className="ui-capacity-meta">
        <span className="ui-capacity-used">
          <strong>{numericUsed.toFixed(numericUsed % 1 === 0 ? 0 : 1)}</strong> / {numericBudget} h
          {label && <small className="ui-capacity-label"> · {label}</small>}
        </span>
        {showRemaining && (
          <span className="ui-capacity-remaining">
            {numericRemaining.toFixed(numericRemaining % 1 === 0 ? 0 : 1)} h remaining
          </span>
        )}
      </div>
      <div
        className="ui-capacity-track"
        role="progressbar"
        aria-label={ariaLabel ?? label ?? 'Capacity usage'}
        aria-valuetext={`${numericUsed} of ${numericBudget} hours`}
        aria-valuemin="0"
        aria-valuemax={numericBudget}
        aria-valuenow={numericUsed}
      >
        <span
          className="ui-capacity-fill"
          style={{ width: `${percent}%` }}
        />
      </div>
    </div>
  )
}
