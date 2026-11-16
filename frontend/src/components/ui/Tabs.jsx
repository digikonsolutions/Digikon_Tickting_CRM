import { useRef } from 'react'

// Tab buttons. Left and right arrows, Home and End move between tabs, as screen-reader users expect.
export function Tabs({ tabs = [], activeTab, onChange, className = '', label = 'Sections' }) {
  const refs = useRef([])

  function onKeyDown(event, index) {
    const last = tabs.length - 1
    let next = null
    if (event.key === 'ArrowRight') next = index === last ? 0 : index + 1
    else if (event.key === 'ArrowLeft') next = index === 0 ? last : index - 1
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = last
    if (next === null) return
    event.preventDefault()
    refs.current[next]?.focus()
    onChange(tabs[next].id)
  }

  return (
    <div className={`ui-tabs ${className}`.trim()} role="tablist" aria-label={label}>
      {tabs.map((tab, index) => {
        const isActive = activeTab === tab.id
        return (
          <button
            key={tab.id}
            ref={(node) => { refs.current[index] = node }}
            type="button"
            role="tab"
            aria-selected={isActive}
            tabIndex={isActive ? 0 : -1}
            className={`ui-tab${isActive ? ' is-active' : ''}`}
            onClick={() => onChange(tab.id)}
            onKeyDown={(event) => onKeyDown(event, index)}
          >
            <span>{tab.label}</span>
            {tab.count !== undefined && <span className="ui-tab-count">{tab.count}</span>}
          </button>
        )
      })}
    </div>
  )
}