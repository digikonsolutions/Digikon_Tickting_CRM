import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'

// A "⋮" button that opens a menu of row actions. Items are { label, to?, onClick?, danger?, separator? }.
// Keyboard: Enter/Space/ArrowDown opens, arrows move, Home/End jump, Escape closes and returns focus.
// The menu is positioned with fixed coordinates so a scrolling table never clips it.
export function ActionMenu({ label = 'Actions', items, triggerLabel, triggerIcon }) {
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState({ top: 0, right: 0 })
  const buttonRef = useRef(null)
  const menuRef = useRef(null)
  const menuId = useId()

  const close = useCallback((returnFocus = true) => {
    setOpen(false)
    if (returnFocus) buttonRef.current?.focus()
  }, [])

  function toggle() {
    if (!open) {
      const rect = buttonRef.current.getBoundingClientRect()
      const spaceBelow = window.innerHeight - rect.bottom
      const estimatedHeight = items.length * 40 + 16
      setPosition({
        top: spaceBelow < estimatedHeight ? Math.max(8, rect.top - estimatedHeight - 4) : rect.bottom + 4,
        right: Math.max(8, window.innerWidth - rect.right),
      })
    }
    setOpen((current) => !current)
  }

  useEffect(() => {
    if (!open) return undefined
    menuRef.current?.querySelector('[role="menuitem"]')?.focus()
    const onPointerDown = (event) => {
      if (!menuRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) close(false)
    }
    const onDismiss = () => close(false)
    document.addEventListener('pointerdown', onPointerDown)
    window.addEventListener('resize', onDismiss)
    window.addEventListener('scroll', onDismiss, true)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      window.removeEventListener('resize', onDismiss)
      window.removeEventListener('scroll', onDismiss, true)
    }
  }, [open, close])

  function onMenuKeyDown(event) {
    const nodes = Array.from(menuRef.current.querySelectorAll('[role="menuitem"]'))
    const index = nodes.indexOf(document.activeElement)
    if (event.key === 'Escape') { event.preventDefault(); close() } else if (event.key === 'Tab') close(false)
    else if (event.key === 'ArrowDown') { event.preventDefault(); nodes[(index + 1) % nodes.length]?.focus() } else if (event.key === 'ArrowUp') { event.preventDefault(); nodes[(index - 1 + nodes.length) % nodes.length]?.focus() } else if (event.key === 'Home') { event.preventDefault(); nodes[0]?.focus() } else if (event.key === 'End') { event.preventDefault(); nodes[nodes.length - 1]?.focus() }
  }

  return (
    <div className="ui-action-menu">
      <button
        ref={buttonRef}
        type="button"
        className={triggerLabel ? 'ui-button ui-button-primary ui-button-md ui-action-menu-trigger' : 'ui-action-menu-button'}
        aria-label={triggerLabel ? undefined : label}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={toggle}
        onKeyDown={(event) => { if (event.key === 'ArrowDown' && !open) { event.preventDefault(); toggle() } }}
      >
        {triggerLabel
          ? (
            <>
              {triggerIcon}
              {triggerLabel}
              <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M6 9l6 6 6-6" /></svg>
            </>
          )
          : <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="currentColor"><circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" /></svg>}
      </button>
      {open && (
        <div ref={menuRef} id={menuId} className="ui-action-menu-list" role="menu" aria-label={label} style={{ top: position.top, right: position.right }} onKeyDown={onMenuKeyDown}>
          {items.map((item) => {
            const className = `ui-action-menu-item${item.danger ? ' is-danger' : ''}`
            return (
              <div key={item.label} role="none">
                {item.separator && <hr className="ui-action-menu-separator" />}
                {item.to
                  ? <Link role="menuitem" className={className} to={item.to} onClick={() => close(false)}>{item.label}</Link>
                  : <button type="button" role="menuitem" className={className} onClick={() => { close(false); item.onClick?.() }}>{item.label}</button>}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
