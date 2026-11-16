// Service worker: listens to tab events, queues records locally and syncs them to ClientHub.
// Everything it keeps lives in chrome.storage.local so a restart of the worker or the browser loses nothing
// that was already recorded.
import { activate, adoptTab, closeTab, createState, focusWindow, isEmpty, navigate, openTab, setPending } from './tracker.js'
import { runSync, trimQueue } from './sync.js'

const DEFAULTS = { enabled: true, apiBase: '' }
const SYNC_ALARM = 'clienthub-sync'

let chain = Promise.resolve()

async function load() {
  const data = await chrome.storage.local.get(['settings', 'tracker', 'queue', 'status'])

  return {
    settings: { ...DEFAULTS, ...(data.settings ?? {}) },
    tracker: data.tracker ?? createState(),
    queue: data.queue ?? [],
    status: data.status ?? { signedIn: false, allowed: false, lastSync: null, error: null },
  }
}

// Serialises every state change: events arrive close together and each one reads, changes, then writes.
function run(task) {
  chain = chain.then(task).catch(() => {})

  return chain
}

function recordingOn(store) {
  return store.settings.enabled && Boolean(store.settings.apiBase) && store.status.allowed
}

// Applies a tracker update and stores the records it produced. Does nothing while tracking is off, the
// employee is signed out, or the current notice has not been accepted.
function track(update) {
  return run(async () => {
    const store = await load()
    if (!recordingOn(store)) {
      if (!isEmpty(store.tracker)) await chrome.storage.local.set({ tracker: createState() })

      return
    }
    const events = update(store.tracker, Date.now()) ?? []
    await chrome.storage.local.set({ tracker: store.tracker, queue: trimQueue([...store.queue, ...events]) })
  })
}

const tabInfo = (tab) => ({ id: tab.id, windowId: tab.windowId, incognito: tab.incognito, active: tab.active, url: tab.url, title: tab.title, favIconUrl: tab.favIconUrl })

chrome.tabs.onCreated.addListener((tab) => track((state, now) => openTab(state, tabInfo(tab), now)))

chrome.tabs.onUpdated.addListener((tabId, change, tab) => {
  // A finished load (or a changed title) is when the page title is known.
  if (change.status !== 'complete' && change.title === undefined) return
  if (!tab.url) return
  track((state, now) => {
    if (!state.tabs[tabId]) adoptTab(state, tabInfo(tab), now)

    return navigate(state, tabInfo(tab), tab.url, tab.title, tab.favIconUrl, now)
  })
})

chrome.tabs.onActivated.addListener(({ tabId }) => {
  chrome.tabs.get(tabId).then((tab) => track((state, now) => {
    if (!state.tabs[tabId]) adoptTab(state, tabInfo(tab), now)

    return activate(state, tabInfo(tab), now)
  })).catch(() => {})
})

chrome.tabs.onRemoved.addListener((tabId) => track((state, now) => closeTab(state, tabId, now)))

chrome.windows.onFocusChanged.addListener((windowId) => track((state, now) => focusWindow(state, windowId, now)))

chrome.webNavigation.onCommitted.addListener((details) => {
  if (details.frameId !== 0) return
  track((state) => { setPending(state, details.tabId, details.url) })
})

// Single-page apps change the address without a page load.
chrome.webNavigation.onHistoryStateUpdated.addListener((details) => {
  if (details.frameId !== 0) return
  chrome.tabs.get(details.tabId).then((tab) => track((state, now) => navigate(state, tabInfo(tab), details.url, tab.title, tab.favIconUrl, now))).catch(() => {})
})

// Follows the tabs that are already open (install, browser start, or tracking just allowed) without reporting
// them as newly opened; the tab in front is reported as the active tab.
async function seed() {
  const [tabs, focused] = await Promise.all([chrome.tabs.query({}), chrome.windows.getLastFocused().catch(() => null)])

  return track((state, now) => {
    for (const tab of tabs) adoptTab(state, tabInfo(tab), now)

    return focusWindow(state, focused?.focused ? focused.id : null, now)
  })
}

// Verifies sign-in and consent, sends what is queued, and keeps the local queue bounded.
function syncNow() {
  return run(async () => {
    const store = await load()
    if (!store.settings.apiBase) return
    const result = await runSync({ apiBase: store.settings.apiBase, queue: store.queue, cookies: chrome.cookies })
    const status = {
      ...store.status,
      ...result.status,
      lastSync: result.stored.size > 0 || (!result.offline && !result.status.error) ? Date.now() : store.status.lastSync,
    }

    let { queue, tracker } = store
    if (result.offline) {
      // No connection: keep everything and try again on the next alarm.
    } else if (result.status.signedIn && result.status.allowed) {
      queue = queue.filter((event) => !result.stored.has(event.client_event_id))
    } else {
      // Signed out, or the notice is not accepted (or was replaced by a new version): keep nothing.
      queue = []
      tracker = createState()
    }
    await chrome.storage.local.set({ status, queue, tracker })

    return !store.status.allowed && result.status.allowed
  }).then((becameAllowed) => (becameAllowed ? seed() : undefined))
}

chrome.alarms.onAlarm.addListener((alarm) => { if (alarm.name === SYNC_ALARM) syncNow() })

chrome.runtime.onMessage.addListener((message, _sender, reply) => {
  if (message?.type === 'sync-now') {
    syncNow().then(() => reply({ ok: true }))

    return true
  }
  if (message?.type === 'settings-changed') {
    // Turning tracking off forgets what the tracker was following; whatever was already queued is still sent.
    run(async () => {
      const store = await load()
      if (!store.settings.enabled) await chrome.storage.local.set({ tracker: createState() })
    }).then(() => syncNow()).then(() => reply({ ok: true }))

    return true
  }
  if (message?.type === 'clear-queue') {
    run(() => chrome.storage.local.set({ queue: [] })).then(() => reply({ ok: true }))

    return true
  }

  return false
})

function start() {
  chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 1 })
  // A new browser session has new tab ids: forget the old ones, then see who is signed in.
  run(() => chrome.storage.local.set({ tracker: createState() })).then(() => syncNow()).then(seed)
}

chrome.runtime.onInstalled.addListener(start)
chrome.runtime.onStartup.addListener(start)
