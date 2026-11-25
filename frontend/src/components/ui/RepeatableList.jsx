import { Button } from './Button'
import { Icon } from './Icon'

// Rows the user can add, remove and reorder, all with the keyboard (every control is a button). `renderRow(item,
// update, index)` draws one row; `update(patch)` merges changes into that row. `blank` is the new row.
export function RepeatableList({ legend, items = [], onChange, renderRow, blank, max = 50, addLabel = 'Add', emptyText, error, reorderable = false }) {
  const update = (index) => (patch) => onChange(items.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  const move = (index, delta) => {
    const next = [...items]
    const [moved] = next.splice(index, 1)
    next.splice(index + delta, 0, moved)
    onChange(next)
  }

  return (
    <fieldset className="ui-repeat">
      {legend && <legend className="ui-field-label">{legend}</legend>}
      {items.length === 0 && emptyText && <p className="ui-field-help">{emptyText}</p>}
      <ol className="ui-repeat-rows">
        {items.map((item, index) => (
          <li className="ui-repeat-row" key={item._key ?? index}>
            <div className="ui-repeat-body">{renderRow(item, update(index), index)}</div>
            <div className="ui-repeat-tools">
              {reorderable && <Button type="button" variant="ghost" size="sm" disabled={index === 0} aria-label={`Move item ${index + 1} up`} onClick={() => move(index, -1)}>&uarr;</Button>}
              {reorderable && <Button type="button" variant="ghost" size="sm" disabled={index === items.length - 1} aria-label={`Move item ${index + 1} down`} onClick={() => move(index, 1)}>&darr;</Button>}
              <Button type="button" variant="ghost" size="sm" aria-label={`Remove item ${index + 1}`} onClick={() => onChange(items.filter((_, i) => i !== index))}><Icon name="trash" size={16} /></Button>
            </div>
          </li>
        ))}
      </ol>
      {error && <p className="ui-field-error" role="alert">{error}</p>}
      <Button type="button" variant="secondary" size="sm" disabled={items.length >= max} onClick={() => onChange([...items, { ...blank, _key: `n${Date.now()}${items.length}` }])}>
        <Icon name="plus" size={16} /> {addLabel}
      </Button>
    </fieldset>
  )
}
