// Option lists and form <-> API mapping for the employee profile screens (create and edit share them).

export const DEPARTMENTS = [
  { value: 'ghl', label: 'GoHighLevel (GHL)' },
  { value: 'web', label: 'Web' },
  { value: 'software', label: 'Software' },
  { value: 'design', label: 'Design' },
  { value: 'qa', label: 'QA' },
  { value: 'other', label: 'Other' },
]
export const AVAILABILITY = [
  { value: 'available', label: 'Available' },
  { value: 'partial', label: 'Partly available' },
  { value: 'unavailable', label: 'Unavailable' },
]
export const AVAILABILITY_VARIANT = { available: 'success', partial: 'warning', unavailable: 'slate' }
export const EMPLOYMENT_TYPES = [
  { value: 'full_time', label: 'Full time' },
  { value: 'part_time', label: 'Part time' },
  { value: 'contract', label: 'Contract' },
]
export const EMPLOYMENT_STATUSES = [
  { value: 'probation', label: 'Probation' },
  { value: 'active', label: 'Active' },
  { value: 'notice', label: 'Serving notice' },
  { value: 'left', label: 'Left' },
]
export const STATUS_VARIANT = { probation: 'warning', active: 'success', notice: 'orange', left: 'slate' }
export const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
  { value: 'prefer_not_to_say', label: 'Prefer not to say' },
]
export const SKILL_LEVELS = [
  { value: 'beginner', label: 'Beginner' },
  { value: 'intermediate', label: 'Intermediate' },
  { value: 'expert', label: 'Expert' },
]
export const ENGLISH_LEVELS = ['Basic', 'Intermediate', 'Upper intermediate', 'Advanced', 'Fluent']
export const DOCUMENT_TYPES = [
  { value: 'cnic_front', label: 'CNIC (front)' },
  { value: 'cnic_back', label: 'CNIC (back)' },
  { value: 'cv', label: 'CV / resume' },
  { value: 'degree', label: 'Degree' },
  { value: 'experience_letter', label: 'Experience letter' },
  { value: 'contract', label: 'Contract' },
  { value: 'nda', label: 'NDA' },
  { value: 'other', label: 'Other' },
]
export const CHECKLIST_ITEMS = [
  { key: 'nda_signed', label: 'NDA signed' },
  { key: 'cnic_verified', label: 'CNIC verified' },
  { key: 'equipment_given', label: 'Equipment given' },
  { key: 'accounts_created', label: 'Accounts created' },
]
export const DOCUMENT_RULES = { maxBytes: 5 * 1024 * 1024, extensions: ['pdf', 'jpg', 'jpeg', 'png'], accept: '.pdf,.jpg,.jpeg,.png' }
export const MAX_SKILLS = 30
export const MAX_ROWS = 20

export const labelOf = (options, value) => options.find((option) => option.value === value)?.label ?? value ?? ''

// Text fields that live on the profile, in one place so the form, the payload and the tests agree.
const TEXT_FIELDS = [
  'father_name', 'date_of_birth', 'gender', 'phone', 'whatsapp', 'personal_email', 'current_address',
  'permanent_address', 'city', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
  'headline', 'bio', 'department', 'timezone', 'preferred_hours', 'english_level', 'portfolio_url', 'github_url',
  'linkedin_url', 'join_date', 'employment_type', 'probation_end_date', 'contract_end_date', 'payment_method',
  'bank_name', 'internal_notes', 'pay_rate',
]

export function emptyForm() {
  return {
    name: '',
    email: '',
    ...Object.fromEntries(TEXT_FIELDS.map((field) => [field, ''])),
    cnic: '',
    bank_account: '',
    availability: 'available',
    employment_status: 'probation',
    weekly_capacity_hours: 36,
    team_lead_id: '',
    checklist: Object.fromEntries(CHECKLIST_ITEMS.map((item) => [item.key, false])),
    skills: [],
    educations: [],
    experiences: [],
    certifications: [],
  }
}

