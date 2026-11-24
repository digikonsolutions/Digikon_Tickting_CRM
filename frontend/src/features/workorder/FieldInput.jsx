import { ChipInput, FieldRow, Input, MultiSelect, RepeatableList, Select, Textarea, UrlInput } from '../../components/ui'

const toNumber = (text) => (text === '' ? '' : Number(text))

// One field of a schema (see briefSchema.js), drawn with the shared inputs. `errors` is the server's 422 `errors`
// object and `errorPrefix` the path in front of `field.path` ("ghl_brief.client"), so a message lands on its own input.
export function FieldInput({ field, value, onChange, errors, errorPrefix, idPrefix }) {
  const error = errors?.[`${errorPrefix}.${field.path}`]?.[0]
  const id = `${idPrefix}-${field.path.replaceAll('.', '-')}`
  const common = { id, label: field.label, helperText: field.help, error }

  switch (field.type) {
    case 'textarea':
      return <Textarea {...common} rows={3} maxLength={field.max} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    case 'url':
      return <UrlInput {...common} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    case 'email':
      return <Input {...common} type="email" maxLength={254} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    case 'tel':
      return <Input {...common} type="tel" maxLength={40} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    case 'date':
      return <Input {...common} type="date" value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    case 'number':
      return <Input {...common} type="number" min="0" value={value ?? ''} onChange={(event) => onChange(toNumber(event.target.value))} />
    case 'select':
      return (
        <Select {...common} value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
          <option value="">Not specified</option>
          {field.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
      )
    case 'bool':
      return (
        <Select {...common} value={value === true ? 'true' : value === false ? 'false' : ''} onChange={(event) => onChange(event.target.value === '' ? '' : event.target.value === 'true')}>
          <option value="">Not specified</option>
          <option value="true">Yes</option>
          <option value="false">No</option>
        </Select>
      )
    case 'chips':
      return <ChipInput {...common} value={value ?? []} max={field.max ?? 50} onChange={onChange} placeholder="Type and press Enter" />
    case 'urls':
      return <ChipInput {...common} value={value ?? []} max={field.max ?? 10} maxLength={500} onChange={onChange} placeholder="https://… then Enter" helperText={field.help ?? 'Full links, one at a time.'} />
    case 'multi':
      return <MultiSelect legend={field.label} options={field.options} value={value ?? []} onChange={onChange} helperText={field.help} error={error} />
    case 'rows':
      return (
        <RepeatableList
          legend={field.label}
          items={value ?? []}
          onChange={onChange}
          max={field.max}
          blank={Object.fromEntries(field.sub.map((sub) => [sub.path, sub.type === 'chips' ? [] : '']))}
          addLabel={field.addLabel}
          error={errors?.[`${errorPrefix}.${field.path}`]?.[0]}
          renderRow={(row, update, index) => (
            <FieldRow columns={Math.min(field.sub.length, 3)}>
              {field.sub.map((sub) => (
                <FieldInput key={sub.path} field={sub} value={row[sub.path]} onChange={(next) => update({ [sub.path]: next })} errors={errors} errorPrefix={`${errorPrefix}.${field.path}.${index}`} idPrefix={`${id}-${index}`} />
              ))}
            </FieldRow>
          )}
        />
      )
    default:
      return <Input {...common} maxLength={field.max} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
  }
}
