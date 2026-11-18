import { contrastRatio, isHex, MIN_TEXT_CONTRAST } from '../../../../branding/colorUtils'

// Curated presets, every one at least 4.5:1 against white (white button text stays readable).
export const COLOR_PRESETS = [
  { name: 'Teal', value: '#0F766E' },
  { name: 'Indigo', value: '#4338CA' },
  { name: 'Violet', value: '#6D28D9' },
  { name: 'Rose', value: '#BE123C' },
  { name: 'Amber', value: '#B45309' },
  { name: 'Emerald', value: '#047857' },
  { name: 'Slate', value: '#334155' },
  { name: 'Sky', value: '#0369A1' },
]

/** Pass/fail of a colour as a white-text background, for the badge and for blocking save. */
export function contrastOnWhite(hex) {
  if (!isHex(hex)) return null
  const ratio = contrastRatio(hex, '#FFFFFF')
  return { ratio, passes: ratio >= MIN_TEXT_CONTRAST }
}
