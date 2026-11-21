import { pluralize } from '../../lib/format'
import { Button } from './Button'

// Single-choice filter chips (for example a status filter). `options` is `[{value, label}]`.
export function FilterChips({ options, value, onChange, label = 'Filter', disabled = false }) {
  return (
    <ul className="ui-chips" aria-label={label}>
      {options.map((option) => (
        <li key={option.value}>
          <button type="button" className="ui-chip" aria-pressed={value === option.value} disabled={disabled} onClick={() => onChange(option.value)}>
            {option.label}
          </button>
        </li>
      ))}
    </ul>
  )
}

// "Page 2 of 5 · 48 projects" with Previous / Next, aligned the same on every list.
export function Pagination({ page, lastPage, total, noun = 'item', plural, loading = false, onPrevious, onNext }) {
  // While the first page is loading the total is still the placeholder 0, so say nothing rather than "0 items".
  const showTotal = total !== undefined && !(loading && total === 0)
  return (
    <div className="ui-pagination">
      <span>Page {page} of {lastPage}{showTotal ? ` · ${total} ${pluralize(total, noun, plural)}` : ''}</span>
      <div>
        <Button type="button" variant="secondary" size="sm" disabled={loading || page <= 1} onClick={onPrevious}>Previous</Button>
        <Button type="button" variant="secondary" size="sm" disabled={loading || page >= lastPage} onClick={onNext}>Next</Button>
      </div>
    </div>
  )
}