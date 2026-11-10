import { useEffect } from 'react'

export function Drawer({
  open = false,
  onClose,
  title,
  description,
  children,
  footer,
  width = 'md',
  className = '',
}) {
  useEffect(() => {
    if (!open) return
    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose?.()
    }
    window.addEventListener('keydown', handleKeyDown)
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="ui-drawer-overlay" onClick={onClose} aria-modal="true" role="dialog">
      <div
        className={`ui-drawer ui-drawer-${width} ${className}`.trim()}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="ui-drawer-header">
          <div className="ui-drawer-title-area">
            {title && <h2 className="ui-drawer-title">{title}</h2>}
            {description && <p className="ui-drawer-description">{description}</p>}
          </div>
          <button
            type="button"
            className="ui-drawer-close"
            aria-label="Close drawer"
            onClick={onClose}
          >
            ✕
          </button>
        </div>
        <div className="ui-drawer-body">
          {children}
        </div>
        {footer && <div className="ui-drawer-footer">{footer}</div>}
      </div>
    </div>
  )
}
