import { describe, expect, it } from 'vitest'
import { brandingVariables } from './brandingVariables'

describe('brandingVariables', () => {
  it('emits nothing without branding or without a colour, so the stylesheet defaults apply', () => {
    expect(brandingVariables(null)).toEqual({})
    expect(brandingVariables({ primary_color: null })).toEqual({})
  })

  it('derives the brand tokens from a readable primary colour', () => {
    const vars = brandingVariables({ primary_color: '#C2410C' })

    expect(vars['--brand']).toBe('#C2410C')
    expect(vars['--brand-hover']).toBeTruthy()
    expect(vars['--brand-soft']).toBeTruthy()
    expect(vars['--brand-shadow-sm']).toContain('rgb')
    expect(vars['--brand-border-soft']).toBeTruthy()
    expect(vars['--color-on-brand']).toBeTruthy()
  })

  it('falls back to the default look for a colour that cannot be read as text on white', () => {
    expect(brandingVariables({ primary_color: '#FFFF99' })).toEqual({})
    expect(brandingVariables({ primary_color: '#F5F5F5' })).toEqual({})
  })

  it('ignores values that are not hex colours', () => {
    expect(brandingVariables({ primary_color: 'teal' })).toEqual({})
    expect(brandingVariables({ primary_color: '#12345' })).toEqual({})
  })

  it('applies the guard against the dark surface in the dark theme', () => {
    // Too dark to read on the dark surface: no brand colours are applied (only the readable default text token remains).
    const unreadable = brandingVariables({ primary_color: '#0A0A0A' }, { dark: true })
    expect(unreadable['--brand']).toBeUndefined()
    expect(unreadable['--brand-text']).toBeTruthy()
    expect(brandingVariables({ primary_color: '#0F766E' }, { dark: false })['--brand']).toBeTruthy()
  })

  it('always gives the settings preview a full set, using the default teal when nothing is chosen', () => {
    const vars = brandingVariables({ primary_color: null, corner_style: 'sharp' }, { complete: true })

    expect(vars['--brand']).toBeTruthy()
    expect(vars['--radius-card']).toBe('.25rem')
  })

  it('maps the corner style to radii and leaves the default style to the stylesheet', () => {
    expect(brandingVariables({ corner_style: 'soft' })['--radius-card']).toBe('.75rem')
    expect(brandingVariables({ corner_style: 'rounded' })['--radius-card']).toBeUndefined()
  })
})