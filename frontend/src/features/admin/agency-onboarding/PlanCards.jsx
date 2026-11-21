import { Icon } from '../../../components/ui/Icon'
import { planPrice } from './onboardingForm'

const VIRTUAL_CUSTOM = { id: null, name: 'Custom plan', tagline: 'Tailored', is_custom: true, price: null, features: [], best_for: 'Set your own price, limits and inclusions.' }

// The plan picker: one card per plan, like the pricing page (heading, price, what is included, who it suits), and a
// Custom card for an agency that needs its own numbers. Radio inputs underneath, so arrow keys and screen readers work.
// `value` is the selected plan id as a string ('' for none); `onSelect` gets the plan, or null for the built-in Custom card.
export function PlanCards({ plans, value, onSelect, error }) {
  const cards = plans.some((plan) => plan.is_custom) ? plans : [...plans, VIRTUAL_CUSTOM]

  return (
    <fieldset className="onb-plans">
      <legend className="ui-field-label">Choose a plan</legend>
      <div className="onb-plan-grid" role="radiogroup" aria-label="Plan">
        {cards.map((plan) => {
          const id = plan.id === null ? '' : String(plan.id)
          const selected = value === id && (id !== '' || plan.id === null)
          const price = planPrice(plan)
          const features = plan.features ?? []
          return (
            <label key={id || 'custom'} className={`onb-plan${selected ? ' is-selected' : ''}${plan.is_custom ? ' is-custom' : ''}`}>
              <input type="radio" name="plan" value={id} checked={selected} onChange={() => onSelect(plan.id === null ? null : plan)} />
              <span className="onb-plan-check" aria-hidden="true"><Icon name="check" size={16} /></span>
              {plan.tagline && <span className="onb-plan-tag">{plan.tagline}</span>}
              <strong className="onb-plan-name">{plan.name}</strong>
              <span className="onb-plan-price">{price ? <><b>{price.money}</b>/{price.per}</> : 'Your own price'}</span>
              {features.length > 0 && (
                <ul className="onb-plan-features">
                  {features.slice(0, 4).map((feature) => <li key={feature}>{feature}</li>)}
                  {features.length > 4 && <li className="onb-plan-more">+ {features.length - 4} more included</li>}
                </ul>
              )}
              {plan.best_for && <span className="onb-plan-best">{plan.best_for}</span>}
            </label>
          )
        })}
      </div>
      {error && <p className="ui-field-error" role="alert">{error}</p>}
    </fieldset>
  )
}