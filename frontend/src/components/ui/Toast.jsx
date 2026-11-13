import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Alert } from './Alert'
import { ToastContext } from './ToastContext'

const TOAST_DURATION_MS = 4000

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const timers = useRef(new Map())
  const dismiss = useCallback((id) => {
    window.clearTimeout(timers.current.get(id))
    timers.current.delete(id)
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])
  const showToast = useCallback((message, variant = 'success') => {
    const id = `${Date.now()}-${Math.random()}`
    setToasts((current) => [...current, { id, message, variant }])
    timers.current.set(id, window.setTimeout(() => dismiss(id), TOAST_DURATION_MS))
  }, [dismiss])
  // Clear every pending timer if the provider unmounts.
  useEffect(() => {
    const pending = timers.current
    return () => {
      pending.forEach((timer) => window.clearTimeout(timer))
      pending.clear()
    }
  }, [])
  const value = useMemo(() => ({ showToast }), [showToast])

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="ui-toast-region" aria-live="polite" aria-label="Notifications">
        {toasts.map((toast) => (
          <Alert key={toast.id} variant={toast.variant} className="ui-toast">
            {toast.message}
          </Alert>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function Toast({ variant = 'success', children, ...props }) {
  return <Alert variant={variant} className="ui-toast" {...props}>{children}</Alert>
}
