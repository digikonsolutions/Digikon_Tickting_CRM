import { useCallback, useState } from 'react'
import { authService } from '../../../../../auth/authService'
import { useAsync } from '../../../../../hooks/useAsync'
import { ApiErrorMessage } from '../../../../auth/components/ApiErrorMessage'
import { ActionMenu, Alert, Button, Icon, Modal, PageHeader, useToast } from '../../../../../components/ui'
import {
  AgenciesCard, AttentionCard, DashboardSkeleton, EmployeeCapacityCard, KpiRow, WorkSummaryCard,
} from './AdminDashboardCards'
import './adminDashboard.css'

const NEW_ITEMS = [
  { label: 'Employee', to: '/admin/employees/new' },
  { label: 'Agency owner', to: '/admin/agency-owners/new' },
]

const timeLabel = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '')

// One request feeds the whole page: employees, agencies, capacity, attention and the work summary.
export const AdminDashboard = () => {
  const { showToast } = useToast()
  const [filter, setFilter] = useState('all')
  const [agencyAction, setAgencyAction] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  // The chips narrow only the capacity list, so they re-request with a filter. The previous data stays on
  // screen while that is in flight (useAsync keeps it), so the page never flashes back to a skeleton.
  const load = useCallback(() => authService.getAdminDashboard({ employeeFilter: filter }), [filter])
  const { data, error, isLoading, reload } = useAsync(load)
  const refreshing = isLoading && Boolean(data)

  async function confirmAgencyAction() {
    if (!agencyAction || isSaving) return
    const suspending = agencyAction.status === 'active'
    setIsSaving(true)
    setActionError(null)
    try {
      await authService.updateAdminAgency(agencyAction.id, suspending ? 'suspended' : 'active')
      showToast(`${agencyAction.name} was ${suspending ? 'suspended' : 'activated'}.`)
      setAgencyAction(null)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  const suspending = agencyAction?.status === 'active'

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
            <ActionMenu label="Create new" triggerLabel="New" triggerIcon={<Icon name="plus" size={17} />} items={NEW_ITEMS} />
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
          <KpiRow data={data} />
          <div className="adm-grid">
            <EmployeeCapacityCard data={data} filter={filter} onFilter={setFilter} busy={refreshing} />
            <AttentionCard items={data.attention} />
          </div>
          <AgenciesCard data={data} onToggle={(agency) => { setActionError(null); setAgencyAction(agency) }} />
          <WorkSummaryCard summary={data.work_summary} />
        </>
      )}

      <Modal
        open={Boolean(agencyAction)}
        onClose={() => !isSaving && setAgencyAction(null)}
        title={suspending ? 'Suspend agency?' : 'Activate agency?'}
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={isSaving} onClick={() => setAgencyAction(null)}>Cancel</Button>
            <Button type="button" variant={suspending ? 'danger' : 'primary'} loading={isSaving} loadingText="Saving…" onClick={confirmAgencyAction}>
              {suspending ? 'Suspend agency' : 'Activate agency'}
            </Button>
          </>
        )}
      >
        {agencyAction && (suspending
          ? <p><strong>{agencyAction.name}</strong> and all of its users will be signed out now and cannot sign in until the agency is activated again. Their projects and held employees are kept.</p>
          : <p>Users of <strong>{agencyAction.name}</strong> will be able to sign in again.</p>)}
        <ApiErrorMessage error={actionError} />
      </Modal>
    </section>
  )
}
