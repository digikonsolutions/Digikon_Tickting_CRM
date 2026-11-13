import { useState } from 'react'
import { useAuth } from '../../../auth/useAuth'
import { ROLE_LABELS } from '../../../auth/roles'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../components/ApiErrorMessage'
import { Badge, Button, Card, Input, PageHeader, useToast } from '../../../components/ui'

export function ProfilePage() {
  const { user, updateUser } = useAuth()
  const [name, setName] = useState(user.name)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const { showToast } = useToast()

  async function saveProfile(event) {
    event.preventDefault()
    setError(null)
    setIsSaving(true)

    try {
      const { user: updatedUser } = await authService.updateProfile({ name: name.trim() })
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
    <section className="page">
      <PageHeader eyebrow="Account" title="My profile" subtitle="Your account is kept out of other user lists. Update your display name here." />
      <Card title="Profile details" description="Your role and agency assignment can only be changed by an authorized administrator or agency owner.">
        <form className="agency-profile-form" onSubmit={saveProfile}>
          <Input
            id="profile-name"
            label="Name"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            maxLength="120"
            required
            error={error?.errors?.name?.[0]}
          />
          <Input id="profile-email" label="Email address" value={user.email} disabled />
          <div className="ui-field">
            <span className="ui-field-label">Signed-in role</span>
            <Badge variant={user.role === 'admin' ? 'primary' : 'slate'}>{ROLE_LABELS[user.role] ?? user.role}</Badge>
          </div>
          {user.agency_name && (
            <div className="ui-field">
              <span className="ui-field-label">Agency</span>
              <span>{user.agency_name}</span>
            </div>
          )}
          <ApiErrorMessage error={error?.status === 422 ? null : error} />
          <Button type="submit" loading={isSaving} loadingText="Saving…">Save profile</Button>
        </form>
      </Card>
    </section>
  )
}
