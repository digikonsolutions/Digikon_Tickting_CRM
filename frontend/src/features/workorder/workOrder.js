import { compact, getIn, withKeys } from './objectPath'

// The ticket's work order: the nested object the API reads and writes under `work_order`. Payload mapping lives here
// and nowhere else, so every form (project page, tickets page) sends the same shape.

export const REF_TYPES = [
  ['workflow', 'Workflow'], ['pipeline', 'Pipeline'], ['stage', 'Pipeline stage'], ['funnel', 'Funnel'], ['website', 'Website'], ['page', 'Page'],
  ['form', 'Form'], ['survey', 'Survey'], ['calendar', 'Calendar'], ['custom_field', 'Custom field'], ['tag', 'Tag'],
  ['email_template', 'Email template'], ['sms_template', 'SMS template'], ['snapshot', 'Snapshot'], ['other', 'Other'],
].map(([value, label]) => ({ value, label }))

export const WAITING_ON = [{ value: 'client', label: 'The client' }, { value: 'employee', label: 'Our employee' }, { value: 'third_party', label: 'A third party' }]
export const SECTION_TITLES = { requirements: 'Requirements', ghl: 'GoHighLevel targets', inputs: 'Inputs and assets', dependencies: 'Dependencies', environment: 'Environment and risk', testing: 'Testing', checklist: 'Checklist', notes: 'Notes' }

export function emptyOrder(location = '') {
  return {
    work_type: '', brief_section: '', labels: [],
    requirements: { instructions: '', acceptance_criteria: '', expected_behavior: [], out_of_scope: '', reference_urls: [], loom_url: '' },
    ghl: { location_id: location, refs: [] },
    inputs: { client_supplies: [], asset_urls: [], inputs_ready: '', missing: [], access_needed: '' },
    dependencies: { depends_on: [], waiting_on: '' },
    environment: { environment: '', affects_live_traffic: '', requires_approval: '', rollback_note: '' },
    testing: { steps: '', test_data: '', devices: '', expected: '', qa_checklist: [] },
    checklist: [],
    notes: { client: '' },
  }
}

// The server's work order (from `work_order` on a ticket) as form state.
export function orderToState(order, location = '') {
  const base = emptyOrder(location)
  if (!order) return base
  const merge = (key) => ({ ...base[key], ...(order[key] ?? {}) })
  return {
    ...base,
    work_type: order.work_type ?? '', brief_section: order.brief_section ?? '', labels: order.labels ?? [],
    requirements: merge('requirements'),
    ghl: { location_id: order.ghl?.location_id ?? '', refs: withKeys(order.ghl?.refs) },
    inputs: merge('inputs'),
    dependencies: { depends_on: order.dependencies?.depends_on ?? [], waiting_on: order.dependencies?.waiting_on ?? '' },
    environment: merge('environment'),
    testing: merge('testing'),
    checklist: withKeys((order.checklist ?? []).map(({ id, text }) => ({ id, text }))),
    notes: { client: order.notes?.client ?? '' },
  }
}

const tri = (value) => (value === '' || value === null || value === undefined ? undefined : value === true || value === 'true')

// What goes to the API. A new ticket leaves out whatever is empty; an edit sends every section, empty ones as {} (or
// an empty checklist), because the server replaces a section it receives and keeps one it does not.
export function orderToPayload(state, { replaceAll = false } = {}) {
  const inputs = { ...state.inputs, inputs_ready: tri(state.inputs.inputs_ready) }
  const environment = { ...state.environment, affects_live_traffic: tri(state.environment.affects_live_traffic), requires_approval: tri(state.environment.requires_approval) }
  const sections = {
    requirements: compact(state.requirements), ghl: compact(state.ghl), inputs: compact(inputs), dependencies: compact(state.dependencies),
    environment: compact(environment), testing: compact(state.testing), notes: compact(state.notes),
  }
  const payload = {}
  Object.entries(sections).forEach(([key, value]) => { if (Object.keys(value).length || replaceAll) payload[key] = value })
  if (state.work_type) payload.work_type = state.work_type
  else if (replaceAll) payload.work_type = null
  if (state.brief_section) payload.brief_section = state.brief_section
  if (state.labels.length) payload.labels = state.labels
  const checklist = state.checklist.filter((item) => item.text.trim()).map(({ id, text }) => (id ? { id, text: text.trim() } : { text: text.trim() }))
  if (checklist.length || replaceAll) payload.checklist = checklist
  return payload
}

// Recommended fields the template names that are still empty: the gentle "Missing: ..." hint. Never blocks saving.
export const FIELD_LABELS = {
  'requirements.instructions': 'instructions', 'requirements.acceptance_criteria': 'acceptance criteria', 'requirements.expected_behavior': 'expected behavior',
  'ghl.refs': 'GoHighLevel targets', 'testing.steps': 'test steps', 'testing.devices': 'devices to test', 'inputs.client_supplies': 'what the client supplies',
  'inputs.access_needed': 'access needed', 'environment.rollback_note': 'rollback note', 'requirements.reference_urls': 'reference links',
}

export function missingFields(state, template) {
  return (template?.recommended ?? []).filter((path) => {
    const value = getIn(state, path)
    return value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)
  }).map((path) => FIELD_LABELS[path] ?? path.split('.').pop().replaceAll('_', ' '))
}
