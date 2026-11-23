import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Modal, useToast } from '../../../components/ui'
import { ContractFields } from '../agency-onboarding/ContractFields'
import { featuresOf, todayString } from '../agency-onboarding/onboardingForm'

const STATUSES = ['trial', 'active', 'paused', 'expired', 'cancelled'].map((value) => ({ value, label: value[0].toUpperCase() + value.slice(1) }))
const NUMBERS = ['employees', 'hours_per_month', 'max_open_projects', 'max_client_accounts', 'price', 'overage_rate', 'notice_period_days', 'payment_terms_days', 'discount_percent']
const loadPlans = () => authService.listPlans({ activeOnly: true })

const text = (value) => (value === null || value === undefined ? '' : String(value))

// The stored contract as the form's text fields; an agency with no contract starts from a blank active one.
function toForm(stored) {
  const base = {
    plan_id: '', employees: '', hours_per_month: '', max_open_projects: '', max_client_accounts: '', price: '', currency: 'USD',
    billing_cycle: 'monthly', overage_rate: '', start_date: todayString(), end_date: '', auto_renew: false, notice_period_days: '',
    status: 'active', trial_end_date: '', payment_terms_days: '', discount_percent: '', features: '',
  }
  if (!stored) return base
  return {
    ...base,
    ...Object.fromEntries(Object.keys(base).map((key) => [key, text(stored[key])]).filter(([key]) => key !== 'auto_renew' && key !== 'features')),
    auto_renew: Boolean(stored.auto_renew),
    features: (stored.features ?? []).join('\n'),
  }
}

// Every field is sent, so clearing one clears it on the server. Numbers go as numbers, empty values as null.
function toPayload(form) {
  const payload = {}
  for (const [key, value] of Object.entries(form)) {
    if (key === 'features' || key === 'auto_renew') continue
    payload[key] = typeof value === 'string' ? (value.trim() === '' ? null : value.trim()) : value
  }
  for (const key of NUMBERS) if (payload[key] !== null) payload[key] = Number(payload[key])
  if (payload.plan_id !== null) payload.plan_id = Number(payload.plan_id)
  if (payload.status !== 'trial') payload.trial_end_date = null
  if (payload.start_date === null) delete payload.start_date
  return { ...payload, auto_renew: form.auto_renew, features: featuresOf(form) }
}

// Edit (or first create) an agency's contract. `agency` is the detail page's agency object (it carries `contract_form`).
export function AgencyContractModal({ agency, open, onClose, onDone }) {
  const { showToast } = useToast()
  const plans = useAsync(loadPlans)
  const [form, setForm] = useState(() => toForm(agency?.contract_form))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  async function save(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.updateAdminAgencyContract(agency.id, toPayload(form))
      showToast(`${agency.name}'s contract was saved.`)
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
      title={agency?.contract_form ? 'Edit contract' : 'Add contract'}
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-contract-form" loading={saving} loadingText="Saving…">Save contract</Button>
        </>
      )}
    >
      <form id="agency-contract-form" onSubmit={save} noValidate>
        <ContractFields
          value={form}
          onChange={setForm}
          errors={error?.errors}
          prefix=""
          plans={plans.data?.plans}
          plansLoading={plans.isLoading}
          plansError={plans.error}
          statuses={STATUSES}
          hideDocument
        />
      </form>
      <ApiErrorMessage error={error} />
    </Modal>
  )
}
