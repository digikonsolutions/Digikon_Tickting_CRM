import { Link } from 'react-router-dom'
import { Badge, CapacityBar } from '../../../components/ui'
import { ReportTable } from './ReportTable'

export function UtilizationCell({ scheduled, capacity, utilization, band }) {
  const bandLabel = band === 'under' ? 'Under (<50%)' : band === 'over' ? 'Over (>90%)' : 'Healthy'

  return (
    <div className="rp-utilization-cell">
      <div className="rp-utilization-header">
        <span className="rp-utilization-pct">
          <strong>{utilization !== null ? `${utilization}%` : '—'}</strong>
        </span>
        <span className={`rp-band-badge is-${band}`}>
          {bandLabel}
        </span>
      </div>
      <CapacityBar
        used={scheduled}
        budget={capacity}
        showRemaining={false}
        aria-label={`Scheduled ${scheduled}h of ${capacity}h capacity, ${bandLabel}`}
      />
    </div>
  )
}

export function EmployeesTab({
  data,
  isLoading,
  error,
  onRetry,
  filters,
  scope = 'admin',
}) {
  const rows = data?.rows ?? []
  const pagination = data?.pagination
  const totals = data?.totals

  const columns = [
    {
      key: 'name',
      label: scope === 'admin' ? 'Employee' : 'Specialist',
      sortKey: 'name',
      render: (val, row) => (
        scope === 'admin' ? (
          <Link
            to={`/admin/employees/${row.id}/activity`}
            style={{ color: 'var(--brand)', fontWeight: 600, textDecoration: 'none' }}
          >
            {val}
          </Link>
        ) : (
          <strong style={{ color: 'var(--color-heading)' }}>{val}</strong>
        )
      ),
    },
    {
      key: 'is_active',
      label: 'Status',
      render: (val) => (
        <Badge variant={val ? 'success' : 'slate'}>
          {val ? 'Active' : 'Inactive'}
        </Badge>
      ),
    },
    {
      key: 'holder',
      label: 'Holder',
      sortKey: 'holder',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    },
    ...(scope === 'admin' ? [{
      key: 'agency',
      label: 'Agency',
      sortKey: 'agency',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    }] : []),
    {
      key: 'projects',
      label: 'Projects',
      sortKey: 'projects',
      align: 'right',
    },
    {
      key: 'tickets',
      label: 'Tickets (Done / Prog / Todo)',
      render: (tickets) => (
        <span style={{ fontSize: '0.8125rem' }}>
          <strong style={{ color: 'var(--color-success-700)' }}>{tickets?.done ?? 0}</strong> /{' '}
          <span style={{ color: 'var(--color-heading)' }}>{tickets?.in_progress ?? 0}</span> /{' '}
          <span style={{ color: 'var(--color-muted)' }}>{tickets?.todo ?? 0}</span>
        </span>
      ),
    },
    {
      key: 'scheduled_hours',
      label: 'Scheduled',
      sortKey: 'scheduled_hours',
      align: 'right',
      render: (val) => `${val} h`,
    },
    {
      key: 'capacity_hours',
      label: 'Capacity',
      align: 'right',
      render: (val) => `${val} h`,
    },
    {
      key: 'utilization',
      label: 'Utilization',
      sortKey: 'utilization',
      render: (val, row) => (
        <UtilizationCell
          scheduled={row.scheduled_hours}
          capacity={row.capacity_hours}
          utilization={val}
          band={row.band}
        />
      ),
    },
  ]

  function renderTotals(t) {
    const colSpan = scope === 'admin' ? 4 : 3
    return (
      <tr>
        <td colSpan={colSpan}>
          <strong>Total ({t.employees} {scope === 'admin' ? 'employees' : 'specialists'})</strong>
        </td>
        <td style={{ textAlign: 'right' }}><strong>{t.projects}</strong></td>
        <td>—</td>
        <td style={{ textAlign: 'right' }}><strong>{t.scheduled_hours} h</strong></td>
        <td style={{ textAlign: 'right' }}><strong>{t.capacity_hours} h</strong></td>
        <td>
          <UtilizationCell
            scheduled={t.scheduled_hours}
            capacity={t.capacity_hours}
            utilization={t.utilization}
            band={t.utilization < 50 ? 'under' : t.utilization > 90 ? 'over' : 'healthy'}
          />
        </td>
      </tr>
    )
  }

  return (
    <ReportTable
      columns={columns}
      rows={rows}
      pagination={pagination}
      sort={filters.sort}
      direction={filters.direction}
      onSort={filters.setSort}
      onPageChange={filters.setPage}
      totals={totals}
      renderTotals={renderTotals}
      isLoading={isLoading}
      error={error}
      onRetry={onRetry}
      emptyTitle="No specialists found"
      emptyText="No specialists match the current filters or date range."
      noun={scope === 'admin' ? 'employee' : 'specialist'}
    />
  )
}
