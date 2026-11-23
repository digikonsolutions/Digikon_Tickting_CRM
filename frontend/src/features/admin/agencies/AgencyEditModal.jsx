import { useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Modal, Spinner, Textarea, useToast } from '../../../components/ui'
import { AgencyFields } from '../agency-onboarding/AgencyFields'

const FIELDS = ['name', 'legal_name', 'brand_name', 'website', 'industry', 'company_size', 'country', 'city', 'address', 'billing_contact_name', 'billing_email', 'account_manager_id', 'internal_notes']

// Edit an agency's name and company details (admin). `agency` is { id, name } or null. The agency is loaded fresh so the
// form always starts from what is stored; contract, status and branding are changed elsewhere.
export function AgencyEditModal({ agency, onClose, onDone }) {
  const { showToast } = useToast()
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const agencyId = agency?.id

  useEffect(() => {
    if (!agencyId) return undefined
    let active = true
    authService.getAdminAgency(agencyId).then(({ agency: full }) => {
      if (!active || !full) return
      const profile = full.profile ?? {}
      setForm({
        name: full.name ?? '',
        ...Object.fromEntries(FIELDS.slice(1).map((key) => [key, profile[key] ?? ''])),
        account_manager_id: profile.account_manager?.id ?? '',
        tax_number: '',
      })
    }).catch((reason) => { if (active) setError(reason) })
    return () => { active = false; setForm(null); setError(null) }
  }, [agencyId])

  async function save(event) {
    event.preventDefault()
    if (!form || saving) return
    setSaving(true)
    setError(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]))
      await authService.updateAdminAgencyDetails(agencyId, payload)
      showToast(`${agency.name} was updated.`)
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
      open={Boolean(agency)}
      onClose={() => !saving && onClose()}
      title="Edit agency"
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-edit-form" loading={saving} loadingText="Saving…" disabled={!form}>Save changes</Button>
        </>
      )}
    >
      {!form && !error && <Spinner label="Loading agency…" />}
      {form && (
        <form id="agency-edit-form" onSubmit={save} noValidate>
          <AgencyFields value={form} onChange={setForm} errors={error?.errors} prefix="" />
          <Textarea id="agency-notes" label="Internal notes" rows="3" maxLength="5000" helperText="Only administrators see these." value={form.internal_notes ?? ''} onChange={(event) => setForm({ ...form, internal_notes: event.target.value })} />
        </form>
      )}
      <ApiErrorMessage error={error} />
    </Modal>
  )
}
