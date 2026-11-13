import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Table } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'

const PER_PAGE = 25

export function AdminTicketsPage() {
  const [page, setPage] = useState(1)
  const loadTickets = useCallback(() => authService.listAdminTickets({ page, perPage: PER_PAGE }), [page])
  const { data, error, isLoading, reload } = useAsync(loadTickets)
  const tickets = data?.tickets ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const columns = [
    { key: 'title', label: 'Ticket' },
    { key: 'project_title', label: 'Project' },
    { key: 'agency_name', label: 'Agency' },
    { key: 'creator', label: 'Created by', render: (creator) => creator?.name ?? 'Account removed' },
    { key: 'estimated_hours', label: 'Hours', render: formatHours },
    { key: 'priority', label: 'Priority', render: (priority) => <Badge preset={priority}>{priority}</Badge> },
    { key: 'status', label: 'Status', render: (status) => <Badge variant={status === 'done' ? 'success' : status === 'cancelled' ? 'slate' : 'primary'}>{status.replace('_', ' ')}</Badge> },
    { key: 'start_date', label: 'Start date', render: formatDate },
    { key: 'due_date', label: 'Due date', render: formatDate },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="All tickets" subtitle="Read-only view of project tickets across agencies." />
      <Card title="Ticket directory" description={`${pagination.total} tickets total.`}>
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table columns={columns} rows={tickets} loading={isLoading} emptyTitle="No tickets found" emptyText="Tickets will appear here when agencies break down project work." />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page}</span>
          <div>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page >= pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button>
          </div>
        </div>
      </Card>
    </section>
  )
}
