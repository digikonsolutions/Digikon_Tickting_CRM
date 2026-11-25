// Work snapshots: when to take one, whether the tab in front may be captured, and how the picture is made small.
// The decisions are plain functions so they can be tested; only `shrink` needs a browser.

export const MAX_WIDTH = 1000
export const QUALITY = 0.6

const listed = (host, list) => list.some((domain) => {
  const d = String(domain).toLowerCase()
  return host === d || host.endsWith(`.${d}`)
})

// Any website unless it is blocked. When the server sends an allow list, only those sites and their subdomains. The server
// checks the same rule again.
export function domainAllowed(host, allowed = [], blocked = []) {
  const name = String(host ?? '').toLowerCase()
  if (!name || listed(name, blocked)) return false
  return allowed.length === 0 || listed(name, allowed)
}

// Only ordinary web pages (http or https) in a normal window. Returns the host to report, or null.
export function capturable(tab, allowed = [], blocked = []) {
  if (!tab || tab.incognito || typeof tab.url !== 'string' || !/^https?:\/\//.test(tab.url)) return null
  try {
    const host = new URL(tab.url).hostname.toLowerCase()
    return domainAllowed(host, allowed, blocked) ? host : null
  } catch {
    return null
  }
}

// A random wait between the minimum and maximum, so pictures never fall on a predictable beat.
export function nextDelayMs(minMinutes, maxMinutes, random = Math.random) {
  const min = Math.max(1, Number(minMinutes) || 5)
  const max = Math.max(min, Number(maxMinutes) || 15)
  return Math.round((min + random() * (max - min)) * 60_000)
}

// What to do on a one-minute tick: wait, schedule the first picture, or capture now.
export function tickAction({ now, nextAt }) {
  if (!nextAt) return 'schedule'
  return now >= nextAt ? 'capture' : 'wait'
}

export function scaledSize(width, height, maxWidth = MAX_WIDTH) {
  if (!width || !height) return { width: maxWidth, height: Math.round(maxWidth * 0.625) }
  if (width <= maxWidth) return { width, height }
  return { width: maxWidth, height: Math.round((height * maxWidth) / width) }
}

// Shrinks a captured picture (a data URL) to a small WebP. Runs in the service worker.
export async function shrink(dataUrl, { maxWidth = MAX_WIDTH, quality = QUALITY } = {}) {
  const source = await (await fetch(dataUrl)).blob()
  const bitmap = await createImageBitmap(source)
  const { width, height } = scaledSize(bitmap.width, bitmap.height, maxWidth)
  const canvas = new OffscreenCanvas(width, height)
  canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height)
  bitmap.close?.()
  const blob = await canvas.convertToBlob({ type: 'image/webp', quality })

  return { blob, width, height }
}

// The page title is shown to administrators next to the picture; keep it short and plain.
export function cleanTitle(title) {
  return typeof title === 'string' ? title.replace(/\s+/g, ' ').trim().slice(0, 200) || null : null
}