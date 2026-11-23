import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { Outlet, useParams } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { useAuth } from '../../auth/useAuth'
import { storePortal } from '../../auth/portal'
import { brandingVariables } from '../../branding/brandingVariables'
import { Loader } from '../../components/ui'
import { PortalContext } from './PortalContext'

// Neutral page for an unknown or suspended portal: no brand, no hint which one it was.
function PortalNotFound() {
  return (
    <main className="status-page">
      <section className="status-card">
        <h1>Page not found</h1>
        <p className="page-copy">This address is not available.</p>
      </section>
    </main>
  )
}

/**
 * Wraps /a/:slug/*. Fetches the agency's public brand behind a neutral loader (so there is no flash of another brand),
 * applies its colour, title and favicon while the portal is open, and shares it with the auth pages.
 */
export function PortalRoute() {
  const { slug } = useParams()
  const { user } = useAuth()
  const signedIn = Boolean(user)
  const [state, setState] = useState({ slug: null, brand: null, failed: false })

  useEffect(() => {
    let active = true
    authService.getPublicBranding(slug)
      .then((response) => { if (active) { setState({ slug, brand: response.branding, failed: false }); storePortal(response.branding.slug) } })
      .catch(() => { if (active) setState({ slug, brand: null, failed: true }) })
    return () => { active = false }
  }, [slug])

  const ready = state.slug === slug
  const brand = ready ? state.brand : null

  useLayoutEffect(() => {
    // Signed-in users get their branding from BrandingProvider; this only paints the logged-out portal pages.
    // (Both write the same variables, so an unmount cleanup here must never run after the provider has applied them.)
    if (!brand || signedIn) return undefined
    const root = document.documentElement
    const vars = brandingVariables({ primary_color: brand.primary_color })
    Object.entries(vars).forEach(([name, value]) => root.style.setProperty(name, value))
    return () => Object.keys(vars).forEach((name) => root.style.removeProperty(name))
  }, [brand, signedIn])

  useEffect(() => {
    if (!brand) return undefined
    const previous = document.title
    document.title = brand.display_name
    let link = document.querySelector('link[rel~="icon"]')
    const before = link?.getAttribute('href')
    if (brand.favicon_url) {
      if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link) }
      link.setAttribute('href', brand.favicon_url)
    }
    return () => {
      document.title = previous
      if (link && brand.favicon_url && before) link.setAttribute('href', before)
    }
  }, [brand])

  const value = useMemo(() => (brand ? { slug, brand } : null), [slug, brand])

  if (!ready) return <Loader label="Loading…" />
  if (state.failed || !brand) return <PortalNotFound />
  return <PortalContext.Provider value={value}><Outlet /></PortalContext.Provider>
}