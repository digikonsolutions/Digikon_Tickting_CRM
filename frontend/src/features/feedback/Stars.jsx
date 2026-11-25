import { useRef } from 'react'

const RATING_WORDS = { 1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very good', 5: 'Excellent' }

function Star({ filled, half = false }) {
  return (
    <svg viewBox="0 0 20 20" width="1em" height="1em" aria-hidden="true" focusable="false" className={`fb-star${filled ? ' is-on' : ''}${half ? ' is-half' : ''}`}>
      <defs><linearGradient id="fb-half"><stop offset="50%" stopColor="currentColor" /><stop offset="50%" stopColor="transparent" /></linearGradient></defs>
      <path d="M10 1.6l2.5 5.3 5.8.8-4.2 4.1 1 5.8L10 14.8l-5.1 2.8 1-5.8L1.7 7.7l5.8-.8z" fill={half ? 'url(#fb-half)' : filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" />
    </svg>
  )
}

// A read-only rating, e.g. 4.5. The number is the accessible text, the stars are decoration.
export function StarRating({ value, size = 'md', showNumber = true }) {
  const number = Number(value)
  if (!value && value !== 0) return <span className="fb-muted">No rating</span>
  return (
    <span className={`fb-rating fb-rating-${size}`} role="img" aria-label={`${number.toFixed(number % 1 ? 1 : 0)} out of 5`}>
      <span className="fb-stars" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((star) => <Star key={star} filled={number >= star - 0.25} half={number >= star - 0.75 && number < star - 0.25} />)}
      </span>
      {showNumber && <strong className="fb-rating-number" aria-hidden="true">{number.toFixed(number % 1 ? 1 : 0)}</strong>}
    </span>
  )
}

// Pick 1 to 5. A radio group: arrow keys move, Home/End jump, and a second click on the chosen star clears it when
// `clearable` (for optional categories).
export function StarInput({ label, value, onChange, clearable = false, error, required = false }) {
  const refs = useRef([])
  const move = (next) => {
    const clamped = Math.min(5, Math.max(1, next))
    onChange(clamped)
    refs.current[clamped - 1]?.focus()
  }
  const onKeyDown = (event) => {
    const current = value || 0
    if (event.key === 'ArrowRight' || event.key === 'ArrowUp') { event.preventDefault(); move(current + 1) }
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') { event.preventDefault(); move(current === 0 ? 1 : current - 1) }
    else if (event.key === 'Home') { event.preventDefault(); move(1) }
    else if (event.key === 'End') { event.preventDefault(); move(5) }
  }
  return (
    <div className="fb-input-row">
      <span className="fb-input-label" id={`fb-${label.replaceAll(' ', '-')}`}>{label}{required && <span className="ui-required" aria-hidden="true"> *</span>}</span>
      <div role="radiogroup" aria-labelledby={`fb-${label.replaceAll(' ', '-')}`} className="fb-star-input" onKeyDown={onKeyDown}>
        {[1, 2, 3, 4, 5].map((star) => (
          <button
            key={star} type="button" role="radio" aria-checked={value === star} aria-label={`${star} ${star === 1 ? 'star' : 'stars'}, ${RATING_WORDS[star]}`}
            tabIndex={value === star || (!value && star === 1) ? 0 : -1} ref={(node) => { refs.current[star - 1] = node }}
            className={`fb-star-button${value >= star ? ' is-on' : ''}`} onClick={() => onChange(clearable && value === star ? null : star)}
          >
            <Star filled={value >= star} />
          </button>
        ))}
        <span className="fb-star-word" aria-live="polite">{value ? RATING_WORDS[value] : clearable ? 'Not rated' : ''}</span>
      </div>
      {error && <p className="ui-field-error" role="alert">{error}</p>}
    </div>
  )
}
