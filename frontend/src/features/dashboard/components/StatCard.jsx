import { KpiCard } from '../../../components/ui'

// Dashboard figures use the shared KpiCard so every screen shows numbers the same way.
const TONES = { primary: 'teal' }

export function StatCard({ label, value, hint, icon = 'dashboard', tone = 'primary' }) {
  return <KpiCard title={label} value={value} subtext={hint} icon={icon} tone={TONES[tone] ?? tone} />
}

export function StatGrid({ stats = [], isLoading = false }) {
  if (isLoading) {
    return <div className="dashboard-kpis" aria-busy="true">{[0, 1, 2, 3].map((i) => <div key={i} className="ui-skeleton ui-skeleton-card" />)}</div>
  }
  return <div className="dashboard-kpis">{stats.map((stat) => <StatCard key={stat.id} {...stat} />)}</div>
}