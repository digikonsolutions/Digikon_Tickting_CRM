import { useEffect, useState } from 'react'
import { authService } from '../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Alert, Button, Input, PageHeader, Select } from '../../components/ui'

const ROLE_OPTIONS = [
  ROLES.ADMIN,
  ROLES.TEAM_LEAD,
  ROLES.EMPLOYEE,
  ROLES.QA,
  ROLES.AGENCY_OWNER,
  ROLES.AGENCY_MEMBER,
]

export function AdminCreateUserPage() {
  const [role, setRole] = useState(ROLES.EMPLOYEE)
  const [agencyId, setAgencyId] = useState('')
  const [agencies, setAgencies] = useState([])
  const [isLoadingAgencies, setIsLoadingAgencies] = useState(false)
  const [agencyLoadError, setAgencyLoadError] = useState(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState('')
  const fieldError = (field) => error?.errors?.[field]?.[0]

  useEffect(() => {
    if (role !== ROLES.AGENCY_MEMBER) return undefined

    let active = true

    authService.listAgencies()
      .then(({ agencies: availableAgencies }) => {
        if (active) setAgencies(availableAgencies)
      })
      .catch((reason) => {
        if (active) setAgencyLoadError(reason)
      })
      .finally(() => {
        if (active) setIsLoadingAgencies(false)
      })

    return () => {
      active = false
    }
  }, [role])

  async function submit(event) {
    event.preventDefault()
    const form = event.currentTarget
    const data = new FormData(form)
    setError(null)
    setSuccess('')
    setIsLoading(true)

    try {
      const email = String(data.get('email')).trim()
      await authService.createUser({
        name: String(data.get('name')).trim(),
        email,
        role,
        agency_id: role === ROLES.AGENCY_MEMBER ? agencyId : undefined,
        agency_name: role === ROLES.AGENCY_OWNER ? String(data.get('agency_name')).trim() : undefined,
      })
      setSuccess(`Invite email sent to ${email}`)
      form.reset()
      setRole(ROLES.EMPLOYEE)
      setAgencyId('')
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }

  function changeRole(nextRole) {
    setRole(nextRole)
    setAgencyId('')
    setAgencyLoadError(null)
    setIsLoadingAgencies(nextRole === ROLES.AGENCY_MEMBER)
    if (nextRole !== ROLES.AGENCY_MEMBER) setAgencies([])
  }

  return (
    <section className="page">
      <PageHeader eyebrow="Administrator" title="Create User" subtitle="Create an account and send an invitation to set a password." />
      <form className="admin-user-form" onSubmit={submit}>
        <Input id="user-name" label="Name" name="name" type="text" maxLength="120" autoComplete="name" required error={fieldError('name')} />
        <Input id="user-email" label="Email address" name="email" type="email" maxLength="254" autoComplete="email" required error={fieldError('email')} />
        <Select
            id="user-role"
            label="Role"
            name="role"
            value={role}
            onChange={(event) => changeRole(event.target.value)}
            required
            error={fieldError('role')}
          >
            {ROLE_OPTIONS.map((option) => (
              <option key={option} value={option}>{ROLE_LABELS[option]}</option>
            ))}
          </Select>
        {role === ROLES.AGENCY_OWNER && (
          <Input id="agency-id" label="Agency name" name="agency_name" type="text" maxLength="160" required error={fieldError('agency_name')} helperText="The agency will be created automatically and assigned a database ID." />
        )}
        {role === ROLES.AGENCY_MEMBER && (
          <Select
              id="agency-id"
              label="Agency"
              name="agency_id"
              value={agencyId}
              onChange={(event) => setAgencyId(event.target.value)}
              required
              disabled={isLoadingAgencies || agencies.length === 0}
              error={fieldError('agency_id')}
            >
              <option value="">
                {isLoadingAgencies ? 'Loading agencies…' : agencies.length ? 'Select an agency' : 'No active agencies available'}
              </option>
              {agencies.map((agency) => (
                <option key={agency.id} value={agency.id}>{agency.name}</option>
              ))}
            </Select>
        )}
        {agencyLoadError && <ApiErrorMessage error={agencyLoadError} />}
        {role === ROLES.AGENCY_MEMBER && !isLoadingAgencies && !agencyLoadError && agencies.length === 0 && (
          <Alert variant="info">Create an agency owner first; that will create an agency you can assign here.</Alert>
        )}
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
        {success && <Alert variant="success">{success}</Alert>}
        <Button type="submit" loading={isLoading} loadingText="Sending invite…" disabled={isLoadingAgencies || Boolean(agencyLoadError) || (role === ROLES.AGENCY_MEMBER && agencies.length === 0)}>
          Create user
        </Button>
      </form>
    </section>
  )
}
