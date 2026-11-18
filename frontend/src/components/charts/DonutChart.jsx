import { useId } from 'react'

export function DonutChart({
  slices = [],
  title,
  ariaLabel,
  centerLabel = 'Total',
  centerValue,
  size = 180,
  className = '',
}) {
  const chartId = useId()
  const total = slices.reduce((acc, s) => acc + (Number(s.value) || 0), 0)

  const R = 64
  const C = 2 * Math.PI * R
  const strokeWidth = 20
  const centerVal = centerValue !== undefined ? centerValue : total
  const summary = ariaLabel || `${title || 'Donut chart'}. Total: ${total}. ${slices.map((s) => `${s.label}: ${s.value}`).join(', ')}.`

  const computedSlices = slices.reduce(
    (acc, s) => {
      const prevOffset = acc.accumulated
      const val = Number(s.value) || 0
      const ratio = total > 0 ? val / total : 0
      const dashLength = ratio * C
      const dashOffset = -prevOffset * C
      const percent = Math.round(ratio * 100)
      acc.list.push({
        ...s,
        val,
        ratio,
        dashLength,
        dashOffset,
        percent,
      })
      acc.accumulated += ratio
      return acc
    },
    { list: [], accumulated: 0 }
  ).list

  return (
    <div className={`rp-chart-container rp-donut-wrap ${className}`.trim()}>
      {title && <h4 className="rp-chart-title">{title}</h4>}
      <div className="rp-donut-body">
        <svg
          className="rp-donut-svg"
          width={size}
          height={size}
          viewBox="0 0 180 180"
          role="img"
          aria-label={summary}
          aria-labelledby={title ? `${chartId}-title` : undefined}
        >
          {title && <title id={`${chartId}-title`}>{title}</title>}
          <desc>{summary}</desc>

          {/* Background circle */}
          <circle
            cx="90"
            cy="90"
            r={R}
            fill="none"
            stroke="var(--color-surface-sunken)"
            strokeWidth={strokeWidth}
          />

          {/* Slices rotated so start is at top (12 o'clock) */}
          <g transform="rotate(-90 90 90)">
            {total > 0 && computedSlices.map((slice, idx) => {
              if (slice.val <= 0) return null
              return (
                <circle
                  key={idx}
                  cx="90"
                  cy="90"
                  r={R}
                  fill="none"
                  stroke={slice.color || 'var(--brand)'}
                  strokeWidth={strokeWidth}
                  strokeDasharray={`${slice.dashLength} ${C}`}
                  strokeDashoffset={slice.dashOffset}
                  className="rp-donut-slice"
                >
                  <title>{`${slice.label}: ${slice.val} (${slice.percent}%)`}</title>
                </circle>
              )
            })}
          </g>

          {/* Center text */}
          <text
            x="90"
            y={centerLabel ? '86' : '96'}
            textAnchor="middle"
            className="rp-donut-center-val"
          >
            {centerVal}
          </text>
          {centerLabel && (
            <text
              x="90"
              y="105"
              textAnchor="middle"
              className="rp-donut-center-lbl"
            >
              {centerLabel}
            </text>
          )}
        </svg>

        {/* Legend */}
        <div className="rp-donut-legend" aria-hidden="true">
          {computedSlices.map((slice, idx) => (
            <div key={idx} className="rp-legend-item">
              <span
                className="rp-legend-dot"
                style={{ backgroundColor: slice.color || 'var(--brand)' }}
              />
              <span className="rp-legend-label">{slice.label}</span>
              <span className="rp-legend-val">
                <strong>{slice.val}</strong>
                {total > 0 && <small className="rp-legend-pct">({slice.percent}%)</small>}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Accessible data table fallback */}
      <table className="rp-sr-only">
        <caption>{summary}</caption>
        <thead>
          <tr>
            <th scope="col">Status / Category</th>
            <th scope="col">Count</th>
            <th scope="col">Percentage</th>
          </tr>
        </thead>
        <tbody>
          {computedSlices.map((slice, idx) => (
            <tr key={idx}>
              <td>{slice.label}</td>
              <td>{slice.val}</td>
              <td>{slice.percent}%</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
