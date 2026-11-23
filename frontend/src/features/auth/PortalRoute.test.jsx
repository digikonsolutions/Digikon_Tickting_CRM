import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { authService } from '../../auth/authService'
import { PortalRoute } from './PortalRoute'

vi.mock('../../auth/authService', () => ({ authService: { getPublicBranding: vi.fn() } }))
vi.mock('../../auth/useAuth', () => ({ useAuth: () => ({ user: null }) }))

const brand = { slug: 'northwind', display_name: 'Northwind', tagline: 'Make it work', logo_url: null, favicon_url: 'https://cdn.example.test/fav.png', primary_color: '#C2410C', support_email: null }

function renderPortal(path = '/a/northwind/login') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/a/:slug" element={<PortalRoute />}>
          <Route path="login" element={<p>portal child</p>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

describe('PortalRoute', () => {
  beforeEach(() => {
    document.title = 'Loading…'
  })

  it('shows a neutral loader first, then the portal with the brand applied', async () => {
    let resolve
    authService.getPublicBranding.mockReturnValue(new Promise((done) => { resolve = done }))
    renderPortal()

    // Before the brand arrives nothing brand-specific (and nothing about the platform) is on screen or in the tab.
    expect(screen.queryByText('portal child')).not.toBeInTheDocument()
    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('')
    expect(document.title).toBe('Loading…')

    resolve({ branding: brand })
    expect(await screen.findByText('portal child')).toBeInTheDocument()
    expect(document.title).toBe('Northwind')
    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('#C2410C')
    expect(document.querySelector('link[rel~="icon"]').getAttribute('href')).toBe('https://cdn.example.test/fav.png')
    expect(window.localStorage.getItem('portal:slug')).toBe('northwind')
  })

  it('asks for the brand of the address in the URL', async () => {
    authService.getPublicBranding.mockResolvedValue({ branding: brand })
    renderPortal('/a/northwind/login')

    await screen.findByText('portal child')
    expect(authService.getPublicBranding).toHaveBeenCalledWith('northwind')
  })

  it('removes the brand again when the portal is left', async () => {
    authService.getPublicBranding.mockResolvedValue({ branding: brand })
    const { unmount } = renderPortal()
    await screen.findByText('portal child')

    unmount()

    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('')
    expect(document.title).toBe('Loading…')
  })

  it('keeps the platform default for a colour that cannot carry readable text', async () => {
    authService.getPublicBranding.mockResolvedValue({ branding: { ...brand, primary_color: '#FFFF99' } })
    renderPortal()

    await screen.findByText('portal child')
    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('')
  })

  it('shows a neutral not-found page for an unknown or suspended portal, naming no brand', async () => {
    authService.getPublicBranding.mockRejectedValue(Object.assign(new Error('Not found.'), { status: 404 }))
    const { container } = renderPortal('/a/nope/login')

    await waitFor(() => expect(screen.getByText('Page not found')).toBeInTheDocument())
    expect(screen.queryByText('portal child')).not.toBeInTheDocument()
    expect(container.textContent).not.toMatch(/clienthub|northwind/i)
    expect(document.title).toBe('Loading…')
    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('')
  })
})