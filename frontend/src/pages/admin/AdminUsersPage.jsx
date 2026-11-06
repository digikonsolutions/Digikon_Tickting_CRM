import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { ROLE_LABELS } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Badge, Button, Card, Input, PageHeader, Table } from '../../components/ui'

export function AdminUsersPage() {
  const [users, setUsers] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadUsers = useCallback(async (page, query) => {
    setIsLoading(true)
    setError(null)
    try {
      const response = await authService.listAdminUsers({ page, search: query })
      setUsers(response.users)
      setPagination(response.pagination)
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAdminUsers({ page: 1, search: '' })
      .then((response) => {
        if (!active) return
        setUsers(response.users)
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
  }, [loadUsers])

  function submitSearch(event) {
    event.preventDefault()
    const nextSearch = searchInput.trim()
    setSearch(nextSearch)
    loadUsers(1, nextSearch)
  }

  const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    {
      key: 'role',
      label: 'Role',
      render: (role) => <Badge variant={role === 'admin' ? 'primary' : 'slate'}>{ROLE_LABELS[role] ?? role}</Badge>,
    },
    { key: 'agency_name', label: 'Agency', render: (name) => name || 'Internal' },
    {
      key: 'is_active',
      label: 'Status',
      render: (isActive) => <Badge variant={isActive ? 'success' : 'slate'}>{isActive ? 'Active' : 'Inactive'}</Badge>,
    },
  ]

  return (
    <section className="page admin-users-page">
      <PageHeader
        eyebrow="Administration"
        title="All users"
        subtitle="View other accounts across the organization and their agency assignments. Your own administrator account is not listed."
        action={<Link className="ui-button ui-button-primary ui-button-md" to="/admin/users/new">Create user</Link>}
      />
      <Card title="User directory" description={`${pagination.total} account${pagination.total === 1 ? '' : 's'} total.`}>
        <form className="admin-users-search" onSubmit={submitSearch}>
          <Input
            id="admin-users-search"
            label="Search users"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search by name or email"
          />
          <Button type="submit" variant="secondary" loading={isLoading} loadingText="Searching…">Search</Button>
        </form>
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={() => loadUsers(pagination.current_page, search)}>Try again</Button>}
        <Table
          columns={columns}
          rows={users}
          loading={isLoading}
          emptyTitle="No users found"
          emptyText={search ? 'Try another name or email address.' : 'Accounts will appear here when they are created.'}
        />
        <div className="admin-users-pagination">
          <span>Page {pagination.current_page} of {pagination.last_page}</span>
          <div>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              disabled={isLoading || pagination.current_page <= 1}
              onClick={() => loadUsers(pagination.current_page - 1, search)}
            >
              Previous
            </Button>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              disabled={isLoading || pagination.current_page >= pagination.last_page}
              onClick={() => loadUsers(pagination.current_page + 1, search)}
            >
              Next
            </Button>
          </div>
        </div>
      </Card>
    </section>
  )
}
