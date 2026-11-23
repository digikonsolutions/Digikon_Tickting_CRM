import { formatShortDate, todayStr } from '../../lib/format'

export const MAX_RUSH_HOURS = 180

// "Dec 10" or "Dec 10 – Dec 12"; a project that has no planned hours yet reads "Not scheduled".
export function plannedRange(start, end) {
  if (!start) return 'Not scheduled'
  if (!end || end === start) return formatShortDate(start)
  return `${formatShortDate(start)} – ${formatShortDate(end)}`
}

export function daysLate(count) {
  return `${count} ${count === 1 ? 'day' : 'days'} late`
}

export const RUSH_TYPE_LABELS = Object.freeze({
  priority_insert: 'Jump the queue',
  extra_employee: 'Another team member',
})

export const RUSH_STATUS = Object.freeze({
  pending: { label: 'Waiting for a quote', variant: 'info' },
  quoted: { label: 'Quote ready', variant: 'warning' },
  accepted: { label: 'Accepted', variant: 'success' },
  fulfilled: { label: 'Scheduled', variant: 'success' },
  rejected: { label: 'Not available', variant: 'slate' },
  cancelled: { label: 'Cancelled', variant: 'slate' },
  expired: { label: 'Quote expired', variant: 'slate' },
})

// Major units typed by a person ("250.50") to integer minor units (25050), without floating point.
export function toMinorUnits(text) {
  const match = /^\s*(\d{1,9})(?:[.,](\d{1,2}))?\s*$/.exec(String(text))
  if (!match) return null
  const minor = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0)
  return minor > 0 ? minor : null
}

// What is wrong with the form, field by field. Empty object means it can be sent.
export function validateRush(values, needsMember) {
  const errors = {}
  if (!values.title.trim()) errors.title = 'Give the request a short title.'
  if (!values.description.trim()) errors.description = 'Describe what is needed.'
  const hours = Number(values.estimated_hours)
  if (!(hours > 0)) errors.estimated_hours = 'Enter the hours needed.'
  else if (hours > MAX_RUSH_HOURS) errors.estimated_hours = `A request can be at most ${MAX_RUSH_HOURS} hours.`
  if (!values.needed_by) errors.needed_by = 'Choose the date it is needed by.'
  else if (values.needed_by < todayStr()) errors.needed_by = 'The date cannot be in the past.'
  if (needsMember && !values.assignment_id) errors.assignment_id = 'Choose which team member this is for.'
  return errors
}
