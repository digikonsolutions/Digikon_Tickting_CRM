import { Badge } from '../../../components/ui'
import { ReportTable } from './ReportTable'

export function AgenciesTab({
  data,
  isLoading,
  error,
  onRetry,
  filters,
}) {
  const rows = data?.rows ?? []
  const pagination = data?.pagination
  const totals = data?.totals

  const columns = [
    {
      key: 'name',
      label: 'Agency',
      sortKey: 'name',
      render: (val) => <strong style={{ color: 'var(--color-heading)' }}>{val}</strong>,
    },
    {
      key: 'status',
      label: 'Status',
      sortKey: 'status',
      render: (val) => (
        <Badge variant={val === 'active' ? 'success' : 'danger'}>
          {val === 'active' ? 'Active' : 'Suspended'}
        </Badge>
      ),
    },
    {
      key: 'owners',
      label: 'Owners',
      sortKey: 'owners',
      align: 'right',
    },
    {
      key: 'clients',
      label: 'Clients',
      sortKey: 'clients',
      align: 'right',
    },
    {
      key: 'employees_held',
      label: 'Employees held',
      sortKey: 'employees_held',
      align: 'right',
    },
    {
      key: 'open_projects',
      label: 'Open projects',
      sortKey: 'open',
      align: 'right',
    },
    {
      key: 'completed_projects',
      label: 'Completed',
      sortKey: 'completed',
      align: 'right',
    },
    {
      key: 'cancelled_projects',
      label: 'Cancelled',
      sortKey: 'cancelled',
      align: 'right',
    },
    {
      key: 'scheduled_hours',
      label: 'Scheduled',
      sortKey: 'scheduled_hours',
      align: 'right',
      render: (val) => `${val} h`,
    },
    {
      key: 'completion_rate',
      label: 'Completion rate',
      align: 'right',
      render: (val) => (val !== null && val !== undefined ? `${val}%` : '—'),
    },
  ]

  function renderTotals(t) {
    return (
      <tr>
        <td colSpan={2}><strong>Total ({t.agencies} agencies)</strong></td>
        <td>—</td>
        <td>—</td>
        <td style={{ textAlign: 'right' }}><strong>{t.employees_held}</strong></td>
        <td style={{ textAlign: 'right' }}><strong>{t.open_projects}</strong></td>
        <td style={{ textAlign: 'right' }}><strong>{t.completed_projects}</strong></td>
        <td style={{ textAlign: 'right' }}><strong>{t.cancelled_projects}</strong></td>
        <td style={{ textAlign: 'right' }}><strong>{t.scheduled_hours} h</strong></td>
        <td style={{ textAlign: 'right' }}>
          <strong>{t.completion_rate !== null ? `${t.completion_rate}%` : '—'}</strong>
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
      emptyTitle="No agencies found"
      emptyText="No agencies match the current status filter."
      noun="agency"
    />
  )
}
