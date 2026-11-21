// Form model for the "Create agency owner" wizard: steps, options, defaults, validation and the API payload.

export const STEPS = [
  { id: 'owner', label: 'Owner account' },
  { id: 'agency', label: 'Agency details' },
  { id: 'contract', label: 'Plan & contract' },
  { id: 'setup', label: 'Setup & access' },
  { id: 'review', label: 'Review & create' },
]

export const BILLING_CYCLES = [
  { value: 'monthly', label: 'Monthly', months: 1 },
  { value: 'quarterly', label: 'Quarterly', months: 3 },
  { value: 'yearly', label: 'Yearly', months: 12 },
]
export const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+']
export const CONTACT_METHODS = [
  { value: 'email', label: 'Email' },
  { value: 'phone', label: 'Phone' },
  { value: 'whatsapp', label: 'WhatsApp' },
]
export const CURRENCIES = ['USD', 'EUR', 'GBP', 'PKR', 'AED', 'CAD', 'AUD']
export const CONTRACT_STATUSES = [
  { value: 'active', label: 'Active' },
  { value: 'trial', label: 'Trial' },
]
export const DOCUMENT_RULES = { maxBytes: 10 * 1024 * 1024, extensions: ['pdf', 'jpg', 'jpeg', 'png'], accept: '.pdf,.jpg,.jpeg,.png' }
export const LOGO_RULES = { maxBytes: 2 * 1024 * 1024, accept: '.png,.jpg,.jpeg,.webp' }

export const todayString = () => {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const pad = (value) => String(value).padStart(2, '0')

/** start + one billing cycle ("2026-01-31" + monthly = "2026-02-28"), or '' when the start is not a date. */
export function endDateFor(start, cycle) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(start ?? '')
  const months = BILLING_CYCLES.find((item) => item.value === cycle)?.months
  if (!match || !months) return ''
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const target = new Date(year, month - 1 + months, 1)
  const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()
  return `${target.getFullYear()}-${pad(target.getMonth() + 1)}-${pad(Math.min(day, lastDay))}`
}

export function emptyWizard() {
  return {
    owner: { name: '', email: '', phone: '', whatsapp: '', job_title: '', preferred_contact_method: '', timezone: '' },
    agency: {
      name: '', legal_name: '', brand_name: '', website: '', industry: '', country: '', city: '', address: '', company_size: '',
      tax_number: '', billing_email: '', billing_contact_name: '', account_manager_id: '', internal_notes: '',
    },
    contractEnabled: false,
    contract: {
      plan_id: '', employees: '', hours_per_month: '', max_open_projects: '', max_client_accounts: '', price: '', currency: 'USD',
      billing_cycle: 'monthly', overage_rate: '', start_date: todayString(), end_date: '', auto_renew: false, notice_period_days: '',
      status: 'active', trial_end_date: '', payment_terms_days: '', discount_percent: '',
    },
    setup: { employee_ids: [], brand_color: '', send_invite: true, welcome_enabled: false, welcome_message: '' },
  }
}

/** Copies a plan's numbers into the contract fields (the admin can still edit every one of them). */
export function applyPlan(contract, plan) {
  if (!plan) return { ...contract, plan_id: '' }
  const text = (value) => (value === null || value === undefined ? '' : String(value))
  const billing = plan.billing_cycle ?? contract.billing_cycle
  return {
    ...contract,
    plan_id: String(plan.id),
    employees: text(plan.employees),
    hours_per_month: text(plan.hours_per_month),
    max_open_projects: text(plan.max_open_projects),
    max_client_accounts: text(plan.max_client_accounts),
    price: text(plan.price),
    currency: plan.currency ?? contract.currency,
    billing_cycle: billing,
    overage_rate: text(plan.overage_rate),
    end_date: endDateFor(contract.start_date, billing),
  }
}

/** "5 employees, 100 h a month, ..." from whatever the contract fields currently say. */
export function contractSummary(contract) {
  const parts = []
  if (contract.employees !== '') parts.push(`${contract.employees} employee${Number(contract.employees) === 1 ? '' : 's'}`)
  if (contract.hours_per_month !== '') parts.push(`${contract.hours_per_month} h per month`)
  if (contract.max_open_projects !== '') parts.push(`${contract.max_open_projects} open projects`)
  if (contract.max_client_accounts !== '') parts.push(`${contract.max_client_accounts} client accounts`)
  return parts
}

const blank = (value) => value === '' || value === null || value === undefined
const NUMBER_FIELDS = ['employees', 'hours_per_month', 'max_open_projects', 'max_client_accounts', 'price', 'overage_rate', 'notice_period_days', 'payment_terms_days', 'discount_percent']

function clean(section) {
  return Object.fromEntries(Object.entries(section).filter(([, value]) => !blank(value)).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]))
}

