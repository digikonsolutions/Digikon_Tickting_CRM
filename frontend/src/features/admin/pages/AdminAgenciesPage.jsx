import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Input, PageHeader, Pagination, Table } from '../../../components/ui'
import { AgencyEditModal } from '../agencies/AgencyEditModal'
import { AgencyStatusModal } from '../agencies/AgencyStatusModal'
import { agencyColumns } from '../agencies/agencyColumns'

export function AdminAgenciesPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [agencyAction, setAgencyAction] = useState(null)
  const [editing, setEditing] = useState(null)
  const load = useCallback(() => authService.listAdminAgencies({ page, search }), [page, search])
  const { data, error, isLoading, reload } = useAsync(load)
  const agencies = data?.agencies ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }

  function submitSearch(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
    setPage(1)
  }

  return (
    <section className="page admin-agencies-page">
      <PageHeader eyebrow="Administration" title="Agencies" subtitle="Plans, seats, hours and status for every agency." />
      <Card title="All agencies" description={`${pagination.total} agenc${pagination.total === 1 ? 'y' : 'ies'}`}>
        <form className="admin-users-search" onSubmit={submitSearch}>
          <Input id="agency-search" label="Search agencies" type="search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search by agency name" />
          <Button type="submit" variant="secondary" loading={isLoading && Boolean(data)} loadingText="Searching…">Search</Button>
        </form>
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        <Table
          columns={agencyColumns({ onToggle: setAgencyAction, onEdit: setEditing })}
          rows={agencies}
          loading={isLoading && !data}
          emptyTitle="No agencies found"
          emptyText={search ? 'Try another name.' : 'Invite an agency owner to create the first agency.'}
        />
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="agency" loading={isLoading} onPrevious={() => setPage((current) => current - 1)} onNext={() => setPage((current) => current + 1)} />
      </Card>
      <AgencyEditModal agency={editing} onClose={() => setEditing(null)} onDone={reload} />
      <AgencyStatusModal key={agencyAction?.id ?? 'none'} agency={agencyAction} onClose={() => setAgencyAction(null)} onDone={reload} />
    </section>
  )
}
