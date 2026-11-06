import { RoleDashboard } from './RoleDashboard'
import { TicketsView } from './TicketsView'

export const AdminDashboard = () => (
  <RoleDashboard
    scope="queue"
    ticketsTitle="Open tickets"
    subtitle="Here is how your agencies, tickets and SLAs are doing today."
    ticketsLink="/team/queue"
    actions={[
      { to: '/admin/employees/new', icon: 'userPlus', label: 'Create employee', description: 'Invite an employee to the workspace' },
      { to: '/admin/agency-owners/new', icon: 'userPlus', label: 'Create agency owner', description: 'Create an agency and invite its owner' },
      { to: '/team/queue', icon: 'queue', label: 'Team queue', description: 'Assign and prioritise work' },
      { to: '/qa/queue', icon: 'check', label: 'QA queue', description: 'Review completed work' },
      { to: '/admin/settings', icon: 'settings', label: 'Settings', description: 'Services, SLAs and statuses' },
    ]}
  />
)

export const AgencyDashboard = () => (
  <RoleDashboard
    scope="agency"
    ticketsTitle="Your requests"
    showAgency={false}
    subtitle="Track your requests and see what the fulfillment team is working on."
    actions={[
      { to: '/agency/dashboard', icon: 'plus', label: 'New request', description: 'Coming soon' },
      { to: '/agency/dashboard', icon: 'inbox', label: 'My requests', description: 'See every ticket you opened' },
    ]}
  />
)

export const TeamQueuePage = () => (
  <TicketsView eyebrow="Team lead" title="Team Queue" subtitle="Triage, assign and keep SLAs on track." scope="queue" />
)

export const MyTicketsPage = () => (
  <TicketsView eyebrow="My work" title="My Tickets" subtitle="Everything currently assigned to you." scope="mine" emptyText="You have no tickets assigned." />
)

export const QaQueuePage = () => (
  <TicketsView eyebrow="Quality assurance" title="QA Queue" subtitle="Review finished work before it goes back to the agency." scope="qa" statuses={['QA Review']} emptyText="Nothing waiting for review." />
)
