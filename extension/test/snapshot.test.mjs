import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectiveApiBase } from '../src/config.js'
import { capturable, cleanTitle, domainAllowed, nextDelayMs, scaledSize, tickAction } from '../src/snapshot.js'
import { uploadSnapshot } from '../src/sync.js'

const allowed = ['gohighlevel.com', 'leadconnectorhq.com']

test('a site is allowed only when it is an approved domain or a subdomain of one', () => {
  assert.equal(domainAllowed('app.gohighlevel.com', allowed), true)
  assert.equal(domainAllowed('GoHighLevel.com', allowed), true)
  assert.equal(domainAllowed('evilgohighlevel.com', allowed), false)
  assert.equal(domainAllowed('gohighlevel.com.evil.test', allowed), false)
  assert.equal(domainAllowed('mail.google.com', allowed), false)
  assert.equal(domainAllowed('', allowed), false)
})

test('only https pages of approved sites in a normal window are captured', () => {
  assert.equal(capturable({ url: 'https://app.gohighlevel.com/v2/location/1/workflows' }, allowed), 'app.gohighlevel.com')
  assert.equal(capturable({ url: 'https://app.gohighlevel.com/', incognito: true }, allowed), null)
  assert.equal(capturable({ url: 'http://app.gohighlevel.com/' }, allowed), null)
  assert.equal(capturable({ url: 'chrome://settings' }, allowed), null)
  assert.equal(capturable({ url: 'https://bank.example.com/' }, allowed), null)
  assert.equal(capturable({}, allowed), null)
  assert.equal(capturable(null, allowed), null)
  assert.equal(capturable({ url: 'not a url' }, allowed), null)
})

test('the wait is random between the minimum and the maximum', () => {
  assert.equal(nextDelayMs(5, 15, () => 0), 5 * 60_000)
  assert.equal(nextDelayMs(5, 15, () => 1), 15 * 60_000)
  assert.equal(nextDelayMs(5, 15, () => 0.5), 10 * 60_000)
  assert.equal(nextDelayMs(10, 3, () => 0.5), 10 * 60_000, 'a bad range never goes below the minimum')
  assert.ok(nextDelayMs(undefined, undefined, () => 0) >= 60_000)
})

test('a tick schedules the first picture, waits, then captures when due', () => {
  assert.equal(tickAction({ now: 1000, nextAt: 0 }), 'schedule')
  assert.equal(tickAction({ now: 1000, nextAt: 5000 }), 'wait')
  assert.equal(tickAction({ now: 5000, nextAt: 5000 }), 'capture')
  assert.equal(tickAction({ now: 9000, nextAt: 5000 }), 'capture')
})

test('pictures are scaled down to the maximum width and never enlarged', () => {
  assert.deepEqual(scaledSize(2000, 1000, 1000), { width: 1000, height: 500 })
  assert.deepEqual(scaledSize(800, 600, 1000), { width: 800, height: 600 })
})

test('titles are trimmed, flattened and shortened', () => {
  assert.equal(cleanTitle('  A \n  title '), 'A title')
  assert.equal(cleanTitle('x'.repeat(300)).length, 200)
  assert.equal(cleanTitle('   '), null)
  assert.equal(cleanTitle(undefined), null)
})

test('a managed address wins over a typed one', () => {
  assert.equal(effectiveApiBase({ apiBase: 'http://localhost:8000/api' }, { apiBase: 'https://api.example.com/api' }), 'https://api.example.com/api')
  assert.equal(effectiveApiBase({ apiBase: 'https://typed.example.com/api' }, {}), 'https://typed.example.com/api')
  assert.equal(effectiveApiBase({}, undefined), '')
})

function fetchWith(status) {
  const calls = []
  const fetchImpl = async (url, init) => { calls.push({ url, init }); return url.endsWith('/auth/csrf-cookie') ? { ok: true, status: 204 } : { ok: status < 300, status } }
  return { calls, fetchImpl }
}
const cookies = { get: async () => ({ value: 'tok%20en' }) }
const meta = { domain: 'app.gohighlevel.com', title: 'Workflows', capturedAt: '2026-12-09T10:00:00.000Z', width: 800, height: 500 }

test('an upload sends the picture with the session cookie and the CSRF header', async () => {
  const { calls, fetchImpl } = fetchWith(201)
  const result = await uploadSnapshot({ apiBase: 'https://x.test/api', blob: new Blob(['x']), meta, cookies, fetchImpl })
  assert.equal(result, 'stored')
  const post = calls.find((call) => call.url === 'https://x.test/api/browser-history/snapshots')
  assert.equal(post.init.method, 'POST')
  assert.equal(post.init.credentials, 'include')
  assert.equal(post.init.headers['X-XSRF-TOKEN'], 'tok en')
  assert.equal(post.init.body.get('domain'), 'app.gohighlevel.com')
})

test('the server saying no is not retried, and a busy server is', async () => {
  for (const status of [403, 404, 409, 422]) {
    assert.equal(await uploadSnapshot({ apiBase: 'https://x.test/api', blob: new Blob(['x']), meta, cookies, fetchImpl: fetchWith(status).fetchImpl }), 'skip', String(status))
  }
  for (const status of [429, 500, 503]) {
    assert.equal(await uploadSnapshot({ apiBase: 'https://x.test/api', blob: new Blob(['x']), meta, cookies, fetchImpl: fetchWith(status).fetchImpl }), 'retry', String(status))
  }
  const offline = async () => { throw new Error('offline') }
  assert.equal(await uploadSnapshot({ apiBase: 'https://x.test/api', blob: new Blob(['x']), meta, cookies, fetchImpl: offline }), 'retry')
})