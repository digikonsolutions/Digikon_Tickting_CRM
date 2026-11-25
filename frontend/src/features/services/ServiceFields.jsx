import { useId } from 'react'
import { Input, Select, Textarea } from '../../components/ui'
import { fieldsFor, typeOf } from './serviceCatalog'

// One answer, drawn by its type: a choice, several choices, a link, or a short line of text.
export function Answer({ field, value, error, onChange, idPrefix }) {
  const id = `${idPrefix}-${field.key}`
  if (field.type === 'select') {
    return (
      <Select id={id} label={field.label} required={field.required} value={value ?? ''} onChange={(event) => onChange(event.target.value)} error={error}>
        <option value="">Choose…</option>
        {Object.entries(field.options ?? {}).map(([optionValue, label]) => <option key={optionValue} value={optionValue}>{label}</option>)}
      </Select>
    )
  }
  if (field.type === 'checkbox') {
    const chosen = Array.isArray(value) ? value : []
    return (
      <fieldset className="ui-field service-checks" aria-describedby={error ? `${id}-error` : undefined}>
        <legend className="ui-field-label">{field.label}{field.required && <span className="ui-required" aria-hidden="true"> *</span>}</legend>
        <div className="service-check-list">
          {Object.entries(field.options ?? {}).map(([optionValue, label]) => (
            <label key={optionValue} className="service-check">
              <input
                type="checkbox"
                checked={chosen.includes(optionValue)}
                onChange={(event) => onChange(event.target.checked ? [...chosen, optionValue] : chosen.filter((item) => item !== optionValue))}
              />
              <span>{label}</span>
            </label>
          ))}
        </div>
        {error && <p className="ui-field-error" id={`${id}-error`}>{error}</p>}
      </fieldset>
    )
  }
  return (
    <Input
      id={id}
      label={field.label}
      required={field.required}
      type={field.type === 'url' ? 'url' : 'text'}
      inputMode={field.type === 'url' ? 'url' : undefined}
      placeholder={field.type === 'url' ? 'https://' : undefined}
      maxLength={field.type === 'url' ? 500 : (field.max ?? 200)}
      value={value ?? ''}
      onChange={(event) => onChange(event.target.value)}
      error={error}
    />
  )
}

/**
 * Pick a GHL service, then answer its few questions. Used by the project form (all fields) and the ticket form
 * (the lighter set). The parent keeps `serviceType` and `details`; `errors(key)` returns a server message for
 * `details.<key>` (or `service_type`).
 */
export function ServiceFields({ catalog, scope, serviceType, details, onChange, errors = () => undefined, disabled = false }) {
  const idPrefix = useId().replaceAll(':', '')
  const type = typeOf(catalog, serviceType)
  const fields = fieldsFor(type, scope)
  const setType = (next) => onChange({ serviceType: next, details: {} })
  const setAnswer = (key) => (value) => onChange({ serviceType, details: { ...details, [key]: value } })

  return (
    <div className="service-fields">
      <Select
        id={`${idPrefix}-type`}
        label="Service"
        value={serviceType ?? ''}
        disabled={disabled || !catalog}
        onChange={(event) => setType(event.target.value || null)}
        error={errors('service_type')}
        helperText={catalog ? 'Pick what you need and answer a few questions. We write the title and description for you.' : 'Loading services…'}
      >
        <option value="">{scope === 'project' ? 'Choose a service' : 'No specific service'}</option>
        {(catalog?.types ?? []).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
      </Select>
      {type && fields.map((field) => (
        <Answer key={`${type.key}-${field.key}`} field={field} value={details?.[field.key]} error={errors(field.key)} idPrefix={idPrefix} onChange={setAnswer(field.key)} />
      ))}
      {type && (
        <Textarea
          id={`${idPrefix}-notes`}
          label="Anything else we should know? (optional)"
          rows={3}
          maxLength={2000}
          value={details?.notes ?? ''}
          onChange={(event) => setAnswer('notes')(event.target.value)}
          error={errors('notes')}
        />
      )}
    </div>
  )
}
