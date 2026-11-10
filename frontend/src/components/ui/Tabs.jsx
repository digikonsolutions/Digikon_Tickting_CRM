export function Tabs({ tabs = [], activeTab, onChange, className = '' }) {
  return (
    <nav className={`ui-tabs ${className}`.trim()} role="tablist">
      {tabs.map((tab) => {
        const isActive = activeTab === tab.id
        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            className={`ui-tab${isActive ? ' is-active' : ''}`}
            onClick={() => onChange(tab.id)}
          >
            <span>{tab.label}</span>
            {tab.count !== undefined && <span className="ui-tab-count">{tab.count}</span>}
          </button>
        )
      })}
    </nav>
  )
}
