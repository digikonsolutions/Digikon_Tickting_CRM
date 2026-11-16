import assert from 'node:assert/strict'
import { test } from 'node:test'
import { activate, adoptTab, closeTab, cleanUrl, createState, focusWindow, navigate, openTab, setPending } from '../src/tracker.js'

let n = 0
const uuid = () => `id-${++n}`
const tab = (id, extra = {}) => ({ id, windowId: 1, active: true, incognito: false, url: 'https://example.com/a?token=SECRET#frag', title: 'A', favIconUrl: 'https://example.com/f.ico', ...extra })
const types = (events) => events.map((event) => event.event_type)

function focused() {
  const state = createState()
  focusWindow(state, 1, 0, uuid)

  return state
}

test('cleanUrl keeps origin and path only and rejects non-web pages', () => {
  assert.deepEqual(cleanUrl('https://Example.com/docs/x?token=1#top'), { url: 'https://example.com/docs/x', domain: 'example.com' })
  assert.equal(cleanUrl('chrome://settings'), null)
  assert.equal(cleanUrl('file:///c:/secret.txt'), null)
  assert.equal(cleanUrl('chrome-extension://abc/page.html'), null)
  assert.equal(cleanUrl('not a url'), null)
})

test('opening a tab records tab_opened once, without query or fragment', () => {
  const state = focused()
  const events = openTab(state, tab(1), 1000, uuid)

  assert.deepEqual(types(events), ['tab_opened'])
  assert.equal(events[0].url, 'https://example.com/a')
  assert.equal(events[0].tab_id, 1)
  assert.equal(events[0].occurred_at, new Date(1000).toISOString())
  // Repeated reports of the same page (reload, title change, duplicate events) add nothing.
  assert.deepEqual(navigate(state, tab(1), 'https://example.com/a?x=2', 'A2', null, 2000, uuid), [])
  assert.deepEqual(navigate(state, tab(1), 'https://example.com/a#other', 'A3', null, 3000, uuid), [])
})

test('a blank new tab records nothing until it reaches a web page', () => {
  const state = focused()
  assert.deepEqual(openTab(state, tab(1, { url: 'chrome://newtab/' }), 0, uuid), [])
  assert.deepEqual(openTab(state, tab(2, { url: '' }), 0, uuid), [])
  assert.deepEqual(types(navigate(state, tab(1), 'https://example.org/', 'Org', null, 5000, uuid)), ['tab_opened'])
})

test('navigating to another page records it with the time spent on the previous page', () => {
  const state = focused()
  openTab(state, tab(1), 0, uuid)
  const events = navigate(state, tab(1), 'https://news.example.org/story', 'Story', null, 30_000, uuid)

  assert.deepEqual(types(events), ['navigated'])
  assert.equal(events[0].url, 'https://news.example.org/story')
  assert.equal(events[0].duration_seconds, 30)
  assert.equal(events[0].tab_session_id, state.tabs[1].sid)
})

test('only time in the focused, active tab counts, and switching tabs records tab_activated', () => {
  const state = focused()
  openTab(state, tab(1), 0, uuid) // A is active from 0
  openTab(state, tab(2, { active: false, url: 'https://b.example.com/' }), 1000, uuid)

  const switched = activate(state, tab(2, { url: 'https://b.example.com/' }), 10_000, uuid) // A had 10s
  assert.deepEqual(types(switched), ['tab_activated'])
  assert.equal(switched[0].url, 'https://b.example.com/')
  assert.equal(switched[0].duration_seconds, null)

  const back = activate(state, tab(1), 30_000, uuid) // B had 20s
  assert.deepEqual(types(back), ['tab_activated'])

  const closedA = closeTab(state, 1, 35_000, uuid) // A: 10s + 5s
  assert.deepEqual(types(closedA), ['tab_closed'])
  assert.equal(closedA[0].duration_seconds, 15)
  const closedB = closeTab(state, 2, 36_000, uuid) // B: 20s, and not active since 30s
  assert.equal(closedB[0].duration_seconds, 20)
})

test('losing and regaining window focus pauses the clock and is not a tab switch', () => {
  const state = focused()
  openTab(state, tab(1), 0, uuid)
  assert.deepEqual(focusWindow(state, -1, 10_000, uuid), []) // browser in the background after 10s
  const regained = focusWindow(state, 1, 100_000, uuid) // 90s away
  assert.deepEqual(regained, [])
  const closed = closeTab(state, 1, 105_000, uuid)
  assert.equal(closed[0].duration_seconds, 15) // 10s + 5s, not 105s
})

test('closing a tab that never finished loading still records its last navigation', () => {
  const state = focused()
  openTab(state, tab(1), 0, uuid)
  setPending(state, 1, 'https://slow.example.net/page?q=1')
  const events = closeTab(state, 1, 4000, uuid)

  assert.deepEqual(types(events), ['navigated', 'tab_closed'])
  assert.equal(events[0].url, 'https://slow.example.net/page')
  assert.equal(events[1].url, 'https://slow.example.net/page')
  assert.equal(state.tabs[1], undefined)
})

test('incognito tabs and non-web pages are never recorded', () => {
  const state = focused()
  assert.deepEqual(openTab(state, tab(1, { incognito: true }), 0, uuid), [])
  assert.deepEqual(navigate(state, tab(1, { incognito: true }), 'https://example.com/', 'x', null, 1, uuid), [])
  assert.deepEqual(activate(state, tab(1, { incognito: true }), 2, uuid), [])
  assert.deepEqual(closeTab(state, 1, 3, uuid), [])
  assert.deepEqual(Object.keys(state.tabs), [])

  openTab(state, tab(2), 0, uuid)
  assert.deepEqual(navigate(state, tab(2), 'chrome://settings/passwords', null, null, 1000, uuid), [])
  assert.deepEqual(closeTab(state, 2, 2000, uuid), []) // left the web: no tab_closed for a non-web page
})

test('tabs found at startup are adopted silently and only the front tab is reported', () => {
  const state = createState()
  assert.deepEqual(adoptTab(state, tab(1), 0, uuid), [])
  assert.deepEqual(adoptTab(state, tab(2, { active: false, url: 'https://b.example.com/x' }), 0, uuid), [])
  const events = focusWindow(state, 1, 100, uuid)

  assert.deepEqual(types(events), ['tab_activated'])
  assert.equal(events[0].tab_id, 1)
  // Its page is already known, so reloading it records nothing.
  assert.deepEqual(navigate(state, tab(1), 'https://example.com/a', 'A', null, 200, uuid), [])
})

test('every record carries a unique id, the tab session id and only allowed favicon schemes', () => {
  const state = focused()
  const a = openTab(state, tab(1, { favIconUrl: 'http://example.com/insecure.ico' }), 0, uuid)
  const b = navigate(state, tab(1), 'https://example.com/b', 'B', 'data:image/png;base64,AAAA', 5000, uuid)

  assert.equal(a[0].favicon_url, null)
  assert.equal(b[0].favicon_url, null)
  assert.notEqual(a[0].client_event_id, b[0].client_event_id)
  assert.equal(a[0].tab_session_id, b[0].tab_session_id)
})

test('durations are capped at one day and ignored when under a second', () => {
  const state = focused()
  openTab(state, tab(1), 0, uuid)
  assert.equal(navigate(state, tab(1), 'https://example.com/b', null, null, 400, uuid)[0].duration_seconds, null)
  const long = navigate(state, tab(1), 'https://example.com/c', null, null, 400 + 200 * 3600 * 1000, uuid)
  assert.equal(long[0].duration_seconds, 86400)
})
