import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../../auth/roles'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { ActionMenu, Avatar, Badge, Button, Card, FormActions, FormSection, Input, Modal, PageHeader, Pagination, Table, useToast } from '../../../components/ui'
import { AVAILABILITY, AVAILABILITY_VARIANT, DEPARTMENTS, EMPLOYMENT_STATUSES, STATUS_VARIANT, labelOf } from '../employee/employeeForm'
import '../employee/employee.css'

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
  const { showToast } = useToast()
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
      showToast('Account updated.')
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
        showToast(`${action.user.name} was permanently deleted.`)
      } else {
        await authService.updateAdminUser(action.user.id, { is_active: !action.user.is_active })
        showToast(`${action.user.name} was ${action.user.is_active ? 'deactivated' : 'reactivated'}.`)
      }
      setAction(null)
      await loadUsers(pagination.current_page, search)
    } catch (reason) {
      setActionError(reason)
    }
  }

  const isEmployeeList = role === ROLES.EMPLOYEE
  const identityColumns = isEmployeeList
    ? [
      {
        key: 'name',
        label: 'Employee',
        render: (_name, user) => (
          <div className="emp-person">
            <Avatar name={user.name} src={user.photo_url} size="md" />
            <div>
              <Link to={`/admin/employees/${user.id}`}>{user.name}</Link>
              <small>{user.email}</small>
            </div>
          </div>
        ),
      },
      { key: 'employee_code', label: 'Code', render: (_v, user) => <span className="emp-code">{user.profile?.employee_code ?? '—'}</span> },
      { key: 'department', label: 'Department', render: (_v, user) => (user.profile?.department ? labelOf(DEPARTMENTS, user.profile.department) : '—') },
      {
        key: 'availability',
        label: 'Availability',
        render: (_v, user) => (user.profile
          ? <Badge variant={AVAILABILITY_VARIANT[user.profile.availability] ?? 'slate'}>{labelOf(AVAILABILITY, user.profile.availability)}</Badge>
          : '—'),
      },
      {
        key: 'employment_status',
        label: 'Status',
        render: (_v, user) => (!user.is_active
          ? <Badge variant="slate">Inactive</Badge>
          : <Badge variant={STATUS_VARIANT[user.profile?.employment_status] ?? 'success'}>{labelOf(EMPLOYMENT_STATUSES, user.profile?.employment_status ?? 'active')}</Badge>),
      },
      {
        key: 'completeness',
        label: 'Profile',
        render: (_v, user) => (
          <span className="emp-meter">
            <progress value={user.profile?.completeness ?? 0} max="100" aria-label={`Profile ${user.profile?.completeness ?? 0}% complete`} />
            {user.profile?.completeness ?? 0}%
          </span>
        ),
      },
    ]
    : [
      { key: 'name', label: 'Name' },
      { key: 'email', label: 'Email' },
      {
        key: 'role',
        label: 'Role',
        render: (value) => <Badge variant={value === 'admin' ? 'primary' : 'slate'}>{ROLE_LABELS[value] ?? value}</Badge>,
      },
      { key: 'agency_name', label: 'Agency', render: (name) => name || 'Internal' },
      {
        key: 'is_active',
        label: 'Status',
        render: (isActive) => <Badge variant={isActive ? 'success' : 'slate'}>{isActive ? 'Active' : 'Inactive'}</Badge>,
      },
    ]
  const columns = [
    ...identityColumns,
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, user) => user.can_manage ? (
        <ActionMenu
          label={`Actions for ${user.name}`}
          items={[
            ...(user.role === 'employee' ? [
              { label: 'View profile', to: `/admin/employees/${user.id}` },
              { label: 'Work activity', to: `/admin/employees/${user.id}/activity` },
            ] : []),
            { label: 'Edit account', onClick: () => { setEditError(null); setEditingUser(user) } },
            { label: user.is_active ? 'Deactivate' : 'Reactivate', onClick: () => setAction({ kind: 'status', user }) },
            { label: 'Delete', danger: true, separator: true, onClick: () => setAction({ kind: 'delete', user }) },
          ]}
        />
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
      <ApiErrorMessage error={actionError} />
      <Card title={title} description={`${pagination.total} account${pagination.total === 1 ? '' : 's'} total.`}>
        <form className="admin-users-search" onSubmit={submitSearch}>
          <Input
            id="admin-users-search"
            label="Search users"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder={isEmployeeList ? 'Search by name, email or employee code' : 'Search by name or email'}
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
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} total={pagination.total} noun="account" loading={isLoading} onPrevious={() => loadUsers(pagination.current_page - 1, search)} onNext={() => loadUsers(pagination.current_page + 1, search)} />
      </Card>
      <Modal
        open={Boolean(editingUser)}
        onClose={() => setEditingUser(null)}
        title="Edit account"
      >
        {editingUser && (
          <form id="admin-user-edit-form" className="ui-form" onSubmit={saveUser}>
            <FormSection>
              <Input id="edit-admin-user-name" label="Name" name="name" defaultValue={editingUser.name} maxLength="120" required error={editError?.errors?.name?.[0]} />
              <Input id="edit-admin-user-email" label="Email address" name="email" type="email" defaultValue={editingUser.email} maxLength="254" required error={editError?.errors?.email?.[0]} />
            </FormSection>
            <ApiErrorMessage error={editError?.status === 422 ? null : editError} />
            <FormActions submitLabel="Save changes" loadingLabel="Saving…" loading={isSaving} onCancel={() => setEditingUser(null)} />
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
