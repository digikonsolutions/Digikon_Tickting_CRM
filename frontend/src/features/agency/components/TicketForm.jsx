import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, FieldRow, FormActions, FormSection, Input, Modal, Select, Textarea } from '../../../components/ui'
import { BRIEF_LIMITS, FilePicker, readTicketBrief, TicketBriefFields, uploadErrorMessage } from '../../briefs'
import { PRIORITIES } from '../../briefs/briefData'
import { ServiceFields } from '../../services/ServiceFields'
import { buildFromAnswers, cleanDetails, typeOf } from '../../services/serviceCatalog'
import { WorkOrderFields, WorkTypeField } from '../../workorder/WorkOrderFields'
import { useTicketDetails } from '../../workorder/useTicketDetails'
import '../../services/services.css'

const FORM_ID = 'ticket-form'

// Create or edit a ticket on one project. The core fields (title, hours, priority, work type) sit on top; the rest of
// the work order is in collapsible sections below, defaulted from the project's brief. Hours and priority go to the
// planner exactly as before; the work order never does.
export function TicketForm({ project, tickets = [], ticket, onClose, onSaved }) {
  const editing = Boolean(ticket)
  const loadCatalog = useCallback(() => authService.getServiceCatalog(), [])
  const catalog = useAsync(loadCatalog).data
  const [service, setService] = useState({ serviceType: ticket?.service_type ?? null, details: ticket?.details ?? {} })
  const [text, setText] = useState({ title: ticket?.title ?? '', description: ticket?.description ?? '' })
  const [touched, setTouched] = useState({ title: editing, description: editing })
  const [files, setFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const details = useTicketDetails({ ticket, location: project?.ghl_brief?.ghl?.location_id ?? '' })
  const ticketType = typeOf(catalog, service.serviceType)

  useEffect(() => {
    if (error?.errors) document.querySelector(`#${FORM_ID} [aria-invalid="true"]`)?.focus()
  }, [error])

  function changeService(next) {
    setService(next)
    setError(null)
    if (!next.serviceType) return
    const written = buildFromAnswers(typeOf(catalog, next.serviceType), next.details, 'ticket')
    setText((current) => ({ title: touched.title ? current.title : written.title, description: touched.description ? current.description : written.description }))
  }
  const serviceError = (key) => error?.errors?.[key === 'service_type' ? key : `details.${key}`]?.[0]

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    const formData = new FormData(event.currentTarget)
    const payload = {
      title: text.title.trim(),
      description: text.description.trim(),
      estimated_hours: Number(formData.get('estimated_hours')),
      priority: String(formData.get('priority')),
      ...readTicketBrief(formData),
    }
    const cleaned = cleanDetails(ticketType, service.details, 'ticket')
    if (!editing || service.serviceType !== (ticket.service_type ?? null)) payload.service_type = service.serviceType
    if (!editing || JSON.stringify(cleaned) !== JSON.stringify(ticket.details ?? null)) payload.details = cleaned
    const order = details.payload({ replaceAll: details.hadOrder })
    if (Object.keys(order).length) payload.work_order = order
    setSaving(true)
    setError(null)
    try {
      const result = editing ? await authService.updateTicket(ticket.id, payload) : await authService.createProjectTicket(project.id, payload)
      let uploadNotice = null
      if (!editing && files.length) {
        try { await authService.uploadTicketAttachments(result.ticket.id, { files }) } catch (reason) { uploadNotice = uploadErrorMessage(reason) }
      }
      onSaved(result, { created: !editing, uploadNotice })
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  const others = tickets.filter((item) => item.id !== ticket?.id && item.status !== 'cancelled')
  return (
    <Modal open onClose={() => !saving && onClose()} title={editing ? 'Edit ticket' : 'Create ticket'} className="client-modal tk-modal" footer={null}>
      <form id={FORM_ID} className="ui-form" onSubmit={submit} noValidate>
        <FormSection title="What is this about?" description="Optional. Pick a service to get a few quick questions; the title and description are written for you.">
          <ServiceFields catalog={catalog} scope="ticket" serviceType={service.serviceType} details={service.details} onChange={changeService} errors={serviceError} />
        </FormSection>
        <FormSection title="Basics">
          <Input name="title" label="Title" maxLength="160" required value={text.title} onChange={(event) => { setText({ ...text, title: event.target.value }); setTouched({ ...touched, title: true }) }} error={error?.errors?.title?.[0]} />
          <Textarea name="description" label="Description" rows="4" required value={text.description} onChange={(event) => { setText({ ...text, description: event.target.value }); setTouched({ ...touched, description: true }) }} error={error?.errors?.description?.[0]} />
        </FormSection>
        <FormSection title="Planning" description="Dates are planned from the project schedule, in priority order. The work order below never changes them.">
          <FieldRow>
            <Input name="estimated_hours" label="Estimated hours" type="number" inputMode="decimal" min="0.01" step="0.01" defaultValue={ticket?.estimated_hours ?? ''} required error={error?.errors?.estimated_hours?.[0]} />
            <Select name="priority" label="Priority" defaultValue={ticket?.priority ?? 'normal'} error={error?.errors?.priority?.[0]}>
              {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority}</option>)}
            </Select>
          </FieldRow>
          <WorkTypeField details={details} errors={error?.errors} disabled={saving} />
        </FormSection>
        <FormSection title="Work order" description="Optional. The more the team knows up front, the less they need to ask you.">
          <WorkOrderFields details={details} errors={error?.errors} siblings={others} brief={project?.ghl_brief} disabled={saving} />
        </FormSection>
        <FormSection title="Details and files" description="Optional. Helpful for bug reports and change requests.">
          <TicketBriefFields key={ticket?.id ?? 'new'} errors={error?.errors} defaults={ticket?.brief} otherTickets={others} />
          {!editing && <FilePicker files={files} onChange={setFiles} notice={fileNotice} onNotice={setFileNotice} disabled={saving} limits={{ ...BRIEF_LIMITS, extensions: BRIEF_LIMITS.extensions.filter((extension) => extension !== 'zip') }} />}
        </FormSection>
        {error && error.status !== 422 && <ApiErrorMessage error={error} />}
        {error?.status === 422 && error.message && !error.errors && <Alert variant="error">{error.message}</Alert>}
        <FormActions submitLabel={editing ? 'Save changes' : 'Create ticket'} loadingLabel="Saving…" loading={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
