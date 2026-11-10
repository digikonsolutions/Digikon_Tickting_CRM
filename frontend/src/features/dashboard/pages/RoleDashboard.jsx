import { useCallback } from 'react'
import { useAuth } from '../../../auth/useAuth'
import { ROLE_LABELS } from '../../../auth/roles'
import { QuickActions, SectionCard, StatGrid, ProjectList, WelcomeBanner } from '../components'
import { Link } from 'react-router-dom'
import { useAsync } from '../../../hooks/useAsync'
import { dashboardService } from '../services/dashboardService'

// One composable dashboard; each role page just supplies scope, copy and quick actions.
export function RoleDashboard({ scope = 'all', subtitle, actions, ticketsLink, ticketsTitle = 'Recent projects', showAgency = true }) {
  const { user } = useAuth()
  const tickets = useAsync(useCallback(() => dashboardService.getTickets({ scope, user }), [scope, user]))
  const stats = dashboardService.getStats(tickets.data)
  const firstName = user.name?.split(' ')[0] ?? 'there'

  return (
    <section className="page dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle={subtitle}>
        {ticketsLink && <Link to={ticketsLink} className="ui-button ui-button-secondary ui-button-sm">View work</Link>}
      </WelcomeBanner>
      {stats.length > 0 && <StatGrid stats={stats} isLoading={tickets.isLoading} />}
      <div className="dashboard-columns">
        <SectionCard title={ticketsTitle} description="Recently scheduled and assigned work" className="span-main">
          {tickets.error
            ? <p role="alert" className="form-error">Could not load assigned work. <button type="button" className="inline-link" onClick={tickets.reload}>Try again</button></p>
            : <ProjectList tickets={(tickets.data ?? []).slice(0, 5)} isLoading={tickets.isLoading} showAgency={showAgency} />}
        </SectionCard>
        <div className="dashboard-side">
          <SectionCard title="Quick actions"><QuickActions actions={actions} /></SectionCard>
        </div>
      </div>
    </section>
  )
}
