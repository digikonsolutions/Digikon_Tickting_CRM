import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, DropdownMenu, Modal, PageHeader, Pagination, Table } from '../../../components/ui'
import { formatShortDate, pluralize } from '../../../lib/format'
import { EmployeeQueueDrawer } from '../../queue/components/EmployeeQueueDrawer'

const PER_PAGE = 25
const STATUS_LABELS = { active: 'Active', ended: 'Ended' }

export function AdminAssignmentsPage() {
  const [page, setPage] = useState(1)
  const [confirm, setConfirm] = useState(null)
  const [queueFor, setQueueFor] = useState(null)
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
    { key: 'status', label: 'Status', render: (status) => STATUS_LABELS[status] ?? status },
    { key: 'started_at', label: 'Started', render: (date) => formatShortDate(date) },
    { key: 'ended_at', label: 'Ended', render: (date) => formatShortDate(date) },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, assignment) => assignment.status === 'active'
        ? (
          <DropdownMenu
            label={`Actions for ${assignment.employee?.name ?? 'this assignment'}`}
            items={[
              { label: 'View queue', onClick: () => setQueueFor(assignment.employee) },
              { label: 'End assignment', danger: true, separator: true, onClick: () => setConfirm(assignment) },
            ]}
          />
        )
        : '—',
    },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="Employee assignments" subtitle="Read-only assignment history with the option to end an active assignment." />
      <ApiErrorMessage error={actionError ?? error} />
      <Card title="Assignment directory" description={isLoading && !data ? 'Loading assignments…' : `${pagination.total} ${pluralize(pagination.total, 'assignment')} total.`}>
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table columns={columns} rows={assignments} loading={isLoading} emptyTitle="No assignments found" emptyText="Employee assignments will appear here." />
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="assignment" loading={isLoading} onPrevious={() => setPage((value) => value - 1)} onNext={() => setPage((value) => value + 1)} />
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
      {queueFor && <EmployeeQueueDrawer employeeId={queueFor.id} name={queueFor.name} onClose={() => setQueueFor(null)} />}
    </section>
  )
}
