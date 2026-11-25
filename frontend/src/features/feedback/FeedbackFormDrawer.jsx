import { useState } from 'react'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../auth/components/ApiErrorMessage'
import { Alert, Button, Drawer, Select, Textarea } from '../../components/ui'
import { StarInput } from './Stars'

const empty = { rating: null, ratings: {}, would_again: '', went_well: '', to_improve: '', highlights: [], concerns: [], private_note: '', work_item_id: '' }

function fromItem(item) {
  return {
    rating: item.rating, ratings: item.ratings ?? {}, would_again: item.would_again, went_well: item.went_well ?? '', to_improve: item.to_improve ?? '',
    highlights: item.highlights.map((chip) => chip.key), concerns: item.concerns.map((chip) => chip.key), private_note: item.private_note ?? '', work_item_id: item.project?.id ?? '',
  }
}

function Chips({ legend, options, selected, onToggle, tone }) {
  return (
    <fieldset className="fb-chip-field">
      <legend>{legend}</legend>
      <div className="fb-chip-pick">
        {options.map((option) => {
          const on = selected.includes(option.key)
          return <button key={option.key} type="button" aria-pressed={on} className={`fb-chip-button${on ? ` is-on ${tone}` : ''}`} onClick={() => onToggle(option.key)}>{option.label}</button>
        })}
      </div>
    </fieldset>
  )
}

// Write or edit feedback in one drawer: the overall rating and "again?" first (the only required ones), then the optional
// detail. Closes only on purpose; a half-written form asks nothing but is never lost to a stray click on Escape.
export function FeedbackFormDrawer({ open, onClose, form, employeeName, projects = [], editing = null, onSaved, employeeId }) {
  const [values, setValues] = useState(() => (editing ? fromItem(editing) : empty))
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const errors = error?.errors ?? {}
  const set = (key, value) => setValues((current) => ({ ...current, [key]: value }))
  const toggle = (key) => (id) => setValues((current) => ({ ...current, [key]: current[key].includes(id) ? current[key].filter((item) => item !== id) : [...current[key], id] }))

  const blocked = !values.rating ? 'Give an overall rating.' : !values.would_again ? 'Say whether you would work with them again.' : !values.went_well.trim() && !values.to_improve.trim() ? 'Write what went well or what could be better.' : null

  async function submit(event) {
    event.preventDefault()
    if (saving || blocked) return
    setSaving(true)
    setError(null)
    const body = {
      rating: values.rating, ratings: values.ratings, would_again: values.would_again, went_well: values.went_well, to_improve: values.to_improve,
      highlights: values.highlights, concerns: values.concerns, private_note: values.private_note,
      ...(!editing && values.work_item_id ? { work_item_id: Number(values.work_item_id) } : {}),
    }
    try {
      const result = editing ? await authService.updateFeedback(editing.id, body) : await authService.createFeedback(employeeId, body)
      onSaved(result.feedback, Boolean(editing))
    } catch (reason) {
      setError(reason)
      setSaving(false)
    }
  }

  const footer = (
    <div className="fb-drawer-footer">
      {blocked && <p className="fb-muted" role="status">{blocked}</p>}
      <Button type="button" variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
      <Button type="submit" form="fb-form" loading={saving} loadingText="Saving…" disabled={Boolean(blocked)}>{editing ? 'Save changes' : 'Send feedback'}</Button>
    </div>
  )

  return (
    <Drawer open={open} onClose={() => !saving && onClose()} width="lg" title={editing ? 'Edit feedback' : `Feedback for ${employeeName}`} description="Two minutes. Only the first two questions are required." footer={footer} className="fb-drawer">
      <form id="fb-form" className="fb-form ui-form" onSubmit={submit} noValidate>
        <section className="fb-section" aria-label="Overall">
          <StarInput label="Overall, how was it?" required value={values.rating} onChange={(value) => set('rating', value)} error={errors.rating?.[0]} />
          <fieldset className="fb-again-field">
            <legend>Would you work with them again? <span className="ui-required" aria-hidden="true">*</span></legend>
            <div className="fb-again-pick">
              {(form?.would_again ?? []).map((option) => (
                <label key={option.key} className={`fb-again-option is-${option.key}${values.would_again === option.key ? ' is-on' : ''}`}>
                  <input type="radio" name="would_again" value={option.key} checked={values.would_again === option.key} onChange={() => set('would_again', option.key)} />{option.label}
                </label>
              ))}
            </div>
            {errors.would_again?.[0] && <p className="ui-field-error" role="alert">{errors.would_again[0]}</p>}
          </fieldset>
        </section>

        <section className="fb-section" aria-label="Details">
          <h3>Rate each area <small>(optional, tap the chosen star again to clear)</small></h3>
          {(form?.categories ?? []).map((category) => (
            <StarInput key={category.key} label={category.label} clearable value={values.ratings[category.key] ?? null} onChange={(value) => set('ratings', { ...values.ratings, [category.key]: value })} error={errors[`ratings.${category.key}`]?.[0]} />
          ))}
        </section>

        <section className="fb-section" aria-label="In your words">
          <h3>In your words</h3>
          <Textarea label="What went well" rows={4} maxLength={3000} value={values.went_well} onChange={(event) => set('went_well', event.target.value)} error={errors.went_well?.[0]} helperText="Examples: clear updates, tidy workflows, quick to learn." />
          <Textarea label="What could be better" rows={4} maxLength={3000} value={values.to_improve} onChange={(event) => set('to_improve', event.target.value)} error={errors.to_improve?.[0]} helperText="Be specific and kind. The employee can read this." />
          <Chips legend="Highlights" options={form?.highlights ?? []} selected={values.highlights} onToggle={toggle('highlights')} tone="is-good" />
          <Chips legend="Concerns" options={form?.concerns ?? []} selected={values.concerns} onToggle={toggle('concerns')} tone="is-concern" />
        </section>

        <section className="fb-section" aria-label="Project and private note">
          {!editing && projects.length > 0 && (
            <Select label="Which project is this about? (optional)" value={values.work_item_id} onChange={(event) => set('work_item_id', event.target.value)} error={errors.work_item_id?.[0]} helperText="Leave empty for feedback about the whole time they worked with you.">
              <option value="">The whole assignment</option>
              {projects.map((project) => <option key={project.id} value={project.id}>{project.title}</option>)}
            </Select>
          )}
          <Textarea label="Private note for our team (optional)" rows={3} maxLength={2000} value={values.private_note} onChange={(event) => set('private_note', event.target.value)} error={errors.private_note?.[0]} helperText="Only the Digikon team and your agency owners see this. The employee never does." />
        </section>

        {error && !error.errors && <ApiErrorMessage error={error} />}
        {error?.errors && <Alert variant="error">Some answers need attention. They are marked above.</Alert>}
      </form>
    </Drawer>
  )
}
