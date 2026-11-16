import { Icon } from './Icon'

// Every empty list gets an icon by default; pass `icon={null}` to opt out or a node to replace it.
export function EmptyState({ icon, title, children, action }) {
  const shown = icon === undefined ? <Icon name="inbox" size={22} /> : icon
  return (
    <div className="ui-empty-state">
      {shown && <div className="ui-empty-icon" aria-hidden="true">{shown}</div>}
      <h3>{title}</h3>
      {children && <p>{children}</p>}
      {action && <div>{action}</div>}
    </div>
  )
}