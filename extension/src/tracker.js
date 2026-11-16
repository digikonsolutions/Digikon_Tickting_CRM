// Pure tab-tracking logic: no chrome.* calls, so it can be tested in Node. The service worker feeds it
// browser events and stores the returned records in a queue.
//
// Privacy by construction: only http(s) pages are recorded, the query string and fragment are removed
// before anything is stored, and nothing about page content, form fields or incognito tabs is ever read.

export const MAX_SECONDS = 86400
const MIN_SECONDS = 1

/** Keeps scheme, host, port and path. Returns null for anything that is not a normal web page. */
export function cleanUrl(raw) {
  try {
    const url = new URL(raw)
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null
    return { url: `${url.protocol}//${url.host}${url.pathname || '/'}`, domain: url.hostname.toLowerCase() }
  } catch {
    return null
  }
}

export function httpsFavicon(value) {
  return typeof value === 'string' && value.startsWith('https://') ? value.slice(0, 500) : null
}

export function createState() {
  return { tabs: {}, activeByWindow: {}, focusedWindow: null, lastActivated: null }
}

export function isEmpty(state) {
  return Object.keys(state.tabs).length === 0
}

function newEntry(tab, uuid) {
  return {
    sid: uuid(), windowId: tab.windowId ?? null, url: null, domain: null, title: null, fav: null,
    activeMs: 0, activeSince: null, opened: false, pendingUrl: null,
  }
}

function pause(entry, now) {
  if (entry.activeSince !== null) {
    entry.activeMs += Math.max(0, now - entry.activeSince)
    entry.activeSince = null
  }
}

function resume(entry, now) {
  if (entry.activeSince === null) entry.activeSince = now
}

// Active time since the last call, in whole seconds (null when too short to be meaningful), then reset.
function takeSeconds(entry, now) {
  const wasRunning = entry.activeSince !== null
  pause(entry, now)
  const seconds = Math.min(MAX_SECONDS, Math.round(entry.activeMs / 1000))
  entry.activeMs = 0
  if (wasRunning) resume(entry, now)

  return seconds >= MIN_SECONDS ? seconds : null
}

function record(type, entry, tabId, seconds, now, uuid) {
  return {
    client_event_id: uuid(),
    event_type: type,
    tab_session_id: entry.sid,
    tab_id: tabId,
    url: entry.url,
    title: entry.title ? String(entry.title).slice(0, 255) : null,
    favicon_url: httpsFavicon(entry.fav),
    duration_seconds: seconds,
    occurred_at: new Date(now).toISOString(),
  }
}

function entryFor(state, tab, uuid) {
  return state.tabs[tab.id] ?? (state.tabs[tab.id] = newEntry(tab, uuid))
}

function isFocusedActive(state, entry, tabId) {
  return state.focusedWindow !== null && state.activeByWindow[entry.windowId] === tabId && state.focusedWindow === entry.windowId
}

/** A tab appeared (created, or found at startup). Incognito tabs are ignored entirely. */
export function openTab(state, tab, now, uuid = () => crypto.randomUUID()) {
  if (tab.id === undefined || tab.incognito) return []
  const entry = entryFor(state, tab, uuid)
  entry.windowId = tab.windowId ?? entry.windowId
  if (tab.active) state.activeByWindow[entry.windowId] = tab.id
  if (isFocusedActive(state, entry, tab.id)) {
    resume(entry, now)
    // A tab opened in front is already the active one: coming back to the window is not a switch.
    state.lastActivated = tab.id
  }

  return tab.url ? navigate(state, tab, tab.url, tab.title, tab.favIconUrl, now, uuid) : []
}

/**
 * A tab that was already open when tracking started (browser start, install, or switched on). It is
 * followed from now on, but its current page is not reported as "opened" and records nothing by itself.
 */
export function adoptTab(state, tab, now, uuid = () => crypto.randomUUID()) {
  if (tab.id === undefined || tab.incognito) return []
  const entry = entryFor(state, tab, uuid)
  entry.windowId = tab.windowId ?? entry.windowId
  const clean = cleanUrl(tab.url)
  entry.opened = true
  if (clean) {
    entry.url = clean.url
    entry.domain = clean.domain
    entry.title = tab.title || null
    entry.fav = tab.favIconUrl || null
  }
  if (tab.active) state.activeByWindow[entry.windowId] = tab.id
  if (isFocusedActive(state, entry, tab.id)) resume(entry, now)

  return []
}

