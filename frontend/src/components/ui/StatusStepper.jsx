import { Badge } from './Badge'
import { Icon } from './Icon'

const STEPS = [
  { key: 'todo', label: 'To do' },
  { key: 'in_progress', label: 'In progress' },
  { key: 'in_review', label: 'In review' },
  { key: 'done', label: 'Done' },
]

const SIDE_STATES = {
  blocked: {
    label: 'Blocked',
    variant: 'danger',
    parentStep: 'in_progress',
    description: 'Work is paused due to an impediment',
  },
  waiting_on_client: {
    label: 'Waiting on client',
    variant: 'warning',
    parentStep: 'in_progress',
    description: 'Awaiting client response or clarification',
  },
  changes_requested: {
    label: 'Changes requested',
    variant: 'orange',
    parentStep: 'in_review',
    description: 'Client requested modifications',
  },
}

export function StatusStepper({ status, className = '' }) {
  const currentStatus = String(status ?? 'todo').toLowerCase()
  const sideState = SIDE_STATES[currentStatus]

  const activeIndex = sideState
    ? STEPS.findIndex((s) => s.key === sideState.parentStep)
    : STEPS.findIndex((s) => s.key === currentStatus)

  return (
    <div className={`ui-status-stepper ${className}`.trim()} role="group" aria-label="Ticket progress">
      <ol className="ui-status-stepper-steps">
        {STEPS.map((step, index) => {
          const isDone = activeIndex > index || currentStatus === 'done'
          const isCurrent = activeIndex === index && !sideState
          const isParentOfSide = sideState && activeIndex === index

          let stateClass = 'is-upcoming'
          if (isDone && !isParentOfSide) stateClass = 'is-done'
          else if (isCurrent) stateClass = 'is-current'
          else if (isParentOfSide) stateClass = 'is-paused'

          return (
            <li key={step.key} className={`ui-status-step ${stateClass}`}>
              <div className="ui-status-step-marker">
                {isDone && !isParentOfSide ? <Icon name="check" size={14} /> : <span>{index + 1}</span>}
              </div>
              <span className="ui-status-step-label">{step.label}</span>
              {index < STEPS.length - 1 && <span className="ui-status-step-connector" />}
            </li>
          )
        })}
      </ol>

      {sideState && (
        <div className="ui-status-stepper-side" role="status">
          <div className="ui-status-stepper-side-branch">
            <span className="ui-status-stepper-side-line" />
            <Badge status={currentStatus} variant={sideState.variant}>
              {sideState.label}
            </Badge>
            <span className="ui-status-stepper-side-desc">{sideState.description}</span>
          </div>
        </div>
      )}
    </div>
  )
}
