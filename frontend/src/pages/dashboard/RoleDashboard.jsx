import { useCallback } from 'react'
import { useAuth } from '../../auth/useAuth'
import { ROLE_LABELS } from '../../auth/roles'
import { ActivityFeed, QuickActions, SectionCard, SlaOverview, StatGrid, TicketList, WelcomeBanner } from '../../components/dashboard'
import { Link } from 'react-router-dom'
import { useAsync } from '../../hooks/useAsync'
import { dashboardService } from '../../services/dashboardService'

// One composable dashboard; each role page just supplies scope, copy and quick actions.
export function RoleDashboard({ scope = 'all', subtitle, actions, ticketsLink, ticketsTitle = 'Recent tickets', showAgency = true, showSla = true }) {
  const { user } = useAuth()
  const stats = useAsync(useCallback(() => dashboardService.getStats(user.role), [user.role]))
  const tickets = useAsync(useCallback(() => dashboardService.getTickets({ scope }), [scope]))
  const activity = useAsync(useCallback(() => dashboardService.getActivity(), []))
  const firstName = user.name?.split(' ')[0] ?? 'there'

  return (
    <section className="page dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle={subtitle}>
        {ticketsLink && <Link to={ticketsLink} className="ui-button ui-button-secondary ui-button-sm">Open tickets</Link>}
      </WelcomeBanner>
      <StatGrid stats={stats.data ?? []} isLoading={stats.isLoading} />
      <div className="dashboard-columns">
        <SectionCard title={ticketsTitle} description="Latest updates across your work" className="span-main">
          <TicketList tickets={(tickets.data ?? []).slice(0, 5)} isLoading={tickets.isLoading} showAgency={showAgency} />
        </SectionCard>
        <div className="dashboard-side">
          <SectionCard title="Quick actions"><QuickActions actions={actions} /></SectionCard>
          {showSla && <SectionCard title="SLA health" description="Open tickets by status"><SlaOverview tickets={tickets.data ?? []} /></SectionCard>}
          <SectionCard title="Recent activity"><ActivityFeed items={activity.data} isLoading={activity.isLoading} /></SectionCard>
        </div>
      </div>
    </section>
  )
}
