import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BrandingPreview } from './BrandingPreview'

const draft = { display_name: 'Northwind', tagline: 'Websites that work', welcome_message: '', primary_color: '#C2410C', accent_color: '', theme_mode: 'light', sidebar_style: 'light', corner_style: 'rounded', support_email: 'help@northwind.test', email_from_name: '' }

function show(overrides = {}) {
  return render(<BrandingPreview draft={{ ...draft, ...overrides }} logoUrl={null} faviconUrl={null} agencyName="Northwind Legal Ltd" systemDark={false} />)
}

describe('BrandingPreview sign-in view', () => {
  it('shows the workspace first and the sign-in page on request', () => {
    show()

    expect(screen.queryByTestId('signin-preview')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sign-in page' }))

    expect(screen.getByTestId('signin-preview')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign-in page' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: 'Workspace' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('follows the unsaved draft: name, tagline, support email and the brand colour', () => {
    const { container } = show()
    fireEvent.click(screen.getByRole('button', { name: 'Sign-in page' }))

    const login = screen.getByTestId('signin-preview')
    expect(login.textContent).toContain('Sign in to Northwind')
    expect(login.textContent).toContain('Websites that work')
    expect(login.textContent).toContain('Need help? help@northwind.test')
    expect(login.textContent).toContain(`© ${new Date().getFullYear()} Northwind`)
    expect(container.querySelector('.ab-pv').style.getPropertyValue('--brand')).toBe('#C2410C')
  })

  it('never mentions the platform and leaves the help line out without a support email', () => {
    const { container } = show({ support_email: '' })
    fireEvent.click(screen.getByRole('button', { name: 'Sign-in page' }))

    expect(screen.getByTestId('signin-preview').textContent).not.toContain('Need help?')
    expect(container.textContent).not.toMatch(/clienthub/i)
  })

  it('falls back to the agency record name while the display name is empty', () => {
    show({ display_name: '' })
    fireEvent.click(screen.getByRole('button', { name: 'Sign-in page' }))

    expect(screen.getByTestId('signin-preview').textContent).toContain('Sign in to Northwind Legal Ltd')
  })

  it('keeps the preview itself hidden from assistive technology (it is a picture of a page, not a page)', () => {
    show()
    fireEvent.click(screen.getByRole('button', { name: 'Sign-in page' }))

    expect(screen.getByTestId('signin-preview')).toHaveAttribute('aria-hidden', 'true')
  })
})