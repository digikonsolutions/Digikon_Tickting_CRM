// Talks to the ClientHub API. Authentication is the employee's own signed-in ClientHub session (cookies);
// the extension never stores a password or token, and never logs one.

export const BATCH = 200
const MAX_QUEUE = 5000

export function normalizeApiBase(value) {
  try {
    const url = new URL(String(value).trim())
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(url.hostname))) return null
    return `${url.origin}${url.pathname.replace(/\/+$/, '')}`
  } catch {
    return null
  }
}

export function trimQueue(queue) {
  return queue.length > MAX_QUEUE ? queue.slice(queue.length - MAX_QUEUE) : queue
}

async function csrfHeader(apiBase, cookies, fetchImpl) {
  await fetchImpl(`${apiBase}/auth/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } })
  const cookie = await cookies.get({ url: new URL(apiBase).origin, name: 'XSRF-TOKEN' })

  return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } : {}
}

/**
 * One sync pass. Returns the new status and the ids that the server stored (or already had).
 * `status.allowed` is only true while the signed-in employee has accepted the current notice.
 */
export async function runSync({ apiBase, queue, cookies, fetchImpl = fetch }) {
  const status = { signedIn: false, allowed: false, error: null }
  const stored = new Set()

  let check
  try {
    check = await fetchImpl(`${apiBase}/browser-history/status`, { credentials: 'include', headers: { Accept: 'application/json' } })
  } catch {
    return { status: { ...status, error: 'offline' }, stored, offline: true }
  }
  if (check.status === 401 || check.status === 419) return { status, stored }
  if (check.status === 403) return { status: { ...status, signedIn: true }, stored }
  if (!check.ok) return { status: { ...status, error: `server ${check.status}` }, stored }
  const body = await check.json().catch(() => ({}))
  status.signedIn = true
  status.allowed = body.tracking_allowed === true
  if (!status.allowed || queue.length === 0) return { status, stored }

  try {
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(await csrfHeader(apiBase, cookies, fetchImpl)) }
    for (let i = 0; i < queue.length; i += BATCH) {
      const batch = queue.slice(i, i + BATCH)
      const response = await fetchImpl(`${apiBase}/browser-history/sync`, { method: 'POST', credentials: 'include', headers, body: JSON.stringify({ events: batch }) })
      if (response.status === 403) { status.allowed = false; status.error = 'consent'; break }
      if (response.status === 429) { status.error = 'rate limited'; break }
      if (response.status === 422) {
        // A batch the server refuses outright would block the queue forever: drop it and carry on.
        batch.forEach((event) => stored.add(event.client_event_id))
        continue
      }
      if (!response.ok) { status.error = `server ${response.status}`; break }
      batch.forEach((event) => stored.add(event.client_event_id))
    }
  } catch {
    status.error = 'offline'
  }

  return { status, stored }
}
