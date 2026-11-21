import { useRef } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Avatar, Button, FieldRow, FormSection, Input, Select, Textarea } from '../../../components/ui'
import { RepeatableList } from './RepeatableList'
import { SkillsInput } from './SkillsInput'
import {
  AVAILABILITY, CHECKLIST_ITEMS, DEPARTMENTS, EMPLOYMENT_STATUSES, EMPLOYMENT_TYPES, ENGLISH_LEVELS, GENDERS,
  firstError,
} from './employeeForm'

// A stable loader: useAsync reruns whenever the function it is given changes identity.
const loadTeamLeadOptions = () => authService.listAdminUsers({ role: 'employee', perPage: 100 })
const TIME_ZONES =typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []
const SIXTEEN_YEARS_AGO = (() => { const d = new Date(); d.setFullYear(d.getFullYear() - 16); return d.toISOString().slice(0, 10) })()

const EDUCATION_FIELDS = [
  { key: 'degree', label: 'Degree', required: true, maxLength: 160 },
  { key: 'institution', label: 'Institution', required: true, maxLength: 160 },
  { key: 'start_date', label: 'Start date', type: 'date' },
  { key: 'end_date', label: 'End date', type: 'date' },
  { key: 'description', label: 'Notes', wide: true, maxLength: 1000 },
]
const EXPERIENCE_FIELDS = [
  { key: 'title', label: 'Job title', required: true, maxLength: 160 },
  { key: 'company', label: 'Company', required: true, maxLength: 160 },
  { key: 'start_date', label: 'Start date', type: 'date' },
  { key: 'end_date', label: 'End date', type: 'date' },
  { key: 'description', label: 'What they did', wide: true, maxLength: 1000 },
]
const CERTIFICATION_FIELDS = [
  { key: 'name', label: 'Certification', required: true, maxLength: 160 },
  { key: 'issuer', label: 'Issuer', maxLength: 160 },
  { key: 'issued_at', label: 'Issued', type: 'date' },
  { key: 'expires_at', label: 'Expires', type: 'date' },
  { key: 'credential_url', label: 'Credential link', type: 'url', maxLength: 255 },
]

// `field(name)` wires a controlled input to the form state and its API error.
function useField(values, set, errors) {
  return (name, extra = {}) => ({
    name,
    value: values[name] ?? '',
    onChange: (event) => set(name, event.target.value),
    error: firstError(errors, name),
    ...extra,
  })
}

const Options = ({ options, blank = 'Not set' }) => (
  <>
    {blank && <option value="">{blank}</option>}
    {options.map((option) => <option key={option.value ?? option} value={option.value ?? option}>{option.label ?? option}</option>)}
  </>
)

export function AccountPanel({ values, set, errors, mode, photoUrl, photoBusy, onPhotoUpload, onPhotoRemove }) {
  const field = useField(values, set, errors)
  const fileRef = useRef(null)

  return (
    <>
      <FormSection title="Account" description="They get an email with a link to set their own password.">
        <Input id="emp-name" label="Name" maxLength="120" autoComplete="off" required {...field('name')} />
        {mode === 'create'
          ? <Input id="emp-email" label="Email address" type="email" maxLength="254" autoComplete="off" required {...field('email')} />
          : (
            <Input
              id="emp-email"
              label="Sign-in email"
              type="email"
              value={values.email}
              readOnly
              helperText="To change the sign-in email or deactivate the account, use Edit on the Employees list."
            />
          )}
      </FormSection>
      <FormSection title="Photo" description="Shown to agencies on the employee's public profile.">
        {mode === 'create'
          ? <p className="emp-empty">You can add a photo right after the account is created.</p>
          : (
            <div className="emp-photo">
              <Avatar name={values.name} src={photoUrl} size="xl" />
              <div className="emp-photo-actions">
                <input
                  ref={fileRef}
                  type="file"
                  accept=".jpg,.jpeg,.png,.webp"
                  hidden
                  onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) onPhotoUpload(file) }}
                />
                <Button type="button" variant="secondary" size="sm" loading={photoBusy} loadingText="Working…" onClick={() => fileRef.current?.click()}>
                  {photoUrl ? 'Change photo' : 'Upload photo'}
                </Button>
                {photoUrl && <Button type="button" variant="ghost" size="sm" disabled={photoBusy} onClick={onPhotoRemove}>Remove</Button>}
                <p className="ui-field-help">JPG, PNG or WEBP, up to 4 MB.</p>
              </div>
            </div>
          )}
      </FormSection>
    </>
  )
}

