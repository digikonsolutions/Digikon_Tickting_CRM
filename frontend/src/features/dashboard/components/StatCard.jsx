import { Icon } from '../../../components/ui'

export function StatCard({ label, value, hint, icon = 'dashboard', tone = 'primary' }) {
  return (
    <article className="stat-card">
      <span className={`stat-icon stat-icon-${tone}`}><Icon name={icon} size={22} /></span>
      <div>
        <p className="stat-label">{label}</p>
        <p className="stat-value">{value}</p>
        {hint && <p className="stat-hint">{hint}</p>}
      </div>
    </article>
  )
}

export function StatGrid({ stats = [], isLoading = false }) {
  if (isLoading) {
    return <div className="stat-grid" aria-busy="true">{[0, 1, 2, 3].map((i) => <div key={i} className="stat-card skeleton" />)}</div>
  }
  return <div className="stat-grid">{stats.map((stat) => <StatCard key={stat.id} {...stat} />)}</div>
}
