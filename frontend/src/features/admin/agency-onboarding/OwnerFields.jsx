import { FieldRow, FormSection, Input, Select } from '../../../components/ui'
import { CONTACT_METHODS, firstError } from './onboardingForm'

const TIME_ZONES = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

// Owner account fields. Reusable: `value` is the owner object, `onChange` receives the whole next object, and
// `errors` is the API error map (keys like "owner.email"). `prefix` lets another page reuse it under a different key.
export function OwnerFields({ value, onChange, errors, prefix = 'owner.' }) {
  const field = (name, extra = {}) => ({
    value: value[name] ?? '',
    onChange: (event) => onChange({ ...value, [name]: event.target.value }),
    error: firstError(errors, `${prefix}${name}`),
    ...extra,
  })

  return (
    <>
      <FormSection title="Owner" description="The person who will run this agency's account. They get an email to set their own password.">
        <FieldRow>
          <Input id="owner-name" label="Full name" maxLength="120" autoComplete="off" required {...field('name')} />
          <Input id="owner-email" label="Email address" type="email" maxLength="254" autoComplete="off" required {...field('email')} />
        </FieldRow>
      </FormSection>
      <FormSection title="Contact details" description="Optional. Helps your team reach the owner.">
        <FieldRow>
          <Input id="owner-phone" label="Phone" type="tel" maxLength="30" placeholder="+92 300 1234567" {...field('phone')} />
          <Input id="owner-whatsapp" label="WhatsApp" type="tel" maxLength="30" {...field('whatsapp')} />
        </FieldRow>
        <FieldRow columns={3}>
          <Input id="owner-title" label="Job title" maxLength="120" {...field('job_title')} />
          <Select id="owner-contact" label="Preferred contact" {...field('preferred_contact_method')}>
            <option value="">No preference</option>
            {CONTACT_METHODS.map((method) => <option key={method.value} value={method.value}>{method.label}</option>)}
          </Select>
          <Input id="owner-timezone" label="Time zone" list="owner-timezones" placeholder="Asia/Karachi" {...field('timezone')} />
        </FieldRow>
        <datalist id="owner-timezones">{TIME_ZONES.map((zone) => <option key={zone} value={zone} />)}</datalist>
      </FormSection>
    </>
  )
}
