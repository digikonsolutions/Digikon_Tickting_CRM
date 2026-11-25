import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, CopyButton, FormActions, FormSection, Input, LifecyclePriorityBadge, PageHeader, Select, Skeleton, Textarea } from '../../../components/ui'
import { FilePicker, uploadErrorMessage } from '../../briefs'
import '../support.css'

const DRAFT_KEY = 'support-ticket-draft'
const readDraft = () => { try { return JSON.parse(window.localStorage.getItem(DRAFT_KEY) ?? 'null') } catch { return null } }
const writeDraft = (value) => { try { window.localStorage.setItem(DRAFT_KEY, JSON.stringify(value)) } catch { /* storage blocked: the form still works */ } }
const clearDraft = () => { try { window.localStorage.removeItem(DRAFT_KEY) } catch { /* nothing to clear */ } }
const BLANK = { type: '', title: '', description: '', expected_result: '', service: '', end_client_id: '', related_project_id: '', requested_deadline: '', details: {}, impact: { live_broken: false, revenue_affected: false, workaround: false }, priority: '', links: [] }

// One page, no wizard: choose what you need, answer the questions that type needs, add evidence, review and send.
export function AgencySupportNewPage() {
  const loadForm = useCallback(() => authService.getSupportForm(), [])
  const form = useAsync(loadForm)
  const [params] = useSearchParams()
  // A choice on the help page arrives with its type; it wins over an old saved draft's type.
  const [values, setValues] = useState(() => ({ ...BLANK, ...(readDraft() ?? {}), ...(params.get('type') ? { type: params.get('type'), details: {} } : {}) }))
  const [files, setFiles] = useState([])
  const [fileNotice, setFileNotice] = useState(null)
  const [link, setLink] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState(null)
  const [hint, setHint] = useState(null)
  const [secret, setSecret] = useState(null)
  const [similar, setSimilar] = useState([])
  const dirty = useRef(false)

  const type = (form.data?.types ?? []).find((item) => item.key === values.type)
  const set = (patch) => { dirty.current = true; setValues((current) => ({ ...current, ...patch })); setError(null) }
  const setDetail = (key, value) => set({ details: { ...values.details, [key]: value } })
  const fieldError = (name) => error?.errors?.[name]?.[0]

  // Draft autosave and a guard against losing the form by accident.
  useEffect(() => { if (dirty.current) writeDraft(values) }, [values])
  useEffect(() => {
    const guard = (event) => { if (dirty.current && !done) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', guard)
    return () => window.removeEventListener('beforeunload', guard)
  }, [done])

  // The suggested priority from the impact answers, and a scan of what is typed for passwords or keys.
  useEffect(() => {
    if (!values.type) return undefined
    let active = true
    authService.suggestSupportPriority({ type: values.type, impact: values.impact }).then((result) => { if (active) setHint(result) }).catch(() => {})
    return () => { active = false }
  }, [values.type, values.impact])
  useEffect(() => {
    const text = `${values.description}\n${Object.values(values.details).join('\n')}`
    if (text.trim().length < 8) return undefined
    let active = true
    const timer = window.setTimeout(() => authService.scanSupportText(text).then((result) => { if (active) setSecret(result.found?.length ? result.message : null) }).catch(() => {}), 600)
    return () => { active = false; window.clearTimeout(timer) }
  }, [values.description, values.details])
  // Similar open tickets while the subject is typed.
  useEffect(() => {
    if (values.title.trim().length < 4) return undefined
    let active = true
    const timer = window.setTimeout(() => authService.listSupportTickets({ tab: 'open', q: values.title.trim(), per_page: 4 }).then((result) => { if (active) setSimilar(result.tickets) }).catch(() => {}), 500)
    return () => { active = false; window.clearTimeout(timer) }
  }, [values.title])

  const priority = values.priority || hint?.priority || 'normal'
  const projects = useMemo(() => form.data?.projects ?? [], [form.data])

  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    const body = {
      type: values.type, title: values.title.trim(), description: values.description.trim(), expected_result: values.expected_result.trim(), details: values.details, impact: values.impact,
      ...(values.service ? { service: values.service } : {}), ...(values.end_client_id ? { end_client_id: Number(values.end_client_id) } : {}),
      ...(values.related_project_id ? { related_project_id: Number(values.related_project_id) } : {}), ...(values.requested_deadline ? { requested_deadline: values.requested_deadline } : {}),
      ...(values.priority ? { priority: values.priority } : {}), ...(values.type === 'critical_incident' ? { confirm_urgent: true } : {}),
    }
    try {
      const result = await authService.createSupportTicket(body)
      const notes = []
      for (const url of [...values.links, ...(link.trim() ? [link.trim()] : [])]) {
        try { await authService.addTicketLink(result.ticket.id, { url }) } catch { notes.push('A link could not be added. You can add it on the ticket.') }
      }
      if (files.length) {
        try { await authService.uploadTicketAttachments(result.ticket.id, { files }) } catch (reason) { notes.push(`Files were not uploaded: ${uploadErrorMessage(reason)}`) }
      }
      clearDraft()
      dirty.current = false
      setDone({ ticket: result.ticket, notes, warnings: result.warnings })
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  if (done) {
    return (
      <section className="page">
        <div className="sup-done" role="status">
          <h1>Ticket received</h1>
          <p className="sup-ref">{done.ticket.ref} <CopyButton value={done.ticket.ref} label="Copy ID" /></p>
          <h2>What happens next</h2>
          <ol className="sup-steps"><li>1. Received</li><li>2. We review it</li><li>3. Someone is assigned</li></ol>
          <p>We aim to respond soon. You will see the status on the ticket and get a notification when something changes.</p>
          {done.warnings?.secret && <Alert variant="warning">{done.warnings.secret}</Alert>}
          {done.notes.map((note) => <Alert key={note} variant="warning">{note}</Alert>)}
          <div><Link className="ui-button ui-button-primary" to={`/agency/support/${done.ticket.ref}`}>View ticket</Link> <Button type="button" variant="secondary" onClick={() => { setValues(BLANK); setFiles([]); setLink(''); setDone(null) }}>Create another</Button></div>
        </div>
      </section>
    )
  }

  return (
    <section className="page">
      <PageHeader eyebrow="Support" title="New ticket" subtitle="Tell us what you need. The more we know now, the fewer questions we have to ask later." />
      {form.isLoading && !form.data ? <Skeleton variant="card" /> : (
        <form className="sup-new ui-form" onSubmit={submit} noValidate onChange={() => { dirty.current = true }}>
          <div className="ui-form">
            <FormSection title="1. What do you need?">
              <fieldset className="sup-types" aria-describedby="type-error">
                <legend className="ui-field-label">Choose one</legend>
                {(form.data?.types ?? []).map((item) => (
                  <label className="sup-type-card" key={item.key}>
                    <input type="radio" name="type" value={item.key} checked={values.type === item.key} onChange={() => set({ type: item.key, details: {} })} />
                    <strong>{item.label}</strong><span>{item.example}</span>
                  </label>
                ))}
              </fieldset>
              {fieldError('type') && <p className="ui-field-error" id="type-error" role="alert">{fieldError('type')}</p>}
            </FormSection>

            {type && (
              <>
                <FormSection title="2. Details">
                  {values.type === 'change_request' && <Alert variant="info">This may need an estimate and your approval before work starts.</Alert>}
                  {values.type === 'account_access' && <Alert variant="warning">Never paste passwords or API keys here. We will arrange a secure way to share access.</Alert>}
                  {values.type === 'critical_incident' && <Alert variant="error">Critical incidents go straight to a manager. Only use this when something important is down right now.</Alert>}
                  <Select label="End client" value={values.end_client_id} onChange={(event) => set({ end_client_id: event.target.value })} error={fieldError('end_client_id')} helperText="Optional. Which of your customers is this about?">
                    <option value="">None</option>{(form.data.end_clients ?? []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                  </Select>
                  <Select label="Related project" value={values.related_project_id} onChange={(event) => set({ related_project_id: event.target.value })} error={fieldError('related_project_id')}>
                    <option value="">None</option>{projects.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                  </Select>
                  <Select label="Service" value={values.service} onChange={(event) => set({ service: event.target.value })} error={fieldError('service')}>
                    <option value="">Not sure</option>{(form.data.services ?? []).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
                  </Select>
                  <Input label="Subject" required maxLength={160} value={values.title} onChange={(event) => set({ title: event.target.value })} error={fieldError('title')} />
                  <Textarea label="Description" required rows={5} maxLength={10000} value={values.description} onChange={(event) => set({ description: event.target.value })} error={fieldError('description')} />
                  <Textarea label="Expected result" required rows={3} maxLength={5000} value={values.expected_result} onChange={(event) => set({ expected_result: event.target.value })} error={fieldError('expected_result')} helperText="What should happen when this is done or fixed?" />
                  {type.fields.map((field) => (field.input === 'textarea'
                    ? <Textarea key={field.key} label={field.label} required={field.required} rows={3} maxLength={5000} value={values.details[field.key] ?? ''} onChange={(event) => setDetail(field.key, event.target.value)} error={fieldError(`details.${field.key}`)} />
                    : <Input key={field.key} label={field.label} required={field.required} maxLength={5000} value={values.details[field.key] ?? ''} onChange={(event) => setDetail(field.key, event.target.value)} error={fieldError(`details.${field.key}`)} />))}
                  {secret && <p className="sup-secret" role="alert">{secret}</p>}
                </FormSection>

                <FormSection title="How urgent is this?" description="Your answers suggest a priority. You can change it.">
                  {[['live_broken', 'Is something broken for live customers right now?'], ['revenue_affected', 'Is revenue or lead capture affected?'], ['workaround', 'Is there a workaround?']].map(([key, label]) => (
                    <label className="ui-checkgroup-option" key={key}><input type="checkbox" checked={values.impact[key]} onChange={(event) => set({ impact: { ...values.impact, [key]: event.target.checked } })} /><span>{label}</span></label>
                  ))}
                  <p>Suggested priority: <LifecyclePriorityBadge priority={priority} label={hint?.label} /></p>
                  {values.type !== 'critical_incident' && (
                    <Select label="Change priority" value={values.priority} onChange={(event) => set({ priority: event.target.value })} error={fieldError('priority')}>
                      <option value="">Use the suggestion</option><option value="low">P4 Low</option><option value="normal">P3 Normal</option><option value="high">P2 High</option>
                    </Select>
                  )}
                  <Input label="Requested deadline" type="date" value={values.requested_deadline} onChange={(event) => set({ requested_deadline: event.target.value })} error={fieldError('requested_deadline')} helperText="Optional. A deadline helps us plan but is not guaranteed." />
                </FormSection>

                <FormSection title="Evidence" description="Screenshots and recordings help most. Do not show passwords or customer data.">
                  <FilePicker files={files} onChange={setFiles} notice={fileNotice} onNotice={setFileNotice} disabled={saving} />
                  <Input label="Add a Loom or other link" type="url" inputMode="url" placeholder="https://" value={link} onChange={(event) => setLink(event.target.value)} />
                </FormSection>

                <ApiErrorMessage error={error && !error.errors ? error : null} />
                {error?.errors && <Alert variant="error">Some answers need attention. They are marked above.</Alert>}
                <FormActions submitLabel="Submit ticket" loadingLabel="Sending…" loading={saving} disabled={!values.title.trim() || !values.description.trim()} disabledReason="Add a subject and a description.">
                  <Button type="button" variant="ghost" onClick={() => { writeDraft(values); dirty.current = false }}>Save draft</Button>
                </FormActions>
              </>
            )}
          </div>
          <aside className="sup-rail" aria-label="Help">
            <div className="sup-banner"><strong>Similar open tickets</strong>
              {similar.length === 0 ? <span className="ui-field-help">Start typing a subject and we will check.</span> : <ul>{similar.map((item) => <li key={item.id}><Link to={`/agency/support/${item.ref}`}>{item.ref}: {item.title}</Link></li>)}</ul>}
            </div>
            <div className="sup-banner"><strong>Good to know</strong><ul><li>Include steps, links and examples.</li><li>One problem per ticket.</li><li>Never share a password or API key.</li></ul></div>
          </aside>
        </form>
      )}
    </section>
  )
}
