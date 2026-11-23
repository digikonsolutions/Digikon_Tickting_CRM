import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../../../auth/authService'
import { useAsync } from '../../../../../hooks/useAsync'
import { Alert, Button, DropdownMenu, Icon, PageHeader } from '../../../../../components/ui'
import { AgencyStatusModal } from '../../../../admin/agencies/AgencyStatusModal'
import {
  AgenciesCard, AttentionCard, ContractsCard, DashboardSkeleton, EmployeeCapacityCard, KpiRow, QuickActionsCard, RecentProjectsCard,
} from './AdminDashboardCards'
import { useUrgentCounts } from '../../../../admin/pages/useUrgentCounts'
import './adminDashboard.css'

// Urgent requests waiting for a quote or for the agency's answer. Hidden when there is nothing to do.
function UrgentRequestsStrip() {
  const counts = useUrgentCounts(true)
  if (!counts.pending && !counts.quoted) return null
  return (
    <Alert variant={counts.pending ? 'warning' : 'info'} title="Urgent requests">
      {counts.pending > 0 && <span>{counts.pending} waiting for a quote. </span>}
      {counts.quoted > 0 && <span>{counts.quoted} quoted, waiting for the agency. </span>}
      <Link to="/admin/rush-requests">Open urgent requests</Link>
    </Alert>
  )
}

const NEW_ITEMS = [
  { label: 'Employee', to: '/admin/employees/new' },
  { label: 'Agency owner', to: '/admin/agency-owners/new' },
]

const timeLabel = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '')

// One request feeds the whole page. Order: KPIs, attention and quick actions, agencies with contracts and
// recent projects, then employee capacity.
export const AdminDashboard = () => {
  const [filter, setFilter] = useState('all')
  const [agencyAction, setAgencyAction] = useState(null)
  // The chips narrow only the capacity list, so they re-request with a filter. The previous data stays on
  // screen while that is in flight (useAsync keeps it), so the page never flashes back to a skeleton.
  const load = useCallback(() => authService.getAdminDashboard({ employeeFilter: filter }), [filter])
  const { data, error, isLoading, reload } = useAsync(load)
  const refreshing = isLoading && Boolean(data)

  return (
    <section className="page dashboard admin-dashboard">
      <nav className="adm-breadcrumb" aria-label="Breadcrumb">
        <ol><li>Admin</li><li aria-current="page">Dashboard</li></ol>
      </nav>
      <PageHeader
        eyebrow="Administration"
        title="Workspace overview"
        subtitle="Employees, agencies and capacity at a glance."
        action={(
          <div className="adm-header-actions">
            {data && <span className="adm-updated" aria-live="polite">Last updated {timeLabel(data.generated_at)}</span>}
            <Button type="button" variant="secondary" loading={refreshing} loadingText="Refreshing…" onClick={reload}>Refresh</Button>
            <DropdownMenu label="Create new" triggerLabel="New" triggerIcon={<Icon name="plus" size={17} />} items={NEW_ITEMS} />
          </div>
        )}
      />

      {error && !data && (
        <Alert variant="error" title="Could not load the dashboard">
          {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {error && data && (
        <Alert variant="warning" title="Could not refresh">
          Showing the last data we loaded. {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      {!data && !error && <DashboardSkeleton />}

      {data && (
        <>
          <UrgentRequestsStrip />
          <KpiRow data={data} />
          <div className="adm-cols">
            <div className="adm-col">
              <AttentionCard items={data.attention} />
              <AgenciesCard data={data} onToggle={setAgencyAction} />
              <RecentProjectsCard projects={data.recent_projects ?? []} />
            </div>
            <div className="adm-col">
              <QuickActionsCard />
              <ContractsCard contracts={data.contracts} />
            </div>
          </div>
          <EmployeeCapacityCard data={data} filter={filter} onFilter={setFilter} busy={refreshing} />
        </>
      )}

      <AgencyStatusModal key={agencyAction?.id ?? 'none'} agency={agencyAction} onClose={() => setAgencyAction(null)} onDone={reload} />
    </section>
  )
}
