import { useId } from 'react'

export function LineChart({
  labels = [],
  values = [],
  title,
  ariaLabel,
  unit = 'h',
  strokeColor = 'var(--brand)',
  height = 220,
  className = '',
}) {
  const chartId = useId()
  const n = labels.length

  const maxRaw = values.length > 0 ? Math.max(...values) : 0
  const maxVal = maxRaw <= 0 ? 1 : Math.ceil(maxRaw * 1.15)
  const summary = ariaLabel || `${title || 'Line chart'}. Peak: ${maxRaw}${unit ? ` ${unit}` : ''}, across ${n} data points.`

  const width = 500
  const pad = { top: 24, right: 20, bottom: 36, left: 44 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom

  const yTicks = [0, Math.round(maxVal / 2), maxVal]
  const step = n > 1 ? plotW / (n - 1) : plotW

  const points = values.map((val, idx) => {
    const x = pad.left + idx * step
    const y = pad.top + plotH - (maxVal > 0 ? (val / maxVal) * plotH : 0)
    return { x, y, val, label: labels[idx] ?? `${idx + 1}` }
  })

  // Build SVG path strings
  let pathD = ''
  let areaD = ''
  if (points.length > 0) {
    pathD = `M ${points[0].x} ${points[0].y} ` + points.slice(1).map((p) => `L ${p.x} ${p.y}`).join(' ')
    areaD = `${pathD} L ${points[points.length - 1].x} ${pad.top + plotH} L ${points[0].x} ${pad.top + plotH} Z`
  }

  // Label interval
  const labelInterval = n > 14 ? Math.ceil(n / 6) : n > 7 ? 2 : 1

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

        <defs>
          <linearGradient id={`${chartId}-gradient`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={strokeColor} stopOpacity="0.25" />
            <stop offset="100%" stopColor={strokeColor} stopOpacity="0.02" />
          </linearGradient>
        </defs>

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

        {/* Area fill */}
        {areaD && (
          <path
            d={areaD}
            fill={`url(#${chartId}-gradient)`}
            className="rp-chart-area"
          />
        )}

        {/* Line */}
        {pathD && (
          <path
            d={pathD}
            fill="none"
            stroke={strokeColor}
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="rp-chart-line"
          />
        )}

        {/* Data points */}
        {points.map((pt, idx) => {
          const showLabel = idx % labelInterval === 0 || idx === n - 1
          return (
            <g key={idx} className="rp-chart-point-group">
              <circle
                cx={pt.x}
                cy={pt.y}
                r="3.5"
                fill="var(--color-white)"
                stroke={strokeColor}
                strokeWidth="2"
                className="rp-chart-dot"
              >
                <title>{`${pt.label}: ${pt.val}${unit ? ` ${unit}` : ''}`}</title>
              </circle>

              {/* X label */}
              {showLabel && (
                <text
                  x={pt.x}
                  y={height - pad.bottom + 16}
                  textAnchor="middle"
                  className="rp-chart-tick-label"
                >
                  {pt.label.length > 5 ? pt.label.slice(5) : pt.label}
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
      <table className="rp-sr-only">
        <caption>{summary}</caption>
        <thead>
          <tr>
            <th scope="col">Date / Period</th>
            <th scope="col">Value</th>
          </tr>
        </thead>
        <tbody>
          {points.map((pt, idx) => (
            <tr key={idx}>
              <td>{pt.label}</td>
              <td>{pt.val}{unit ? ` ${unit}` : ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
