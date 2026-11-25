import { RepeatableList } from './RepeatableList'
import { Input } from './Fields'

export const MAX_CHECKLIST = 50

// The ordered subtasks of a ticket, as the agency writes them. Items keep their `id` so a saved item keeps its
// progress when it is renamed or moved. Ticking is separate (see the work order's checklist ticker).
export function ChecklistEditor({ items, onChange, errors = {}, name = 'work_order.checklist' }) {
  return (
    <RepeatableList
      legend="Checklist"
      items={items}
      onChange={onChange}
      reorderable
      max={MAX_CHECKLIST}
      blank={{ text: '' }}
      addLabel="Add step"
      emptyText="Break the work into small steps the employee can tick off."
      error={errors[name]?.[0]}
      renderRow={(item, update, index) => (
        <Input label={`Step ${index + 1}`} maxLength={300} value={item.text} onChange={(event) => update({ text: event.target.value })} error={errors[`${name}.${index}.text`]?.[0]} />
      )}
    />
  )
}
