import { useId } from 'react'

export function BarChart({
  labels = [],
  values = [],
  title,
  ariaLabel,
  unit = '',
  barColor = 'var(--brand)',
  height = 220,
  className = '',
}) {
  const chartId = useId()
  const n = labels.length

  const maxRaw = values.length > 0 ? Math.max(...values) : 0
  const maxVal = maxRaw <= 0 ? 1 : Math.ceil(maxRaw * 1.15)
  const summary = ariaLabel || `${title || 'Bar chart'}. Maximum: ${maxRaw}${unit ? ` ${unit}` : ''}, across ${n} periods.`

  const width = 500
  const pad = { top: 24, right: 16, bottom: 36, left: 44 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom

  const yTicks = [0, Math.round(maxVal / 2), maxVal]
  const step = n > 0 ? plotW / n : plotW
  const barWidth = Math.max(6, Math.min(32, step * 0.65))

  // If there are many items, only label every k-th item to avoid overlap
  const labelInterval = n > 14 ? Math.ceil(n / 7) : n > 8 ? 2 : 1

  return (
    <div className={`rp-chart-container ${className}`.trim()}>
      {title && <h4 className="rp-chart-title">{title}</h4>}
      <svg
        className="rp-chart-svg"
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label={summary}
        aria-labelledby={title ? `${chartId}-title` : undefined}
      >
        {title && <title id={`${chartId}-title`}>{title}</title>}
        <desc>{summary}</desc>

        {/* Y Gridlines and labels */}
        {yTicks.map((tickVal, idx) => {
          const y = pad.top + plotH - (tickVal / maxVal) * plotH
          return (
            <g key={idx} className="rp-chart-grid-group">
              <line
                x1={pad.left}
                y1={y}
                x2={width - pad.right}
                y2={y}
                stroke="var(--color-border)"
                strokeDasharray="3 3"
                strokeWidth="1"
              />
              <text
                x={pad.left - 8}
                y={y + 4}
                textAnchor="end"
                className="rp-chart-tick-label"
              >
                {tickVal}
              </text>
            </g>
          )
        })}

        {/* Bars */}
        {values.map((val, idx) => {
          const barH = maxVal > 0 ? (val / maxVal) * plotH : 0
          const x = pad.left + (idx + 0.5) * step - barWidth / 2
          const y = pad.top + plotH - barH
          const label = labels[idx] ?? `Item ${idx + 1}`
          const showLabel = idx % labelInterval === 0 || idx === n - 1

          return (
            <g key={idx} className="rp-chart-bar-group">
              <rect
                x={x}
                y={y}
                width={barWidth}
                height={Math.max(barH, 0)}
                rx="2"
                fill={barColor}
                className="rp-chart-bar"
              >
                <title>{`${label}: ${val}${unit ? ` ${unit}` : ''}`}</title>
              </rect>

              {/* Value label on top of bar if space permits */}
              {barH > 18 && (
                <text
                  x={x + barWidth / 2}
                  y={y - 5}
                  textAnchor="middle"
                  className="rp-chart-bar-val"
                >
                  {val}
                </text>
              )}

              {/* X label */}
              {showLabel && (
                <text
                  x={x + barWidth / 2}
                  y={height - pad.bottom + 16}
                  textAnchor="middle"
                  className="rp-chart-tick-label"
                >
                  {label}
                </text>
              )}
            </g>
          )
        })}

        {/* X Axis line */}
        <line
          x1={pad.left}
          y1={pad.top + plotH}
          x2={width - pad.right}
          y2={pad.top + plotH}
          stroke="var(--color-border-strong)"
          strokeWidth="1.5"
        />
      </svg>

      {/* Accessible data table fallback for screen readers */}
      <div className="rp-sr-only">
        <table>
          <caption>{summary}</caption>
          <thead>
            <tr>
              <th scope="col">Period / Category</th>
              <th scope="col">Value</th>
            </tr>
          </thead>
          <tbody>
            {labels.map((lbl, idx) => (
              <tr key={idx}>
                <td>{lbl}</td>
                <td>{values[idx] ?? 0}{unit ? ` ${unit}` : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
