import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, EmptyState, FieldRow, FormActions, FormSection, Input, Modal, PageHeader, Select, Skeleton, Table, Textarea, useToast } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { BILLING_CYCLES, CURRENCIES, labelOf } from '../agency-onboarding/onboardingForm'
import '../agency-onboarding/onboarding.css'

const loadPlans = () => authService.listPlans()
const EMPTY = { name: '', tagline: '', description: '', best_for: '', features: '', sort_order: 0, is_custom: false, employees: '', hours_per_month: '', max_open_projects: '', max_client_accounts: '', price: '', currency: 'USD', billing_cycle: 'monthly', overage_rate: '', is_active: true }
const NUMBER_FIELDS = ['employees', 'hours_per_month', 'max_open_projects', 'max_client_accounts', 'price', 'overage_rate']

const toForm = (plan) => ({
  ...Object.fromEntries(Object.keys(EMPTY).map((key) => [key, plan[key] === null || plan[key] === undefined ? '' : plan[key]])),
  features: (plan.features ?? []).join('\n'),
  is_custom: Boolean(plan.is_custom),
  sort_order: plan.sort_order ?? 0,
})

function toPayload(form) {
  const payload = {
    name: form.name.trim(),
    tagline: form.tagline.trim() || null,
    best_for: form.best_for.trim() || null,
    features: String(form.features).split(/\r?\n/).map((line) => line.trim()).filter(Boolean),
    is_custom: form.is_custom,
    sort_order: Number(form.sort_order) || 0,
    description: form.description.trim() || null,
    currency: form.currency,
    billing_cycle: form.billing_cycle,
    is_active: form.is_active,
  }
  for (const field of NUMBER_FIELDS) payload[field] = form[field] === '' ? null : Number(form[field])
  return payload
}

