export function PageHeader({ eyebrow, title, subtitle, action, className = '' }) {
  return (
    <header className={`ui-page-header ${className}`.trim()}>
      <div>
        {eyebrow && <p className="ui-eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
      {action && <div className="ui-page-header-action">{action}</div>}
    </header>
  )
}
