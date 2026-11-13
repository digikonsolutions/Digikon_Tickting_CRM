import { isAgencyRole } from '../auth/roles'

// Single source of truth for ticket status wording and colour.
// `variant` maps to the Badge variants; `stepIndex` is the position on the main workflow path,
// and `isSideState` marks the off-path states (shown as a side branch by StatusStepper).
export const TICKET_STATUSES = Object.freeze({
  todo: { label: 'To do', variant: 'slate', stepIndex: 0 },
  in_progress: { label: 'In progress', variant: 'primary', stepIndex: 1 },
  in_review: { label: 'In review', variant: 'violet', stepIndex: 2 },
  done: { label: 'Done', variant: 'success', stepIndex: 3 },
  blocked: { label: 'Blocked', variant: 'danger', isSideState: true },
  waiting_on_client: {
    label: 'Waiting on client',
    // Agency users are the client, so the wording is relative to the viewer.
    agencyLabel: 'Waiting on you',
    variant: 'warning',
    isSideState: true,
  },
  changes_requested: { label: 'Changes requested', variant: 'orange', isSideState: true },
  cancelled: { label: 'Cancelled', variant: 'slate' },
})

export function isTicketStatus(status) {
  return Object.prototype.hasOwnProperty.call(TICKET_STATUSES, status)
}

export function getTicketStatusLabel(status, viewerRole = null) {
  const meta = TICKET_STATUSES[status]
  if (!meta) return status ? String(status).replace(/_/g, ' ') : ''
  if (meta.agencyLabel && isAgencyRole(viewerRole)) return meta.agencyLabel
  return meta.label
}

export function getTicketStatusVariant(status) {
  return TICKET_STATUSES[status]?.variant ?? 'slate'
}

// Who the ticket is waiting on, relative to the viewer: "you", "client", "specialist"/"employee", "team".
export function getWaitingLabel(waitingOn, viewerRole = null) {
  const viewerIsAgency = isAgencyRole(viewerRole)
  switch (waitingOn) {
    case 'client':
      return viewerIsAgency ? 'you' : 'client'
    case 'employee':
      if (viewerRole === 'employee') return 'you'
      return viewerIsAgency ? 'specialist' : 'employee'
    case 'team':
      return 'team'
    default:
      return null
  }
}

// True when the ticket is waiting on the viewer's side (used for "needs your attention").
export function isWaitingOnViewer(waitingOn, viewerRole = null) {
  if (waitingOn === 'client') return isAgencyRole(viewerRole)
  if (waitingOn === 'employee') return viewerRole === 'employee'
  return false
}
