import { useRef } from 'react'

// Tabs with a live count each ("Unassigned 4"). Arrow keys, Home and End move between tabs; the active one is announced.
export function SavedViewTabs({ views, active, onChange, counts = {}, label = 'Views' }) {
  const refs = useRef([])
  function onKeyDown(event, index) {
    const last = views.length - 1
    const next = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key]
    if (next === undefined) return
    event.preventDefault()
    refs.current[next]?.focus()
    onChange(views[next].id)
  }
  return (
    <div className="sup-view-tabs" role="tablist" aria-label={label}>
      {views.map((view, index) => (
        <button
          key={view.id}
          ref={(node) => { refs.current[index] = node }}
          type="button"
          role="tab"
          aria-selected={active === view.id}
          tabIndex={active === view.id ? 0 : -1}
          className={`sup-view-tab${active === view.id ? ' is-active' : ''}`}
          onClick={() => onChange(view.id)}
          onKeyDown={(event) => onKeyDown(event, index)}
        >
          {view.label}
          {counts[view.id] !== undefined && <span className="sup-view-count">{counts[view.id]}</span>}
        </button>
      ))}
    </div>
  )
}
