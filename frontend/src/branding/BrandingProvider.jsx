import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/useAuth'
import { authService } from '../auth/authService'
import { BrandingContext } from './BrandingContext'
import { brandingVariables, resolveTheme } from './brandingVariables'

const CACHE_KEY = 'clienthub.branding'
const DARK_QUERY = '(prefers-color-scheme: dark)'

function readCache() {
  try {
    const raw = window.localStorage.getItem(CACHE_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function writeCache(branding) {
  try {
    if (branding) window.localStorage.setItem(CACHE_KEY, JSON.stringify(branding))
    else window.localStorage.removeItem(CACHE_KEY)
  } catch {
    // Storage can be blocked; the server response is still the source of truth.
  }
}

function systemPrefersDark() {
  return typeof window.matchMedia === 'function' && window.matchMedia(DARK_QUERY).matches
}

function faviconLink() {
  let link = document.querySelector('link[rel~="icon"]')
  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }
  return link
}

/**
 * Applies the signed-in agency user's branding to the document (CSS variables, theme, favicon, title)
 * and resets everything on logout or for users without branding. While the session is still loading,
 * the last cached branding is painted so a reload does not flash the default look; the server
 * response then reconciles it.
 */
export function BrandingProvider({ children }) {
  const { user, isLoading, updateBranding } = useAuth()
  const [prefersDark, setPrefersDark] = useState(systemPrefersDark)
  const [cached] = useState(readCache)

  const serverBranding = user?.branding ?? null
  const branding = isLoading ? cached : serverBranding

  useEffect(() => {
    if (isLoading) return
    writeCache(serverBranding)
  }, [serverBranding, isLoading])

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return undefined
    const query = window.matchMedia(DARK_QUERY)
    const onChange = (event) => setPrefersDark(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  // Pick up changes the owner made while this user was signed in: re-read the branding when the tab
  // regains focus (at most every 30 s). Only agency users have branding, so others never fetch.
  const hasBranding = serverBranding !== null
  useEffect(() => {
    if (!hasBranding) return undefined
    const seen = { at: Date.now(), json: JSON.stringify(serverBranding) }
    async function refresh() {
      if (document.visibilityState === 'hidden' || Date.now() - seen.at < 30_000) return
      seen.at = Date.now()
      try {
        const response = await authService.getCurrentUser()
        const next = (response?.user ?? response)?.branding ?? null
        if (next && JSON.stringify(next) !== seen.json) {
          seen.json = JSON.stringify(next)
          updateBranding(next)
        }
      } catch {
        // Offline or signed out: the next real request deals with it.
      }
    }
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', refresh)
    return () => {
      window.removeEventListener('focus', refresh)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [hasBranding, serverBranding, updateBranding])

  const resolvedTheme =resolveTheme(branding, prefersDark)

  // Layout effect: variables are in place before the browser paints the branded tree.
  useLayoutEffect(() => {
    const root = document.documentElement
    const vars = brandingVariables(branding, { dark: resolvedTheme === 'dark' })
    const names = Object.keys(vars)
    names.forEach((name) => root.style.setProperty(name, vars[name]))

    if (branding) {
      root.dataset.theme = resolvedTheme
      root.dataset.sidebar = branding.sidebar_style === 'brand' ? 'brand' : 'light'
    }

    return () => {
      names.forEach((name) => root.style.removeProperty(name))
      delete root.dataset.theme
      delete root.dataset.sidebar
    }
  }, [branding, resolvedTheme])

  const logoUrl = branding?.logo_url ?? null
  const displayName = branding?.display_name || null
  const faviconUrl = branding?.favicon_url ?? null

  useEffect(() => {
    if (!faviconUrl) return undefined
    const link = faviconLink()
    const previous = { href: link.getAttribute('href'), type: link.getAttribute('type') }
    link.removeAttribute('type')
    link.setAttribute('href', faviconUrl)
    return () => {
      if (previous.href) link.setAttribute('href', previous.href)
      if (previous.type) link.setAttribute('type', previous.type)
    }
  }, [faviconUrl])

  // The tab title is set per page by AppLayout (it includes this display name).

  const value = useMemo(() => ({
    branding,
    isBranded: Boolean(branding && (branding.primary_color || branding.logo_url || branding.display_name)),
    displayName,
    logoUrl,
    collapsedLogoUrl: branding?.logo_collapsed_url ?? null,
    tagline: branding?.tagline || null,
    welcomeMessage: branding?.welcome_message || null,
    sidebarStyle: branding?.sidebar_style === 'brand' ? 'brand' : 'light',
    resolvedTheme,
  }), [branding, displayName, logoUrl, resolvedTheme])

  return <BrandingContext.Provider value={value}>{children}</BrandingContext.Provider>
}
