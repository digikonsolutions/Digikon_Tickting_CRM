import { useState } from 'react'
import { useBranding } from './useBranding'

/**
 * The sidebar brand: the agency logo (or the square mark when collapsed), else the default mark plus
 * the display name. The box size is reserved by CSS so a slow or broken image never shifts the layout;
 * a failed image swaps to the default mark.
 */
export function BrandMark({ collapsed = false }) {
  const { displayName, logoUrl, collapsedLogoUrl } = useBranding()
  const wanted = collapsed ? (collapsedLogoUrl ?? null) : logoUrl
  const [failedUrl, setFailedUrl] = useState(null)
  const url = wanted && wanted !== failedUrl ? wanted : null

  const name = displayName || 'ClientHub'

  if (url) {
    return (
      <>
        <span className={`ab-logo-box${collapsed ? ' ab-logo-box-square' : ''}`}>
          <img className="ab-logo" src={url} alt="" onError={() => setFailedUrl(url)} />
        </span>
        <span className="sr-only">{name}</span>
      </>
    )
  }

  return (
    <>
      <span className="brand-mark" aria-hidden="true">{displayName ? displayName.trim().charAt(0).toUpperCase() : 'C'}</span>
      <span className="sidebar-brand-name">{name}</span>
    </>
  )
}
