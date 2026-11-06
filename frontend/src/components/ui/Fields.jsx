import { useId, useState } from 'react'

function FieldShell({ id, label, required, helperText, error, children }) {
  const generatedId = useId()
  const fieldId = id ?? generatedId
  const helperId = helperText ? `${fieldId}-help` : undefined
  const errorId = error ? `${fieldId}-error` : undefined
  const describedBy = [helperId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <div className="ui-field">
      {label && (
        <label className="ui-field-label" htmlFor={fieldId}>
          {label}{required && <span className="ui-required" aria-hidden="true"> *</span>}
        </label>
      )}
      {children({ fieldId, describedBy, errorId })}
      {helperText && <p className="ui-field-help" id={helperId}>{helperText}</p>}
      {error && <p className="ui-field-error" id={errorId}>{error}</p>}
    </div>
  )
}

function VisibilityIcon({ visible }) {
  return visible
    ? (
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8" />
        <path d="M9.9 5.2A10.8 10.8 0 0112 5c5.5 0 9 7 9 7a15.7 15.7 0 01-3 3.8M6.2 6.2C3.9 7.7 3 12 3 12s3.5 7 9 7a10.5 10.5 0 004.1-.8" />
      </svg>
    )
    : (
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 12s3.5-7 9-7 9 7 9 7-3.5 7-9 7-9-7-9-7z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
    )
}

export function Input({ id, label, helperText, error, required = false, className = '', type = 'text', ...props }) {
  const [isPasswordVisible, setIsPasswordVisible] = useState(false)
  const isPassword = type === 'password'

  return (
    <FieldShell id={id} label={label} helperText={helperText} error={error} required={required}>
      {({ fieldId, describedBy, errorId }) => (
        <div className={`ui-input-wrap${isPassword ? ' ui-password-wrap' : ''}`}>
          <input
            id={fieldId}
            className={`ui-input ${className}`.trim()}
            type={isPassword && isPasswordVisible ? 'text' : type}
            required={required}
            aria-invalid={Boolean(error)}
            aria-describedby={describedBy}
            aria-errormessage={errorId}
            {...props}
          />
          {isPassword && (
            <button
              className="ui-password-toggle"
              type="button"
              aria-label={isPasswordVisible ? 'Hide password' : 'Show password'}
              aria-pressed={isPasswordVisible}
              onClick={() => setIsPasswordVisible((visible) => !visible)}
            >
              <VisibilityIcon visible={isPasswordVisible} />
            </button>
          )}
        </div>
      )}
    </FieldShell>
  )
}

export function Select({
  id,
  label,
  helperText,
  error,
  required = false,
  className = '',
  children,
  ...props
}) {
  return (
    <FieldShell id={id} label={label} helperText={helperText} error={error} required={required}>
      {({ fieldId, describedBy, errorId }) => (
        <select
          id={fieldId}
          className={`ui-input ui-select ${className}`.trim()}
          required={required}
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy}
          aria-errormessage={errorId}
          {...props}
        >
          {children}
        </select>
      )}
    </FieldShell>
  )
}

export function Textarea({ id, label, helperText, error, required = false, className = '', ...props }) {
  return (
    <FieldShell id={id} label={label} helperText={helperText} error={error} required={required}>
      {({ fieldId, describedBy, errorId }) => (
        <textarea
          id={fieldId}
          className={`ui-input ui-textarea ${className}`.trim()}
          required={required}
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy}
          aria-errormessage={errorId}
          {...props}
        />
      )}
    </FieldShell>
  )
}
