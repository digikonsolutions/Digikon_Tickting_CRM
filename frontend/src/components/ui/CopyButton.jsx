import { useState } from 'react'

// Copies an id or a link. The label changes for two seconds so the click is confirmed, and screen readers hear it.
export function CopyButton({ value, label = 'Copy', className = '' }) {
  const [copied, setCopied] = useState(false)
  async function copy() {
    try {
      await navigator.clipboard.writeText(String(value))
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard blocked: the value stays visible next to the button, so nothing is lost.
    }
  }
  if (value === null || value === undefined || value === '') return null
  return (
    <button type="button" className={`ui-copy-button ${className}`.trim()} onClick={copy} aria-label={`${label} ${value}`}>
      <span aria-live="polite">{copied ? 'Copied' : label}</span>
    </button>
  )
}
