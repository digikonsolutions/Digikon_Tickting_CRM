import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../../auth/roles'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Input, PageHeader } from '../../../components/ui'

export function AdminCreateUserPage({ role = ROLES.EMPLOYEE }) {
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState('')
  const fieldError = (field) => error?.errors?.[field]?.[0]

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
        agency_name: role === ROLES.AGENCY_OWNER ? String(data.get('agency_name')).trim() : undefined,
      })
      setSuccess(`Invite email sent to ${email}`)
      form.reset()
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <section className="page">
      <PageHeader
        eyebrow="Administrator"
        title={`Create ${ROLE_LABELS[role]}`}
        subtitle="Create an account and send an invitation to set a password."
      />
      <form className="admin-user-form" onSubmit={submit}>
        <Input id="user-name" label="Name" name="name" type="text" maxLength="120" autoComplete="name" required error={fieldError('name')} />
        <Input id="user-email" label="Email address" name="email" type="email" maxLength="254" autoComplete="email" required error={fieldError('email')} />
        {role === ROLES.AGENCY_OWNER && (
          <Input id="agency-name" label="Agency name" name="agency_name" type="text" maxLength="160" required error={fieldError('agency_name')} helperText="The agency will be created automatically and assigned a database ID." />
        )}
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
        {success && <Alert variant="success">{success}</Alert>}
        <Button type="submit" loading={isLoading} loadingText="Sending invite…">
          Create {ROLE_LABELS[role]?.toLowerCase() ?? 'user'}
        </Button>
      </form>
    </section>
  )
}
