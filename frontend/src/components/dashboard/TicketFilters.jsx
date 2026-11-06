import { Icon } from '../ui'

export function TicketFilters({ query, onQueryChange, status, onStatusChange, statuses }) {
  return (
    <div className="ticket-filters">
      <label className="filter-search">
        <Icon name="search" size={18} />
        <span className="sr-only">Search tickets</span>
        <input type="search" value={query} placeholder="Search by ID, title or agency" onChange={(e) => onQueryChange(e.target.value)} />
      </label>
      <div className="filter-chips" role="group" aria-label="Filter by status">
        {['All', ...statuses].map((item) => (
          <button key={item} type="button" className={`chip${status === item ? ' chip-active' : ''}`} aria-pressed={status === item} onClick={() => onStatusChange(item)}>
            {item}
          </button>
        ))}
      </div>
    </div>
  )
}