// Plans the agency wizard offers. Editing a plan never changes contracts that already used it: a contract keeps a copy.
export function AdminPlansPage() {
  const { showToast } = useToast()
  const { data, error, isLoading, reload } = useAsync(loadPlans)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [saveError, setSaveError] = useState(null)
  const [saving, setSaving] = useState(false)
  const plans = data?.plans ?? []

  function open(plan) {
    setEditing(plan ?? {})
    setForm(plan ? toForm(plan) : EMPTY)
    setSaveError(null)
  }

  async function save(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setSaveError(null)
    try {
      if (editing?.id) await authService.updatePlan(editing.id, toPayload(form))
      else await authService.createPlan(toPayload(form))
      showToast(editing?.id ? 'Plan updated.' : 'Plan created.')
      setEditing(null)
      await reload()
    } catch (reason) {
      setSaveError(reason)
    } finally {
      setSaving(false)
    }
  }

  const field = (name) => ({
    value: form[name] ?? '',
    onChange: (event) => setForm((current) => ({ ...current, [name]: event.target.value })),
    error: saveError?.errors?.[name]?.[0],
  })

  const columns = [
    { key: 'name', label: 'Plan', render: (_v, plan) => <span><strong>{plan.name}</strong>{plan.is_custom && <> <Badge variant="info">Custom</Badge></>}{plan.tagline && <small className="adm-muted" style={{ display: 'block' }}>{plan.tagline}</small>}</span> },
    { key: 'employees', label: 'Seats', render: (value) => value ?? <span className="adm-muted">No limit</span> },
    { key: 'hours_per_month', label: 'Hours / month', render: (value) => value ?? <span className="adm-muted">No limit</span> },
    { key: 'max_open_projects', label: 'Open projects', render: (value) => value ?? <span className="adm-muted">No limit</span> },
    { key: 'features', label: 'Included', render: (_v, plan) => `${(plan.features ?? []).length} items` },
    { key: 'price', label: 'Price', render: (_v, plan) => (plan.price != null ? `${plan.currency} ${Number(plan.price).toLocaleString()} / ${labelOf(BILLING_CYCLES, plan.billing_cycle).toLowerCase()}` : <span className="adm-muted">Not set</span>) },
    { key: 'is_active', label: 'Status', render: (value) => <Badge variant={value ? 'success' : 'slate'}>{value ? 'Active' : 'Retired'}</Badge> },
    { key: 'actions', label: 'Actions', render: (_v, plan) => <Button type="button" size="sm" variant="secondary" aria-label={`Edit ${plan.name}`} onClick={() => open(plan)}>Edit</Button> },
  ]

  return (
    <section className="page onb-page">
      <PageHeader eyebrow="Administration" title="Plans" subtitle="Reusable limits and prices. A contract copies a plan when it is made." action={<Button type="button" onClick={() => open(null)}><Icon name="plus" size={16} /> New plan</Button>} />
      {error && <Alert variant="error" title="Could not load plans">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>}
      {isLoading && !data && <Skeleton variant="card" />}
      {data && plans.length === 0 && (
        <Card><EmptyState icon={<Icon name="file" size={22} />} title="No plans yet" action={<Button type="button" onClick={() => open(null)}>Create your first plan</Button>}>Plans save you retyping seats, hours and price for every agency.</EmptyState></Card>
      )}
      {plans.length > 0 && <Card><Table columns={columns} rows={plans} /></Card>}

      <Modal open={Boolean(editing)} onClose={() => !saving && setEditing(null)} title={editing?.id ? 'Edit plan' : 'New plan'} footer={null}>
        <form className="ui-form" onSubmit={save}>
          <FormSection description={editing?.id ? 'Changes apply to new contracts only. Existing contracts keep the numbers they were made with.' : undefined}>
            <FieldRow>
              <Input id="plan-name" label="Name" maxLength="120" required {...field('name')} />
              <Input id="plan-tagline" label="Small heading" maxLength="60" placeholder="e.g. Scale delivery" {...field('tagline')} />
            </FieldRow>
            <Textarea id="plan-description" label="Description" rows="2" maxLength="500" {...field('description')} />
            <Input id="plan-best" label="Best for" maxLength="255" placeholder="e.g. Best for agencies managing 10–20 sub-accounts." {...field('best_for')} />
            <Textarea id="plan-features" label="What's included" rows="6" helperText="One item per line." {...field('features')} />
            <FieldRow columns={3}>
              <Input id="plan-employees" label="Employees (seats)" type="number" min="0" max="1000" {...field('employees')} />
              <Input id="plan-hours" label="Hours per month" type="number" min="0" step="0.5" {...field('hours_per_month')} />
              <Input id="plan-projects" label="Max open projects" type="number" min="0" max="1000" {...field('max_open_projects')} />
            </FieldRow>
            <FieldRow columns={3}>
              <Input id="plan-clients" label="Max client accounts" type="number" min="0" max="1000" {...field('max_client_accounts')} />
              <Input id="plan-price" label="Price" type="number" min="0" step="0.01" {...field('price')} />
              <Select id="plan-currency" label="Currency" {...field('currency')}>{CURRENCIES.map((code) => <option key={code} value={code}>{code}</option>)}</Select>
            </FieldRow>
            <FieldRow>
              <Select id="plan-cycle" label="Billing cycle" {...field('billing_cycle')}>{BILLING_CYCLES.map((cycle) => <option key={cycle.value} value={cycle.value}>{cycle.label}</option>)}</Select>
              <Input id="plan-overage" label="Overage rate (per extra hour)" type="number" min="0" step="0.01" {...field('overage_rate')} />
            </FieldRow>
            <FieldRow>
              <Input id="plan-order" label="Order in lists" type="number" min="0" max="999" {...field('sort_order')} />
              <label className="onb-check"><input type="checkbox" checked={form.is_custom} onChange={(event) => setForm((current) => ({ ...current, is_custom: event.target.checked }))} /><span><strong>Custom plan</strong><small>No fixed price or limits; filled in per agency.</small></span></label>
            </FieldRow>
            <label className="onb-check"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} /><span>Available when creating agencies</span></label>
          </FormSection>
          <ApiErrorMessage error={saveError?.status === 422 ? null : saveError} />
          <FormActions submitLabel={editing?.id ? 'Save plan' : 'Create plan'} loadingLabel="Saving…" loading={saving} onCancel={() => setEditing(null)} />
        </form>
      </Modal>
    </section>
  )
}
