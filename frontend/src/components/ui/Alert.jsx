const ALERT_ICONS = {
  success: '✓',
  error: '!',
  warning: '!',
  info: 'i',
}

export function Alert({ variant = 'info', title, children, className = '', role, ...props }) {
  return (
    <div className={`ui-alert ui-alert-${variant} ${className}`.trim()} role={role ?? (variant === 'error' ? 'alert' : 'status')} {...props}>
      <span className="ui-alert-icon" aria-hidden="true">{ALERT_ICONS[variant]}</span>
      <div>
        {title && <strong className="ui-alert-title">{title}</strong>}
        {children && <div className="ui-alert-content">{children}</div>}
      </div>
    </div>
  )
}
