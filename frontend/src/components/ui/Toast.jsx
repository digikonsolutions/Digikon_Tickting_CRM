import { useCallback, useMemo, useState } from 'react'
import { Alert } from './Alert'
import { ToastContext } from './ToastContext'

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])
  const showToast = useCallback((message, variant = 'success') => {
    const id = `${Date.now()}-${Math.random()}`
    setToasts((current) => [...current, { id, message, variant }])
    window.setTimeout(() => dismiss(id), 4000)
  }, [dismiss])
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
