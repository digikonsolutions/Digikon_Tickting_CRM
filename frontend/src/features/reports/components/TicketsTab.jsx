import { Card, KpiCard, PriorityBadge, StatusBadge, TicketKey } from '../../../components/ui'
import { BarChart } from '../../../components/charts'
import { ReportTable } from './ReportTable'

const STATUS_LABELS = {
  todo: 'To do',
  in_progress: 'In progress',
  in_review: 'In review',
  blocked: 'Blocked',
  waiting_on_client: 'Waiting on client',
  changes_requested: 'Changes requested',
  done: 'Done',
  cancelled: 'Cancelled',
}

const PRIORITY_LABELS = {
  low: 'Low',
  normal: 'Normal',
  high: 'High',
  urgent: 'Urgent',
}

export function TicketsTab({
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
  const summary = data?.summary ?? {}

  const matrix = summary.matrix ?? {}
  const priorities = summary.priorities ?? ['low', 'normal', 'high', 'urgent']
  const statuses = summary.statuses ?? Object.keys(STATUS_LABELS)
  const throughput = summary.throughput ?? { labels: [], values: [] }

  const columns = [
    {
      key: 'key',
      label: 'Key',
      render: (val) => (val ? <TicketKey ticketKey={val} /> : '—'),
    },
    {
      key: 'title',
      label: 'Title',
      sortKey: 'title',
      render: (val) => <strong style={{ color: 'var(--color-heading)' }}>{val}</strong>,
    },
    {
      key: 'project',
      label: 'Project',
      sortKey: 'project',
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
      key: 'priority',
      label: 'Priority',
      sortKey: 'priority',
      render: (val) => <PriorityBadge priority={val} />,
    },
    {
      key: 'estimated_hours',
      label: 'Est. hours',
      sortKey: 'estimated_hours',
      align: 'right',
      render: (val) => `${val} h`,
    },
    {
      key: 'created_at',
      label: 'Created',
      sortKey: 'created_at',
    },
    {
      key: 'due_date',
      label: 'Due date',
      sortKey: 'due_date',
      render: (val) => val || <span style={{ color: 'var(--color-subtle)' }}>—</span>,
    },
  ]

  function renderTotals(t) {
    return (
      <tr>
        <td colSpan={4}><strong>Total ({t.tickets} tickets created in range)</strong></td>
        <td>
          <span style={{ color: 'var(--color-success-700)' }}>
            <strong>{t.done}</strong> done
          </span>
        </td>
        <td>—</td>
        <td style={{ textAlign: 'right' }}><strong>{t.estimated_hours} h</strong></td>
        <td colSpan={2}>—</td>
      </tr>
    )
  }

  return (
    <div className="rp-table-tab">
      {/* Top metrics: Cycle time & Weekly throughput */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.25rem' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <KpiCard
            title="Avg cycle time (Creation to done)"
            value={summary.avg_days_to_done !== null && summary.avg_days_to_done !== undefined ? `${summary.avg_days_to_done} days` : '—'}
            subtext={`${summary.done_in_range ?? 0} tickets completed in this date window`}
            icon="clock"
            tone="teal"
          />

          {/* Status x Priority Matrix */}
          <Card title="Status × Priority matrix" description="Created tickets breakdown by priority level.">
            <div style={{ overflowX: 'auto' }}>
              <table className="rp-matrix-table" aria-label="Status by priority count">
                <thead>
                  <tr>
                    <th scope="col">Status</th>
                    {priorities.map((pr) => (
                      <th key={pr} scope="col">{PRIORITY_LABELS[pr] ?? pr}</th>
                    ))}
                    <th scope="col">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {statuses.map((st) => {
                    const rowCounts = matrix[st] ?? {}
                    const rowTotal = priorities.reduce((sum, pr) => sum + (rowCounts[pr] || 0), 0)
                    return (
                      <tr key={st}>
                        <td>{STATUS_LABELS[st] ?? st}</td>
                        {priorities.map((pr) => {
                          const count = rowCounts[pr] || 0
                          return (
                            <td key={pr} className={count > 0 ? 'rp-matrix-cell-val' : 'rp-matrix-cell-zero'}>
                              {count}
                            </td>
                          )
                        })}
                        <td style={{ fontWeight: 600 }}>{rowTotal}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        </div>

        {/* Throughput Chart */}
        <BarChart
          title="Weekly throughput (Tickets marked done)"
          labels={throughput.labels}
          values={throughput.values}
          unit="tickets"
          barColor="var(--brand)"
        />
      </div>

      {/* Main tickets list */}
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
        emptyTitle="No tickets found"
        emptyText="No tickets were created in the selected range matching the filters."
        noun="ticket"
      />
    </div>
  )
}
