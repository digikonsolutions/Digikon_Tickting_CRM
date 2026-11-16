// One place for the password rules shown to people, so the reset page and the change-password form agree
// with the server (min 12, upper and lower case, a number, a symbol, at most 128).
export const PASSWORD_HINT = 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.'

const STRONG = /(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{12,128}/

export function isStrongPassword(value) {
  return typeof value === 'string' && STRONG.test(value)
}

// "  Ada   Lovelace " -> "Ada Lovelace" (the server does the same)
export function normalizeName(value) {
  return String(value ?? '').replace(/[\s\u00A0]+/g, ' ').trim()
}