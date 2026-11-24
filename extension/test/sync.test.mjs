import assert from 'node:assert/strict'
import { test } from 'node:test'
import { BATCH, normalizeApiBase, runSync, trimQueue } from '../src/sync.js'

const cookies = { get: async () => ({ value: encodeURIComponent('csrf-token') }) }
const events = (count) => Array.from({ length: count }, (_, i) => ({ client_event_id: `e${i}`, event_type: 'navigated', url: 'https://example.com/' }))
const json = (status, body = {}) => ({ status, ok: status >= 200 && status < 300, json: async () => body })

function server(handlers) {
  const calls = []
  const fetchImpl = async (url, options = {}) => {
    calls.push({ url, options })
    const key = Object.keys(handlers).find((suffix) => url.endsWith(suffix))
    if (!key) return json(200)
    const result = handlers[key]

    return typeof result === 'function' ? result(options, calls) : result
  }

  return { fetchImpl, calls }
}

test('normalizeApiBase accepts https and local http only', () => {
  assert.equal(normalizeApiBase('https://app.example.com/api/'), 'https://app.example.com/api')
  assert.equal(normalizeApiBase('http://localhost:8000/api'), 'http://localhost:8000/api')
  assert.equal(normalizeApiBase('http://evil.example.com/api'), null)
  assert.equal(normalizeApiBase('javascript:alert(1)'), null)
  assert.equal(normalizeApiBase('nonsense'), null)
})

test('signed out: nothing is sent', async () => {
  const { fetchImpl, calls } = server({ '/browser-history/status': json(401) })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(3), cookies, fetchImpl })

  assert.equal(result.status.signedIn, false)
  assert.equal(result.stored.size, 0)
  assert.equal(calls.length, 1)
})

test('notice not accepted: nothing is sent', async () => {
  const { fetchImpl, calls } = server({ '/browser-history/status': json(200, { tracking_allowed: false }) })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(3), cookies, fetchImpl })

  assert.equal(result.status.signedIn, true)
  assert.equal(result.status.allowed, false)
  assert.equal(calls.some((call) => call.url.endsWith('/sync')), false)
})

test('sends in batches with the CSRF header and cookies, and reports what was stored', async () => {
  const { fetchImpl, calls } = server({
    '/browser-history/status': json(200, { tracking_allowed: true }),
    '/browser-history/sync': json(200, { accepted: 1, duplicates: 0, skipped: 0 }),
  })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(BATCH + 5), cookies, fetchImpl })
  const posts = calls.filter((call) => call.url.endsWith('/sync'))

  assert.equal(posts.length, 2)
  assert.equal(JSON.parse(posts[0].options.body).events.length, BATCH)
  assert.equal(JSON.parse(posts[1].options.body).events.length, 5)
  assert.equal(posts[0].options.credentials, 'include')
  assert.equal(posts[0].options.headers['X-XSRF-TOKEN'], 'csrf-token')
  assert.equal(result.stored.size, BATCH + 5)
  assert.equal(result.status.allowed, true)
})

test('offline: everything stays queued for the next attempt', async () => {
  const fetchImpl = async () => { throw new TypeError('Failed to fetch') }
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(3), cookies, fetchImpl })

  assert.equal(result.offline, true)
  assert.equal(result.stored.size, 0)
})

test('going offline mid-sync keeps the batches that did not go through', async () => {
  let posts = 0
  const { fetchImpl } = server({
    '/browser-history/status': json(200, { tracking_allowed: true }),
    '/browser-history/sync': () => { posts += 1; if (posts === 2) throw new TypeError('Failed to fetch'); return json(200) },
  })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(BATCH * 2), cookies, fetchImpl })

  assert.equal(result.stored.size, BATCH)
  assert.equal(result.status.error, 'offline')
})

test('consent withdrawn or replaced during sync: stops and marks tracking not allowed', async () => {
  const { fetchImpl } = server({
    '/browser-history/status': json(200, { tracking_allowed: true }),
    '/browser-history/sync': json(403, { consent_required: true }),
  })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(3), cookies, fetchImpl })

  assert.equal(result.status.allowed, false)
  assert.equal(result.stored.size, 0)
})

test('resending after a lost response is safe: the server reports duplicates and the batch is cleared', async () => {
  const { fetchImpl } = server({
    '/browser-history/status': json(200, { tracking_allowed: true }),
    '/browser-history/sync': json(200, { accepted: 0, duplicates: 3, skipped: 0 }),
  })
  const result = await runSync({ apiBase: 'https://x.test/api', queue: events(3), cookies, fetchImpl })

  assert.equal(result.stored.size, 3)
})

test('the local queue is bounded', () => {
  assert.equal(trimQueue(events(6000)).length, 5000)
  assert.equal(trimQueue(events(6000))[0].client_event_id, 'e1000')
})

test('records only while the server reports work in progress, and says for what', async () => {
  const working = server({ '/browser-history/status': json(200, { tracking_allowed: true, recording: true, active_work: { ticket_id: 4, ticket_key: 'NW-4', title: 'Header' } }) })
  const on = await runSync({ apiBase: 'https://x.test/api', queue: [], cookies, fetchImpl: working.fetchImpl })
  assert.equal(on.status.recording, true)
  assert.deepEqual(on.status.activeWork, { ticket_id: 4, ticket_key: 'NW-4', title: 'Header' })

  const idle = server({ '/browser-history/status': json(200, { tracking_allowed: true, recording: false, active_work: null }) })
  const off = await runSync({ apiBase: 'https://x.test/api', queue: events(2), cookies, fetchImpl: idle.fetchImpl })
  assert.equal(off.status.recording, false)
  assert.equal(off.status.activeWork, null)
  // What was queued during work is still delivered after work stops.
  assert.equal(off.stored.size, 2)

  const noConsent = server({ '/browser-history/status': json(200, { tracking_allowed: false, recording: true, active_work: { ticket_id: 4 } }) })
  const blocked = await runSync({ apiBase: 'https://x.test/api', queue: [], cookies, fetchImpl: noConsent.fetchImpl })
  assert.equal(blocked.status.recording, false)
})