export function PersonalPanel({ values, set, errors, mode, employee, onReveal }) {
  const field = useField(values, set, errors)
  const hasCnic = mode === 'edit' && employee?.profile?.has_cnic

  return (
    <>
      <Alert variant="info">Personal details are visible to administrators only. They are never shown to agencies.</Alert>
      <FormSection title="Identity">
        <FieldRow>
          <Input id="emp-father" label="Father's name" maxLength="120" {...field('father_name')} />
          <Input id="emp-dob" label="Date of birth" type="date" min="1940-01-01" max={SIXTEEN_YEARS_AGO} {...field('date_of_birth')} />
        </FieldRow>
        <FieldRow>
          <Select id="emp-gender" label="Gender" {...field('gender')}><Options options={GENDERS} /></Select>
          <div className="emp-cnic">
            <Input
              id="emp-cnic"
              label="CNIC"
              inputMode="numeric"
              autoComplete="off"
              maxLength="15"
              placeholder="12345-1234567-1"
              helperText={hasCnic ? `On file: ${employee.profile.cnic_masked}. Leave blank to keep it.` : '13 digits. Stored encrypted.'}
              {...field('cnic')}
            />
            {hasCnic && <Button type="button" variant="secondary" size="sm" onClick={onReveal}>Reveal CNIC</Button>}
          </div>
        </FieldRow>
      </FormSection>
      <FormSection title="Contact">
        <FieldRow>
          <Input id="emp-phone" label="Phone" type="tel" maxLength="30" placeholder="+92 300 1234567" {...field('phone')} />
          <Input id="emp-whatsapp" label="WhatsApp" type="tel" maxLength="30" {...field('whatsapp')} />
        </FieldRow>
        <FieldRow>
          <Input id="emp-personal-email" label="Personal email" type="email" maxLength="254" {...field('personal_email')} />
          <Input id="emp-city" label="City" maxLength="100" {...field('city')} />
        </FieldRow>
        <Textarea id="emp-current-address" label="Current address" rows="2" maxLength="500" {...field('current_address')} />
        <Textarea id="emp-permanent-address" label="Permanent address" rows="2" maxLength="500" {...field('permanent_address')} />
      </FormSection>
      <FormSection title="Emergency contact">
        <FieldRow columns={3}>
          <Input id="emp-ec-name" label="Name" maxLength="120" {...field('emergency_contact_name')} />
          <Input id="emp-ec-relation" label="Relation" maxLength="60" {...field('emergency_contact_relation')} />
          <Input id="emp-ec-phone" label="Phone" type="tel" maxLength="30" {...field('emergency_contact_phone')} />
        </FieldRow>
      </FormSection>
    </>
  )
}

export function ProfessionalPanel({ values, set, errors }) {
  const field = useField(values, set, errors)

  return (
    <>
      <Alert variant="info">Everything on this tab is shown to agency owners and clients on the employee's public profile.</Alert>
      <FormSection title="Profile">
        <Input id="emp-headline" label="Headline" maxLength="120" placeholder="e.g. Full-stack developer with 5 years of Laravel" {...field('headline')} />
        <Textarea id="emp-bio" label="Bio" rows="4" maxLength="1000" helperText={`${values.bio.length} of 1000 characters`} {...field('bio')} />
        <FieldRow>
          <Select id="emp-department" label="Department" {...field('department')}><Options options={DEPARTMENTS} /></Select>
          <Select id="emp-english" label="English level" {...field('english_level')}><Options options={ENGLISH_LEVELS} /></Select>
        </FieldRow>
        <FieldRow columns={3}>
          <Select id="emp-availability" label="Availability" {...field('availability')}><Options options={AVAILABILITY} blank={null} /></Select>
          <Input id="emp-timezone" label="Time zone" list="emp-timezones" placeholder="Asia/Karachi" {...field('timezone')} />
          <Input id="emp-hours" label="Preferred hours" maxLength="120" placeholder="e.g. 10:00 to 18:00 PKT" {...field('preferred_hours')} />
        </FieldRow>
        <datalist id="emp-timezones">{TIME_ZONES.map((zone) => <option key={zone} value={zone} />)}</datalist>
      </FormSection>
      <FormSection title="Links">
        <FieldRow columns={3}>
          <Input id="emp-portfolio" label="Portfolio" type="url" maxLength="255" placeholder="https://" {...field('portfolio_url')} />
          <Input id="emp-github" label="GitHub" type="url" maxLength="255" placeholder="https://github.com/…" {...field('github_url')} />
          <Input id="emp-linkedin" label="LinkedIn" type="url" maxLength="255" placeholder="https://linkedin.com/in/…" {...field('linkedin_url')} />
        </FieldRow>
      </FormSection>
      <FormSection title="Skills" description="Add each skill with a level, so agencies can see what this person is strongest at.">
        <SkillsInput skills={values.skills} onChange={(next) => set('skills', next)} errors={errors} />
      </FormSection>
      <FormSection title="Education">
        <RepeatableList name="educations" title="Education" items={values.educations} onChange={(next) => set('educations', next)} errors={errors} fields={EDUCATION_FIELDS} blank={{ degree: '', institution: '', start_date: '', end_date: '', description: '' }} addLabel="Add education" emptyText="No education added yet." />
      </FormSection>
      <FormSection title="Work experience">
        <RepeatableList name="experiences" title="Experience" items={values.experiences} onChange={(next) => set('experiences', next)} errors={errors} fields={EXPERIENCE_FIELDS} blank={{ title: '', company: '', start_date: '', end_date: '', description: '' }} addLabel="Add experience" emptyText="No experience added yet." />
      </FormSection>
      <FormSection title="Certifications">
        <RepeatableList name="certifications" title="Certification" items={values.certifications} onChange={(next) => set('certifications', next)} errors={errors} fields={CERTIFICATION_FIELDS} blank={{ name: '', issuer: '', issued_at: '', expires_at: '', credential_url: '' }} addLabel="Add certification" emptyText="No certifications added yet." />
      </FormSection>
    </>
  )
}

