import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { authService } from '../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Table } from '../../components/ui'

export function AgencyDirectoryPage() {
  const { user } = useAuth()
  const isAgencyOwner = user.role === ROLES.AGENCY_OWNER
  const [users, setUsers] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [directoryError, setDirectoryError] = useState(null)

  const loadDirectory = useCallback(async () => {
    setIsLoading(true)
    setDirectoryError(null)
    try {
      const response = await authService.listAgencyDirectory()
      setUsers(response.users.filter((account) => (
        String(account.id) !== String(user.id)
        && account.email?.trim().toLowerCase() !== user.email?.trim().toLowerCase()
      )))
    } catch (error) {
      setDirectoryError(error)
    } finally {
      setIsLoading(false)
    }
  }, [user.email, user.id])

  useEffect(() => {
    let active = true
    authService.listAgencyDirectory()
      .then(({ users: agencyUsers }) => {
        if (active) {
          setUsers(agencyUsers.filter((account) => (
            String(account.id) !== String(user.id)
            && account.email?.trim().toLowerCase() !== user.email?.trim().toLowerCase()
          )))
        }
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
  }, [user.email, user.id])

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
      <Card
        title="Agency members"
        description={<>This list excludes your signed-in account. <Link className="inline-link" to="/profile">Edit your profile</Link>.</>}
      >
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
