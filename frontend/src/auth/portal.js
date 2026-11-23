// The agency portal an agency user signs in through (/a/{slug}/login). Stored under a neutral key so a signed-out or
// expired session can be sent back to the right portal instead of the platform login.
const KEY = 'portal:slug'

export function getStoredPortal() {
  try {
    return window.localStorage.getItem(KEY)
  } catch {
    return null
  }
}

export function storePortal(slug) {
  try {
    if (slug) window.localStorage.setItem(KEY, slug)
  } catch {
    // Storage can be blocked; the user can still use the portal address directly.
  }
}

export function portalPath(slug, path = '/login') {
  return slug ? `/a/${slug}${path}` : path
}