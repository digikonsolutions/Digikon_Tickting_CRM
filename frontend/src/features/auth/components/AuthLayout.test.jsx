import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { PortalContext } from '../PortalContext'
import { AuthLayout } from './AuthLayout'

const brand = { slug: 'northwind', display_name: 'Northwind', tagline: 'Websites that work', logo_url: null, favicon_url: null, primary_color: '#0F766E', support_email: 'help@northwind.test' }

function renderLayout(portal) {
  return render(
    <MemoryRouter>
      <PortalContext.Provider value={portal}>
        <AuthLayout eyebrow="Welcome back" title="Sign in to Northwind" description="Use your account." footer={<a href="/x">Back</a>}>
          <button type="button">Sign in</button>
        </AuthLayout>
      </PortalContext.Provider>
    </MemoryRouter>,
  )
}

describe('AuthLayout', () => {
  it('in agency mode shows the agency and never the platform', () => {
    const { container } = renderLayout({ slug: 'northwind', brand })

    expect(container.textContent).toMatch(/Northwind/)
    expect(container.textContent).toMatch(/Websites that work/)
    expect(container.textContent).toContain(`© ${new Date().getFullYear()} Northwind`)
    expect(container.textContent).not.toMatch(/clienthub/i)
    expect(container.textContent).not.toMatch(/built for better teamwork|every request, in the right hands|this week/i)
    expect(container.querySelector('.auth-preview-card')).toBeNull()
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('links the agency mark to its own portal login, not the platform login', () => {
    const { container } = renderLayout({ slug: 'northwind', brand })

    const hrefs = [...container.querySelectorAll('a.brand')].map((link) => link.getAttribute('href'))
    expect(hrefs.length).toBeGreaterThan(0)
    hrefs.forEach((href) => expect(href).toBe('/a/northwind/login'))
  })

  it('uses the logo image, with the agency name as its text alternative, when there is one', () => {
    renderLayout({ slug: 'northwind', brand: { ...brand, logo_url: 'https://cdn.example.test/logo.png' } })

    expect(screen.getAllByAltText('Northwind').length).toBeGreaterThan(0)
  })

  it('offers the support email only when the agency set one', () => {
    const { container, rerender } = renderLayout({ slug: 'northwind', brand })
    expect(container.querySelector('a[href="mailto:help@northwind.test"]')).not.toBeNull()

    rerender(
      <MemoryRouter>
        <PortalContext.Provider value={{ slug: 'northwind', brand: { ...brand, support_email: null } }}>
          <AuthLayout title="Sign in"><span /></AuthLayout>
        </PortalContext.Provider>
      </MemoryRouter>,
    )
    expect(container.querySelector('a[href^="mailto:"]')).toBeNull()
  })

  it('names the sign-in region after the agency and builds its headline from the tagline', () => {
    const { container, rerender } = renderLayout({ slug: 'northwind', brand })
    expect(container.querySelector('.auth-shell')).toHaveAttribute('aria-label', 'Northwind account access')
    expect(container.querySelector('.showcase-title').textContent).toBe('Websites that work')

    rerender(
      <MemoryRouter>
        <PortalContext.Provider value={{ slug: 'northwind', brand: { ...brand, tagline: null } }}>
          <AuthLayout title="Sign in"><span /></AuthLayout>
        </PortalContext.Provider>
      </MemoryRouter>,
    )
    expect(container.querySelector('.showcase-title').textContent).toBe('Welcome to Northwind')
  })

  it('shows the help line in the card footer, so it is visible on phones too', () => {
    const { container } = renderLayout({ slug: 'northwind', brand })

    expect(container.querySelector('.auth-form-footer .portal-help a[href="mailto:help@northwind.test"]')).not.toBeNull()
  })
  it('on the platform pages keeps the ClientHub brand (the control case)', () => {
    const { container } = renderLayout(null)

    expect(container.textContent).toMatch(/ClientHub/)
    expect(container.querySelector('.auth-preview-card')).not.toBeNull()
  })
})