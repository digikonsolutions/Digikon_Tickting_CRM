import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { authService } from '../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Alert, Badge, Button, Card, Input, PageHeader, Table } from '../../components/ui'

export function AgencyDirectoryPage() {
  const { user, updateUser } = useAuth()
  const isAgencyOwner = user.role === ROLES.AGENCY_OWNER
  const [users, setUsers] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [directoryError, setDirectoryError] = useState(null)
  const [name, setName] = useState(user.name)
  const [isSaving, setIsSaving] = useState(false)
  const [profileError, setProfileError] = useState(null)
  const [success, setSuccess] = useState('')

  const loadDirectory = useCallback(async () => {
    setIsLoading(true)
    setDirectoryError(null)
    try {
      const response = await authService.listAgencyDirectory()
      setUsers(response.users)
    } catch (error) {
      setDirectoryError(error)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAgencyDirectory()
      .then(({ users: agencyUsers }) => {
        if (active) setUsers(agencyUsers)
      })
      .catch((error) => {
        if (active) setDirectoryError(error)
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })
    return () => {
      active = false
    }
  }, [])

  async function updateProfile(event) {
    event.preventDefault()
    setProfileError(null)
    setSuccess('')
    setIsSaving(true)
    try {
      const response = await authService.updateAgencyProfile({ name: name.trim() })
      setName(response.user.name)
      updateUser(response.user)
      setSuccess('Your profile was updated.')
    } catch (error) {
      setProfileError(error)
    } finally {
      setIsSaving(false)
    }
  }

  const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    {
      key: 'role',
      label: 'Role',
      render: (role) => <Badge variant="slate">{ROLE_LABELS[role] ?? role}</Badge>,
    },
    {
      key: 'is_active',
      label: 'Status',
      render: (isActive) => <Badge variant={isActive ? 'success' : 'slate'}>{isActive ? 'Active' : 'Inactive'}</Badge>,
    },
  ]

  return (
    <section className="page agency-directory-page">
      <PageHeader
        eyebrow="Agency workspace"
        title={isAgencyOwner ? 'Agency users' : 'Agency directory'}
        subtitle={user.agency_name ? `People in ${user.agency_name}.` : 'People in your agency.'}
      />
      {success && <Alert variant="success">{success}</Alert>}
      {!isAgencyOwner && <Card title="My profile" description="You can update your display name. Contact your agency owner to change your email or account type.">
        <form className="agency-profile-form" onSubmit={updateProfile}>
          <Input label="Name" name="name" value={name} onChange={(event) => setName(event.target.value)} maxLength="120" required error={profileError?.errors?.name?.[0]} />
          <Input label="Email address" name="email" type="email" value={user.email} disabled />
          <ApiErrorMessage error={profileError?.status === 422 ? null : profileError} />
          <Button type="submit" loading={isSaving} loadingText="Saving…">Save profile</Button>
        </form>
      </Card>}
      <Card title="Agency members" description="This list is limited to users in your agency.">
        <ApiErrorMessage error={directoryError} />
        {directoryError && <Button type="button" variant="secondary" onClick={loadDirectory}>Try again</Button>}
        <Table
          columns={columns}
          rows={users}
          loading={isLoading}
          emptyTitle="No agency members found"
          emptyText="There are no other agency users to show."
        />
      </Card>
    </section>
  )
}
