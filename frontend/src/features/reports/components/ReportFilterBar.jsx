import { useState } from 'react'
import { Button, Drawer, Icon, Spinner } from '../../../components/ui'
import { PRESET_OPTIONS } from '../useReportFilters'

const STATUS_LABELS = {
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
  scheduled: 'Scheduled',
  in_progress: 'In progress',
  in_review: 'In review',
  blocked: 'Blocked',
  waiting_on_client: 'Waiting on client',
  changes_requested: 'Changes requested',
  done: 'Done',
  cancelled: 'Cancelled',
  ended: 'Ended',
}

export function ReportFilterBar({
  filters,
  scope = 'admin',
  role,
  tabStatuses = [],
  filterOptions = {},
  onExport,
  isExporting = false,
}) {
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)

  const {
    preset,
    setPreset,
    from,
    to,
    setDates,
    status,
    setStatus,
    agencyId,
    setAgencyId,
    employeeId,
    setEmployeeId,
    clientId,
    setClientId,
    resetFilters,
    hasActiveFilters,
  } = filters

  const agencies = filterOptions.agencies ?? []
  const employees = filterOptions.employees ?? []
  const clients = filterOptions.clients ?? []

  const canFilterAgency = scope === 'admin' && agencies.length > 0
  const canFilterEmployee = employees.length > 0
  const canFilterClient = (scope === 'admin' || role === 'agency_owner') && clients.length > 0
  const hasStatusFilter = tabStatuses.length > 0

  function handleDateChange(key, value) {
    if (key === 'from') {
      setDates({ from: value, to })
    } else {
      setDates({ from, to: value })
    }
  }

  return (
    <aside className="rp-filter-bar" aria-label="Report filters">
      {/* Desktop view */}
      <div className="rp-filter-desktop">
        <div className="rp-filter-row">
          {/* Date range presets */}
          <div className="rp-filter-presets" role="radiogroup" aria-label="Date range preset">
            {PRESET_OPTIONS.map((item) => (
              <button
                key={item.key}
                type="button"
                role="radio"
                aria-checked={preset === item.key}
                className={`rp-preset-btn${preset === item.key ? ' is-active' : ''}`}
                onClick={() => setPreset(item.key)}
              >
                {item.label}
              </button>
            ))}
          </div>

          {/* Date range inputs */}
          <div className="rp-date-range">
            <label className="rp-date-field" htmlFor="rp-date-from">
              <span>From</span>
              <input
                id="rp-date-from"
                type="date"
                className="rp-date-input"
                value={from}
                onChange={(e) => handleDateChange('from', e.target.value)}
              />
            </label>
            <label className="rp-date-field" htmlFor="rp-date-to">
              <span>To</span>
              <input
                id="rp-date-to"
                type="date"
                className="rp-date-input"
                value={to}
                min={from}
                onChange={(e) => handleDateChange('to', e.target.value)}
              />
            </label>
          </div>

          {/* Export & Reset action buttons */}
          <div className="rp-filter-actions">
            {hasActiveFilters && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={resetFilters}
              >
                Reset
              </Button>
            )}
            <Button
              type="button"
              variant="secondary"
              size="sm"
              className="rp-export-btn"
              disabled={isExporting}
              onClick={onExport}
            >
              {isExporting ? (
                <>
                  <Spinner size={14} />
                  <span>Exporting…</span>
                </>
              ) : (
                <>
                  <Icon name="download" size={14} />
                  <span>Export CSV</span>
                </>
              )}
            </Button>
          </div>
        </div>

        {/* Secondary row for contextual filters: status, agency, employee, client */}
        {(hasStatusFilter || canFilterAgency || canFilterEmployee || canFilterClient) && (
          <div className="rp-filter-row">
            {hasStatusFilter && (
              <div className="rp-select-group">
                <label className="rp-sr-only" htmlFor="rp-filter-status">Filter by status</label>
                <select
                  id="rp-filter-status"
                  className="rp-select"
                  value={status}
                  onChange={(e) => setStatus(e.target.value)}
                >
                  <option value="">All statuses</option>
                  {tabStatuses.map((st) => (
                    <option key={st} value={st}>
                      {STATUS_LABELS[st] ?? st}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {canFilterAgency && (
              <div className="rp-select-group">
                <label className="rp-sr-only" htmlFor="rp-filter-agency">Filter by agency</label>
                <select
                  id="rp-filter-agency"
                  className="rp-select"
                  value={agencyId}
                  onChange={(e) => setAgencyId(e.target.value)}
                >
                  <option value="">All agencies</option>
                  {agencies.map(([id, name]) => (
                    <option key={id} value={id}>
                      {name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {canFilterEmployee && (
              <div className="rp-select-group">
                <label className="rp-sr-only" htmlFor="rp-filter-employee">
                  {scope === 'admin' ? 'Filter by employee' : 'Filter by specialist'}
                </label>
                <select
                  id="rp-filter-employee"
                  className="rp-select"
                  value={employeeId}
                  onChange={(e) => setEmployeeId(e.target.value)}
                >
                  <option value="">
                    {scope === 'admin' ? 'All employees' : 'All specialists'}
                  </option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={emp.id}>
                      {emp.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {canFilterClient && (
              <div className="rp-select-group">
                <label className="rp-sr-only" htmlFor="rp-filter-client">Filter by client</label>
                <select
                  id="rp-filter-client"
                  className="rp-select"
                  value={clientId}
                  onChange={(e) => setClientId(e.target.value)}
                >
                  <option value="">All clients</option>
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Mobile view trigger */}
      <div className="rp-mobile-bar">
        <div className="rp-mobile-summary">
          <span>{from} → {to}</span>
          {status && <span> · {STATUS_LABELS[status] ?? status}</span>}
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => setIsDrawerOpen(true)}
          >
            Filters{hasActiveFilters ? ' •' : ''}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={isExporting}
            onClick={onExport}
            aria-label="Export CSV"
          >
            {isExporting ? <Spinner size={14} /> : <Icon name="download" size={16} />}
          </Button>
        </div>
      </div>

      {/* Mobile Drawer */}
      <Drawer
        open={isDrawerOpen}
        onClose={() => setIsDrawerOpen(false)}
        title="Report filters"
        description="Filter report by dates, status and assignees."
        footer={
          <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end', width: '100%' }}>
            {hasActiveFilters && (
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  resetFilters()
                  setIsDrawerOpen(false)
                }}
              >
                Reset
              </Button>
            )}
            <Button
              type="button"
              variant="primary"
              onClick={() => setIsDrawerOpen(false)}
            >
              Done
            </Button>
          </div>
        }
      >
        <div className="rp-drawer-form">
          <div className="rp-drawer-section">
            <span className="rp-drawer-label">Date preset</span>
            <div className="rp-filter-presets">
              {PRESET_OPTIONS.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  className={`rp-preset-btn${preset === item.key ? ' is-active' : ''}`}
                  onClick={() => setPreset(item.key)}
                >
                  {item.label}
                </button>
              ))}
            </div>
          </div>

          <div className="rp-drawer-section">
            <span className="rp-drawer-label">Date range</span>
            <div style={{ display: 'flex', gap: '0.5rem', flexDirection: 'column' }}>
              <label className="rp-date-field" htmlFor="rp-drawer-from">
                <span style={{ minWidth: '3rem' }}>From</span>
                <input
                  id="rp-drawer-from"
                  type="date"
                  className="rp-date-input"
                  style={{ width: '100%' }}
                  value={from}
                  onChange={(e) => handleDateChange('from', e.target.value)}
                />
              </label>
              <label className="rp-date-field" htmlFor="rp-drawer-to">
                <span style={{ minWidth: '3rem' }}>To</span>
                <input
                  id="rp-drawer-to"
                  type="date"
                  className="rp-date-input"
                  style={{ width: '100%' }}
                  value={to}
                  min={from}
                  onChange={(e) => handleDateChange('to', e.target.value)}
                />
              </label>
            </div>
          </div>

          {hasStatusFilter && (
            <div className="rp-drawer-section">
              <label className="rp-drawer-label" htmlFor="rp-drawer-status">Status</label>
              <select
                id="rp-drawer-status"
                className="rp-select"
                value={status}
                onChange={(e) => setStatus(e.target.value)}
              >
                <option value="">All statuses</option>
                {tabStatuses.map((st) => (
                  <option key={st} value={st}>
                    {STATUS_LABELS[st] ?? st}
                  </option>
                ))}
              </select>
            </div>
          )}

          {canFilterAgency && (
            <div className="rp-drawer-section">
              <label className="rp-drawer-label" htmlFor="rp-drawer-agency">Agency</label>
              <select
                id="rp-drawer-agency"
                className="rp-select"
                value={agencyId}
                onChange={(e) => setAgencyId(e.target.value)}
              >
                <option value="">All agencies</option>
                {agencies.map(([id, name]) => (
                  <option key={id} value={id}>
                    {name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {canFilterEmployee && (
            <div className="rp-drawer-section">
              <label className="rp-drawer-label" htmlFor="rp-drawer-employee">
                {scope === 'admin' ? 'Employee' : 'Specialist'}
              </label>
              <select
                id="rp-drawer-employee"
                className="rp-select"
                value={employeeId}
                onChange={(e) => setEmployeeId(e.target.value)}
              >
                <option value="">
                  {scope === 'admin' ? 'All employees' : 'All specialists'}
                </option>
                {employees.map((emp) => (
                  <option key={emp.id} value={emp.id}>
                    {emp.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {canFilterClient && (
            <div className="rp-drawer-section">
              <label className="rp-drawer-label" htmlFor="rp-drawer-client">Client</label>
              <select
                id="rp-drawer-client"
                className="rp-select"
                value={clientId}
                onChange={(e) => setClientId(e.target.value)}
              >
                <option value="">All clients</option>
                {clients.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>
      </Drawer>
    </aside>
  )
}
