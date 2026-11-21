import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, FilterChips, Input, PageHeader, Pagination, ProjectCardList } from '../../../components/ui'

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

export function AdminProjectsPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  // Links from the dashboard (?status=in_progress) open the list already filtered.
  const [searchParams] = useSearchParams()
  const [status, setStatus] = useState(() => {
    const wanted = searchParams.get('status') ?? ''
    return STATUS_FILTERS.some((filter) => filter.value === wanted) ? wanted : ''
  })
  const load = useCallback(() => authService.listAdminProjects({ page, search, status: status || undefined }), [page, search, status])
  const { data, error, isLoading, reload } = useAsync(load)
  const projects = data?.projects ?? []
  const pagination = data?.pagination ?? { current_page: page, last_page: 1, total: 0 }
  const filtered = Boolean(search || status)

  function submitSearch(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
    setPage(1)
  }

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="All projects" subtitle="View projects, client agencies, assignments, schedules, and progress across the system." />
      <Card title="Project directory" description={`${pagination.total} project${pagination.total === 1 ? '' : 's'}${filtered ? ' match' : ' total'}. Read-only.`}>
        <form className="ui-toolbar" onSubmit={submitSearch} role="search">
          <Input
            id="admin-project-search"
            label="Search projects"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Project, client, employee or agency"
          />
          <Button type="submit" variant="secondary" loading={isLoading && Boolean(searchInput)} loadingText="Searching…">Search</Button>
        </form>
        <FilterChips label="Filter by status" options={STATUS_FILTERS} value={status} disabled={isLoading} onChange={(value) => { setStatus(value); setPage(1) }} />
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
        {isLoading && !data
          ? <p role="status">Loading projects…</p>
          : (
            <ProjectCardList
              projects={projects}
              routeFor={(project) => `/admin/projects/${project.id}`}
              showAgency
              emptyTitle={filtered ? 'No matching projects' : 'No projects found'}
              emptyText={filtered ? 'Try a different status or search.' : 'Projects will appear here when an agency creates them.'}
            />
          )}
        <Pagination
          page={pagination.current_page}
          lastPage={pagination.last_page}
          total={pagination.total}
          noun="project"
          loading={isLoading}
          onPrevious={() => setPage((value) => value - 1)}
          onNext={() => setPage((value) => value + 1)}
        />
      </Card>
    </section>
  )
}