/** The API payload. Empty fields are left out; the contract is null unless the admin chose to add one. */
export function buildPayload(values, mode) {
  const agency = clean(values.agency)
  if (agency.account_manager_id) agency.account_manager_id = Number(agency.account_manager_id)

  let contract = null
  if (values.contractEnabled) {
    contract = clean(values.contract)
    if (contract.plan_id) contract.plan_id = Number(contract.plan_id)
    for (const field of NUMBER_FIELDS) if (contract[field] !== undefined) contract[field] = Number(contract[field])
    if (contract.status !== 'trial') delete contract.trial_end_date
  }

  const setup = { employee_ids: values.setup.employee_ids, send_invite: values.setup.send_invite }
  if (values.setup.brand_color) setup.brand_color = values.setup.brand_color.toUpperCase()
  if (values.setup.welcome_enabled && values.setup.welcome_message.trim()) setup.welcome_message = values.setup.welcome_message.trim()

  return { mode, owner: clean(values.owner), agency, contract, setup }
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const HEX = /^#[0-9a-fA-F]{6}$/

/** WCAG contrast of a #RRGGBB colour against white (the server refuses anything under 4.5). */
export function contrastOnWhite(hex) {
  if (!HEX.test(hex ?? '')) return null
  const channel = (offset) => {
    const value = parseInt(hex.slice(offset, offset + 2), 16) / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  }
  const luminance = 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
  return 1.05 / (luminance + 0.05)
}

/** Client-side checks for one step. Returns { 'owner.email': ['message'] }; empty when the step is fine. */
export function validateStep(stepId, values) {
  const errors = {}
  const add = (key, message) => { errors[key] = [message] }

  if (stepId === 'owner') {
    if (blank(values.owner.name?.trim())) add('owner.name', 'Enter the owner’s name.')
    if (blank(values.owner.email?.trim())) add('owner.email', 'Enter the owner’s email address.')
    else if (!EMAIL.test(values.owner.email.trim())) add('owner.email', 'Enter a valid email address.')
  }
  if (stepId === 'agency') {
    if (blank(values.agency.name?.trim())) add('agency.name', 'Enter the agency name.')
    if (!blank(values.agency.billing_email) && !EMAIL.test(values.agency.billing_email.trim())) add('agency.billing_email', 'Enter a valid billing email.')
  }
  if (stepId === 'contract' && values.contractEnabled) {
    const c = values.contract
    if (!blank(c.start_date) && !blank(c.end_date) && c.end_date <= c.start_date) add('contract.end_date', 'The end date must be after the start date.')
    if (c.status === 'trial' && blank(c.trial_end_date)) add('contract.trial_end_date', 'A trial needs a trial end date.')
    if (!blank(c.discount_percent) && (Number(c.discount_percent) < 0 || Number(c.discount_percent) > 100)) add('contract.discount_percent', 'Discount must be between 0 and 100.')
    for (const field of NUMBER_FIELDS) if (!blank(c[field]) && Number(c[field]) < 0) add(`contract.${field}`, 'This cannot be negative.')
  }
  if (stepId === 'setup') {
    const color = values.setup.brand_color
    if (!blank(color) && !HEX.test(color)) add('setup.brand_color', 'Use a hex colour like #0F766E.')
    else if (!blank(color) && contrastOnWhite(color) < 4.5) add('setup.brand_color', 'This colour is too light to read on white. Pick a darker shade.')
    const seats = values.contractEnabled && !blank(values.contract.employees) ? Number(values.contract.employees) : null
    if (seats !== null && values.setup.employee_ids.length > seats) add('setup.employee_ids', `The contract allows ${seats} employee${seats === 1 ? '' : 's'}, but ${values.setup.employee_ids.length} are chosen.`)
  }
  return errors
}

/** Which step a server error belongs to (internal notes are on the Setup step). */
export function stepOfKey(key) {
  if (key === 'agency.internal_notes') return 'setup'
  const root = key.split('.')[0]
  return { owner: 'owner', agency: 'agency', contract: 'contract', setup: 'setup' }[root] ?? 'review'
}

export const stepsWithErrors = (errors) => new Set(Object.keys(errors).map(stepOfKey))

/** What the admin should know before creating, mirroring the warnings the server returns. */
export function reviewWarnings(values, mode) {
  const warnings = []
  if (!values.contractEnabled) warnings.push('No contract added. The agency will have no plan, seat or hour limits.')
  else if (blank(values.contract.plan_id)) warnings.push('No plan selected. The contract will be a custom one.')
  if (values.setup.employee_ids.length === 0) warnings.push('No employees assigned yet.')
  if (mode === 'draft') warnings.push('A draft sends no invitation and the owner cannot sign in until you activate the agency.')
  else if (!values.setup.send_invite) warnings.push('No invitation will be sent. The owner can set a password with “Forgot password”.')
  return warnings
}

// Draft recovery. Stored only for this browser tab, never the tax number, and always inside try/catch because
// storage can be unavailable (private windows, blocked cookies).
const DRAFT_KEY = 'clienthub.agencyWizard.draft'

export function loadDraft() {
  try {
    const raw = window.sessionStorage.getItem(DRAFT_KEY)
    if (!raw) return null
    const draft = JSON.parse(raw)
    return draft?.values?.owner && draft?.values?.agency ? draft : null
  } catch {
    return null
  }
}

export function saveDraft(state) {
  try {
    const values = { ...state.values, agency: { ...state.values.agency, tax_number: '' } }
    window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ values, step: state.step, savedAt: new Date().toISOString() }))
  } catch {
    // Not being able to save a draft must never break the form.
  }
}

export function clearDraft() {
  try {
    window.sessionStorage.removeItem(DRAFT_KEY)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}

/** True when the admin has typed anything worth recovering. */
export function hasContent(values) {
  return [...Object.values(values.owner), ...Object.values(values.agency)].some((value) => !blank(value)) || values.setup.employee_ids.length > 0
}

export const firstError = (errors, key) => errors?.[key]?.[0]
export const formatBytes = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`)
export const labelOf = (options, value) => options.find((option) => option.value === value)?.label ?? value
