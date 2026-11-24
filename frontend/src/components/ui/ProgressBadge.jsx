// "3/5" or "62%": a small pill with a fill behind the text. Pass `done`/`total`, or `percent`.
export function ProgressBadge({ done, total, percent, label }) {
  const pct = percent ?? (total > 0 ? Math.round((done / total) * 100) : 0)
  const text = percent !== undefined ? `${percent}%` : `${done}/${total}`
  return (
    <span className="ui-progress-badge" role="img" aria-label={label ? `${label}: ${text}` : text} style={{ '--progress': `${Math.min(100, Math.max(0, pct))}%` }}>
      <span aria-hidden="true">{label ? `${label} ${text}` : text}</span>
    </span>
  )
}
