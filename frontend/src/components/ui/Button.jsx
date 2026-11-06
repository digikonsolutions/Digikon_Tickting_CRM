import { Spinner } from './Spinner'

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  loadingText,
  disabled = false,
  className = '',
  children,
  ...props
}) {
  return (
    <button
      className={`ui-button ui-button-${variant} ui-button-${size} ${className}`.trim()}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      <span className="ui-button-label-stack">
        <span className={loading ? 'ui-button-label ui-button-label-hidden' : 'ui-button-label'} aria-hidden={loading || undefined}>
          {children}
        </span>
        <span className={loading ? 'ui-button-loading-label' : 'ui-button-loading-label ui-button-label-hidden'} aria-hidden={!loading || undefined}>
          {loading && <Spinner size="sm" label="" />}
          {loadingText ?? children}
        </span>
      </span>
    </button>
  )
}
