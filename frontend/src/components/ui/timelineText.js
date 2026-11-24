import { getTicketStatusLabel } from '../../lib/ticketStatus'

function formatMinutes(minutes) {
  const total = Number(minutes) || 0
  const hours = Math.floor(total / 60)
  const rest = total % 60
  if (!hours) return `${rest} min`
  return rest ? `${hours} h ${rest} min` : `${hours} h`
}

const CANCEL_CAUSES = {
  ticket: '',
  project: ' because the project was cancelled',
  assignment_ended: ' because the employee was released',
  employee_deactivated: ' because the employee is no longer available',
}

// One line for a ticket event ("Ada moved this to In review"). Also used to list a project's activity across tickets.
export function describeTimelineItem(item, viewerRole) {
  const actor = item.actor?.name ?? 'System'
  const payload = item.payload ?? {}

  switch (item.type) {
    case 'created':
      return `${actor} created this ticket`
    case 'status_changed':
      if (payload.to_label) return `${actor} moved this to ${payload.to_label}`
      return `${actor} moved this to ${getTicketStatusLabel(payload.to, viewerRole)}`
    case 'rescheduled':
      return payload.reason ?? 'Schedule recalculated'
    case 'reordered':
      return `${actor} changed this ticket's priority order`
    case 'cancelled':
      return `${actor} cancelled this ticket${CANCEL_CAUSES[payload.cause] ?? ''}`
    case 'updated': {
      const fields = Object.keys(payload.changes ?? {}).map((field) => field.replace(/_/g, ' '))
      return `${actor} updated ${fields.join(', ') || 'this ticket'}`
    }
    case 'attachment_added': {
      const count = (payload.files ?? []).length
      return `${actor} attached ${count} file${count === 1 ? '' : 's'}${item.visibility === 'internal' ? ' (internal)' : ''}`
    }
    case 'time_logged':
      return `${actor} logged ${formatMinutes(payload.minutes)}`
    case 'attachment_removed':
      return `${actor} removed ${payload.name ?? 'a file'}`
    case 'priority_changed':
      return `${actor} changed the priority to ${payload.to_label ?? payload.to}`
    case 'reassigned':
      return `${actor} reassigned this ticket`
    case 'status_detail':
      return 'Internal note on the status change'
    case 'secret_flagged':
      return 'Possible password or key in the text: flagged for staff'
    case 'comment':
      return item.visibility === 'internal' ? `${actor} added an internal note` : `${actor} commented`
    default:
      return `${actor} made changes`
  }
}
