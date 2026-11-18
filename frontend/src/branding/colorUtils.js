// Small colour toolkit for agency branding: hex <-> HSL, derived shade scales and WCAG contrast.
// Everything is computed from the agency's primary colour; nothing is hardcoded per brand.

const HEX = /^#[0-9a-f]{6}$/i

export const MIN_TEXT_CONTRAST = 4.5

export function isHex(value) {
  return typeof value === 'string' && HEX.test(value)
}

export function hexToRgb(hex) {
  const n = parseInt(hex.slice(1), 16)
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 }
}

export function rgbToHex({ r, g, b }) {
  const part = (v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')
  return `#${part(r)}${part(g)}${part(b)}`.toUpperCase()
}

export function hexToHsl(hex) {
  const { r, g, b } = hexToRgb(hex)
  const rn = r / 255
  const gn = g / 255
  const bn = b / 255
  const max = Math.max(rn, gn, bn)
  const min = Math.min(rn, gn, bn)
  const l = (max + min) / 2
  const d = max - min
  if (d === 0) return { h: 0, s: 0, l }

  const s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
  let h
  if (max === rn) h = (gn - bn) / d + (gn < bn ? 6 : 0)
  else if (max === gn) h = (bn - rn) / d + 2
  else h = (rn - gn) / d + 4
  return { h: h * 60, s, l }
}

export function hslToHex({ h, s, l }) {
  const hue = ((h % 360) + 360) % 360
  const c = (1 - Math.abs(2 * l - 1)) * s
  const x = c * (1 - Math.abs(((hue / 60) % 2) - 1))
  const m = l - c / 2
  let rgb
  if (hue < 60) rgb = [c, x, 0]
  else if (hue < 120) rgb = [x, c, 0]
  else if (hue < 180) rgb = [0, c, x]
  else if (hue < 240) rgb = [0, x, c]
  else if (hue < 300) rgb = [x, 0, c]
  else rgb = [c, 0, x]
  return rgbToHex({ r: (rgb[0] + m) * 255, g: (rgb[1] + m) * 255, b: (rgb[2] + m) * 255 })
}

const clamp01 = (v) => Math.min(1, Math.max(0, v))

/** Lower lightness by `amount` (0.08 = 8 percentage points). */
export function darken(hex, amount) {
  const hsl = hexToHsl(hex)
  return hslToHex({ ...hsl, l: clamp01(hsl.l - amount) })
}

export function lighten(hex, amount) {
  const hsl = hexToHsl(hex)
  return hslToHex({ ...hsl, l: clamp01(hsl.l + amount) })
}

/** Mix `hex` with `base` (`weight` = share of `hex`, 0..1). */
export function mix(hex, base, weight) {
  const a = hexToRgb(hex)
  const b = hexToRgb(base)
  return rgbToHex({
    r: a.r * weight + b.r * (1 - weight),
    g: a.g * weight + b.g * (1 - weight),
    b: a.b * weight + b.b * (1 - weight),
  })
}

export function withAlpha(hex, alpha) {
  const { r, g, b } = hexToRgb(hex)
  return `rgb(${r} ${g} ${b} / ${Math.round(alpha * 100)}%)`
}

function luminance(hex) {
  const { r, g, b } = hexToRgb(hex)
  const [rl, gl, bl] = [r, g, b].map((v) => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * rl + 0.7152 * gl + 0.0722 * bl
}

export function contrastRatio(a, b) {
  const l1 = luminance(a)
  const l2 = luminance(b)
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)
}

export function meetsAAOnWhite(hex) {
  return contrastRatio(hex, '#FFFFFF') >= MIN_TEXT_CONTRAST
}

/** White or near-black, whichever reads better on `hex`. */
export function readableTextOn(hex) {
  return contrastRatio(hex, '#FFFFFF') >= contrastRatio(hex, '#0F172A') ? '#FFFFFF' : '#0F172A'
}

/** Lighten `hex` until it reaches `ratio`:1 against `background` (used for brand-coloured text on dark surfaces). */
export function ensureContrast(hex, background, ratio = MIN_TEXT_CONTRAST + 0.3) {
  let color = hex
  for (let step = 0; step < 25 && contrastRatio(color, background) < ratio; step += 1) {
    color = lighten(color, 0.04)
  }
  return color
}

/**
 * The shade scale derived from the primary colour. `primary` is the 600 step (buttons, links);
 * hover is ~8% darker; 400 is lighter for accents on dark surfaces; 50/100/200 are tints.
 * In dark mode the tints are mixed into the dark card colour instead of white.
 */
export function generateScale(primary, { dark = false } = {}) {
  const base = dark ? '#111827' : '#FFFFFF'
  return {
    50: dark ? mix(primary, base, 0.16) : mix(primary, base, 0.07),
    100: dark ? mix(primary, base, 0.26) : mix(primary, base, 0.16),
    200: dark ? mix(primary, base, 0.38) : mix(primary, base, 0.3),
    400: lighten(primary, 0.14),
    600: primary,
    // Hover shade: darker on light surfaces, lighter on dark ones so it stays visible.
    700: dark ? lighten(primary, 0.08) : darken(primary, 0.08),
    900: mix(primary, '#000000', 0.5),
  }
}
