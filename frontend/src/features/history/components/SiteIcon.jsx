import { useState } from 'react'
import { safeFavicon } from '../historyData'

// A website's favicon, or its first letter when there is none (or it fails to load).
export function SiteIcon({ domain, src, size = 'md' }) {
  const [failed, setFailed] = useState(false)
  const url = safeFavicon(src)

  return (
    <span className={`site-icon site-icon-${size}`} aria-hidden="true">
      {url && !failed
        ? <img src={url} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(true)} />
        : <span>{(domain ?? '?').replace(/^www\./, '').charAt(0).toUpperCase()}</span>}
    </span>
  )
}
