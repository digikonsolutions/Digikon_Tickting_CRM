import { Icon } from './Icon'

export function KpiCard({
  title,
  value,
  subtext,
  icon,
  progress,
  tone = 'teal',
  hero = false,
  className = '',
  children,
  ...props
}) {
  if (hero) {
    return (
      <article className={`ui-kpi-card ui-kpi-hero ${className}`.trim()} {...props}>
        <div className="ui-kpi-hero-header">
          <p className="ui-kpi-eyebrow">{title}</p>
          {icon && <span className="ui-kpi-hero-icon"><Icon name={icon} size={20} /></span>}
        </div>
        <div>
          <strong className="ui-kpi-hero-value">{value}</strong>
          {subtext && <p className="ui-kpi-hero-subtext">{subtext}</p>}
        </div>
        {progress}
        {children}
      </article>
    )
  }

  return (
    <article className={`ui-kpi-card ui-kpi-${tone} ${className}`.trim()} {...props}>
      <div className="ui-kpi-topline">
        {icon && <span className="ui-kpi-icon"><Icon name={icon} size={18} /></span>}
        <span className="ui-kpi-title">{title}</span>
      </div>
      <strong className="ui-kpi-value">{value}</strong>
      {subtext && <span className="ui-kpi-subtext">{subtext}</span>}
      {children}
    </article>
  )
}
