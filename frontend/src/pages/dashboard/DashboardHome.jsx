import { useAuth } from '../../auth/useAuth'
import { ROLE_LABELS } from '../../auth/roles'

export function DashboardHome() {
  const { user } = useAuth()
  return <section className="page"><p className="eyebrow">{ROLE_LABELS[user.role]}</p><h1>Welcome, {user.name}</h1><p className="page-copy">This is the shared dashboard foundation. Add role-specific data, actions, and permissions here as backend modules become available.</p><div className="dashboard-grid"><article><h2>Role-based navigation</h2><p>Only routes intended for your role appear in the sidebar.</p></article><article><h2>Next step</h2><p>Connect the Laravel API and enforce these same permissions server-side.</p></article></div></section>
}
