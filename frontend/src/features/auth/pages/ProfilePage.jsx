import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { ROLE_LABELS } from '../../../auth/roles'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../components/ApiErrorMessage'
import { Alert, Avatar, Badge, Button, Card, FormActions, FormSection, Input, PageHeader, useToast } from '../../../components/ui'
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

const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const PHOTO_MAX_BYTES = 4 * 1024 * 1024

// Pick, preview, then save. The server validates again (type sniffed from content, size); this only gives
// quick feedback. Saving a photo never touches the name or any other profile field.
function PhotoCard({ user, updateUser }) {
  const { showToast } = useToast()
  const inputRef = useRef(null)
  const [file, setFile] = useState(null)
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  // Derived from the chosen file; the effect only releases the old object URL.
  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])

  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  function choose(event) {
    const picked = event.target.files?.[0]
    event.target.value = ''
    setError(null)
    if (!picked) return
    if (!PHOTO_TYPES.includes(picked.type)) {
      setError({ message: 'Choose a JPG, PNG or WEBP image.' })
      return
    }
    if (picked.size > PHOTO_MAX_BYTES) {
      setError({ message: 'That image is larger than 4 MB. Choose a smaller one.' })
      return
    }
    setFile(picked)
  }

  function discard() {
    setFile(null)
    setError(null)
  }

  async function save() {
    if (!file || isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      const { user: updated } = await authService.uploadProfilePhoto(file, user)
      updateUser({ photo_url: updated.photo_url })
      discard()
      showToast('Your photo was updated.')
    } catch (reason) {
      setError({ message: reason.errors?.photo?.[0] ?? reason.message })
    } finally {
      setIsSaving(false)
    }
  }

  async function remove() {
    setIsSaving(true)
    setError(null)
    try {
      await authService.deleteProfilePhoto(user)
      updateUser({ photo_url: null })
      showToast('Your photo was removed.')
    } catch (reason) {
      setError({ message: reason.message })
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <Card className="ui-form-card" title="Profile photo" description="Shown in the header, sidebar and timelines. JPG, PNG or WEBP, up to 4 MB.">
      <div className="profile-photo">
        <Avatar name={user.name} src={preview ?? user.photo_url} size="xl" />
        <div className="profile-photo-actions">
          <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" className="sr-only" aria-label="Choose a photo" onChange={choose} />
          {file ? (
            <>
              <p className="profile-photo-note">Preview. Not saved yet.</p>
              <div className="profile-photo-buttons">
                <Button type="button" loading={isSaving} loadingText="Saving…" onClick={save}>Save photo</Button>
                <Button type="button" variant="secondary" disabled={isSaving} onClick={discard}>Cancel</Button>
              </div>
            </>
          ) : (
            <div className="profile-photo-buttons">
              <Button type="button" variant="secondary" onClick={() => inputRef.current?.click()}>{user.photo_url ? 'Change photo' : 'Upload photo'}</Button>
              {user.photo_url && <Button type="button" variant="ghost" loading={isSaving} onClick={remove}>Remove</Button>}
            </div>
          )}
          {error && <Alert variant="error">{error.message}</Alert>}
        </div>
      </div>
    </Card>
  )
}

export function ProfilePage() {
  const { user, updateUser } = useAuth()

  return (
    <section className="page">
      <PageHeader eyebrow="Account" title="My profile" subtitle="Update how your name appears and keep your account secure." />
      <PhotoCard user={user} updateUser={updateUser} />
      <NameCard user={user} updateUser={updateUser} />
      <PasswordCard />
      <Card title="Notifications" description="Choose which updates reach you in the app and by email.">
        <Link className="ui-button ui-button-secondary ui-button-sm" to="/settings/notifications">Notification settings</Link>
      </Card>
    </section>
  )
}
