import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLES, ROLE_LABELS } from '../../../../../auth/roles'
import { Alert, Button } from '../../../../../components/ui'
import { RequestModal } from '../../../../agency/components/RequestModal'
import { WelcomeBanner } from '../../../components'
import { NeedsAttentionCard } from '../../../../tickets'
import { EmployeeProfileDrawer } from '../../../../agency/components/EmployeeProfileDrawer'
import { useAsync } from '../../../../../hooks/useAsync'
import { AttentionCard } from '../admin/AdminDashboardCards'
import {
  AgencyDashboardSkeleton, AgencyKpiRow, AgencyQuickActions, ContractCard, ContractStrip, EmployeesSection, HoursUsageCard,
  LimitWarnings, RecentProjectsCard,
} from './AgencyDashboardCards'
import { limitState } from './agencyLimits'
import { OwnerDashboard } from './OwnerDashboard'
import './agencyDashboard.css'

// Legacy agency members only get a welcome card and the directory: they have no projects or holds.
function AgencyMemberDashboard() {
  const { user } = useAuth()
  const firstName = user.name?.split(' ')[0] ?? 'there'

  return (
    <section className="page dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Find the people in your agency.">
        <Link to="/agency/directory" className="ui-button ui-button-secondary ui-button-sm">Agency directory</Link>
      </WelcomeBanner>
    </section>
  )
}

// Owner and client dashboard: contract and limits, what needs attention, employees, hours and recent work.
function AgencyWorkspaceDashboard() {
  const { user } = useAuth()
  const load = useCallback(() => authService.getAgencyDashboard(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const [requestType, setRequestType] = useState(null)
  const [profileId, setProfileId] = useState(null)
  const dashboard = data?.dashboard
  const firstName = user.name?.split(' ')[0] ?? 'there'

  if (!dashboard) {
    return (
      <section className="page dashboard agency-dashboard">
        <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Your contract, employees and hours at a glance." />
        {error
          ? (
            <Alert variant="error" title="Could not load the dashboard">
              {error.message}
              <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
            </Alert>
          )
          : isLoading && <AgencyDashboardSkeleton />}
      </section>
    )
  }

  const state = limitState(dashboard)
  const { isOwner } = state

  return (
    <section className="page dashboard agency-dashboard">
      <WelcomeBanner eyebrow={dashboard.agency.name} title={`Welcome back, ${firstName}`} subtitle="Your contract, employees and hours at a glance.">
        {state.holdDisabled
          ? <Button type="button" variant="secondary" size="sm" disabled aria-describedby="agd-hold-reason" title={state.holdReason}>Hold employee</Button>
          : <Link to="/agency/employees?hold=1" className="ui-button ui-button-secondary ui-button-sm">Hold employee</Link>}
        <Button type="button" variant="secondary" size="sm" onClick={() => setRequestType('more_hours')}>Request more hours</Button>
      </WelcomeBanner>
      {state.holdDisabled && <p id="agd-hold-reason" className="agd-reason">{state.holdReason}</p>}
      {error && (
        <Alert variant="warning" title="Could not refresh">
          Showing the last data we loaded. {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      <LimitWarnings dashboard={dashboard} onRequest={setRequestType} />
      {(isOwner || dashboard.contract) && <ContractStrip dashboard={dashboard} />}
      <AgencyKpiRow dashboard={dashboard} />

      <div className="adm-cols">
        <div className="adm-col">
          <AttentionCard items={dashboard.attention} emptyText="Your projects and plan are on track." />
          <NeedsAttentionCard />
          <EmployeesSection dashboard={dashboard} onProfile={setProfileId} holdDisabled={state.holdDisabled} holdReason={state.holdReason} />
          <RecentProjectsCard dashboard={dashboard} />
        </div>
        <div className="adm-col">
          {isOwner && <ContractCard dashboard={dashboard} onRequest={setRequestType} />}
          {isOwner && <HoursUsageCard dashboard={dashboard} onRequest={setRequestType} />}
          <AgencyQuickActions dashboard={dashboard} />
        </div>
      </div>
      <EmployeeProfileDrawer employeeId={profileId} onClose={() => setProfileId(null)} />
      <RequestModal key={requestType ?? 'none'} type={requestType} onClose={() => setRequestType(null)} />
    </section>
  )
}

export const AgencyDashboard = () => {
  const { user } = useAuth()
  if (user.role === ROLES.AGENCY_MEMBER) return <AgencyMemberDashboard />
  return user.role === ROLES.AGENCY_OWNER ? <OwnerDashboard /> : <AgencyWorkspaceDashboard />
}
