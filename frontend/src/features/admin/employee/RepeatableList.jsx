import { Button, FieldRow, Input, Textarea } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { MAX_ROWS, firstError } from './employeeForm'

// A list of small repeatable forms (education, experience, certifications).
// `fields` describes one row: { key, label, type, required, maxLength, wide }.
export function RepeatableList({ name, title, items, onChange, fields, errors, blank, addLabel, emptyText }) {
  function update(index, key, value) {
    onChange(items.map((item, i) => (i === index ? { ...item, [key]: value } : item)))
  }

  return (
    <div className="emp-repeat">
      {items.length === 0 && <p className="emp-empty">{emptyText}</p>}
      {items.map((item, index) => (
        <fieldset className="emp-repeat-row" key={index}>
          <legend>{title} {index + 1}</legend>
          <FieldRow>
            {fields.filter((field) => !field.wide).map((field) => (
              <Input
                key={field.key}
                label={field.label}
                type={field.type ?? 'text'}
                maxLength={field.maxLength}
                required={field.required}
                value={item[field.key] ?? ''}
                onChange={(event) => update(index, field.key, event.target.value)}
                error={firstError(errors, `${name}.${index}.${field.key}`)}
              />
            ))}
          </FieldRow>
          {fields.filter((field) => field.wide).map((field) => (
            <Textarea
              key={field.key}
              label={field.label}
              rows="2"
              maxLength={field.maxLength}
              value={item[field.key] ?? ''}
              onChange={(event) => update(index, field.key, event.target.value)}
              error={firstError(errors, `${name}.${index}.${field.key}`)}
            />
          ))}
          <Button type="button" variant="ghost" size="sm" className="emp-remove-row" onClick={() => onChange(items.filter((_, i) => i !== index))}>
            <Icon name="trash" size={16} /> Remove {title.toLowerCase()} {index + 1}
          </Button>
        </fieldset>
      ))}
      {firstError(errors, name) && <p className="ui-field-error" role="alert">{firstError(errors, name)}</p>}
      <Button type="button" variant="secondary" size="sm" disabled={items.length >= MAX_ROWS} onClick={() => onChange([...items, { ...blank }])}>
        <Icon name="plus" size={16} /> {addLabel}
      </Button>
    </div>
  )
}
