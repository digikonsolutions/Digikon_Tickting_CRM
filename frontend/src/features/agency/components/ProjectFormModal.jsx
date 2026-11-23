import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FieldRow, FormActions, FormSection, Input, Modal, Select, Textarea } from '../../../components/ui'
import { formatHours, toDateStr, todayStr } from '../../../lib/format'
import { FilePicker, ProjectBriefFields, readProjectBrief, uploadErrorMessage } from '../../briefs'

const CAPACITY_DEBOUNCE_MS = 300
const FORM_ID = 'project-form'

// Create a project, or edit one, in a dialog (the list comes first). On create the chosen employee's real
// availability is checked on the server (same rules as the scheduler) before the button enables.
export function ProjectFormModal({ project, assignments, assignmentsLoading, defaultAssignmentId, onRequestUrgent, onClose, onSaved }) {
  const editing = Boolean(project)
  const [today] = useState(todayStr)
  const [values, setValues] = useState(() => {
    if (project) return { title: project.title, description: project.description ?? '', estimated_hours: String(Number(project.estimated_hours)), start_date: project.start_date, due_date: project.due_date }
    const due = new Date()
    due.setDate(due.getDate() + 6)
    return { title: '', description: '', estimated_hours: '6', start_date: todayStr(), due_date: toDateStr(due) }
  })
  const [assignmentId, setAssignmentId] = useState(project ? String(project.assignment_id) : (defaultAssignmentId ? String(defaultAssignmentId) : ''))
  const [files, setFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [capacity, setCapacity] = useState({ key: null, hours: null, error: null })

  const selected = assignments.find((assignment) => String(assignment.id) === assignmentId)
  const hours = Number(values.estimated_hours)
  const datesInvalid = Boolean(values.start_date && values.due_date && values.due_date < values.start_date)
  const capacityKey = !editing && assignmentId && values.start_date && values.due_date && !datesInvalid ? `${assignmentId}|${values.start_date}|${values.due_date}` : null

  useEffect(() => {
    const timer = window.setTimeout(() => document.getElementById('project-title')?.focus(), 0)
    return () => window.clearTimeout(timer)
  }, [])
  useEffect(() => {
    if (error?.errors) document.querySelector(`#${FORM_ID} [aria-invalid="true"]`)?.focus()
  }, [error])
  useEffect(() => {
    if (!capacityKey) return undefined
    const [id, from, to] = capacityKey.split('|')
    let active = true
    const timer = window.setTimeout(async () => {
      try {
        const response = await authService.getCapacity({ assignmentId: id, from, to })
        if (active) setCapacity({ key: capacityKey, hours: Number(response.total_available_hours), error: null })
      } catch (reason) {
        if (active) setCapacity({ key: capacityKey, hours: null, error: reason })
      }
    }, CAPACITY_DEBOUNCE_MS)
    return () => { active = false; window.clearTimeout(timer) }
  }, [capacityKey])

  const checking = Boolean(capacityKey) && capacity.key !== capacityKey
  const result = capacityKey && capacity.key === capacityKey ? capacity : null
  const remaining = result?.hours ?? null
  const fieldError = (field) => error?.errors?.[field]?.[0]
  const set = (field) => (event) => { setValues((current) => ({ ...current, [field]: event.target.value })); setError(null) }

  let disabledReason = null
  if (!assignmentsLoading && assignments.length === 0) disabledReason = 'You need an employee on your agency first.'
  else if (!assignmentId) disabledReason = 'Choose an employee.'
  else if (datesInvalid) disabledReason = 'The due date must be on or after the start date.'
  else if (!(hours > 0)) disabledReason = 'Enter the estimated hours.'
  else if (!editing && checking) disabledReason = 'Checking the employee’s availability…'
  else if (!editing && result?.error) disabledReason = 'Availability could not be checked. Try changing the dates.'
  else if (!editing && remaining !== null && hours > remaining) disabledReason = `Reduce the hours to ${formatHours(remaining)} or fewer, or extend the dates.`
  else if (!editing && remaining === null) disabledReason = 'Checking the employee’s availability…'

  async function submit(event) {
    event.preventDefault()
    if (saving || disabledReason) return
    const form = new FormData(event.currentTarget)
    setSaving(true)
    setError(null)
    try {
      if (editing) {
        // Hours, dates and employee re-plan the schedule, so they are only sent when they changed.
        const payload = { title: values.title.trim(), description: values.description.trim() }
        if (hours !== Number(project.estimated_hours)) payload.estimated_hours = hours
        if (values.start_date !== project.start_date) payload.start_date = values.start_date
        if (values.due_date !== project.due_date) payload.due_date = values.due_date
        if (Number(assignmentId) !== Number(project.assignment_id)) payload.assignment_id = Number(assignmentId)
        const { project: saved } = await authService.updateAgencyProject(project.id, payload)
        onSaved(saved, { created: false })
      } else {
        const { project: saved } = await authService.createAgencyProject({
          title: values.title.trim(), description: values.description.trim(), estimated_hours: hours,
          start_date: values.start_date, due_date: values.due_date, assignment_id: Number(assignmentId), ...readProjectBrief(form),
        })
        let uploadNotice = null
        if (files.length) {
          try { await authService.uploadProjectAttachments(saved.id, files) } catch (reason) { uploadNotice = uploadErrorMessage(reason) }
        }
        onSaved(saved, { created: true, uploadNotice })
      }
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  const name = selected?.employee?.name ?? 'This employee'
  const callout = (() => {
    if (editing || !assignmentId) return null
    if (checking) return <Alert variant="info" className="ui-form-callout">Checking {name}’s availability…</Alert>
    if (result?.error) return <Alert variant="error" className="ui-form-callout">{result.error.message ?? 'Could not check availability.'}</Alert>
    if (remaining === null) return null
    return hours > remaining
      ? <Alert variant="warning" className="ui-form-callout" title="Not enough availability">{name} has {formatHours(remaining)} available between these dates, but the project needs {formatHours(hours)}.</Alert>
      : <Alert variant="info" className="ui-form-callout">{name} has <strong>{formatHours(remaining)}</strong> available between these dates.</Alert>
  })()

  return (
    <Modal open onClose={() => !saving && onClose()} title={editing ? 'Edit project' : 'Create project'} className="client-modal">
      <form id={FORM_ID} className="ui-form" onSubmit={submit} noValidate>
        {!assignmentsLoading && assignments.length === 0 && (
          <Alert variant="info" className="ui-form-callout">You need an employee on your agency before creating a project. <Link to="/agency/employees">Go to Employees</Link>.</Alert>
        )}
        <FormSection title="Basics">
          <Input id="project-title" label="Project title" required maxLength={160} value={values.title} onChange={set('title')} error={fieldError('title')} />
          <Textarea id="project-description" label="Description" rows={4} maxLength={5000} required value={values.description} onChange={set('description')} error={fieldError('description')} />
        </FormSection>
        <FormSection title="Schedule" description="Hours are scheduled earliest-first, Monday to Saturday.">
          <FieldRow columns={3}>
            <Input id="project-hours" label="Estimated hours" type="number" inputMode="decimal" min="0.01" max="180" step="0.01" required value={values.estimated_hours} onChange={set('estimated_hours')} error={fieldError('estimated_hours')} />
            <Input id="project-start" label="Start date" type="date" min={editing ? undefined : today} required value={values.start_date} onChange={set('start_date')} error={fieldError('start_date')} />
            <Input id="project-due" label="Due date" type="date" min={values.start_date || today} required value={values.due_date} onChange={set('due_date')} error={fieldError('due_date') ?? (datesInvalid ? 'Must be on or after the start date.' : undefined)} />
          </FieldRow>
        </FormSection>
        <FormSection title="Employee">
          <Select id="project-assignment" label="Employee" required value={assignmentId} disabled={assignmentsLoading || assignments.length === 0} onChange={(event) => { setAssignmentId(event.target.value); setError(null) }} error={fieldError('assignment_id')}>
            <option value="">{assignmentsLoading ? 'Loading employees…' : 'Choose an employee'}</option>
            {assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>)}
          </Select>
          {callout}
        </FormSection>
        {!editing && (
          <FormSection title="Brief, notes and files" description="Optional. Anything that helps your employee get started.">
            <ProjectBriefFields errors={error?.errors} />
            <FilePicker files={files} onChange={setFiles} notice={fileNotice} onNotice={setFileNotice} disabled={saving} />
          </FormSection>
        )}
        {!editing && onRequestUrgent && (
          <p className="ui-field-help">
            Cannot wait in the queue?{' '}
            <button type="button" className="ui-link-button" onClick={() => onRequestUrgent({ assignmentId, defaults: { title: values.title.trim(), description: values.description.trim(), estimated_hours: hours > 0 ? hours : '', needed_by: values.due_date } })}>Request urgent help</button>
            {' '}for a price quote. Nothing is charged until your account owner accepts it.
          </p>
        )}
        {error && error.status !== 422 && <ApiErrorMessage error={error} />}
        {error?.status === 422 && error.message && !error.errors && <Alert variant="error">{error.message}</Alert>}
        <FormActions submitLabel={editing ? 'Save changes' : 'Create project'} loadingLabel={editing ? 'Saving…' : 'Scheduling project…'} loading={saving} disabled={Boolean(disabledReason)} disabledReason={disabledReason} onCancel={onClose} />
      </form>
    </Modal>
  )
}
