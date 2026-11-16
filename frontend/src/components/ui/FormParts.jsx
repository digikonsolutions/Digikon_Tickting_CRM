import { useId } from 'react'
import { Button } from './Button'

// A titled group of fields. Use several to break a long form into scannable steps
// (for example Basics, Schedule, Assignment). Children are stacked; put short fields side by side with FieldRow.
export function FormSection({ title, description, children, className = '' }) {
  const headingId = useId()
  return (
    <section className={`ui-form-section ${className}`.trim()} aria-labelledby={title ? headingId : undefined}>
      {(title || description) && (
        <header className="ui-form-section-header">
          {title && <h3 id={headingId}>{title}</h3>}
          {description && <p>{description}</p>}
        </header>
      )}
      <div className="ui-form-section-body">{children}</div>
    </section>
  )
}

// Fields in a row on desktop (two by default, three with columns={3}); collapses to one column on phones.
export function FieldRow({ columns = 2, children, className = '' }) {
  return <div className={`ui-field-row ui-field-row-${columns} ${className}`.trim()}>{children}</div>
}

// Submit and cancel buttons. When the form cannot be submitted, `disabledReason` says why next to the
// button instead of leaving it silently greyed out. Sticks to the bottom of the screen on phones and
// inside scrolling modals.
export function FormActions({
  submitLabel = 'Save',
  loadingLabel,
  loading = false,
  disabled = false,
  disabledReason = null,
  onCancel,
  cancelLabel = 'Cancel',
  variant = 'primary',
  align = 'end',
  children = null,
}) {
  const reasonId = useId()
  const blocked = disabled && !loading
  return (
    <div className={`ui-form-actions is-${align}`}>
      {blocked && disabledReason && <p className="ui-form-actions-reason" id={reasonId}>{disabledReason}</p>}
      <div className="ui-form-actions-buttons">
        {children}
        {onCancel && <Button type="button" variant="secondary" disabled={loading} onClick={onCancel}>{cancelLabel}</Button>}
        <Button
          type="submit"
          variant={variant}
          loading={loading}
          loadingText={loadingLabel}
          disabled={disabled}
          aria-describedby={blocked && disabledReason ? reasonId : undefined}
        >
          {submitLabel}
        </Button>
      </div>
    </div>
  )
}
