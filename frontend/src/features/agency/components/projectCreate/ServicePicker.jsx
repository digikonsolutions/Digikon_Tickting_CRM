import { useMemo, useState } from 'react'
import { Badge, Icon, Input } from '../../../../components/ui'

// The services an agency can ask for, grouped, with a search box and ready-made packages. A package only ticks services;
// every one can still be changed. `selected` is a list of service keys; the parent owns it.
export function ServicePicker({ catalog, selected, onToggle, onPackage }) {
  const [query, setQuery] = useState('')
  const q = query.trim().toLowerCase()
  const groups = useMemo(() => (catalog?.groups ?? []).map((group) => ({
    ...group,
    types: (catalog.types ?? []).filter((type) => type.group === group.key && (!q || `${type.label} ${type.description}`.toLowerCase().includes(q))),
  })).filter((group) => group.types.length > 0), [catalog, q])
  const typeByKey = Object.fromEntries((catalog?.types ?? []).map((type) => [type.key, type]))

  if (!catalog) return <p className="ui-field-help" role="status">Loading the list of services…</p>
  return (
    <div className="pc-picker">
      <section aria-label="Packages" className="pc-packages">
        <h3>Start from a package</h3>
        <p className="ui-field-help">Ticks the services that usually go together. You can add or remove any of them.</p>
        <ul>
          {(catalog.packages ?? []).map((pkg) => {
            const all = pkg.services.every((key) => selected.includes(key))
            return (
              <li key={pkg.key}>
                <button type="button" className={`pc-package${all ? ' is-on' : ''}`} aria-pressed={all} onClick={() => onPackage(pkg)}>
                  <strong>{pkg.label}</strong>
                  <span>{pkg.description}</span>
                  <small>{pkg.services.map((key) => typeByKey[key]?.label ?? key).join(' · ')}</small>
                </button>
              </li>
            )
          })}
        </ul>
      </section>

      <Input label="Search the services" type="search" placeholder="For example chatbot, calendar, reviews" value={query} onChange={(event) => setQuery(event.target.value)} />

      {groups.map((group) => (
        <fieldset key={group.key} className="pc-group">
          <legend>{group.label}</legend>
          <ul className="pc-cards">
            {group.types.map((type) => {
              const on = selected.includes(type.key)
              return (
                <li key={type.key}>
                  <label className={`pc-card${on ? ' is-on' : ''}`}>
                    <input type="checkbox" checked={on} onChange={() => onToggle(type.key)} />
                    <span className="pc-card-body">
                      <strong>{type.label}</strong>
                      <span>{type.description}</span>
                      {type.hours && <small>Often {type.hours[0]}–{type.hours[1]} hours</small>}
                    </span>
                    {on && <Badge variant="primary"><Icon name="check" size={12} /> Added</Badge>}
                  </label>
                </li>
              )
            })}
          </ul>
        </fieldset>
      ))}
      {groups.length === 0 && <p className="ui-field-help">No service matches &ldquo;{query}&rdquo;. Choose &ldquo;Something else&rdquo; and describe it.</p>}
    </div>
  )
}
