import { useCallback, useEffect, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FieldRow, FormActions, FormSection, Input, Modal, Select, Textarea } from '../../../components/ui'
import { FilePicker } from '../../briefs/components/FilePicker'
import { PRIORITIES, uploadErrorMessage } from '../../briefs/briefData'
import { ServiceFields } from '../../services/ServiceFields'
import { buildFromAnswers, cleanDetails, typeOf } from '../../services/serviceCatalog'
import '../../services/services.css'

const FORM_ID = 'ticket-form'
const hours = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`

// Create a ticket on an open project, or edit one. The remaining hours of the chosen project are shown next to the
// estimate; the server still enforces the cap.
export function TicketFormModal({ ticket, projectId: initialProject, onClose, onSaved }) {
  const editing = Boolean(ticket)
  const [projectId, setProjectId] = useState(String(ticket?.work_item_id ?? initialProject ?? ''))
  const [values, setValues] = useState({ title: ticket?.title ?? '', description: ticket?.description ?? '', estimated_hours: ticket?.estimated_hours ?? '', priority: ticket?.priority ?? 'normal' })
  // Same idea as a project, with fewer questions: the answers write the title and description until they are edited.
  const [serviceType, setServiceType] = useState(ticket?.service_type ?? null)
  const [details, setDetails] = useState(ticket?.details ?? {})
  const [touched, setTouched] = useState({ title: editing, description: editing })
  const [files, setFiles] = useState([])
  const [notice, setNotice] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const first = useRef(null)

  const loadCatalog = useCallback(() => authService.getServiceCatalog(), [])
  const catalog = useAsync(loadCatalog).data
  const loadProjects = useCallback(() => authService.listAgencyProjects({ status: 'open', perPage: 100 }), [])
  const projects = useAsync(loadProjects)
  const loadTickets = useCallback(() => (projectId ? authService.listProjectTickets(projectId) : Promise.resolve({ tickets: [] })), [projectId])
  const siblings = useAsync(loadTickets)

  useEffect(() => {
    const timer = window.setTimeout(() => (first.current ?? document.getElementById('ticket-title'))?.focus(), 0)
    return () => window.clearTimeout(timer)
  }, [])
  useEffect(() => {
    if (error?.errors) document.querySelector(`#${FORM_ID} [aria-invalid="true"]`)?.focus()
  }, [error])

  const project = (projects.data?.projects ?? []).find((item) => String(item.id) === projectId)
  const used = (siblings.data?.tickets ?? []).filter((item) => item.status !== 'cancelled' && item.id !== ticket?.id).reduce((sum, item) => sum + Number(item.estimated_hours), 0)
  const remaining = project ? Number(project.estimated_hours) - used : null
  const typed = Number(values.estimated_hours)
  const over = remaining !== null && typed > remaining + 1e-9
  const set = (field) => (event) => {
    setValues((current) => ({ ...current, [field]: event.target.value }))
    if (field === 'title' || field === 'description') setTouched((current) => ({ ...current, [field]: true }))
  }
  const type = typeOf(catalog, serviceType)
  function changeService(next) {
    setServiceType(next.serviceType)
    setDetails(next.details)
    setError(null)
    if (!next.serviceType) return
    const written = buildFromAnswers(typeOf(catalog, next.serviceType), next.details, 'ticket')
    setValues((current) => ({ ...current, ...(touched.title ? {} : { title: written.title }), ...(touched.description ? {} : { description: written.description }) }))
  }
  const serviceError = (key) => {
    const errors = error?.errors ?? {}
    const name = key === 'service_type' ? key : `details.${key}`
    return errors[name]?.[0] ?? Object.entries(errors).find(([field]) => field.startsWith(`.`))?.[1]?.[0]
  }
  const fieldError = (field) => error?.errors?.[field]?.[0]

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    const payload = { title: values.title.trim(), description: values.description.trim(), estimated_hours: values.estimated_hours === '' ? '' : Number(values.estimated_hours), priority: values.priority }
    const cleaned = cleanDetails(type, details, 'ticket')
    if (!editing || serviceType !== (ticket.service_type ?? null)) payload.service_type = serviceType
    if (!editing || JSON.stringify(cleaned) !== JSON.stringify(ticket.details ?? null)) payload.details = cleaned
    try {
      const { ticket: saved } = editing ? await authService.updateTicket(ticket.id, payload) : await authService.createProjectTicket(projectId, payload)
      let uploadNotice = null
      if (files.length > 0) {
        try { await authService.uploadTicketAttachments(saved.id, { files }) } catch (reason) { uploadNotice = uploadErrorMessage(reason) }
      }
      onSaved(saved, { created: !editing, uploadNotice })
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  const noProjects = !projects.isLoading && (projects.data?.projects ?? []).length === 0 && !editing

  return (
    <Modal open onClose={() => !saving && onClose()} title={editing ? 'Edit ticket' : 'Create ticket'} className="client-modal">
      <form id={FORM_ID} className="ui-form" onSubmit={submit} noValidate>
        {noProjects && <Alert variant="info">You need an open project before you can create a ticket.</Alert>}
        <FormSection title="What is this about?" description="Optional. Pick a service to get a few quick questions; the title and description are written for you.">
          <ServiceFields catalog={catalog} scope="ticket" serviceType={serviceType} details={details} onChange={changeService} errors={serviceError} />
        </FormSection>
        <FormSection title="Ticket">
          <Select id="ticket-project" label="Project" required value={projectId} disabled={editing || projects.isLoading} onChange={(event) => setProjectId(event.target.value)} error={fieldError('work_item_id')}>
            <option value="">{projects.isLoading ? 'Loading projects…' : 'Choose a project'}</option>
            {(projects.data?.projects ?? []).map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
            {editing && !project && <option value={projectId}>{ticket.project_title ?? 'Current project'}</option>}
          </Select>
          <Input ref={first} id="ticket-title" label="Title" required maxLength={160} value={values.title} onChange={set('title')} error={fieldError('title')} />
          <Textarea id="ticket-description" label="Description" required rows={4} value={values.description} onChange={set('description')} error={fieldError('description')} helperText="What should be done, and how will you know it is finished?" />
        </FormSection>
        <FormSection title="Planning">
          <FieldRow>
            <Input id="ticket-hours" label="Estimated hours" type="number" inputMode="decimal" min="0.01" step="0.01" required value={values.estimated_hours} onChange={set('estimated_hours')} error={fieldError('estimated_hours') ?? (over ? `Only ${hours(Math.max(0, remaining))} are left on this project.` : undefined)}
              helperText={remaining !== null ? `${hours(Math.max(0, remaining))} left of ${hours(project.estimated_hours)} on this project` : 'Choose a project to see its remaining hours.'} />
            <Select id="ticket-priority" label="Priority" value={values.priority} onChange={set('priority')} error={fieldError('priority')}>
              {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority[0].toUpperCase() + priority.slice(1)}</option>)}
            </Select>
          </FieldRow>
        </FormSection>
        {!editing && (
          <FormSection title="Attachments">
            <FilePicker files={files} onChange={setFiles} disabled={saving} notice={notice} onNotice={setNotice} />
          </FormSection>
        )}
        <ApiErrorMessage error={error && error.status !== 422 ? error : null} />
        <FormActions submitLabel={editing ? 'Save changes' : 'Create ticket'} loadingLabel="Saving…" loading={saving} disabled={noProjects || (!editing && !projectId)} disabledReason={noProjects ? 'Create a project first.' : 'Choose a project.'} onCancel={onClose} />
      </form>
    </Modal>
  )
}
