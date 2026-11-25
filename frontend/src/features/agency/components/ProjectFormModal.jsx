import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FormActions, FormSection, Input, Modal, Select, Textarea } from '../../../components/ui'
import { can } from '../../../lib/permissions'
import { toDateStr, todayStr } from '../../../lib/format'
import { FilePicker, ProjectBriefFields, readProjectBrief, uploadErrorMessage } from '../../briefs'
import { ServiceFields } from '../../services/ServiceFields'
import { buildFromAnswers, cleanDetails, missingRequired, typeOf } from '../../services/serviceCatalog'
import { useAsync } from '../../../hooks/useAsync'
import { BriefForm } from '../../workorder/BriefForm'
import { useProjectBrief } from '../../workorder/useProjectBrief'
import { ScheduleFields } from './ScheduleFields'
import '../../services/services.css'

const FORM_ID = 'project-form'

// Create a project request, or edit one, in a dialog. The agency describes the work and may give an estimate and a
// preferred employee; it never sets dates, because our team plans the days. The Schedule fields only exist for a role
// that may set the schedule (see lib/permissions), so owners and clients share this one form without them.
export function ProjectFormModal({ project, assignments, assignmentsLoading, defaultAssignmentId, onRequestUrgent, onClose, onSaved }) {
  const { user } = useAuth()
  const canSchedule = can(user, 'schedule.set')
  const editing = Boolean(project)
  const [today] = useState(todayStr)
  const [values, setValues] = useState(() => {
    if (project) return { title: project.title, description: project.description ?? '', estimated_hours: Number(project.estimated_hours) > 0 ? String(Number(project.estimated_hours)) : '', start_date: project.start_date, due_date: project.due_date }
    const due = new Date()
    due.setDate(due.getDate() + 6)
    return { title: '', description: '', estimated_hours: '', start_date: todayStr(), due_date: toDateStr(due) }
  })
  // The GHL service and its answers write the title and description until the person edits those themselves.
  const loadCatalog = useCallback(() => authService.getServiceCatalog(), [])
  const catalog = useAsync(loadCatalog).data
  const [serviceType, setServiceType] = useState(project?.service_type ?? null)
  const [details, setDetails] = useState(project?.details ?? {})
  const [touched, setTouched] = useState({ title: editing, description: editing })
  const [assignmentId, setAssignmentId] = useState(project ? String(project.assignment_id) : (defaultAssignmentId ? String(defaultAssignmentId) : ''))
  const ghlBrief = useProjectBrief(null)
  const [files, setFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  // The estimate is optional. The employee can only be switched while our team has not planned the days yet.
  const hours = values.estimated_hours === '' ? null : Number(values.estimated_hours)
  const planned = editing && project.schedule_state !== 'awaiting'
  const datesInvalid = canSchedule && Boolean(values.start_date && values.due_date && values.due_date < values.start_date)

  useEffect(() => {
    const timer = window.setTimeout(() => document.getElementById('project-title')?.focus(), 0)
    return () => window.clearTimeout(timer)
  }, [])
  useEffect(() => {
    if (error?.errors) document.querySelector(`#${FORM_ID} [aria-invalid="true"]`)?.focus()
  }, [error])

  const fieldError = (field) => error?.errors?.[field]?.[0]
  const set = (field) => (event) => {
    setValues((current) => ({ ...current, [field]: event.target.value }))
    if (field === 'title' || field === 'description') setTouched((current) => ({ ...current, [field]: true }))
    setError(null)
  }
  const type = typeOf(catalog, serviceType)
  const missing = missingRequired(type, details, 'project')
  function changeService(next) {
    setServiceType(next.serviceType)
    setDetails(next.details)
    setError(null)
    if (!next.serviceType) return
    const written = buildFromAnswers(typeOf(catalog, next.serviceType), next.details, 'project')
    setValues((current) => ({ ...current, ...(touched.title ? {} : { title: written.title }), ...(touched.description ? {} : { description: written.description }) }))
  }
  const serviceError = (key) => {
    const errors = error?.errors ?? {}
    const name = key === 'service_type' ? key : `details.${key}`
    return errors[name]?.[0] ?? Object.entries(errors).find(([field]) => field.startsWith('.'))?.[1]?.[0]
  }

  let disabledReason = null
  if (!assignmentsLoading && assignments.length === 0) disabledReason = 'You need an employee on your agency first.'
  else if (!editing && !serviceType) disabledReason = 'Choose a service.'
  else if (missing.length > 0) disabledReason = `Answer: ${missing[0]}.`
  else if (!values.title.trim() || !values.description.trim()) disabledReason = 'Add a title and a description.'
  else if (!assignmentId) disabledReason = 'Choose an employee.'
  else if (datesInvalid) disabledReason = 'The due date must be on or after the start date.'
  else if (hours !== null && !(hours > 0)) disabledReason = 'The estimated hours must be more than 0, or left empty.'

  async function submit(event) {
    event.preventDefault()
    if (saving || disabledReason) return
    const form = new FormData(event.currentTarget)
    setSaving(true)
    setError(null)
    try {
      if (editing) {
        // Only what changed is sent. Dates are never sent by an agency; a new estimate goes back to our team for review.
        const payload = { title: values.title.trim(), description: values.description.trim() }
        if (hours !== null && hours !== Number(project.estimated_hours)) payload.estimated_hours = hours
        if (canSchedule && values.start_date !== project.start_date) payload.start_date = values.start_date
        if (canSchedule && values.due_date !== project.due_date) payload.due_date = values.due_date
        if (!planned && Number(assignmentId) !== Number(project.assignment_id)) payload.assignment_id = Number(assignmentId)
        const cleaned = cleanDetails(type, details, 'project')
        if (serviceType !== (project.service_type ?? null)) payload.service_type = serviceType
        if (JSON.stringify(cleaned) !== JSON.stringify(project.details ?? null)) payload.details = cleaned
        const { project: saved } = await authService.updateAgencyProject(project.id, payload)
        onSaved(saved, { created: false })
      } else {
        const body = {
          title: values.title.trim(), description: values.description.trim(), assignment_id: Number(assignmentId),
          service_type: serviceType, details: cleanDetails(type, details, 'project'), ...readProjectBrief(form),
        }
        if (hours !== null) body.estimated_hours = hours
        const ghl = ghlBrief.payload()
        if (Object.keys(ghl).length) body.ghl_brief = ghl
        if (canSchedule) Object.assign(body, { start_date: values.start_date, due_date: values.due_date })
        const { project: saved } = await authService.createAgencyProject(body)
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

  return (
    <Modal open onClose={() => !saving && onClose()} title={editing ? 'Edit project' : 'Create project'} className="client-modal">
      <form id={FORM_ID} className="ui-form" onSubmit={submit} noValidate>
        {!assignmentsLoading && assignments.length === 0 && (
          <Alert variant="info" className="ui-form-callout">You need an employee on your agency before creating a project. <Link to="/agency/employees">Go to Employees</Link>.</Alert>
        )}
        <FormSection title="What do you need?" description="Pick a service and answer a few questions. The title and description below are written for you.">
          <ServiceFields catalog={catalog} scope="project" serviceType={serviceType} details={details} onChange={changeService} errors={serviceError} />
        </FormSection>
        <FormSection title="Basics" description={serviceType ? 'Written from your answers. Change them if you like.' : undefined}>
          <Input id="project-title" label="Project title" required maxLength={160} value={values.title} onChange={set('title')} error={fieldError('title')} />
          <Textarea id="project-description" label="Description" rows={4} maxLength={5000} required value={values.description} onChange={set('description')} error={fieldError('description')} />
        </FormSection>
        <FormSection title="Estimate">
          <Input id="project-hours" label="Estimated hours (optional)" type="number" inputMode="decimal" min="0.01" max="180" step="0.01" value={values.estimated_hours} onChange={set('estimated_hours')} error={fieldError('estimated_hours')} helperText="Our team will confirm the final schedule." />
        </FormSection>
        {canSchedule && <ScheduleFields values={values} onChange={set} errors={{ start_date: fieldError('start_date'), due_date: fieldError('due_date') }} min={editing ? undefined : today} />}
        <FormSection title="Preferred employee">
          <Select id="project-assignment" label="Employee" required value={assignmentId} disabled={assignmentsLoading || assignments.length === 0 || planned} onChange={(event) => { setAssignmentId(event.target.value); setError(null) }} error={fieldError('assignment_id')} helperText={planned ? 'Our team has planned this project. Ask us if the employee needs to change.' : 'A preference. Our team can change it when planning the schedule.'}>
            <option value="">{assignmentsLoading ? 'Loading employees...' : 'Choose an employee'}</option>
            {assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>)}
          </Select>
        </FormSection>
        {!editing && (
          <FormSection title="Brief, notes and files" description="Optional. Anything that helps your employee get started.">
            <ProjectBriefFields errors={error?.errors} />
            <FilePicker files={files} onChange={setFiles} notice={fileNotice} onNotice={setFileNotice} disabled={saving} />
          </FormSection>
        )}
        {!editing && (
          <FormSection title="GoHighLevel brief" description="Optional. Tell us about the account, the scope and your brand once, and every ticket starts from it. You can add or change it later.">
            <BriefForm brief={ghlBrief} errors={error?.errors} idPrefix="new-brief" />
          </FormSection>
        )}
        {!editing && onRequestUrgent && (
          <p className="ui-field-help">
            Cannot wait in the queue?{' '}
            <button type="button" className="ui-link-button" onClick={() => onRequestUrgent({ assignmentId, defaults: { title: values.title.trim(), description: values.description.trim(), estimated_hours: hours > 0 ? hours : '' } })}>Request urgent help</button>
            {' '}for a price quote. Nothing is charged until your account owner accepts it.
          </p>
        )}
        {error && error.status !== 422 && <ApiErrorMessage error={error} />}
        {error?.status === 422 && error.message && !error.errors && <Alert variant="error">{error.message}</Alert>}
        <FormActions submitLabel={editing ? 'Save changes' : 'Create project'} loadingLabel="Saving..." loading={saving} disabled={Boolean(disabledReason)} disabledReason={disabledReason} onCancel={onClose} />
      </form>
    </Modal>
  )
}
