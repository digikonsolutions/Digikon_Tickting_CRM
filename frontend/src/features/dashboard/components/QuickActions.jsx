import { Link } from 'react-router-dom'
import { Icon } from '../../../components/ui'

export function QuickActions({ actions }) {
  return (
    <div className="quick-actions">
      {actions.map((action) => (
        <Link key={action.to} to={action.to} className="quick-action">
          <span className="quick-action-icon"><Icon name={action.icon} size={20} /></span>
          <span><strong>{action.label}</strong><small>{action.description}</small></span>
          <Icon name="arrow" size={16} className="quick-action-arrow" />
        </Link>
      ))}
    </div>
  )
}
