import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../../auth/roles'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, Input, Modal, PageHeader, Select, Table } from '../../../components/ui'

const AGENCY_USER_ROLES = [ROLES.AGENCY_EMPLOYEE, ROLES.AGENCY_CLIENT]

export function AgencyUsersPage() {
  const { user } = useAuth()
  const isAgencyOwner = user.role === ROLES.AGENCY_OWNER
  const [users, setUsers] = useState([])
  const [isLoadingUsers, setIsLoadingUsers] = useState(true)
  const [usersError, setUsersError] = useState(null)
  const [createError, setCreateError] = useState(null)
  const [createSuccess, setCreateSuccess] = useState('')
  const [isCreating, setIsCreating] = useState(false)
  const [createRole, setCreateRole] = useState(isAgencyOwner ? ROLES.AGENCY_EMPLOYEE : ROLES.AGENCY_CLIENT)
  const [editingUser, setEditingUser] = useState(null)
  const [editError, setEditError] = useState(null)
  const [isSavingEdit, setIsSavingEdit] = useState(false)
  const [confirmation, setConfirmation] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [isActing, setIsActing] = useState(false)
  const [pendingUserId, setPendingUserId] = useState(null)

  const loadUsers = useCallback(async () => {
    setIsLoadingUsers(true)
    setUsersError(null)
    try {
      const response = await authService.listAgencyUsers()
      setUsers(response.users)
    } catch (error) {
      setUsersError(error)
    } finally {
      setIsLoadingUsers(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAgencyUsers()
      .then(({ users: agencyUsers }) => {
        if (active) setUsers(agencyUsers)
      })
      .catch((error) => {
        if (active) setUsersError(error)
      })
      .finally(() => {
        if (active) setIsLoadingUsers(false)
      })

    return () => {
      active = false
    }
  }, [])

  async function createUser(event) {
    event.preventDefault()
    if (isCreating) return
    const form = event.currentTarget
    const data = new FormData(form)
    const email = String(data.get('email')).trim()
    setCreateError(null)
    setCreateSuccess('')
    setIsCreating(true)

    try {
      await authService.createAgencyUser({
        name: String(data.get('name')).trim(),
        email,
        role: createRole,
      })
      setCreateSuccess(`Invitation sent to ${email}.`)
      form.reset()
      setCreateRole(isAgencyOwner ? ROLES.AGENCY_EMPLOYEE : ROLES.AGENCY_CLIENT)
      await loadUsers()
    } catch (error) {
      setCreateError(error)
    } finally {
      setIsCreating(false)
    }
  }

  async function saveUser(event) {
    event.preventDefault()
    if (!editingUser || isSavingEdit) return
    const data = new FormData(event.currentTarget)
    setEditError(null)
    setIsSavingEdit(true)

    try {
      const { user } = await authService.updateAgencyUser(editingUser.id, {
        name: String(data.get('name')).trim(),
        email: String(data.get('email')).trim(),
        ...(isAgencyOwner ? { role: String(data.get('role')) } : {}),
      })
      setEditingUser(null)
      setCreateSuccess(user.password_setup_email_sent
        ? `Account updated. A password setup email was sent to ${user.email}.`
        : 'Account updated.')
      await loadUsers()
    } catch (error) {
      setEditError(error)
    } finally {
      setIsSavingEdit(false)
    }
  }

  async function confirmAction() {
    if (!confirmation) return
    setActionError(null)
    setIsActing(true)
    try {
      if (confirmation.kind === 'delete') {
        await authService.deleteAgencyUser(confirmation.user.id)
        setCreateSuccess(`${confirmation.user.name} was permanently deleted.`)
      } else {
        await authService.updateAgencyUser(confirmation.user.id, { is_active: false })
        setCreateSuccess(`${confirmation.user.name} was deactivated.`)
      }
      setConfirmation(null)
      await loadUsers()
    } catch (error) {
      setActionError(error)
    } finally {
      setIsActing(false)
    }
  }

  async function reactivateUser(user) {
    setActionError(null)
    setPendingUserId(user.id)
    try {
      await authService.updateAgencyUser(user.id, { is_active: true })
      setCreateSuccess(`${user.name} was reactivated.`)
      await loadUsers()
    } catch (error) {
      setActionError(error)
    } finally {
      setPendingUserId(null)
    }
  }

  const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    {
      key: 'role',
      label: 'Account type',
      render: (role) => <Badge variant={role === ROLES.AGENCY_EMPLOYEE ? 'primary' : 'info'}>{ROLE_LABELS[role] ?? 'Agency member'}</Badge>,
    },
    {
      key: 'is_active',
      label: 'Status',
      render: (isActive) => <Badge variant={isActive ? 'success' : 'slate'}>{isActive ? 'Active' : 'Inactive'}</Badge>,
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, account) => account.can_manage ? (
        <div className="agency-user-actions">
          <Button type="button" variant="secondary" size="sm" onClick={() => { setEditError(null); setEditingUser(account) }}>Edit</Button>
          {account.is_active
            ? <Button type="button" variant="secondary" size="sm" onClick={() => setConfirmation({ kind: 'deactivate', user: account })}>Deactivate</Button>
            : <Button type="button" variant="secondary" size="sm" loading={pendingUserId === account.id} loadingText="Activating…" onClick={() => reactivateUser(account)}>Reactivate</Button>}
          <Button type="button" variant="danger" size="sm" onClick={() => setConfirmation({ kind: 'delete', user: account })}>Delete</Button>
        </div>
      ) : <span>View only</span>,
    },
  ]

  const editingRole = editingUser?.role === ROLES.AGENCY_CLIENT ? ROLES.AGENCY_CLIENT : ROLES.AGENCY_EMPLOYEE
  const fieldError = (error, field) => error?.errors?.[field]?.[0]

  return (
    <section className="page agency-users-page">
      <PageHeader
        eyebrow="Agency workspace"
        title={isAgencyOwner ? 'Employees & clients' : 'Agency clients'}
        subtitle={isAgencyOwner
          ? 'Manage your agency employees and clients. Admin-created employees are visible but read-only.'
          : 'Manage client accounts in your agency.'}
      />
      {createSuccess && <Alert variant="success">{createSuccess}</Alert>}
      <ApiErrorMessage error={actionError} />

      <Card title={isAgencyOwner ? 'Invite someone' : 'Invite a client'} description="They’ll receive an email to set their password.">
        <form className="agency-user-form" onSubmit={createUser}>
          <Input id="agency-user-name" label="Name" name="name" autoComplete="name" maxLength="120" required error={fieldError(createError, 'name')} />
          <Input id="agency-user-email" label="Email address" name="email" type="email" autoComplete="email" maxLength="254" required error={fieldError(createError, 'email')} />
          {isAgencyOwner
            ? <Select id="agency-user-role" label="Account type" name="role" value={createRole} onChange={(event) => setCreateRole(event.target.value)} required error={fieldError(createError, 'role')}>
              {AGENCY_USER_ROLES.map((role) => <option key={role} value={role}>{ROLE_LABELS[role]}</option>)}
            </Select>
            : <input type="hidden" name="role" value={ROLES.AGENCY_CLIENT} />}
          <ApiErrorMessage error={createError?.status === 422 ? null : createError} />
          <Button type="submit" loading={isCreating} loadingText="Sending invitation…">Invite user</Button>
        </form>
      </Card>

      <Card title={isAgencyOwner ? 'Agency accounts' : 'Agency clients'} description={isAgencyOwner ? 'Agency employees and clients are listed here; admin-created employees are read-only.' : 'Only clients belonging to your agency are shown.'}>
        <ApiErrorMessage error={usersError} />
        {usersError && <Button type="button" variant="secondary" size="sm" onClick={loadUsers}>Try again</Button>}
        <Table
          columns={columns}
          rows={users}
          loading={isLoadingUsers}
          emptyTitle="No agency accounts yet"
          emptyText={isAgencyOwner ? 'Invite an employee or client to get started.' : 'Invite a client to get started.'}
        />
      </Card>

      <Modal
        open={Boolean(editingUser)}
        onClose={() => setEditingUser(null)}
        title="Edit account"
        footer={(
          <>
            <Button type="button" variant="secondary" onClick={() => setEditingUser(null)}>Cancel</Button>
            <Button type="submit" form="agency-user-edit-form" loading={isSavingEdit} loadingText="Saving…">Save changes</Button>
          </>
        )}
      >
        {editingUser && (
          <form id="agency-user-edit-form" className="agency-user-form" onSubmit={saveUser}>
            <Input id="edit-agency-user-name" label="Name" name="name" defaultValue={editingUser.name} maxLength="120" required error={fieldError(editError, 'name')} />
            <Input id="edit-agency-user-email" label="Email address" name="email" type="email" defaultValue={editingUser.email} maxLength="254" required error={fieldError(editError, 'email')} />
            {isAgencyOwner && <Select id="edit-agency-user-role" label="Account type" name="role" defaultValue={editingRole} required error={fieldError(editError, 'role')}>
              {AGENCY_USER_ROLES.map((role) => <option key={role} value={role}>{ROLE_LABELS[role]}</option>)}
            </Select>}
            <ApiErrorMessage error={editError?.status === 422 ? null : editError} />
          </form>
        )}
      </Modal>

      <Modal
        open={Boolean(confirmation)}
        onClose={() => setConfirmation(null)}
        title={confirmation?.kind === 'delete' ? 'Permanently delete account?' : 'Deactivate account?'}
        footer={(
          <>
            <Button type="button" variant="secondary" onClick={() => setConfirmation(null)}>Cancel</Button>
            <Button type="button" variant="danger" loading={isActing} loadingText="Working…" onClick={confirmAction}>
              {confirmation?.kind === 'delete' ? 'Delete permanently' : 'Deactivate'}
            </Button>
          </>
        )}
      >
        {confirmation?.kind === 'delete'
          ? <p>This permanently removes {confirmation.user.name} ({confirmation.user.email}) and ends their active sessions. This cannot be undone.</p>
          : <p>{confirmation?.user.name} will no longer be able to sign in. You can reactivate this account later.</p>}
      </Modal>
    </section>
  )
}
