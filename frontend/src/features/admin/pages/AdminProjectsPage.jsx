import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Input, PageHeader, StatusBadge, Table } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'

const formatSchedule = (schedule = []) => schedule.map(({ work_date, hours }) => `${formatDate(work_date)}: ${formatHours(hours)}`).join(', ')

export function AdminProjectsPage() {
  const [projects, setProjects] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadProjects = useCallback(async (page, query) => {
    try {
      const response = await authService.listAdminProjects({ page, search: query })
      setProjects(response.projects)
      setPagination(response.pagination)
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAdminProjects({ page: 1, search: '' })
      .then((response) => {
        if (!active) return
        setProjects(response.projects)
        setPagination(response.pagination)
      })
      .catch((reason) => {
        if (active) setError(reason)
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  function submitSearch(event) {
    event.preventDefault()
    const query = searchInput.trim()
    setSearch(query)
    setError(null)
    setIsLoading(true)
    loadProjects(1, query)
  }

  const columns = [
    { key: 'title', label: 'Project', render: (title, project) => <Link className="admin-project-detail-link" to={`/admin/projects/${project.id}`}>{title}</Link> },
    { key: 'description', label: 'Description' },
    { key: 'agency_name', label: 'Agency' },
    { key: 'client', label: 'Client', render: (client) => client?.name ?? 'Account removed' },
    { key: 'employee', label: 'Employee', render: (employee) => employee?.name ?? 'Unassigned' },
    { key: 'estimated_hours', label: 'Hours', render: formatHours },
    { key: 'due_date', label: 'Due date', render: formatDate },
    { key: 'status', label: 'Status', render: (status) => <StatusBadge status={status} /> },
    { key: 'schedule', label: 'Daily schedule', render: formatSchedule },
  ]

  return (
    <section className="page">
      <PageHeader eyebrow="Administration" title="All projects" subtitle="View projects, client agencies, assignments, schedules, and progress across the system." />
      <Card title="Project directory" description={`${pagination.total} project${pagination.total === 1 ? '' : 's'} total. Read-only.`}>
        <form className="admin-users-search" onSubmit={submitSearch}>
          <Input
            id="admin-project-search"
            label="Search projects"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search project, client, employee, or agency"
          />
          <Button type="submit" variant="secondary" loading={isLoading} loadingText="Searching…">Search</Button>
        </form>
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={() => { setError(null); setIsLoading(true); loadProjects(pagination.current_page, search) }}>Try again</Button>}
        <Table columns={columns} rows={projects} loading={isLoading} emptyTitle="No projects found" emptyText="Projects will appear here when an agency creates them." />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page}</span>
          <div>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || pagination.current_page <= 1} onClick={() => { setIsLoading(true); loadProjects(pagination.current_page - 1, search) }}>Previous</Button>
            <Button type="button" variant="secondary" size="sm" disabled={isLoading || pagination.current_page >= pagination.last_page} onClick={() => { setIsLoading(true); loadProjects(pagination.current_page + 1, search) }}>Next</Button>
          </div>
        </div>
      </Card>
    </section>
  )
}
