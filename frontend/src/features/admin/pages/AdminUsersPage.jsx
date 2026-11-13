import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../../auth/roles'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, Input, Modal, PageHeader, Table } from '../../../components/ui'

export function AdminUsersPage({ role, title, createTo, createLabel }) {
  const [users, setUsers] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editingUser, setEditingUser] = useState(null)
  const [editError, setEditError] = useState(null)
  const [action, setAction] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [success, setSuccess] = useState('')
  const [isSaving, setIsSaving] = useState(false)

  const loadUsers = useCallback(async (page, query) => {
    setIsLoading(true)
    setError(null)
    try {
      const response = await authService.listAdminUsers({ page, search: query, role })
      setUsers(response.users)
      setPagination(response.pagination)
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }, [role])

  // Initial state is already loading/no error, and the routes remount this page per role (key={role}).
  useEffect(() => {
    let active = true
    authService.listAdminUsers({ page: 1, search: '', role })
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
  }, [loadUsers, role])

  function submitSearch(event) {
    event.preventDefault()
    const nextSearch = searchInput.trim()
    setSearch(nextSearch)
    loadUsers(1, nextSearch)
  }

  async function saveUser(event) {
    event.preventDefault()
    if (!editingUser || isSaving) return
    const data = new FormData(event.currentTarget)
    setEditError(null)
    setIsSaving(true)

    try {
      await authService.updateAdminUser(editingUser.id, {
        name: String(data.get('name')).trim(),
        email: String(data.get('email')).trim(),
      })
      setEditingUser(null)
      setSuccess('Account updated.')
      await loadUsers(pagination.current_page, search)
    } catch (reason) {
      setEditError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  async function confirmAction() {
    if (!action) return
    setActionError(null)
    try {
      if (action.kind === 'delete') {
        await authService.deleteAdminUser(action.user.id)
        setSuccess(`${action.user.name} was permanently deleted.`)
      } else {
        await authService.updateAdminUser(action.user.id, { is_active: !action.user.is_active })
        setSuccess(`${action.user.name} was ${action.user.is_active ? 'deactivated' : 'reactivated'}.`)
      }
      setAction(null)
      await loadUsers(pagination.current_page, search)
    } catch (reason) {
      setActionError(reason)
    }
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
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, user) => user.can_manage ? (
        <div className="agency-user-actions">
          <Button type="button" variant="secondary" size="sm" onClick={() => { setEditError(null); setEditingUser(user) }}>Edit</Button>
          <Button type="button" variant="secondary" size="sm" onClick={() => setAction({ kind: 'status', user })}>
            {user.is_active ? 'Deactivate' : 'Reactivate'}
          </Button>
          <Button type="button" variant="danger" size="sm" onClick={() => setAction({ kind: 'delete', user })}>Delete</Button>
        </div>
      ) : <span>View only</span>,
    },
  ]

  return (
    <section className="page admin-users-page">
      <PageHeader
        eyebrow="Administration"
        title={title}
        subtitle={role === ROLES.AGENCY_CLIENT
          ? 'Client accounts are listed for reference only; they cannot be created or edited here.'
          : role
            ? `View and manage ${title.toLowerCase()} and their account status.`
            : 'View every account and its role and status. Only employees and agency owners can be managed here.'}
        action={createTo && <Link className="ui-button ui-button-primary ui-button-md" to={createTo}>{createLabel}</Link>}
      />
      {success && <Alert variant="success">{success}</Alert>}
      <ApiErrorMessage error={actionError} />
      <Card title={title} description={`${pagination.total} account${pagination.total === 1 ? '' : 's'} total.`}>
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
      <Modal
        open={Boolean(editingUser)}
        onClose={() => setEditingUser(null)}
        title="Edit account"
        footer={(
          <>
            <Button type="button" variant="secondary" onClick={() => setEditingUser(null)}>Cancel</Button>
            <Button type="submit" form="admin-user-edit-form" loading={isSaving} loadingText="Saving…">Save changes</Button>
          </>
        )}
      >
        {editingUser && (
          <form id="admin-user-edit-form" className="admin-user-form" onSubmit={saveUser}>
            <Input id="edit-admin-user-name" label="Name" name="name" defaultValue={editingUser.name} maxLength="120" required error={editError?.errors?.name?.[0]} />
            <Input id="edit-admin-user-email" label="Email address" name="email" type="email" defaultValue={editingUser.email} maxLength="254" required error={editError?.errors?.email?.[0]} />
            <ApiErrorMessage error={editError?.status === 422 ? null : editError} />
          </form>
        )}
      </Modal>
      <Modal
        open={Boolean(action)}
        onClose={() => setAction(null)}
        title={action?.kind === 'delete' ? 'Permanently delete account?' : `${action?.user.is_active ? 'Deactivate' : 'Reactivate'} account?`}
        footer={(
          <>
            <Button type="button" variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button type="button" variant={action?.kind === 'delete' ? 'danger' : 'primary'} onClick={confirmAction}>
              {action?.kind === 'delete' ? 'Delete permanently' : action?.user.is_active ? 'Deactivate' : 'Reactivate'}
            </Button>
          </>
        )}
      >
        {action?.kind === 'delete'
          ? <p>This permanently removes {action.user.name} ({action.user.email}) and ends their active sessions. This cannot be undone.</p>
          : <p>{action?.user.name} will {action?.user.is_active ? 'no longer be able to sign in.' : 'be able to sign in again.'}</p>}
      </Modal>
    </section>
  )
}
