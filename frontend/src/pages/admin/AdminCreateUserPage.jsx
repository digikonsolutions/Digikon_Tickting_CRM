import { useEffect, useState } from 'react'
import { authService } from '../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'

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
      <p className="eyebrow">Administrator</p>
      <h1>Create User</h1>
      <p className="page-copy">Create an account and send an invitation to set a password.</p>
      <form className="admin-user-form" onSubmit={submit}>
        <label className="field" htmlFor="user-name">
          <span>Name</span>
          <input id="user-name" name="name" type="text" maxLength="120" autoComplete="name" required aria-invalid={Boolean(fieldError('name'))} aria-describedby={fieldError('name') ? 'user-name-error' : undefined} />
          {fieldError('name') && <span className="field-error" id="user-name-error">{fieldError('name')}</span>}
        </label>
        <label className="field" htmlFor="user-email">
          <span>Email address</span>
          <input id="user-email" name="email" type="email" maxLength="254" autoComplete="email" required aria-invalid={Boolean(fieldError('email'))} aria-describedby={fieldError('email') ? 'user-email-error' : undefined} />
          {fieldError('email') && <span className="field-error" id="user-email-error">{fieldError('email')}</span>}
        </label>
        <label className="field" htmlFor="user-role">
          <span>Role</span>
          <select
            id="user-role"
            name="role"
            value={role}
            onChange={(event) => changeRole(event.target.value)}
            required
            aria-invalid={Boolean(fieldError('role'))}
            aria-describedby={fieldError('role') ? 'user-role-error' : undefined}
          >
            {ROLE_OPTIONS.map((option) => (
              <option key={option} value={option}>{ROLE_LABELS[option]}</option>
            ))}
          </select>
          {fieldError('role') && <span className="field-error" id="user-role-error">{fieldError('role')}</span>}
        </label>
        {role === ROLES.AGENCY_OWNER && (
          <label className="field" htmlFor="agency-id">
            <span>Agency name</span>
            <input id="agency-id" name="agency_name" type="text" maxLength="160" required aria-invalid={Boolean(fieldError('agency_name'))} aria-describedby={fieldError('agency_name') ? 'agency-id-error' : undefined} />
            {fieldError('agency_name') && <span className="field-error" id="agency-id-error">{fieldError('agency_name')}</span>}
            <span className="field-help">The agency will be created automatically and assigned a database ID.</span>
          </label>
        )}
        {role === ROLES.AGENCY_MEMBER && (
          <label className="field" htmlFor="agency-id">
            <span>Agency</span>
            <select
              id="agency-id"
              name="agency_id"
              value={agencyId}
              onChange={(event) => setAgencyId(event.target.value)}
              required
              disabled={isLoadingAgencies || agencies.length === 0}
              aria-invalid={Boolean(fieldError('agency_id'))}
              aria-describedby={fieldError('agency_id') ? 'agency-id-error' : undefined}
            >
              <option value="">
                {isLoadingAgencies ? 'Loading agencies…' : agencies.length ? 'Select an agency' : 'No active agencies available'}
              </option>
              {agencies.map((agency) => (
                <option key={agency.id} value={agency.id}>{agency.name}</option>
              ))}
            </select>
            {fieldError('agency_id') && <span className="field-error" id="agency-id-error">{fieldError('agency_id')}</span>}
          </label>
        )}
        {agencyLoadError && <ApiErrorMessage error={agencyLoadError} />}
        {role === ROLES.AGENCY_MEMBER && !isLoadingAgencies && !agencyLoadError && agencies.length === 0 && (
          <p className="form-error" role="status">Create an agency owner first; that will create an agency you can assign here.</p>
        )}
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
        {success && <p className="form-success" role="status">{success}</p>}
        <button className="primary-button" type="submit" disabled={isLoading || isLoadingAgencies || Boolean(agencyLoadError) || (role === ROLES.AGENCY_MEMBER && agencies.length === 0)}>
          {isLoading ? 'Sending invite…' : 'Create user and send invite'}
        </button>
      </form>
    </section>
  )
}
