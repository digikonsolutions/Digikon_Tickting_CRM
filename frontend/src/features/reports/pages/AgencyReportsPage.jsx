import { useCallback, useState } from 'react'
import { authService, getErrorMessage } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { Button, Icon, PageHeader, Tabs, useToast } from '../../../components/ui'
import { ReportFilterBar } from '../components/ReportFilterBar'
import { OverviewTab } from '../components/OverviewTab'
import { EmployeesTab } from '../components/EmployeesTab'
import { ProjectsTab } from '../components/ProjectsTab'
import { TicketsTab } from '../components/TicketsTab'
import { loadFilterOptions } from '../loadFilterOptions'
import { useReportFilters } from '../useReportFilters'

const AGENCY_TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'employees', label: 'Specialists' },
  { id: 'projects', label: 'Projects' },
  { id: 'tickets', label: 'Tickets' },
]

const TAB_STATUSES = {
  overview: [],
  employees: ['scheduled', 'in_progress', 'completed', 'cancelled'],
  projects: ['scheduled', 'in_progress', 'completed', 'cancelled'],
  tickets: ['todo', 'in_progress', 'in_review', 'blocked', 'waiting_on_client', 'changes_requested', 'done', 'cancelled'],
}

export function AgencyReportsPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const [isExporting, setIsExporting] = useState(false)

  const filters = useReportFilters({
    initialTab: 'overview',
    allowedTabs: AGENCY_TABS.map((t) => t.id),
  })

  const { data: filterOptions = { agencies: [], employees: [], clients: [] } } = useAsync(
    () => loadFilterOptions('agency', user?.role),
    [user?.role]
  )

  const loadReportData = useCallback(
    () => authService.getReport('agency', filters.tab, filters.apiParams),
    [filters.tab, filters.apiParams]
  )
  const {
    data: reportData,
    error: reportError,
    isLoading,
    reload: reloadReport,
  } = useAsync(loadReportData, [filters.tab, filters.apiParams])

  async function handleExport() {
    setIsExporting(true)
    try {
      await authService.downloadReport('agency', filters.tab, filters.apiParams)
      toast({
        title: 'Report exported',
        description: `Exported ${filters.tab} report as CSV.`,
        variant: 'success',
      })
    } catch (err) {
      toast({
        title: 'Export failed',
        description: getErrorMessage(err),
        variant: 'danger',
      })
    } finally {
      setIsExporting(false)
    }
  }

  function handlePrint() {
    window.print()
  }

  return (
    <div className="rp-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '1rem' }}>
        <PageHeader
          eyebrow="Analytics"
          title="Reports"
          subtitle="View project delivery trends, specialist allocation, and ticket throughput."
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
        tabs={AGENCY_TABS}
        activeTab={filters.tab}
        onChange={filters.setTab}
        label="Report sections"
      />

      <ReportFilterBar
        filters={filters}
        scope="agency"
        role={user?.role}
        tabStatuses={TAB_STATUSES[filters.tab] ?? []}
        filterOptions={filterOptions}
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
      <div id="rp-tab-panel" role="tabpanel" aria-labelledby={filters.tab}>
        {filters.tab === 'overview' && (
          <OverviewTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            scope="agency"
          />
        )}
        {filters.tab === 'employees' && (
          <EmployeesTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="agency"
          />
        )}
        {filters.tab === 'projects' && (
          <ProjectsTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="agency"
          />
        )}
        {filters.tab === 'tickets' && (
          <TicketsTab
            data={reportData}
            isLoading={isLoading}
            error={reportError}
            onRetry={reloadReport}
            filters={filters}
            scope="agency"
          />
        )}
      </div>
    </div>
  )
}
