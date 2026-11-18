import { darken, ensureContrast, generateScale, lighten, isHex, mix, readableTextOn, withAlpha } from './colorUtils'

export const CORNER_RADII = {
  soft: { card: '.75rem', control: '.5rem', sm: '.375rem' },
  rounded: { card: '1rem', control: '.75rem', sm: '.5rem' },
  sharp: { card: '.25rem', control: '.25rem', sm: '.125rem' },
}

/**
 * CSS custom properties for a branding payload. Pure, so the settings-page preview can scope the exact
 * same variables to a container without touching the real document. Properties that are not branded
 * are simply absent, so the stylesheet defaults apply.
 */
export const DEFAULT_PRIMARY = '#0D9488'

export function brandingVariables(branding, { dark = false, complete = false } = {}) {
  const vars = {}
  if (!branding) return vars

  // `complete` (the settings preview) always emits a full set so it never inherits the page's own
  // branding: the default teal when no primary colour is chosen, and explicit radii.
  const chosen = isHex(branding.primary_color) ? branding.primary_color : (complete ? DEFAULT_PRIMARY : null)

  if (chosen) {
    const primary = chosen.toUpperCase()
    const scale = generateScale(primary, { dark })
    Object.assign(vars, {
      '--brand': scale[600],
      '--brand-hover': scale[700],
      '--brand-soft': scale[50],
      '--brand-ring': withAlpha(primary, 0.22),
      '--brand-shadow': `0 4px 12px ${withAlpha(primary, 0.2)}`,
      '--brand-shadow-hover': `0 6px 16px ${withAlpha(primary, 0.26)}`,
      '--focus-ring': scale[400],
      '--color-brand-tint': mix(primary, dark ? '#111827' : '#FFFFFF', dark ? 0.1 : 0.04),
      '--color-primary-100': scale[100],
      '--color-primary-200': scale[200],
      '--color-primary-900': scale[900],
      '--color-navy': scale[900],
      '--color-navy-light': scale[700],
      '--color-accent-50': scale[50],
      '--color-accent-300': mix(primary, '#FFFFFF', 0.5),
      '--color-accent-400': scale[400],
      '--color-accent-600': scale[600],
      '--color-accent-700': scale[700],
      '--color-accent-ring': withAlpha(primary, 0.18),
      '--shadow-accent': `0 .6rem 1.25rem ${withAlpha(primary, 0.2)}`,
      '--color-on-brand': readableTextOn(primary),
      // Welcome banner gradient: always dark enough for white text, in light and dark themes.
      '--banner-start': mix(primary, '#000000', 0.6),
      '--banner-end': darken(primary, 0.08),
      '--banner-glow': withAlpha(lighten(primary, 0.14), 0.3),
      // Brand-tinted sidebar: a dark shade of the primary, with readable text on top.
      '--sidebar-brand-bg': mix(primary, '#000000', 0.55),
      '--sidebar-brand-fg': readableTextOn(mix(primary, '#000000', 0.55)),
    })
  }

  // The accent colour is an optional second colour for text-weight highlights (links, active nav label).
  if (isHex(branding.accent_color)) {
    vars['--color-brand-accent'] = branding.accent_color.toUpperCase()
  }

  // Brand-coloured text must stay readable on dark surfaces: a lightened primary, AA on the card colour
  // and on the (lighter) tinted badge background. Light themes use the primary itself (AA on white).
  if (dark) {
    const base = (chosen ?? DEFAULT_PRIMARY).toUpperCase()
    vars['--brand-text'] = ensureContrast(base, mix(base, '#111827', 0.26), 5)
  }

  const radii = CORNER_RADII[branding.corner_style]
  if (radii && (complete || branding.corner_style !== 'rounded')) {
    vars['--radius-card'] = radii.card
    vars['--radius-control'] = radii.control
    vars['--radius-sm'] = radii.sm
  }

  return vars
}

export function resolveTheme(branding, systemPrefersDark) {
  if (!branding) return 'light'
  if (branding.theme_mode === 'dark') return 'dark'
  if (branding.theme_mode === 'system') return systemPrefersDark ? 'dark' : 'light'
  return 'light'
}
