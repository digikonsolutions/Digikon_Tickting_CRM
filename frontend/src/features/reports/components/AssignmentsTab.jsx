import { Link } from 'react-router-dom'
import { Badge, Card } from '../../../components/ui'
import { ReportTable } from './ReportTable'

export function AssignmentsTab({
  data,
  isLoading,
  error,
  onRetry,
  filters,
}) {
  const rows = data?.rows ?? []
  const pagination = data?.pagination
  const totals = data?.totals
  const summary = data?.summary ?? {}

  const unassignedCount = summary.unassigned_count ?? 0
  const unassignedEmployees = summary.unassigned_employees ?? []

  const columns = [
    {
      key: 'employee',
      label: 'Specialist',
      sortKey: 'employee',
      render: (val, row) => (
        <Link
          to={`/admin/employees/${row.employee_id}/activity`}
          style={{ color: 'var(--brand)', fontWeight: 600, textDecoration: 'none' }}
        >
          {val}
        </Link>
      ),
    },
    {
      key: 'holder',
      label: 'Holder',
      sortKey: 'holder',
    },
    {
      key: 'agency',
      label: 'Agency',
      sortKey: 'agency',
    },
    {
      key: 'status',
      label: 'Status',
      sortKey: 'status',
      render: (val) => (
        <Badge variant={val === 'active' ? 'success' : 'slate'}>
          {val === 'active' ? 'Active' : 'Ended'}
        </Badge>
      ),
    },
    {
      key: 'started_at',
      label: 'Started',
      sortKey: 'started_at',
    },
    {
      key: 'ended_at',
      label: 'Ended',
      sortKey: 'ended_at',
      render: (val) => (val ? val : <Badge variant="info">Current</Badge>),
    },
    {
      key: 'hold_days',
      label: 'Hold duration',
      align: 'right',
      render: (val) => `${val} days`,
    },
  ]

  function renderTotals(t) {
    return (
      <tr>
        <td colSpan={3}><strong>Total ({t.assignments} assignments)</strong></td>
        <td>
          <span style={{ color: 'var(--color-success-700)' }}>
            <strong>{t.active}</strong> active
          </span>
          {' · '}
          <span style={{ color: 'var(--color-muted)' }}>
            {t.ended} ended
          </span>
        </td>
        <td colSpan={3}>—</td>
      </tr>
    )
  }

  return (
    <div className="rp-table-tab">
      {/* Snapshot of currently unassigned specialists */}
      <Card
        title={`Unassigned active specialists (${unassignedCount})`}
        description="Active specialists in the system not held by any agency right now."
      >
        {unassignedEmployees.length === 0 ? (
          <p style={{ color: 'var(--color-muted)', fontSize: '0.8125rem' }}>
            All active specialists are currently assigned to agencies.
          </p>
        ) : (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
            {unassignedEmployees.map((emp) => (
              <Link
                key={emp.id}
                to={`/admin/employees/${emp.id}/activity`}
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '0.375rem',
                  padding: '0.25rem 0.625rem',
                  background: 'var(--color-surface-sunken)',
                  border: '1px solid var(--color-border)',
                  borderRadius: '0.375rem',
                  fontSize: '0.8125rem',
                  color: 'var(--color-heading)',
                  textDecoration: 'none',
                  fontWeight: 500,
                }}
              >
                <span>{emp.name}</span>
                <Badge variant="info">Available</Badge>
              </Link>
            ))}
          </div>
        )}
      </Card>

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
        emptyTitle="No assignments found"
        emptyText="No assignments were active during this date range."
        noun="assignment"
      />
    </div>
  )
}
