import { useEffect, useId, useRef } from 'react'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

// Side panel. Like Modal it moves focus inside, keeps Tab within the panel, closes on Escape and returns
// focus to whatever opened it. The page behind cannot scroll while it is open.
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
  const panelRef = useRef(null)
  const onCloseRef = useRef(onClose)
  const titleId = useId()

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    if (!open) return undefined
    const previousFocus = document.activeElement
    const panel = panelRef.current
    ;(panel?.querySelector(FOCUSABLE) ?? panel)?.focus()
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        onCloseRef.current?.()
        return
      }
      if (event.key !== 'Tab' || !panel) return
      const items = panel.querySelectorAll(FOCUSABLE)
      if (!items.length) {
        event.preventDefault()
        panel.focus()
        return
      }
      const first = items[0]
      const last = items[items.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
      previousFocus?.focus?.()
    }
  }, [open])

  if (!open) return null

  return (
    <div className="ui-drawer-overlay" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose?.() }}>
      <div
        ref={panelRef}
        className={`ui-drawer ui-drawer-${width} ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        tabIndex={-1}
      >
        <div className="ui-drawer-header">
          <div className="ui-drawer-title-area">
            {title && <h2 className="ui-drawer-title" id={titleId}>{title}</h2>}
            {description && <p className="ui-drawer-description">{description}</p>}
          </div>
          <button type="button" className="ui-drawer-close" aria-label="Close panel" onClick={onClose}>
            <span aria-hidden="true">✕</span>
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