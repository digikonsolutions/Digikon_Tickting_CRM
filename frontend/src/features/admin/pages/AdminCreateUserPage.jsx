import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ROLE_LABELS, ROLES } from '../../../auth/roles'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Card, FormActions, FormSection, Input, PageHeader, useToast } from '../../../components/ui'

export function AdminCreateUserPage({ role = ROLES.EMPLOYEE }) {
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState(null)
  const { showToast } = useToast()
  const fieldError = (field) => error?.errors?.[field]?.[0]

  async function submit(event) {
    event.preventDefault()
    const form = event.currentTarget
    const data = new FormData(form)
    setError(null)
    setIsLoading(true)

    try {
      const email = String(data.get('email')).trim()
      await authService.createUser({
        name: String(data.get('name')).trim(),
        email,
        role,
        agency_name: role === ROLES.AGENCY_OWNER ? String(data.get('agency_name')).trim() : undefined,
      })
      showToast(`Invite email sent to ${email}`)
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
      <Card className="ui-form-card">
        <form className="ui-form" onSubmit={submit}>
          <FormSection title="Account" description="They get an email with a link to set their own password.">
            <Input id="user-name" label="Name" name="name" type="text" maxLength="120" autoComplete="name" required error={fieldError('name')} />
            <Input id="user-email" label="Email address" name="email" type="email" maxLength="254" autoComplete="email" required error={fieldError('email')} />
          </FormSection>
          {role === ROLES.AGENCY_OWNER && (
            <FormSection title="Agency" description="The agency is created together with its owner.">
              <Input id="agency-name" label="Agency name" name="agency_name" type="text" maxLength="160" required error={fieldError('agency_name')} />
            </FormSection>
          )}
          {role === ROLES.EMPLOYEE && (
            <Alert variant="info">Employees’ sign-ins and work activity are recorded for administrators. The invitation email tells them so.</Alert>
          )}
          <ApiErrorMessage error={error?.status === 422 ? null : error} />
          <FormActions submitLabel={`Create ${ROLE_LABELS[role]?.toLowerCase() ?? 'user'}`} loadingLabel="Sending invite…" loading={isLoading} />
        </form>
      </Card>
    </section>
  )
}
