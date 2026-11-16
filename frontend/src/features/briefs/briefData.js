// Helpers that turn the optional brief form fields into API payloads and back. Everything is optional:
// empty fields are sent as null so a user can clear a value they set earlier.

import { DEFAULT_LIMITS } from '../../lib/files'

// Project briefs also accept zip files (the server decides; this only mirrors the default).
export const BRIEF_LIMITS = Object.freeze({
  ...DEFAULT_LIMITS,
  extensions: [...DEFAULT_LIMITS.extensions, 'zip'],
})

export const PLATFORMS = [
  { value: '', label: 'Not specified' },
  { value: 'ghl', label: 'GoHighLevel (GHL)' },
  { value: 'wordpress', label: 'WordPress' },
  { value: 'custom', label: 'Custom build' },
  { value: 'other', label: 'Other' },
]

export const PRIORITIES = ['low', 'normal', 'high', 'urgent']
export const SEVERITIES = ['low', 'medium', 'high', 'critical']
export const DUE_PREFERENCES = [
  { value: '', label: 'No preference' },
  { value: 'asap', label: 'As soon as possible' },
  { value: 'this_week', label: 'This week' },
  { value: 'next_week', label: 'Next week' },
  { value: 'flexible', label: 'Flexible' },
]

const text = (formData, name) => {
  const value = String(formData.get(name) ?? '').trim()
  return value === '' ? null : value
}

// One entry per line, blanks dropped.
export function splitLines(value) {
  const lines = String(value ?? '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
  return lines.length ? lines : null
}

export function readProjectBrief(formData) {
  return {
    objective: text(formData, 'objective'),
    deliverables: splitLines(formData.get('deliverables')),
    target_url: text(formData, 'target_url'),
    staging_url: text(formData, 'staging_url'),
    repo_url: text(formData, 'repo_url'),
    platform: text(formData, 'platform'),
    tech_stack: text(formData, 'tech_stack'),
    brand_assets_url: text(formData, 'brand_assets_url'),
    access_details: text(formData, 'access_details'),
    reference_links: splitLines(formData.get('reference_links')),
    acceptance_criteria: text(formData, 'acceptance_criteria'),
    budget: text(formData, 'budget'),
    brief_priority: text(formData, 'brief_priority'),
    contact_name: text(formData, 'contact_name'),
    contact_email: text(formData, 'contact_email'),
  }
}

export function readTicketBrief(formData) {
  const related = formData.getAll('related_ticket_ids').map(Number).filter(Boolean)
  return {
    steps_to_reproduce: text(formData, 'steps_to_reproduce'),
    expected_result: text(formData, 'expected_result'),
    actual_result: text(formData, 'actual_result'),
    affected_url: text(formData, 'affected_url'),
    environment: text(formData, 'environment'),
    severity: text(formData, 'severity'),
    due_preference: text(formData, 'due_preference'),
    related_ticket_ids: related.length ? related : null,
  }
}

// Only web links are rendered as links, whatever the server stored.
export function safeUrl(value) {
  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null
  } catch {
    return null
  }
}

export function hasAnyValue(brief) {
  return Object.values(brief ?? {}).some((value) => (Array.isArray(value) ? value.length > 0 : Boolean(value)))
}

// First message from a failed upload response.
export function uploadErrorMessage(reason) {
  return Object.values(reason?.errors ?? {}).flat()[0] ?? reason?.message ?? 'The upload failed.'
}
