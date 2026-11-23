import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, FieldRow, Input, Modal, useToast } from '../../../components/ui'

const KEYS = ['slug', 'display_name', 'tagline', 'primary_color', 'support_email']

// Edit an agency's portal address and brand. Changing the address breaks links in emails already sent, so it says so.
export function AgencyBrandingModal({ agency, open, onClose, onDone }) {
  const { showToast } = useToast()
  const [form, setForm] = useState(() => Object.fromEntries(KEYS.map((key) => [key, agency?.branding?.[key] ?? ''])))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const field = (name, extra = {}) => ({
    value: form[name] ?? '',
    onChange: (event) => setForm({ ...form, [name]: event.target.value }),
    error: error?.errors?.[name]?.[0],
    ...extra,
  })

  async function save(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      const payload = Object.fromEntries(KEYS.map((key) => [key, form[key].trim() === '' ? null : form[key].trim()]))
      if (!payload.slug) delete payload.slug
      await authService.updateAdminAgencyBranding(agency.id, payload)
      showToast(`${agency.name}'s portal settings were saved.`)
      onDone?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Portal & branding"
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-branding-form" loading={saving} loadingText="Saving…">Save changes</Button>
        </>
      )}
    >
      <form id="agency-branding-form" onSubmit={save} noValidate>
        <Input id="brand-slug" label="Portal address" maxLength="40" helperText="Lowercase letters, numbers and dashes. Changing it breaks links in emails already sent." {...field('slug')} />
        <FieldRow>
          <Input id="brand-name" label="Display name" maxLength="120" {...field('display_name')} />
          <Input id="brand-tagline" label="Tagline" maxLength="140" {...field('tagline')} />
        </FieldRow>
        <FieldRow>
          <Input id="brand-color" label="Brand colour" placeholder="#1D4ED8" maxLength="7" {...field('primary_color')} />
          <Input id="brand-email" label="Support email" type="email" maxLength="254" {...field('support_email')} />
        </FieldRow>
      </form>
      <ApiErrorMessage error={error} />
    </Modal>
  )
}
