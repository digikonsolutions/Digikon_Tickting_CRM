import { Link } from 'react-router-dom'
import { useAuth } from '../../../../../auth/useAuth'
import { ROLES, ROLE_LABELS } from '../../../../../auth/roles'
import { WelcomeBanner } from '../../../components'
import { ClientDashboard } from './ClientDashboard'
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

export const AgencyDashboard = () => {
  const { user } = useAuth()
  if (user.role === ROLES.AGENCY_MEMBER) return <AgencyMemberDashboard />
  return user.role === ROLES.AGENCY_OWNER ? <OwnerDashboard /> : <ClientDashboard />
}
