import { useCallback, useState } from 'react'
import { authService, getErrorMessage } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { Button, Icon, PageHeader, Tabs, useToast } from '../../../components/ui'
import { ReportFilterBar } from '../components/ReportFilterBar'
import { OverviewTab } from '../components/OverviewTab'
import { EmployeesTab } from '../components/EmployeesTab'
import { AgenciesTab } from '../components/AgenciesTab'
import { ProjectsTab } from '../components/ProjectsTab'
import { TicketsTab } from '../components/TicketsTab'
import { AssignmentsTab } from '../components/AssignmentsTab'
import { loadFilterOptions } from '../loadFilterOptions'
import { useReportFilters } from '../useReportFilters'

const ADMIN_TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'employees', label: 'Employees' },
  { id: 'agencies', label: 'Agencies' },
  { id: 'projects', label: 'Projects' },
  { id: 'tickets', label: 'Tickets' },
  { id: 'assignments', label: 'Assignments' },
]

const EMPTY_OPTIONS = { agencies: [], employees: [], clients: [] }

const TAB_STATUSES = {
  overview: [],
  employees: ['scheduled', 'in_progress', 'completed', 'cancelled'],
  agencies: ['active', 'suspended'],
  projects: ['scheduled', 'in_progress', 'completed', 'cancelled'],
  tickets: ['todo', 'in_progress', 'in_review', 'blocked', 'waiting_on_client', 'changes_requested', 'done', 'cancelled'],
  assignments: ['active', 'ended'],
}

export function AdminReportsPage() {
  const { user } = useAuth()
  const { showToast } = useToast()
  const [isExporting, setIsExporting] = useState(false)

  const filters = useReportFilters({
    initialTab: 'overview',
    allowedTabs: ADMIN_TABS.map((t) => t.id),
  })

  // A stable loader: useAsync re-runs whenever the function changes, so an inline arrow would refetch on every render.
  const loadOptions = useCallback(() => loadFilterOptions('admin', user?.role), [user?.role])
  const { data: filterOptions } = useAsync(loadOptions)

  const loadReportData = useCallback(
    () => authService.getReport('admin', filters.tab, filters.apiParams),
    [filters.tab, filters.apiParams]
  )
  const {
    data: reportData,
    error: reportError,
    isLoading,
    reload: reloadReport,
  } = useAsync(loadReportData)

  async function handleExport() {
    setIsExporting(true)
    try {
      await authService.downloadReport('admin', filters.tab, filters.apiParams)
      showToast(`Exported the ${filters.tab} report as CSV.`, 'success')
    } catch (err) {
      showToast(getErrorMessage(err), 'error')
    } finally {
      setIsExporting(false)
    }
  }

  function handlePrint() {
    window.print()
  }

  return (
    <div className="rp-page">
      <div className="rp-page-head">
        <PageHeader
          eyebrow="Analytics"
          title="Reports"
          subtitle="Comprehensive system metrics, employee utilization, agency performance, and work logs."
        />
        <div className="rp-header-actions">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={handlePrint}
            aria-label="Print or save as PDF"
          >
            <Icon name="file" size={15} />
            <span>Print / PDF</span>
          </Button>
        </div>
      </div>

      <Tabs
        tabs={ADMIN_TABS}
        activeTab={filters.tab}
        onChange={filters.setTab}
        label="Report sections"
      />

      <ReportFilterBar
        filters={filters}
        scope="admin"
        role={user?.role}
        tabStatuses={TAB_STATUSES[filters.tab] ?? []}
        filterOptions={filterOptions ?? EMPTY_OPTIONS}
        onExport={handleExport}
        isExporting={isExporting}
      />

      {/* Live announcement region for screen readers */}
      <div className="rp-sr-only" aria-live="polite" aria-atomic="true">
        {isLoading
          ? `Loading ${filters.tab} report data…`
          : `${filters.tab} report loaded. ${reportData?.pagination?.total !== undefined ? `${reportData.pagination.total} records.` : ''}`}
      </div>

      {/* Report tab container */}
      <div id="rp-tab-panel" role="tabpanel" aria-label={`${filters.tab} report`}>
        {filters.tab === 'overview' && (
          <OverviewTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            scope="admin"
          />
        )}
        {filters.tab === 'employees' && (
          <EmployeesTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="admin"
          />
        )}
        {filters.tab === 'agencies' && (
          <AgenciesTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
          />
        )}
        {filters.tab === 'projects' && (
          <ProjectsTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="admin"
          />
        )}
        {filters.tab === 'tickets' && (
          <TicketsTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="admin"
          />
        )}
        {filters.tab === 'assignments' && (
          <AssignmentsTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
          />
        )}
      </div>
    </div>
  )
}
