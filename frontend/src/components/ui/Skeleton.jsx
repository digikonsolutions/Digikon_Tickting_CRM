export function Skeleton({ variant = 'card', className = '', ...props }) {
  return <div className={`ui-skeleton ui-skeleton-${variant} ${className}`.trim()} aria-hidden="true" {...props} />
}
