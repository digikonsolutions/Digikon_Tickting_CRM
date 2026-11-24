import { useId, useState } from 'react'

// A list of short values typed one at a time: Enter or a comma adds a chip, Backspace on an empty box removes the last
// one, and every chip has its own remove button. `value` is an array of strings.
export function ChipInput({ id, label, value = [], onChange, max = 50, maxLength = 200, placeholder, helperText, error, normalize = (text) => text }) {
  const generated = useId()
  const fieldId = id ?? generated
  const describedBy = [helperText ? `${fieldId}-help` : null, error ? `${fieldId}-error` : null].filter(Boolean).join(' ') || undefined
  const [draft, setDraft] = useState('')

  function commit(raw) {
    const text = normalize(raw.trim()).slice(0, maxLength)
    if (!text || value.includes(text) || value.length >= max) return
    onChange([...value, text])
    setDraft('')
  }
  function onKeyDown(event) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault()
      commit(draft)
    } else if (event.key === 'Backspace' && draft === '' && value.length) {
      onChange(value.slice(0, -1))
    }
  }

  return (
    <div className="ui-field">
      {label && <label className="ui-field-label" htmlFor={fieldId}>{label}</label>}
      <div className="ui-chip-box">
        <ul className="ui-chip-list" aria-label={label ? `${label}, added` : 'Added values'}>
          {value.map((chip) => (
            <li key={chip} className="ui-chip">
              <span>{chip}</span>
              <button type="button" className="ui-chip-remove" aria-label={`Remove ${chip}`} onClick={() => onChange(value.filter((item) => item !== chip))}>&times;</button>
            </li>
          ))}
        </ul>
        <input
          id={fieldId}
          className="ui-chip-input"
          value={draft}
          maxLength={maxLength}
          placeholder={value.length >= max ? `Up to ${max}` : placeholder}
          disabled={value.length >= max}
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy}
          onChange={(event) => setDraft(event.target.value.replace(',', ''))}
          onKeyDown={onKeyDown}
          onBlur={() => commit(draft)}
        />
      </div>
      {helperText && <p className="ui-field-help" id={`${fieldId}-help`}>{helperText}</p>}
      {error && <p className="ui-field-error" id={`${fieldId}-error`}>{error}</p>}
    </div>
  )
}

// A group of checkboxes for picking several of a fixed set. `options` is [{ value, label }].
export function MultiSelect({ legend, options, value = [], onChange, helperText, error }) {
  const id = useId()
  const toggle = (option) => onChange(value.includes(option) ? value.filter((item) => item !== option) : [...value, option])
  return (
    <fieldset className="ui-checkgroup" aria-describedby={[helperText ? `${id}-help` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined}>
      <legend className="ui-field-label">{legend}</legend>
      <div className="ui-checkgroup-options">
        {options.map((option) => (
          <label key={option.value} className="ui-checkgroup-option">
            <input type="checkbox" checked={value.includes(option.value)} onChange={() => toggle(option.value)} />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
      {helperText && <p className="ui-field-help" id={`${id}-help`}>{helperText}</p>}
      {error && <p className="ui-field-error" id={`${id}-error`}>{error}</p>}
    </fieldset>
  )
}
