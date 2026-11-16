const $ = (id) => document.getElementById(id)

async function render() {
  const { settings = {}, queue = [], status = {} } = await chrome.storage.local.get(['settings', 'queue', 'status'])
  const enabled = settings.enabled !== false
  $('enabled').checked = enabled
  $('queue').textContent = String(queue.length)
  $('last').textContent = status.lastSync ? new Date(status.lastSync).toLocaleTimeString() : '—'

  const state = $('state')
  state.className = ''
  if (!settings.apiBase) {
    state.textContent = 'Not set up: open Settings and add your ClientHub address.'
    state.className = 'bad'
  } else if (status.error === 'offline') {
    state.textContent = 'Offline: will send when the connection returns.'
  } else if (!status.signedIn) {
    state.textContent = 'Sign in to ClientHub in this browser.'
    state.className = 'bad'
  } else if (!status.allowed) {
    state.textContent = 'Paused: accept the work activity notice in ClientHub.'
    state.className = 'bad'
  } else if (!enabled) {
    state.textContent = 'Tracking is switched off.'
  } else {
    state.textContent = 'Tracking and syncing.'
    state.className = 'ok'
  }
  $('hint').textContent = enabled
    ? 'Records the address (without anything after “?” or “#”), title and time of the tabs you use. Never page content, passwords or private windows.'
    : 'Nothing new is recorded while this is off.'
}

$('enabled').addEventListener('change', async (event) => {
  const { settings = {} } = await chrome.storage.local.get('settings')
  await chrome.storage.local.set({ settings: { ...settings, enabled: event.target.checked } })
  await chrome.runtime.sendMessage({ type: 'settings-changed' })
  render()
})

$('sync').addEventListener('click', async (event) => {
  event.target.disabled = true
  await chrome.runtime.sendMessage({ type: 'sync-now' })
  event.target.disabled = false
  render()
})

$('clear').addEventListener('click', async () => {
  await chrome.runtime.sendMessage({ type: 'clear-queue' })
  render()
})

$('options').addEventListener('click', (event) => {
  event.preventDefault()
  chrome.runtime.openOptionsPage()
})

chrome.storage.onChanged.addListener(render)
render()
