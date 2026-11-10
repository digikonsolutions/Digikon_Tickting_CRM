import { Icon } from './Icon'

function getInitials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return ''
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export function Avatar({ name, email, src, size = 'md', className = '', ...props }) {
  const initials = getInitials(name || email || '')
  const title = name || email || 'User'

  if (src) {
    return (
      <img
        src={src}
        alt={title}
        title={title}
        className={`ui-avatar ui-avatar-${size} ${className}`.trim()}
        {...props}
      />
    )
  }

  return (
    <span
      className={`ui-avatar ui-avatar-${size}${initials ? '' : ' ui-avatar-fallback'} ${className}`.trim()}
      title={title}
      role="img"
      aria-label={title}
      {...props}
    >
      {initials || <Icon name="user" size={size === 'sm' ? 14 : size === 'lg' ? 20 : 16} />}
    </span>
  )
}
