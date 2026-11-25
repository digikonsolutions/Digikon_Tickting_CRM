import { useCallback, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Card, FieldRow, FormActions, FormSection, Input, PageHeader, Select, Skeleton, Textarea, useToast } from '../../../components/ui'
import { FilePicker, uploadErrorMessage } from '../../briefs'
import { BRIEF_SECTIONS } from '../../workorder/briefSchema'
import { FieldInput } from '../../workorder/FieldInput'
import { compact } from '../../workorder/objectPath'
import { cleanDetails, missingRequired } from '../../services/serviceCatalog'
import { ServicePanel } from '../components/projectCreate/ServicePanel'
import { ServicePicker } from '../components/projectCreate/ServicePicker'
import '../components/projectCreate/projectCreate.css'

const GHL_FIELDS = ['location_id', 'access_method', 'plan_tier', 'white_label_domain', 'build_type', 'credentials_shared_via']
const ghlSection = BRIEF_SECTIONS.find((section) => section.key === 'ghl')
const scopeSection = BRIEF_SECTIONS.find((section) => section.key === 'scope')

// Creating a project: say what you want (the services), answer what each one needs, tell us about the account, send.
// One page, no wizard: the summary on the side always shows what will be sent.
export function AgencyProjectCreatePage() {
  const navigate = useNavigate()
  const { showToast } = useToast()
  const catalog = useAsync(useCallback(() => authService.getServiceCatalog(), [])).data
  const assignmentsState = useAsync(useCallback(() => authService.listMyAssignments(), []))
  const assignments = assignmentsState.data?.assignments ?? []
  const [basics, setBasics] = useState({ title: '', description: '', priority: 'normal', launch_date: '', deadline_flexibility: '', assignment_id: '' })
  const [selected, setSelected] = useState([]) // service keys, in the order they were added
  const [answers, setAnswers] = useState({}) // key -> { details, estimated_hours }
  const [ghl, setGhl] = useState({})
  const [totalHours, setTotalHours] = useState('')
  const [files, setFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  // With a single employee there is nothing to choose, so that one is used until the person picks another.
  const assignmentId = basics.assignment_id || (assignments.length === 1 ? String(assignments[0].id) : '')

  const typeOf = useCallback((key) => (catalog?.types ?? []).find((type) => type.key === key), [catalog])
  const toggle = (key) => setSelected((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]))
  const addPackage = (pkg) => setSelected((current) => [...new Set([...current, ...pkg.services])])
  const setAnswer = (key) => (next) => setAnswers((current) => ({ ...current, [key]: next }))
  const errors = error?.errors ?? {}
  const fieldError = (name) => errors[name]?.[0]

  const guessed = selected.reduce((sum, key) => sum + (Number(answers[key]?.estimated_hours) || 0), 0)
  const hours = totalHours !== '' ? Number(totalHours) : guessed
  const missing = useMemo(() => selected.flatMap((key) => {
    const type = typeOf(key)
    return type ? missingRequired(type, answers[key]?.details ?? {}, 'project').map((label) => `${type.label}: ${label}`) : []
  }), [selected, answers, typeOf])
  const suggestedTitle = selected.length ? `${typeOf(selected[0])?.label ?? 'GoHighLevel'}${selected.length > 1 ? ` and ${selected.length - 1} more` : ''}` : ''

  let blocked = null
  if (!assignmentsState.isLoading && assignments.length === 0) blocked = 'You need an employee on your agency first.'
  else if (selected.length === 0) blocked = 'Choose at least one service.'
  else if (!basics.description.trim()) blocked = 'Tell us what you want to achieve.'
  else if (!assignmentId) blocked = 'Choose an employee.'
  else if (missing.length > 0) blocked = `Answer: ${missing[0]}.`

  async function submit(event) {
    event.preventDefault()
    if (saving || blocked) return
    setSaving(true)
    setError(null)
    const services = selected.map((key) => {
      const value = answers[key] ?? {}
      return { key, details: cleanDetails(typeOf(key), value.details ?? {}, 'project'), ...(value.estimated_hours ? { estimated_hours: Number(value.estimated_hours) } : {}) }
    })
    const brief = compact({ ghl, scope: { launch_date: basics.launch_date, deadline_flexibility: basics.deadline_flexibility, goal: basics.description.trim() } })
    const body = {
      title: basics.title.trim() || suggestedTitle, description: basics.description.trim(), assignment_id: Number(assignmentId), brief_priority: basics.priority,
      services, ...(hours > 0 ? { estimated_hours: hours } : {}), ...(Object.keys(brief).length ? { ghl_brief: brief } : {}),
    }
    try {
      const { project } = await authService.createAgencyProject(body)
      if (files.length) {
        try { await authService.uploadProjectAttachments(project.id, files) } catch (reason) { showToast(`Project created, but the files were not uploaded: ${uploadErrorMessage(reason)}`, 'error') }
      }
      showToast('Project request sent.')
      navigate(`/agency/projects/${project.id}`, { replace: true })
    } catch (reason) {
      setError(reason)
      window.setTimeout(() => document.querySelector('[aria-invalid="true"], .ui-field-error')?.scrollIntoView({ block: 'center' }), 50)
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="page">
      <PageHeader eyebrow={<Link to="/agency/projects">Projects</Link>} title="New project" subtitle="Tell us what you need built in GoHighLevel. Choose the services, answer a few questions for each, and we plan the rest." />
      {!catalog ? <Skeleton variant="card" /> : (
        <form className="pc-layout" onSubmit={submit} noValidate>
          <div className="pc-main ui-form">
            <FormSection title="1. What do you want to achieve?" description="In your own words. This becomes the project description.">
              <Textarea id="pc-description" label="Goal" required rows={4} maxLength={5000} value={basics.description} onChange={(event) => setBasics({ ...basics, description: event.target.value })} error={fieldError('description')} helperText="For example: get more booked appointments from our Facebook ads and follow up with every lead." />
              <Input id="pc-title" label="Project name (optional)" maxLength={160} value={basics.title} placeholder={suggestedTitle || 'We will name it from your services'} onChange={(event) => setBasics({ ...basics, title: event.target.value })} error={fieldError('title')} />
              <FieldRow>
                <Select label="Priority" value={basics.priority} onChange={(event) => setBasics({ ...basics, priority: event.target.value })} error={fieldError('brief_priority')}>
                  <option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option>
                </Select>
                <Input label="Wanted by (optional)" type="date" value={basics.launch_date} onChange={(event) => setBasics({ ...basics, launch_date: event.target.value })} error={fieldError('ghl_brief.scope.launch_date')} />
                <Select label="How firm is that date?" value={basics.deadline_flexibility} onChange={(event) => setBasics({ ...basics, deadline_flexibility: event.target.value })}>
                  {scopeSection.fields.find((field) => field.path === 'deadline_flexibility').options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  <option value="">Not sure</option>
                </Select>
              </FieldRow>
              <Select id="pc-employee" label="Employee" required value={assignmentId} disabled={assignmentsState.isLoading || assignments.length === 0} onChange={(event) => setBasics({ ...basics, assignment_id: event.target.value })} error={fieldError('assignment_id')} helperText="A preference. Our team can change it when planning the schedule.">
                <option value="">{assignmentsState.isLoading ? 'Loading employees…' : 'Choose an employee'}</option>
                {assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee.name}</option>)}
              </Select>
            </FormSection>

            <FormSection title="2. Which services do you need?" description="Pick everything you want in this project. You can combine as many as you like.">
              <ServicePicker catalog={catalog} selected={selected} onToggle={toggle} onPackage={addPackage} />
              {fieldError('services') && <p className="ui-field-error" role="alert">{fieldError('services')}</p>}
            </FormSection>

            {selected.length > 0 && (
              <FormSection title="3. Details for each service" description="Only a few questions each. Anything marked with a star is needed to start.">
                {selected.map((key, index) => {
                  const type = typeOf(key)
                  return type ? <ServicePanel key={key} type={type} index={index} value={answers[key] ?? { details: {} }} onChange={setAnswer(key)} onRemove={() => toggle(key)} errors={errors} /> : null
                })}
              </FormSection>
            )}

            <FormSection title="4. Your GoHighLevel account" description="Optional now, but it saves us a round of questions. Never type a password or API key.">
              <div className="pc-ghl">
                {GHL_FIELDS.map((path) => {
                  const field = ghlSection.fields.find((item) => item.path === path)
                  return <FieldInput key={path} field={field} value={ghl[path]} onChange={(next) => setGhl({ ...ghl, [path]: next })} errors={errors} errorPrefix="ghl_brief.ghl" idPrefix="pc-ghl" />
                })}
              </div>
            </FormSection>

            <FormSection title="5. Files and links" description="Logos, brand guides, examples, exports. Anything that helps.">
              <FilePicker files={files} onChange={setFiles} notice={fileNotice} onNotice={setFileNotice} disabled={saving} />
            </FormSection>

            <ApiErrorMessage error={error && !error.errors ? error : null} />
            {error?.errors && <Alert variant="error">Some answers need attention. They are marked above.</Alert>}
            <FormActions submitLabel="Send project request" loadingLabel="Sending…" loading={saving} disabled={Boolean(blocked)} disabledReason={blocked} onCancel={() => navigate('/agency/projects')} />
          </div>

          <aside className="pc-summary" aria-label="Summary of your request">
            <Card title="Your request">
              {selected.length === 0 ? <p className="ui-field-help">No services chosen yet.</p> : (
                <ul className="pc-summary-list">
                  {selected.map((key) => {
                    const type = typeOf(key)
                    const stillMissing = missing.filter((item) => item.startsWith(`${type?.label}:`)).length
                    return <li key={key}><span>{type?.label}</span>{stillMissing > 0 ? <Badge variant="warning">{stillMissing} to answer</Badge> : <Badge variant="success">Ready</Badge>}</li>
                  })}
                </ul>
              )}
              <Input label="Total hours (optional)" type="number" min="0.25" step="0.25" inputMode="decimal" value={totalHours} placeholder={guessed ? String(guessed) : ''} onChange={(event) => setTotalHours(event.target.value)} error={fieldError('estimated_hours')}
                helperText={guessed ? `Your service estimates add up to ${guessed} hours. Leave this empty to use that.` : 'Leave empty if you are not sure. Our team confirms the final schedule.'} />
              <div className="pc-next"><strong>What happens next</strong><ol><li>We read your request.</li><li>We plan the days and confirm the hours with you.</li><li>Work starts and you follow it ticket by ticket.</li></ol></div>
            </Card>
          </aside>
        </form>
      )}
    </section>
  )
}
