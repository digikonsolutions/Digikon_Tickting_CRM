// The seven roles under test, plus one extra employee used for the two-browser-context scenario.
export const PASSWORD_PREFIX = 'Seed-E2E-Pass#'

export const ROLE_USERS = [
  { role: 'admin', label: 'Administrator', name: 'Ada Admin', email: 'admin@e2e.test' },
  { role: 'team_lead', label: 'Team lead', name: 'Tess Lead', email: 'lead@e2e.test' },
  { role: 'employee', label: 'Employee', name: 'Eli Employee', email: 'employee@e2e.test' },
  { role: 'qa', label: 'QA', name: 'Quinn Tester', email: 'qa@e2e.test' },
  { role: 'agency_owner', label: 'Agency owner', name: 'Olive Owner', email: 'owner@e2e.test' },
  { role: 'agency_member', label: 'Agency member', name: 'Mia Member', email: 'member@e2e.test' },
  { role: 'agency_client', label: 'Agency client', name: 'Cal Client', email: 'client@e2e.test' },
]

export const MULTI_USER = { role: 'employee', label: 'Multi-device employee', name: 'Max Multi', email: 'multi@e2e.test' }