export function EmploymentPanel({ values, set, errors, mode, employee }) {
  const field = useField(values, set, errors)
  // Anyone can lead; the list is every employee except this one.
  const leads = useAsync(loadTeamLeadOptions)
  const leadOptions = (leads.data?.users ?? []).filter((user) => user.id !== employee?.id).map((user) => ({ value: user.id, label: user.name }))
  const hasBank = mode === 'edit' && employee?.profile?.bank_account_masked

  return (
    <>
      <FormSection title="Employment" description={mode === 'create' ? 'The employee code (EMP-0001, …) is generated automatically.' : `Employee code: ${employee?.profile?.employee_code ?? '—'}`}>
        <FieldRow>
          <Input id="emp-join" label="Join date" type="date" {...field('join_date')} />
          <Select id="emp-type" label="Employment type" {...field('employment_type')}><Options options={EMPLOYMENT_TYPES} /></Select>
        </FieldRow>
        <FieldRow>
          <Select id="emp-status" label="Status" {...field('employment_status')}><Options options={EMPLOYMENT_STATUSES} blank={null} /></Select>
          <Input id="emp-capacity" label="Weekly capacity (hours)" type="number" min="1" max="60" inputMode="numeric" helperText="Informational. Scheduling still uses the workspace-wide weekly limit." {...field('weekly_capacity_hours')} />
        </FieldRow>
        <FieldRow>
          <Input id="emp-probation" label="Probation ends" type="date" {...field('probation_end_date')} />
          <Input id="emp-contract" label="Contract ends" type="date" {...field('contract_end_date')} />
        </FieldRow>
        <Select id="emp-lead" label="Team lead" {...field('team_lead_id')}><Options options={leadOptions} blank="No team lead" /></Select>
      </FormSection>
      <FormSection title="Onboarding checklist">
        <div className="emp-checklist" role="group" aria-label="Onboarding checklist">
          {CHECKLIST_ITEMS.map((item) => (
            <label className="emp-check" key={item.key}>
              <input
                type="checkbox"
                checked={Boolean(values.checklist[item.key])}
                onChange={(event) => set('checklist', { ...values.checklist, [item.key]: event.target.checked })}
              />
              <span>{item.label}</span>
            </label>
          ))}
        </div>
      </FormSection>
      <FormSection title="Pay and notes" description="Administrators only. Never shown to agencies or the employee.">
        <FieldRow>
          <Input id="emp-pay" label="Pay rate" type="number" min="0" step="0.01" inputMode="decimal" {...field('pay_rate')} />
          <Input id="emp-payment-method" label="Payment method" maxLength="40" placeholder="Bank transfer" {...field('payment_method')} />
        </FieldRow>
        <FieldRow>
          <Input id="emp-bank" label="Bank name" maxLength="120" {...field('bank_name')} />
          <Input
            id="emp-account"
            label="Account / IBAN"
            maxLength="34"
            autoComplete="off"
            helperText={hasBank ? `On file: ${employee.profile.bank_account_masked}. Leave blank to keep it.` : 'Stored encrypted.'}
            {...field('bank_account')}
          />
        </FieldRow>
        <Textarea id="emp-notes" label="Internal notes" rows="3" maxLength="5000" {...field('internal_notes')} />
      </FormSection>
    </>
  )
}
