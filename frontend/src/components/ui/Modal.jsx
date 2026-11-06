import { useEffect, useRef } from 'react'
import { Button } from './Button'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function Modal({ open, onClose, title, children, footer, labelledBy, className = '' }) {
  const dialogRef = useRef(null)

  useEffect(() => {
    if (!open) return undefined

    const previousFocus = document.activeElement
    const dialog = dialogRef.current
    const focusable = dialog?.querySelectorAll(FOCUSABLE)
    ;(focusable?.[0] ?? dialog)?.focus()

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        onClose()
        return
      }

      if (event.key !== 'Tab' || !dialog) return
      const current = dialog.querySelectorAll(FOCUSABLE)
      if (!current.length) {
        event.preventDefault()
        dialog.focus()
        return
      }

      const first = current[0]
      const last = current[current.length - 1]
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
      previousFocus?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="ui-modal-overlay"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <section
        ref={dialogRef}
        className={`ui-modal ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy ?? 'ui-modal-title'}
        tabIndex={-1}
      >
        <header className="ui-modal-header">
          <h2 id={labelledBy ?? 'ui-modal-title'}>{title}</h2>
          <Button type="button" variant="ghost" size="sm" aria-label="Close dialog" onClick={onClose}>×</Button>
        </header>
        <div className="ui-modal-body">{children}</div>
        {footer && <footer className="ui-modal-footer">{footer}</footer>}
      </section>
    </div>
  )
}
