import { useEffect, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FieldRow, FormActions, FormSection, Input, Modal, Select, Textarea } from '../../../components/ui'

const EMPTY = { name: '', email: '', company_name: '', job_title: '', website: '', phone: '', preferred_contact_method: '', timezone: '', address: '', country: '', notes: '', send_invitation: true }
const FIELDS = Object.keys(EMPTY)
const FORM_ID = 'client-form'

function toForm(client) {
  if (!client) return EMPTY
  return Object.fromEntries(FIELDS.map((field) => [field, field === 'send_invitation' ? false : (client[field] ?? '')]))
}

// Create and edit share this one dialog: Account, Company, Contact and Notes, with the invitation choice on create.
export function ClientFormModal({ client, onClose, onSaved }) {
  const editing = Boolean(client)
  const [values, setValues] = useState(() => toForm(client))
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const bodyRef = useRef(null)

  // The parent mounts this dialog fresh each time it opens, so the state above starts clean; just focus the first field.
  useEffect(() => {
    const timer = window.setTimeout(() => document.getElementById('client-name')?.focus(), 0)
    return () => window.clearTimeout(timer)
  }, [])

  // After a failed save the keyboard goes to the first invalid field.
  useEffect(() => {
    if (error?.errors) document.querySelector(`#${FORM_ID} [aria-invalid="true"]`)?.focus()
  }, [error])

  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }))
  const fieldError = (field) => error?.errors?.[field]?.[0]

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    const payload = Object.fromEntries(FIELDS.filter((field) => editing ? field !== 'send_invitation' : true).map((field) => [field, typeof values[field] === 'string' ? values[field].trim() : values[field]]))
    try {
      const { client: saved } = editing
        ? await authService.updateAgencyClient(client.id, payload)
        : await authService.createAgencyClient(payload)
      onSaved(saved, { created: !editing, invited: !editing && values.send_invitation, emailChanged: Boolean(saved.password_setup_email_sent) })
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  const otherErrors = error && (error.status !== 422 || error.errors?.limit)
  const timezones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

  return (
    <Modal open onClose={() => !saving && onClose()} title={editing ? 'Edit client' : 'Create client'} className="client-modal">
      <form id={FORM_ID} className="ui-form" onSubmit={submit} noValidate ref={bodyRef}>
        {error?.errors?.limit && <Alert variant="warning">{error.errors.limit[0]}</Alert>}
        <FormSection title="Account" description="They sign in with this email.">
          <FieldRow>
            <Input id="client-name" label="Full name" required maxLength={120} autoComplete="off" value={values.name} onChange={set('name')} error={fieldError('name')} />
            <Input id="client-email" label="Email address" type="email" required maxLength={254} autoComplete="off" value={values.email} onChange={set('email')} error={fieldError('email')}
              helperText={editing ? 'Changing the email sends a new password setup link.' : undefined} />
          </FieldRow>
        </FormSection>
        <FormSection title="Company">
          <FieldRow>
            <Input id="client-company" label="Company name" maxLength={120} value={values.company_name} onChange={set('company_name')} error={fieldError('company_name')} />
            <Input id="client-job" label="Job title" maxLength={120} value={values.job_title} onChange={set('job_title')} error={fieldError('job_title')} />
          </FieldRow>
          <Input id="client-website" label="Website" type="url" maxLength={255} placeholder="https://example.com" value={values.website} onChange={set('website')} error={fieldError('website')} />
        </FormSection>
        <FormSection title="Contact">
          <FieldRow>
            <Input id="client-phone" label="Phone" type="tel" maxLength={30} value={values.phone} onChange={set('phone')} error={fieldError('phone')} />
            <Select id="client-method" label="Preferred contact" value={values.preferred_contact_method} onChange={set('preferred_contact_method')} error={fieldError('preferred_contact_method')}>
              <option value="">No preference</option>
              <option value="email">Email</option>
              <option value="phone">Phone</option>
              <option value="whatsapp">WhatsApp</option>
            </Select>
          </FieldRow>
          <FieldRow>
            <Input id="client-timezone" label="Time zone" list="client-timezones" placeholder="e.g. Europe/London" value={values.timezone} onChange={set('timezone')} error={fieldError('timezone')} />
            <Input id="client-country" label="Country" maxLength={100} value={values.country} onChange={set('country')} error={fieldError('country')} />
          </FieldRow>
          <datalist id="client-timezones">{timezones.map((zone) => <option key={zone} value={zone} />)}</datalist>
          <Input id="client-address" label="Address" maxLength={255} value={values.address} onChange={set('address')} error={fieldError('address')} />
        </FormSection>
        <FormSection title="Notes">
          <Textarea id="client-notes" label="Internal notes" rows={3} maxLength={2000} helperText="Only you and your support team see these." value={values.notes} onChange={set('notes')} error={fieldError('notes')} />
        </FormSection>
        {!editing && (
          <label className="ui-checkbox-row">
            <input type="checkbox" checked={values.send_invitation} onChange={set('send_invitation')} />
            <span>Send the invitation email now</span>
          </label>
        )}
        <ApiErrorMessage error={otherErrors && !error?.errors?.limit ? error : null} />
        <FormActions submitLabel={editing ? 'Save changes' : 'Create client'} loadingLabel="Saving…" loading={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