// The detail response never carries the CNIC or bank account (only masked copies), so those two stay blank
// on edit and are only sent when the admin types a new value.
export function formFromEmployee(employee) {
  const profile = employee.profile ?? {}
  const base = emptyForm()
  const text = Object.fromEntries(TEXT_FIELDS.map((field) => [field, profile[field] ?? '']))
  return {
    ...base,
    ...text,
    name: employee.name ?? '',
    email: employee.email ?? '',
    availability: profile.availability ?? 'available',
    employment_status: profile.employment_status ?? 'active',
    weekly_capacity_hours: profile.weekly_capacity_hours ?? 36,
    team_lead_id: profile.team_lead_id ?? '',
    checklist: { ...base.checklist, ...(profile.checklist ?? {}) },
    skills: (employee.skills ?? []).map((s) => ({ name: s.name, level: s.level, years_experience: s.years_experience ?? '' })),
    educations: (employee.educations ?? []).map((e) => ({ degree: e.degree, institution: e.institution, start_date: e.start_date ?? '', end_date: e.end_date ?? '', description: e.description ?? '' })),
    experiences: (employee.experiences ?? []).map((e) => ({ title: e.title, company: e.company, start_date: e.start_date ?? '', end_date: e.end_date ?? '', description: e.description ?? '' })),
    certifications: (employee.certifications ?? []).map((c) => ({ name: c.name, issuer: c.issuer ?? '', issued_at: c.issued_at ?? '', expires_at: c.expires_at ?? '', credential_url: c.credential_url ?? '' })),
  }
}

const blankToNull = (value) => (value === '' || value === undefined ? null : value)

function cleanRows(rows) {
  return rows.map((row) => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, typeof value === 'string' ? blankToNull(value.trim()) : blankToNull(value)])))
}

// mode 'create': blank fields are left out entirely (everything except name and email is optional).
// mode 'update': a blank field is sent as null so the admin can clear it, and the lists replace the saved ones.
export function buildPayload(values, mode) {
  const payload = {}
  for (const field of TEXT_FIELDS) {
    const raw = typeof values[field] === 'string' ? values[field].trim() : values[field]
    if (raw === '' || raw === undefined) {
      if (mode === 'update') payload[field] = null
    } else {
      payload[field] = field === 'pay_rate' ? Number(raw) : raw
    }
  }
  for (const secret of ['cnic', 'bank_account']) {
    if (String(values[secret] ?? '').trim() !== '') payload[secret] = String(values[secret]).trim()
  }
  payload.availability = values.availability
  payload.employment_status = values.employment_status
  payload.weekly_capacity_hours = Number(values.weekly_capacity_hours) || 36
  if (values.team_lead_id !== '' && values.team_lead_id !== null) payload.team_lead_id = Number(values.team_lead_id)
  else if (mode === 'update') payload.team_lead_id = null
  payload.checklist = values.checklist
  for (const list of ['skills', 'educations', 'experiences', 'certifications']) {
    if (mode === 'update' || values[list].length) payload[list] = cleanRows(values[list])
  }
  return payload
}

// Which tab each API field belongs to, so a 422 can send the admin to the first tab with a problem.
const TAB_OF_PREFIX = [
  ['account', ['name', 'email']],
  ['personal', ['father_name', 'date_of_birth', 'gender', 'cnic', 'phone', 'whatsapp', 'personal_email', 'current_address', 'permanent_address', 'city', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone']],
  ['professional', ['headline', 'bio', 'department', 'timezone', 'availability', 'preferred_hours', 'english_level', 'portfolio_url', 'github_url', 'linkedin_url', 'skills', 'educations', 'experiences', 'certifications']],
  ['employment', ['join_date', 'employment_type', 'employment_status', 'probation_end_date', 'contract_end_date', 'team_lead_id', 'weekly_capacity_hours', 'checklist', 'pay_rate', 'payment_method', 'bank_name', 'bank_account', 'internal_notes']],
]

export function tabsWithErrors(errors = {}) {
  const found = new Set()
  for (const key of Object.keys(errors)) {
    const root = key.split('.')[0]
    const tab = TAB_OF_PREFIX.find(([, fields]) => fields.includes(root))?.[0]
    if (tab) found.add(tab)
  }
  return TAB_OF_PREFIX.map(([id]) => id).filter((id) => found.has(id))
}

export const firstError = (errors, key) => errors?.[key]?.[0]

export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
