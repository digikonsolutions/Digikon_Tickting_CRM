import { Icon } from './Icon'

export function NeedsInputBanner({
  title,
  body,
  actions = null,
  variant = 'warning',
  icon = null,
  className = '',
}) {
  const iconName = icon ?? (variant === 'muted' ? 'info' : 'clock')

  return (
    <aside
      className={`ui-needs-input-banner ui-needs-input-banner-${variant} ${className}`.trim()}
      role="region"
      aria-label={title}
    >
      <div className="ui-needs-input-icon">
        <Icon name={iconName} size={20} />
      </div>
      <div className="ui-needs-input-content">
        <h4 className="ui-needs-input-title">{title}</h4>
        {body && <p className="ui-needs-input-body">{body}</p>}
        {actions && <div className="ui-needs-input-actions">{actions}</div>}
      </div>
    </aside>
  )
}
