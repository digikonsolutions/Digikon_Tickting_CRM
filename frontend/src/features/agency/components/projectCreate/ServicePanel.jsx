import { useId } from 'react'
import { Button, Card, Input, Textarea } from '../../../../components/ui'
import { Answer } from '../../../services/ServiceFields'
import { fieldsFor } from '../../../services/serviceCatalog'

// The questions of one chosen service, with its own notes and an optional guess at the hours. `errors` is the server's
// 422 `errors` object; a message for `services.<index>.details.<key>` lands on its own input.
export function ServicePanel({ type, index, value, onChange, onRemove, errors }) {
  const uid = useId().replaceAll(':', '')
  const fields = fieldsFor(type, 'project')
  const base = `services.${index}`
  const setAnswer = (key) => (next) => onChange({ ...value, details: { ...value.details, [key]: next } })
  const required = fields.filter((field) => field.required)
  const answered = required.filter((field) => { const v = value.details?.[field.key]; return Array.isArray(v) ? v.length > 0 : Boolean(String(v ?? '').trim()) }).length

  return (
    <Card title={type.label} description={type.description} header={(
      <div className="pc-panel-head">
        <div><h3>{type.label}</h3><p>{required.length > 0 ? `${answered} of ${required.length} required answers` : 'Nothing is required here'}</p></div>
        <Button type="button" variant="ghost" size="sm" onClick={onRemove} aria-label={`Remove ${type.label}`}>Remove</Button>
      </div>
    )}>
      <div className="pc-panel-body">
        {fields.map((field) => (
          <Answer key={field.key} field={field} idPrefix={`${uid}-${index}`} value={value.details?.[field.key]} error={errors?.[`${base}.details.${field.key}`]?.[0]} onChange={setAnswer(field.key)} />
        ))}
        <Textarea id={`${uid}-notes`} label="Anything else about this? (optional)" rows={3} maxLength={2000} value={value.details?.notes ?? ''} onChange={(event) => setAnswer('notes')(event.target.value)} error={errors?.[`${base}.details.notes`]?.[0]} />
        <Input id={`${uid}-hours`} label="Your estimate in hours (optional)" type="number" min="0.25" step="0.25" inputMode="decimal" value={value.estimated_hours ?? ''} onChange={(event) => onChange({ ...value, estimated_hours: event.target.value })}
          helperText={type.hours ? `Most projects like this take ${type.hours[0]} to ${type.hours[1]} hours. Our team confirms the final number.` : 'Our team confirms the final number.'} error={errors?.[`${base}.estimated_hours`]?.[0]} />
        {errors?.[`${base}.key`]?.[0] && <p className="ui-field-error" role="alert">{errors[`${base}.key`][0]}</p>}
      </div>
    </Card>
  )
}
