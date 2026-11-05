export const ROLES = Object.freeze({
  ADMIN: 'admin',
  AGENCY_OWNER: 'agency_owner',
  CLIENT: 'client',
  EMPLOYEE: 'employee',
})

export const ROLE_LABELS = Object.freeze({
  [ROLES.ADMIN]: 'Administrator',
  [ROLES.AGENCY_OWNER]: 'Agency owner',
  [ROLES.CLIENT]: 'Client',
  [ROLES.EMPLOYEE]: 'Employee',
})

export const isKnownRole = (role) => Object.values(ROLES).includes(role)
