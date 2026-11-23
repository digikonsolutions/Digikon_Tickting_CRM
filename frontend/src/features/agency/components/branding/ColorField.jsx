import { useId, useState } from 'react'
import { generateScale, isHex, readableTextOn } from '../../../../branding/colorUtils'
import { COLOR_PRESETS, contrastOnWhite } from './colorPresets'

const SHADE_STEPS = ['50', '100', '200', '400', '600', '700', '900']

/**
 * A colour input: native picker + hex text kept in sync, preset swatches, a live contrast badge and the
 * derived shade strip. An empty value means "use the default". Remount with a new `key` to reset the text.
 */
export function ColorField({ label, value, onChange, error, helperText, fallbackPicker = '#0F766E', showShades = false, optional = true }) {
  const id = useId()
  const [text, setText] = useState(value ?? '')
  const [touched, setTouched] = useState(false)
  const typedInvalid = text !== '' && !isHex(text)
  const contrast = contrastOnWhite(value)

  function commitText(next) {
    setText(next)
    if (next === '') onChange('')
    else if (isHex(next)) onChange(next.toUpperCase())
  }

  function choose(next) {
    setText(next)
    onChange(next)
  }

  const message = typedInvalid && touched
    ? 'Use a hex value like #0F766E.'
    : error

  const statusId = `${id}-status`
  const scale = isHex(value) ? generateScale(value) : null

  return (
    <div className="ab-color-field">
      <label className="ui-field-label" htmlFor={`${id}-hex`}>{label}</label>
      <div className="ab-color-inputs">
        <input
          className="ab-color-native"
          type="color"
          aria-label={`${label} picker`}
          value={isHex(value) ? value.toLowerCase() : fallbackPicker.toLowerCase()}
          onChange={(event) => choose(event.target.value.toUpperCase())}
        />
        <input
          id={`${id}-hex`}
          className="ui-input ab-color-hex"
          type="text"
          inputMode="text"
          spellCheck={false}
          autoComplete="off"
          maxLength={7}
          placeholder="#0F766E"
          value={text}
          aria-invalid={Boolean(message)}
          aria-describedby={`${statusId}${helperText ? ` ${id}-help` : ''}`}
          onChange={(event) => commitText(event.target.value.trim())}
          onBlur={() => setTouched(true)}
        />
        {optional && text !== '' && (
          <button type="button" className="inline-link ab-color-clear" onClick={() => choose('')}>Use default</button>
        )}
      </div>

      <div className="ab-swatches" role="group" aria-label={`${label} presets`}>
        {COLOR_PRESETS.map((preset) => {
          const selected = value?.toUpperCase() === preset.value
          return (
            <button
              key={preset.value}
              type="button"
              className={`ab-swatch${selected ? ' is-selected' : ''}`}
              style={{ '--swatch': preset.value, '--swatch-fg': readableTextOn(preset.value) }}
              aria-label={`${preset.name} ${preset.value}`}
              aria-pressed={selected}
              title={preset.name}
              onClick={() => choose(preset.value)}
            >
              {selected && <span aria-hidden="true">✓</span>}
            </button>
          )
        })}
      </div>

      <p id={statusId} className={`ab-contrast${contrast ? (contrast.passes ? ' is-pass' : ' is-fail') : ''}`} role="status">
        {contrast
          ? contrast.passes
            ? `AA pass ${contrast.ratio.toFixed(1)}:1 with white text`
            : `Too light for white text (${contrast.ratio.toFixed(1)}:1, needs 4.5:1). Pick a darker shade.`
          : 'Using the default colour.'}
      </p>
      {helperText && <p className="ui-field-help" id={`${id}-help`}>{helperText}</p>}
      {message && <p className="ui-field-error">{message}</p>}

      {showShades && scale && (
        <div className="ab-shades" role="img" aria-label="Generated shades of this colour">
          {SHADE_STEPS.map((step) => (
            <span key={step} className="ab-shade" style={{ '--shade': scale[step], '--shade-fg': readableTextOn(scale[step]) }}>{step}</span>
          ))}
        </div>
      )}
    </div>
  )
}
