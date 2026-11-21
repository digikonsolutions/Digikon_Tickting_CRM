import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { FieldRow, FormSection, Input, Select, Textarea } from '../../../components/ui'
import { COMPANY_SIZES, firstError } from './onboardingForm'

// A stable loader: useAsync reruns whenever the function it is given changes identity.
const loadManagers = () => authService.listAccountManagers()

// Agency company details. Reusable: `value` is the agency object, `onChange` receives the whole next object.
// Internal notes live in the Setup step of the wizard (see SetupFields), but they belong to this same object.
export function AgencyFields({ value, onChange, errors, prefix = 'agency.' }) {
  const managers = useAsync(loadManagers)
  const field = (name, extra = {}) => ({
    value: value[name] ?? '',
    onChange: (event) => onChange({ ...value, [name]: event.target.value }),
    error: firstError(errors, `${prefix}${name}`),
    ...extra,
  })

  return (
    <>
      <FormSection title="Agency" description="The company the owner represents. Capitalisation is tidied automatically.">
        <FieldRow>
          <Input id="agency-name" label="Agency name" maxLength="160" autoComplete="off" required {...field('name')} />
          <Input id="agency-brand" label="Brand name" maxLength="160" helperText="If it differs from the agency name." {...field('brand_name')} />
        </FieldRow>
        <FieldRow>
          <Input id="agency-legal" label="Legal name" maxLength="160" {...field('legal_name')} />
          <Input id="agency-website" label="Website" type="url" maxLength="255" placeholder="https://" {...field('website')} />
        </FieldRow>
        <FieldRow>
          <Input id="agency-industry" label="Industry" maxLength="100" placeholder="e.g. Marketing" {...field('industry')} />
          <Select id="agency-size" label="Company size" {...field('company_size')}>
            <option value="">Not set</option>
            {COMPANY_SIZES.map((size) => <option key={size} value={size}>{size} people</option>)}
          </Select>
        </FieldRow>
      </FormSection>
      <FormSection title="Location">
        <FieldRow>
          <Input id="agency-country" label="Country" maxLength="100" {...field('country')} />
          <Input id="agency-city" label="City" maxLength="100" {...field('city')} />
        </FieldRow>
        <Textarea id="agency-address" label="Address" rows="2" maxLength="500" {...field('address')} />
      </FormSection>
      <FormSection title="Billing and ownership" description="Optional. Used by your team, never shown to the agency.">
        <FieldRow>
          <Input id="agency-billing-contact" label="Billing contact" maxLength="120" {...field('billing_contact_name')} />
          <Input id="agency-billing-email" label="Billing email" type="email" maxLength="254" {...field('billing_email')} />
        </FieldRow>
        <FieldRow>
          <Input id="agency-tax" label="Tax number" maxLength="50" autoComplete="off" helperText="Stored encrypted." {...field('tax_number')} />
          <Select id="agency-manager" label="Account manager" helperText={managers.error ? 'Could not load your team. You can set this later.' : undefined} {...field('account_manager_id')}>
            <option value="">Unassigned</option>
            {(managers.data?.managers ?? []).map((manager) => <option key={manager.id} value={manager.id}>{manager.name}{manager.role === 'team_lead' ? ' (team lead)' : ''}</option>)}
          </Select>
        </FieldRow>
      </FormSection>
    </>
  )
}
