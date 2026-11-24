// Option lists for the support filters and forms, kept apart from the badge components so they hot-reload cleanly.
export const LIFECYCLE_STATUSES = [
  ['new', 'New'], ['triage', 'Triage'], ['assigned', 'Assigned'], ['in_progress', 'In progress'], ['waiting_agency', 'Waiting for agency'],
  ['waiting_third_party', 'Waiting for third party'], ['qa', 'QA / testing'], ['resolved', 'Resolved'], ['closed', 'Closed'], ['rejected', 'Rejected'],
].map(([value, label]) => ({ value, label }))

export const PRIORITY_OPTIONS = [['urgent', 'P1 Critical'], ['high', 'P2 High'], ['normal', 'P3 Normal'], ['low', 'P4 Low']].map(([value, label]) => ({ value, label }))