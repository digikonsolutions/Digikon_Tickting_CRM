export function SlaOverview({ tickets = [] }) {
  const open = tickets.filter((t) => t.status !== 'Resolved' && t.status !== 'Closed')
  const groups = [
    { key: 'On Track', tone: 'success' },
    { key: 'At Risk', tone: 'warning' },
    { key: 'Breached', tone: 'danger' },
    { key: 'Paused', tone: 'muted' },
  ].map((g) => ({ ...g, count: open.filter((t) => t.sla === g.key).length }))
  const total = open.length || 1
  return (
    <div className="sla-overview">
      <div className="sla-bar" role="img" aria-label="SLA status distribution of open tickets">
        {groups.map((g) => g.count > 0 && <span key={g.key} className={`sla-seg sla-${g.tone}`} style={{ width: `${(g.count / total) * 100}%` }} />)}
      </div>
      <ul className="sla-legend">
        {groups.map((g) => <li key={g.key}><span className={`sla-swatch sla-${g.tone}`} />{g.key}<strong>{g.count}</strong></li>)}
      </ul>
    </div>
  )
}
