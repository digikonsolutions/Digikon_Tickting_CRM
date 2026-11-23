import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FieldRow, FormActions, Input, Modal, Select, Textarea } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { todayStr } from '../../../lib/format'
import { ServiceFields } from '../../services/ServiceFields'
import { buildFromAnswers, cleanDetails, fieldsFor, missingRequired, typeOf } from '../../services/serviceCatalog'
import '../../services/services.css'
import { MAX_RUSH_HOURS as MAX_HOURS, validateRush } from '../queueFormat'

/**
 * Ask for urgent help. Two kinds: put a project at the front of a team member's queue, or ask for another team
 * member. Pricing is quoted first; nothing is charged until the account owner accepts the quote.
 */
export function RushRequestModal({ assignments = [], defaultAssignmentId = '', defaults = {}, onClose, onSubmitted }) {
  const [values, setValues] = useState({
    type: 'priority_insert',
    assignment_id: defaultAssignmentId ? String(defaultAssignmentId) : (assignments.length === 1 ? String(assignments[0].id) : ''),
    title: defaults.title ?? '',
    description: defaults.description ?? '',
    skills_notes: '',
    estimated_hours: defaults.estimated_hours ? String(defaults.estimated_hours) : '',
    needed_by: defaults.needed_by ?? '',
    reason: '',
  })
  // An optional GHL service: its answers write the title and description until the person edits those themselves.
  const loadCatalog = useCallback(() => authService.getServiceCatalog?.() ?? Promise.resolve(null), [])
  const catalog = useAsync(loadCatalog).data
  const [serviceType, setServiceType] = useState(null)
  const [details, setDetails] = useState({})
  const [touched, setTouched] = useState({ title: false, description: false })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const insert = values.type === 'priority_insert'
  const set = (field) => (event) => { if (field === 'title' || field === 'description') setTouched((current) => ({ ...current, [field]: true })); setValues((current) => ({ ...current, [field]: event.target.value })); setErrors((current) => ({ ...current, [field]: undefined })) }
  const fieldError = (field) => errors[field] ?? error?.errors?.[field]?.[0]
  const type = typeOf(catalog, serviceType)
  const serviceError = (key) => (key === 'service_type' ? fieldError('service_type') : fieldError(`details.${key}`))
  function changeService(next) {
    setServiceType(next.serviceType)
    setDetails(next.details)
    setErrors({})
    if (!next.serviceType) return
    const written = buildFromAnswers(typeOf(catalog, next.serviceType), next.details, 'project')
    setValues((current) => ({ ...current, ...(touched.title ? {} : { title: written.title }), ...(touched.description ? {} : { description: written.description }) }))
  }

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    const found = validateRush(values, insert)
    for (const field of fieldsFor(type, 'project')) {
      if (missingRequired(type, details, 'project').includes(field.label)) found[`details.${field.key}`] = `Answer: ${field.label}.`
    }
    setErrors(found)
    setError(null)
    if (Object.keys(found).length) {
      window.setTimeout(() => document.querySelector('#rush-form [aria-invalid="true"]')?.focus(), 0)
      return
    }
    setSaving(true)
    try {
      const body = {
        type: values.type,
        title: values.title.trim(),
        description: values.description.trim(),
        estimated_hours: Number(values.estimated_hours),
        needed_by: values.needed_by,
        reason: values.reason.trim() || null,
      }
      if (type) { body.service_type = type.key; body.details = cleanDetails(type, details, 'project') }
      if (insert) body.assignment_id = Number(values.assignment_id)
      else body.skills_notes = values.skills_notes.trim() || null
      const { rush_request: created } = await authService.createRushRequest(body)
      onSubmitted?.(created)
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={() => !saving && onClose()} title="Request urgent help" className="client-modal">
      <form id="rush-form" className="ui-form" onSubmit={submit} noValidate>
        <Alert variant="info" className="ui-form-callout">
          You will get a price quote first. <strong>Nothing is charged</strong> until your account owner accepts it.
        </Alert>
        <fieldset className="queue-type-choice">
          <legend>What do you need?</legend>
          {[
            ['priority_insert', 'Jump the queue', 'Put new work at the front of a team memberâ€™s queue, right behind what they have already started.'],
            ['extra_employee', 'Another team member', 'Ask for extra hands for a short, urgent piece of work.'],
          ].map(([value, label, help]) => (
            <label key={value} className="queue-type-option">
              <input type="radio" name="rush-type" value={value} checked={values.type === value} onChange={() => { setValues((current) => ({ ...current, type: value })); setErrors({}) }} />
              <span><strong>{label}</strong><small>{help}</small></span>
            </label>
          ))}
        </fieldset>
        {insert && (
          <Select id="rush-assignment" label="For which team member?" required value={values.assignment_id} onChange={set('assignment_id')} error={fieldError('assignment_id')}>
            <option value="">Choose a team member</option>
            {assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>)}
          </Select>
        )}
        <ServiceFields catalog={catalog} scope="project" serviceType={serviceType} details={details} onChange={changeService} errors={serviceError} />
        <Input id="rush-title" label="Title" required maxLength={160} value={values.title} onChange={set('title')} error={fieldError('title')} />
        <Textarea id="rush-description" label="What is needed" rows={4} maxLength={5000} required value={values.description} onChange={set('description')} error={fieldError('description')} />
        {!insert && <Textarea id="rush-skills" label="Skills you need (optional)" rows={2} maxLength={2000} value={values.skills_notes} onChange={set('skills_notes')} error={fieldError('skills_notes')} />}
        <FieldRow columns={2}>
          <Input id="rush-hours" label="Estimated hours" type="number" inputMode="decimal" min="0.25" max={MAX_HOURS} step="0.25" required value={values.estimated_hours} onChange={set('estimated_hours')} error={fieldError('estimated_hours')} />
          <Input id="rush-needed" label="Needed by" type="date" min={todayStr()} required value={values.needed_by} onChange={set('needed_by')} error={fieldError('needed_by')} />
        </FieldRow>
        <Textarea id="rush-reason" label="Why is it urgent? (optional)" rows={2} maxLength={1000} value={values.reason} onChange={set('reason')} error={fieldError('reason')} />
        {error && Object.keys(error.errors ?? {}).length === 0 && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Request a quote" loadingLabel="Sendingâ€¦" loading={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
