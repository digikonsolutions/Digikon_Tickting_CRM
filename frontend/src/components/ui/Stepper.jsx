import { Icon } from './Icon'

// A step indicator for a multi-step form. `steps` is [{ id, label, status }] where status is 'current', 'done',
// 'error' or 'todo'. Steps that are done, in error or current can be clicked to go back; later steps cannot.
// On narrow screens only the current label shows, with a "Step 2 of 5" line and a progress bar.
export function Stepper({ steps, current, onSelect, label = 'Progress' }) {
  const currentIndex = steps.findIndex((step) => step.id === current)
  const percent = steps.length > 1 ? Math.round((currentIndex / (steps.length - 1)) * 100) : 100

  return (
    <nav className="ui-stepper" aria-label={label}>
      <p className="ui-stepper-summary">Step {currentIndex + 1} of {steps.length}: <strong>{steps[currentIndex]?.label}</strong></p>
      <div className="ui-stepper-bar" role="progressbar" aria-label={`${label}: step ${currentIndex + 1} of ${steps.length}`} aria-valuemin="1" aria-valuemax={steps.length} aria-valuenow={currentIndex + 1}>
        <span style={{ width: `${percent}%` }} />
      </div>
      <ol className="ui-stepper-list">
        {steps.map((step, index) => {
          const reachable = step.status !== 'todo' || index <= currentIndex
          return (
            <li key={step.id} className={`ui-step is-${step.status}`}>
              <button
                type="button"
                className="ui-step-button"
                aria-current={step.id === current ? 'step' : undefined}
                disabled={!reachable}
                onClick={() => onSelect?.(step.id)}
              >
                <span className="ui-step-dot" aria-hidden="true">
                  {step.status === 'done' ? <Icon name="check" size={16} /> : step.status === 'error' ? <Icon name="alert" size={16} /> : index + 1}
                </span>
                <span className="ui-step-label">{step.label}</span>
                <span className="sr-only">{step.status === 'done' ? ' (completed)' : step.status === 'error' ? ' (needs attention)' : ''}</span>
              </button>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}