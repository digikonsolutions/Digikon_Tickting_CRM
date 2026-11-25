import { effectiveApiBase, readManaged } from '../src/config.js'
const $ = (id) => document.getElementById(id)

async function render() {
  const { settings: stored = {}, queue = [], status = {}, snapshotLast = null } = await chrome.storage.local.get(['settings', 'queue', 'status', 'snapshotLast'])
  // A managed install supplies the address; the popup treats it as set.
  const settings = { ...stored, apiBase: effectiveApiBase(stored, await readManaged()) }
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
  } else if (!status.recording) {
    state.textContent = 'Not recording: no ticket or project is in progress.'
  } else {
    const work = status.activeWork
    state.textContent = work ? `Recording for ${work.ticket_key ? `${work.ticket_key} ` : ''}${work.title}.` : 'Recording.'
    state.className = 'ok'
  }
  const snap = $('snap')
  snap.hidden = !(status.snapshots && status.recording && enabled)
  const last = snapshotLast ? ` Last: ${new Date(snapshotLast).toLocaleTimeString()}.` : ''
  snap.textContent = status.snapshots ? `Snapshots on: a small picture of approved work sites, every ${status.snapshots.min_minutes} to ${status.snapshots.max_minutes} minutes.${last}` : ''
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
