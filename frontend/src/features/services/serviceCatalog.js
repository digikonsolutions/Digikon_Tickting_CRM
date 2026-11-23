// Helpers for the GHL service catalog. The catalog itself lives on the server (config/service_catalog.php) and is
// fetched with authService.getServiceCatalog(), so adding a service or a field there changes every form.
//
// A type is { key, label, fields: [{ key, label, type: 'select'|'checkbox'|'url'|'text', options?, required?, ticket?, max? }] }.
// "details" are the answers, keyed by field key, plus an optional free `notes`.

export const typeOf = (catalog, key) => (catalog?.types ?? []).find((type) => type.key === key) ?? null

// Fields asked on the project form, or the lighter ticket form.
export function fieldsFor(type, scope) {
  return (type?.fields ?? []).filter((field) => scope === 'project' || field.ticket)
}

const isEmpty = (value) => value == null || String(value).trim() === '' || (Array.isArray(value) && value.length === 0)

// Only answered fields of this service are sent; nothing answered means null (the server then stores nothing).
export function cleanDetails(type, details, scope) {
  if (!type) return null
  const allowed = new Set(['notes', ...fieldsFor(type, scope).map((field) => field.key)])
  const result = {}
  for (const [key, value] of Object.entries(details ?? {})) {
    if (!allowed.has(key) || isEmpty(value)) continue
    result[key] = Array.isArray(value) ? value : String(value).trim()
  }
  return Object.keys(result).length ? result : null
}

// What is still missing on a project (tickets never require answers).
export function missingRequired(type, details, scope) {
  if (!type || scope !== 'project') return []
  return fieldsFor(type, scope).filter((field) => field.required && isEmpty(details?.[field.key])).map((field) => field.label)
}

const shown = (field, value) => (Array.isArray(value)
  ? value.map((item) => field.options?.[item] ?? item).join(', ')
  : (field.options?.[value] ?? value))

const MAX_TITLE = 160
const MAX_DESCRIPTION = 5000

// A title and description written from the answers. The form shows them as a starting point and the person can edit both.
export function buildFromAnswers(type, details, scope) {
  if (!type) return { title: '', description: '' }
  const fields = fieldsFor(type, scope)
  const answered = fields.filter((field) => !isEmpty(details?.[field.key]))
  const lead = answered.find((field) => field.type === 'select' || field.type === 'text')
  const head = lead ? shown(lead, details[lead.key]) : null
  const title = (head ? `${type.label}: ${head}` : type.label).slice(0, MAX_TITLE)

  const lines = [`Service: ${type.label}`]
  for (const field of answered) lines.push(`${field.label}: ${shown(field, details[field.key])}`)
  if (!isEmpty(details?.notes)) lines.push('', String(details.notes).trim())

  return { title, description: lines.join('\n').slice(0, MAX_DESCRIPTION) }
}

// "Automation specialist, 20 h per week for 8 weeks" for the request form's summary line.
export function describeEmployeeRequest(catalog, details) {
  const role = catalog?.employee_request?.roles?.[details.role] ?? details.role
  const weeks = Number(details.duration_weeks)
  return `${role}, ${details.hours_per_week} h per week for ${weeks} ${weeks === 1 ? 'week' : 'weeks'}`
}
