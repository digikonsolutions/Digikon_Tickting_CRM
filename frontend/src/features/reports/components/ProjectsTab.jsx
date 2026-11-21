import { Link } from 'react-router-dom'
import { Badge, StatusBadge } from '../../../components/ui'
import { ReportTable } from './ReportTable'

export function ProjectsTab({
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
      key: 'title',
      label: 'Project',
      sortKey: 'title',
      render: (val, row) => (
        <Link
          to={scope === 'admin' ? `/admin/projects/${row.id}` : `/agency/projects/${row.id}`}
          style={{ color: 'var(--brand)', fontWeight: 600, textDecoration: 'none' }}
        >
          {val}
        </Link>
      ),
    },
    ...(scope === 'admin' ? [{
      key: 'agency',
      label: 'Agency',
      sortKey: 'agency',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    }] : []),
    {
      key: 'client',
      label: 'Client',
      sortKey: 'client',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    },
    {
      key: 'employee',
      label: scope === 'admin' ? 'Employee' : 'Employee',
      sortKey: 'employee',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    },
    {
      key: 'status',
      label: 'Status',
      sortKey: 'status',
      render: (val) => <StatusBadge status={val} />,
    },
    {
      key: 'flag',
      label: 'Schedule flag',
      render: (flag, row) => {
        if (flag === 'overdue') {
          return <Badge variant="danger">{row.days_late > 0 ? `${row.days_late}d overdue` : 'Overdue'}</Badge>
        }
        if (flag === 'at_risk') {
          return <Badge variant="warning">At risk</Badge>
        }
        // Only open projects can be on track; finished ones show how they ended instead.
        if (row.status === 'completed') {
          return row.days_late > 0 ? <Badge variant="warning">{`Done ${row.days_late}d late`}</Badge> : <Badge variant="success">On time</Badge>
        }
        if (row.status === 'cancelled') return <span className="rp-muted">—</span>
        return <Badge variant="slate">On track</Badge>
      },
    },
    {
      key: 'hours',
      label: 'Hours (Ticketed / Est)',
      sortKey: 'estimated_hours',
      align: 'right',
      render: (_, row) => (
        <span>
          <strong>{row.ticketed_hours}</strong> / {row.estimated_hours} h
        </span>
      ),
    },
    {
      key: 'start_date',
      label: 'Start date',
      sortKey: 'start_date',
    },
    {
      key: 'due_date',
      label: 'Due date',
      sortKey: 'due_date',
    },
  ]

  function renderTotals(t) {
    const colSpan = scope === 'admin' ? 5 : 4
    return (
      <tr>
        <td colSpan={colSpan}><strong>Total ({t.projects} projects)</strong></td>
        <td>
          {t.overdue > 0 ? (
            <Badge variant="danger">{t.overdue} overdue</Badge>
          ) : (
            <span style={{ color: 'var(--color-muted)' }}>0 overdue</span>
          )}
        </td>
        <td style={{ textAlign: 'right' }}>
          <strong>{t.ticketed_hours}</strong> / {t.estimated_hours} h
        </td>
        <td colSpan={2}>—</td>
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
      emptyTitle="No projects found"
      emptyText="No projects overlap the selected date range and filters."
      noun="project"
    />
  )
}
