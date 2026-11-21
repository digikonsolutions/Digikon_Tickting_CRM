import { Link } from 'react-router-dom'
import { useRef } from 'react'
import { Alert, Button, EmptyState, FieldRow, FormSection, Input, Select, Skeleton } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import {
  BILLING_CYCLES, CONTRACT_STATUSES, CURRENCIES, DOCUMENT_RULES, applyPlan, contractSummary, endDateFor, firstError, formatBytes, labelOf,
} from './onboardingForm'

// Plan and contract fields. Reusable: `value` is the contract object, `onChange` receives the whole next object.
// Picking a plan fills the limits (all still editable). The end date follows start date and billing cycle for as
// long as it still equals the calculated value, and stops following once the admin types their own.
export function ContractFields({
  value, onChange, errors, plans, plansLoading, plansError, document: contractDocument, onDocument, documentNotice, prefix = 'contract.',
}) {
  const fileRef = useRef(null)
  const field = (name, extra = {}) => ({
    value: value[name] ?? '',
    onChange: (event) => onChange({ ...value, [name]: event.target.value }),
    error: firstError(errors, `${prefix}${name}`),
    ...extra,
  })
  const endFollowsDates = value.end_date === '' || value.end_date === endDateFor(value.start_date, value.billing_cycle)

  function setStart(start) {
    onChange({ ...value, start_date: start, ...(endFollowsDates ? { end_date: endDateFor(start, value.billing_cycle) } : {}) })
  }
  function setCycle(cycle) {
    onChange({ ...value, billing_cycle: cycle, ...(endFollowsDates ? { end_date: endDateFor(value.start_date, cycle) } : {}) })
  }
  function setPlan(id) {
    onChange(applyPlan(value, (plans ?? []).find((plan) => String(plan.id) === id)))
  }
  const summary = contractSummary(value)
  const price = value.price !== '' ? `${value.currency} ${Number(value.price).toLocaleString()} ${labelOf(BILLING_CYCLES, value.billing_cycle).toLowerCase()}` : null

  return (
    <>
      <FormSection title="Plan" description="Choosing a plan fills in the limits below. You can change any of them for this agency.">
        {plansLoading && !plans && <Skeleton variant="row" />}
        {plansError && <Alert variant="warning">Could not load plans. You can still enter the limits by hand.</Alert>}
        {plans && plans.length === 0 && (
          <EmptyState icon={<Icon name="file" size={22} />} title="No plans yet" action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/admin/plans">Create a plan</Link>}>
            Plans save you retyping the same limits and price. You can also fill in a custom contract below.
          </EmptyState>
        )}
        {plans && plans.length > 0 && (
          <Select id="contract-plan" label="Plan" value={value.plan_id} onChange={(event) => setPlan(event.target.value)} error={firstError(errors, `${prefix}plan_id`)}>
            <option value="">Custom (no plan)</option>
            {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}{plan.price != null ? ` · ${plan.currency} ${plan.price}/${plan.billing_cycle}` : ''}</option>)}
          </Select>
        )}
        <FieldRow>
          <Select id="contract-status" label="Contract status" {...field('status')}>
            {CONTRACT_STATUSES.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
          </Select>
          {value.status === 'trial' && <Input id="contract-trial-end" label="Trial ends" type="date" required {...field('trial_end_date')} />}
        </FieldRow>
      </FormSection>

      <FormSection title="Limits" description="What the agency can use each month. Leave a field empty for no limit.">
        <FieldRow columns={3}>
          <Input id="contract-employees" label="Employees (seats)" type="number" min="0" max="1000" inputMode="numeric" {...field('employees')} />
          <Input id="contract-hours" label="Hours per month" type="number" min="0" step="0.5" inputMode="decimal" {...field('hours_per_month')} />
          <Input id="contract-projects" label="Max open projects" type="number" min="0" max="1000" inputMode="numeric" {...field('max_open_projects')} />
        </FieldRow>
        <FieldRow columns={3}>
          <Input id="contract-clients" label="Max client accounts" type="number" min="0" max="1000" inputMode="numeric" {...field('max_client_accounts')} />
          <Input id="contract-overage" label="Overage rate (per extra hour)" type="number" min="0" step="0.01" inputMode="decimal" {...field('overage_rate')} />
        </FieldRow>
        {summary.length > 0 && (
          <p className="onb-summary" aria-live="polite"><Icon name="check" size={16} /> This agency will get <strong>{summary.join(' · ')}</strong>{price ? <> for <strong>{price}</strong></> : null}.</p>
        )}
      </FormSection>

      <FormSection title="Price and billing">
        <FieldRow columns={3}>
          <Input id="contract-price" label="Price" type="number" min="0" step="0.01" inputMode="decimal" {...field('price')} />
          <Select id="contract-currency" label="Currency" {...field('currency')}>
            {CURRENCIES.map((code) => <option key={code} value={code}>{code}</option>)}
          </Select>
          <Select id="contract-cycle" label="Billing cycle" value={value.billing_cycle} onChange={(event) => setCycle(event.target.value)} error={firstError(errors, `${prefix}billing_cycle`)}>
            {BILLING_CYCLES.map((cycle) => <option key={cycle.value} value={cycle.value}>{cycle.label}</option>)}
          </Select>
        </FieldRow>
        <FieldRow columns={3}>
          <Input id="contract-terms" label="Payment terms (days)" type="number" min="0" max="365" inputMode="numeric" placeholder="e.g. 15" {...field('payment_terms_days')} />
          <Input id="contract-discount" label="Discount (%)" type="number" min="0" max="100" step="0.5" inputMode="decimal" {...field('discount_percent')} />
        </FieldRow>
      </FormSection>

      <FormSection title="Dates and renewal">
        <FieldRow>
          <Input id="contract-start" label="Start date" type="date" value={value.start_date} onChange={(event) => setStart(event.target.value)} error={firstError(errors, `${prefix}start_date`)} />
          <Input id="contract-end" label="End date" type="date" min={value.start_date || undefined} helperText={endFollowsDates ? 'Calculated from the billing cycle. Change it if the contract differs.' : 'Set by hand.'} {...field('end_date')} />
        </FieldRow>
        <FieldRow>
          <Input id="contract-notice" label="Notice period (days)" type="number" min="0" max="365" inputMode="numeric" {...field('notice_period_days')} />
          <label className="onb-check">
            <input type="checkbox" checked={value.auto_renew} onChange={(event) => onChange({ ...value, auto_renew: event.target.checked })} />
            <span>Renew automatically at the end date</span>
          </label>
        </FieldRow>
      </FormSection>

      <FormSection title="Signed contract" description="Optional PDF, JPG or PNG up to 10 MB. It uploads right after the agency is created.">
        <div className="onb-file">
          <input
            ref={fileRef}
            type="file"
            hidden
            accept={DOCUMENT_RULES.accept}
            onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) onDocument(file) }}
          />
          {contractDocument ? (
            <>
              <span><Icon name="file" size={18} /> <strong>{contractDocument.name}</strong> <span className="adm-muted">{formatBytes(contractDocument.size)}</span></span>
              <Button type="button" variant="ghost" size="sm" onClick={() => onDocument(null)}>Remove</Button>
            </>
          ) : (
            <Button type="button" variant="secondary" size="sm" onClick={() => fileRef.current?.click()}><Icon name="paperclip" size={16} /> Choose file</Button>
          )}
        </div>
        {documentNotice && <p className="ui-field-error" role="alert">{documentNotice}</p>}
      </FormSection>
    </>
  )
}
