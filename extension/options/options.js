import { normalizeApiBase } from '../src/sync.js'

const form = document.getElementById('form')
const input = document.getElementById('api')
const message = document.getElementById('message')

function say(text, bad = false) {
  message.textContent = text
  message.className = bad ? 'bad' : ''
}

chrome.storage.local.get('settings').then(({ settings }) => { input.value = settings?.apiBase ?? '' })

form.addEventListener('submit', async (event) => {
  event.preventDefault()
  const apiBase = normalizeApiBase(input.value)
  if (!apiBase) {
    say('Use an https:// address (http:// only for localhost).', true)
    return
  }
  // Least privilege: ask the browser for access to this one origin only.
  const granted = await chrome.permissions.request({ origins: [`${new URL(apiBase).origin}/*`] })
  if (!granted) {
    say('Access was not granted, so the extension cannot reach ClientHub.', true)
    return
  }
  const { settings = {} } = await chrome.storage.local.get('settings')
  await chrome.storage.local.set({ settings: { enabled: true, ...settings, apiBase } })
  input.value = apiBase
  await chrome.runtime.sendMessage({ type: 'sync-now' })
  say('Saved. The popup shows whether you are signed in and tracking.')
})
