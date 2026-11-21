export const ROLES = Object.freeze({
  ADMIN: 'admin',
  TEAM_LEAD: 'team_lead',
  EMPLOYEE: 'employee',
  QA: 'qa',
  AGENCY_OWNER: 'agency_owner',
  AGENCY_MEMBER: 'agency_member',
  AGENCY_CLIENT: 'agency_client',
})

export const ROLE_LABELS = Object.freeze({
  [ROLES.ADMIN]: 'Administrator',
  [ROLES.TEAM_LEAD]: 'Team lead',
  [ROLES.EMPLOYEE]: 'Employee',
  [ROLES.QA]: 'Quality assurance',
  [ROLES.AGENCY_OWNER]: 'Agency owner',
  [ROLES.AGENCY_MEMBER]: 'Agency member',
  [ROLES.AGENCY_CLIENT]: 'Agency client',
})

// Team lead and QA have no workspace in this version: they land on their profile, inside the normal shell.
export const ROLE_HOME = Object.freeze({
  [ROLES.ADMIN]: '/admin/dashboard',
  [ROLES.TEAM_LEAD]: '/profile',
  [ROLES.EMPLOYEE]: '/my-work',
  [ROLES.QA]: '/profile',
  [ROLES.AGENCY_OWNER]: '/agency/dashboard',
  [ROLES.AGENCY_MEMBER]: '/agency/dashboard',
  [ROLES.AGENCY_CLIENT]: '/agency/dashboard',
})

// Where to send someone after signing in: the page they were on when the session ended, if their role may open it.
const SHARED_PREFIXES = ['/profile', '/notifications', '/settings/', '/tickets/']
export function postLoginPath(user, from) {
  const home = ROLE_HOME[user.role]
  if (typeof from !== 'string' || !from.startsWith('/') || from.startsWith('//')) return home
  if (['/login', '/forgot-password', '/reset-password', '/unauthorized', '/404'].some((path) => from.startsWith(path))) return home
  const area = `/${home.split('/')[1]}`
  const inOwnArea = area !== '/profile' && (from === area || from.startsWith(`${area}/`))
  return inOwnArea || SHARED_PREFIXES.some((prefix) => from.startsWith(prefix)) ? from : home
}

const INTERNAL_ROLES = new Set([ROLES.ADMIN, ROLES.TEAM_LEAD, ROLES.EMPLOYEE, ROLES.QA])
const AGENCY_ROLES = new Set([
  ROLES.AGENCY_OWNER,
  ROLES.AGENCY_MEMBER,
  ROLES.AGENCY_CLIENT,
])

export const isInternal = (role) => INTERNAL_ROLES.has(role)
export const isAgencyRole = (role) => AGENCY_ROLES.has(role)
export const isKnownRole = (role) => Object.values(ROLES).includes(role)
