import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { ROLE_LABELS } from '../../../auth/roles'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../components/ApiErrorMessage'
import { Alert, Badge, Card, FormActions, FormSection, Input, PageHeader, useToast } from '../../../components/ui'
import { normalizeName, PASSWORD_HINT } from '../../../lib/password'

// Errors the form already shows next to a field must not be repeated in the generic box. Anything else
// (another field, 429, 5xx, network) is shown by ApiErrorMessage so nothing is ever swallowed.
function unshownError(error, shownFields) {
  if (!error) return null
  if (error.status === 422) {
    const others = Object.keys(error.errors ?? {}).filter((field) => !shownFields.includes(field))
    if (others.length === 0) return null
    return { ...error, errors: Object.fromEntries(others.map((field) => [field, error.errors[field]])) }
  }
  return error
}

function NameCard({ user, updateUser }) {
  const { showToast } = useToast()
  const [name, setName] = useState(user.name)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const cleaned = normalizeName(name)
  const unchanged = cleaned === user.name

  async function saveProfile(event) {
    event.preventDefault()
    if (isSaving || unchanged) return
    setError(null)
    setIsSaving(true)
    try {
      const { user: updatedUser } = await authService.updateProfile({ name: cleaned }, user)
      // Sidebar, header avatar and the account menu all read this, so they change immediately.
      updateUser(updatedUser)
      setName(updatedUser.name)
      showToast('Your profile was updated.')
    } catch (reason) {
      setError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <Card className="ui-form-card" title="Profile details" description="Your role and agency can only be changed by an administrator or your agency owner.">
      <form className="ui-form" onSubmit={saveProfile} aria-label="Profile details" noValidate>
        <FormSection title="You">
          <Input
            id="profile-name"
            label="Name"
            name="name"
            value={name}
            onChange={(event) => { setName(event.target.value); setError(null) }}
            maxLength="200"
            autoComplete="name"
            required
            error={error?.errors?.name?.[0]}
            helperText="Shown to your team in the sidebar, tickets and comments."
          />
          <Input id="profile-email" label="Email address" value={user.email} disabled helperText="Ask an administrator to change your email address." />
        </FormSection>
        <FormSection title="Access">
          <div className="ui-field">
            <span className="ui-field-label">Signed-in role</span>
            <span><Badge variant={user.role === 'admin' ? 'primary' : 'slate'}>{ROLE_LABELS[user.role] ?? user.role}</Badge></span>
          </div>
          {user.agency_name && (
            <div className="ui-field">
              <span className="ui-field-label">Agency</span>
              <span>{user.agency_name}</span>
            </div>
          )}
        </FormSection>
        <ApiErrorMessage error={unshownError(error, ['name'])} />
        <FormActions
          submitLabel="Save profile"
          loadingLabel="Saving…"
          loading={isSaving}
          disabled={unchanged}
          disabledReason="Change your name to save."
          align="start"
        />
      </form>
    </Card>
  )
}

const EMPTY_PASSWORDS = { current: '', next: '', confirm: '' }

function PasswordCard() {
  const { showToast } = useToast()
  const [values, setValues] = useState(EMPTY_PASSWORDS)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [done, setDone] = useState(false)
  const mismatch = values.confirm !== '' && values.next !== values.confirm
  const ready = values.current !== '' && values.next !== '' && values.confirm !== '' && !mismatch

  function update(field, value) {
    setValues((current) => ({ ...current, [field]: value }))
    setError(null)
    setDone(false)
  }

  async function changePassword(event) {
    event.preventDefault()
    if (!ready || isSaving) return
    setError(null)
    setDone(false)
    setIsSaving(true)
    try {
      const result = await authService.changePassword({
        currentPassword: values.current,
        password: values.next,
        passwordConfirmation: values.confirm,
      })
      setValues(EMPTY_PASSWORDS)
      setDone(true)
      showToast(result.message ?? 'Your password was changed.')
    } catch (reason) {
      setError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  const fieldError = (field) => error?.errors?.[field]?.[0]

  return (
    <Card className="ui-form-card" title="Change password" description="You stay signed in on this device. Any other device is signed out.">
      <form className="ui-form" onSubmit={changePassword} aria-label="Change password" autoComplete="off" noValidate>
        <FormSection>
          <Input
            id="current-password"
            label="Current password"
            name="current_password"
            type="password"
            autoComplete="current-password"
            maxLength={128}
            value={values.current}
            onChange={(event) => update('current', event.target.value)}
            required
            error={fieldError('current_password')}
          />
          <Input
            id="new-password"
            label="New password"
            name="password"
            type="password"
            autoComplete="new-password"
            maxLength={128}
            value={values.next}
            onChange={(event) => update('next', event.target.value)}
            required
            helperText={PASSWORD_HINT}
            error={fieldError('password')}
          />
          <Input
            id="confirm-password"
            label="Confirm new password"
            name="password_confirmation"
            type="password"
            autoComplete="new-password"
            maxLength={128}
            value={values.confirm}
            onChange={(event) => update('confirm', event.target.value)}
            required
            error={mismatch ? 'The new password and its confirmation do not match.' : fieldError('password_confirmation')}
          />
        </FormSection>
        {done && <Alert variant="success">Your password was changed. Other devices have been signed out.</Alert>}
        <ApiErrorMessage error={unshownError(error, ['current_password', 'password', 'password_confirmation'])} />
        <FormActions
          submitLabel="Change password"
          loadingLabel="Changing…"
          loading={isSaving}
          disabled={!ready}
          disabledReason={mismatch ? 'The new password and its confirmation must match.' : 'Fill in all three fields.'}
          align="start"
        />
      </form>
    </Card>
  )
}

export function ProfilePage() {
  const { user, updateUser } = useAuth()

  return (
    <section className="page">
      <PageHeader eyebrow="Account" title="My profile" subtitle="Update how your name appears and keep your account secure." />
      <NameCard user={user} updateUser={updateUser} />
      <PasswordCard />
      <Card title="Notifications" description="Choose which updates reach you in the app and by email.">
        <Link className="ui-button ui-button-secondary ui-button-sm" to="/settings/notifications">Notification settings</Link>
      </Card>
    </section>
  )
}
