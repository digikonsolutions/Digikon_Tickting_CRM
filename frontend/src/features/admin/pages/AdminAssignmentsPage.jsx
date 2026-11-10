import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Modal, PageHeader, Table } from '../../../components/ui'

const PER_PAGE = 25

export function AdminAssignmentsPage() {
  const [page, setPage] = useState(1)
  const [confirm, setConfirm] = useState(null)
  const [isEnding, setIsEnding] = useState(false)
  const [actionError, setActionError] = useState(null)
  const loadAssignments = useCallback(() => authService.listAdminAssignments({ page, perPage: PER_PAGE }), [page])
  const { data, error, isLoading, reload } = useAsync(loadAssignments)
  const assignments = data?.assignments ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }

  async function endAssignment() {
    if (!confirm || isEnding) return
    setIsEnding(true)
    setActionError(null)
    try {
      await authService.endAdminAssignment(confirm.id)
      setConfirm(null)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setIsEnding(false)
    }
  }

  const columns = [
    { key: 'employee', label: 'Employee', render: (employee) => employee?.name ?? 'Account removed' },
    { key: 'holder', label: 'Holder', render: (holder) => holder?.name ?? 'Account removed' },
    { key: 'agency_name', label: 'Agency' },
    { key: 'status', label: 'Status' },
    { key: 'started_at', label: 'Started', render: (date) => date ? new Date(date).toLocaleDateString() : '—' },
    { key: 'ended_at', label: 'Ended', render: (date) => date ? new Date(date).toLocaleDateString() : '—' },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, assignment) => assignment.status === 'active'
        ? <Button type="button" variant="danger" size="sm" onClick={() => setConfirm(assignment)}>End assignment</Button>
        : '—',
    },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="Employee assignments" subtitle="Read-only assignment history with the option to end an active assignment." />
      <ApiErrorMessage error={actionError ?? error} />
      <Card title="Assignment directory" description={`${pagination.total} assignments total.`}>
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table columns={columns} rows={assignments} loading={isLoading} emptyTitle="No assignments found" emptyText="Employee assignments will appear here." />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page}</span>
          <div>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || page >= pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button>
          </div>
        </div>
      </Card>
      <Modal
        open={Boolean(confirm)}
        onClose={() => !isEnding && setConfirm(null)}
        title="End employee assignment?"
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={isEnding} onClick={() => setConfirm(null)}>Keep assignment</Button>
            <Button type="button" variant="danger" loading={isEnding} loadingText="Ending…" onClick={endAssignment}>End assignment</Button>
          </>
        )}
      >
        <p>Ending this assignment will cancel its open projects and release future work allocations.</p>
      </Modal>
    </section>
  )
}