/** Notes a committed navigation so it can still be recorded if the tab closes before the page finishes loading. */
export function setPending(state, tabId, rawUrl) {
  const entry = state.tabs[tabId]
  if (entry) entry.pendingUrl = rawUrl
}

/**
 * The tab shows a (new) page. The first page of a tab is "tab_opened"; later pages are "navigated" and carry
 * the time spent on the page before. Reporting the same page again (reload, repeated events) records nothing.
 */
export function navigate(state, tab, rawUrl, title, fav, now, uuid = () => crypto.randomUUID()) {
  if (tab.id === undefined || tab.incognito) return []
  const entry = entryFor(state, tab, uuid)
  const clean = cleanUrl(rawUrl)
  entry.pendingUrl = null

  if (!clean) {
    // Left the web (chrome://, new tab page, file://): nothing is recorded and the next page starts fresh.
    pause(entry, now)
    entry.activeMs = 0
    entry.url = null
    if (isFocusedActive(state, entry, tab.id)) resume(entry, now)

    return []
  }
  if (entry.url === clean.url) {
    if (title) entry.title = title
    if (fav) entry.fav = fav

    return []
  }

  const seconds = entry.url === null ? null : takeSeconds(entry, now)
  const first = !entry.opened
  entry.url = clean.url
  entry.domain = clean.domain
  entry.title = title || null
  entry.fav = fav || null
  entry.opened = true
  if (first) entry.activeMs = 0

  return [record(first ? 'tab_opened' : 'navigated', entry, tab.id, first ? null : seconds, now, uuid)]
}

/** A tab became the active tab of its window. */
export function activate(state, tab, now, uuid = () => crypto.randomUUID()) {
  if (tab.id === undefined || tab.incognito) return []
  const entry = entryFor(state, tab, uuid)
  entry.windowId = tab.windowId ?? entry.windowId
  const previousId = state.activeByWindow[entry.windowId]
  if (previousId !== undefined && previousId !== tab.id && state.tabs[previousId]) pause(state.tabs[previousId], now)
  state.activeByWindow[entry.windowId] = tab.id

  if (state.focusedWindow !== entry.windowId) return []
  resume(entry, now)

  return announce(state, entry, tab.id, now, uuid)
}

/** The focused browser window changed. `windowId` is null/-1 when the browser lost focus entirely. */
export function focusWindow(state, windowId, now, uuid = () => crypto.randomUUID()) {
  const focused = windowId === null || windowId === undefined || windowId < 0 ? null : windowId
  for (const [id, entry] of Object.entries(state.tabs)) {
    if (focused === null || state.activeByWindow[entry.windowId] !== Number(id) || entry.windowId !== focused) pause(entry, now)
  }
  state.focusedWindow = focused
  if (focused === null) return []
  const tabId = state.activeByWindow[focused]
  const entry = tabId === undefined ? undefined : state.tabs[tabId]
  if (!entry) return []
  resume(entry, now)

  return announce(state, entry, tabId, now, uuid)
}

// "tab_activated" only when the person actually switched to a different tab, never when focus returns to the same one.
function announce(state, entry, tabId, now, uuid) {
  if (state.lastActivated === tabId || entry.url === null) {
    state.lastActivated = entry.url === null ? state.lastActivated : tabId

    return []
  }
  state.lastActivated = tabId

  return [record('tab_activated', entry, tabId, null, now, uuid)]
}

/** The tab closed. Records its last page and the time spent on it, plus any navigation that never finished loading. */
export function closeTab(state, tabId, now, uuid = () => crypto.randomUUID()) {
  const entry = state.tabs[tabId]
  if (!entry) return []
  const events = []
  if (entry.pendingUrl) events.push(...navigate(state, { id: tabId, windowId: entry.windowId }, entry.pendingUrl, null, null, now, uuid))
  if (entry.url !== null) events.push(record('tab_closed', entry, tabId, takeSeconds(entry, now), now, uuid))

  delete state.tabs[tabId]
  if (state.activeByWindow[entry.windowId] === tabId) delete state.activeByWindow[entry.windowId]
  if (state.lastActivated === tabId) state.lastActivated = null

  return events
}
