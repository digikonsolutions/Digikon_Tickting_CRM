export function EmptyState({ icon, title, children, action }) {
  return (
    <div className="ui-empty-state">
      {icon && <div className="ui-empty-icon" aria-hidden="true">{icon}</div>}
      <h3>{title}</h3>
      {children && <p>{children}</p>}
      {action && <div>{action}</div>}
    </div>
  )
}
