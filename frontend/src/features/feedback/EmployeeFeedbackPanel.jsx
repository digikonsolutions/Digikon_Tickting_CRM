import { useCallback, useState } from 'react'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { Alert, Button, Card, EmptyState, FormActions, Modal, Skeleton, Textarea, useToast } from '../../components/ui'
import { ApiErrorMessage } from '../auth/components/ApiErrorMessage'
import { FeedbackFormDrawer } from './FeedbackFormDrawer'
import { FeedbackList } from './FeedbackList'
import { FeedbackSummary } from './FeedbackSummary'
import './feedback.css'

// The agency's view of one employee's feedback: the averages, the history, and (owner only) the button to add more.
export function AgencyFeedbackPanel({ employeeId, employeeName }) {
  const { showToast } = useToast()
  const load = useCallback(() => Promise.all([authService.getEmployeeFeedback(employeeId), authService.getFeedbackForm()]).then(([list, form]) => ({ ...list, form })), [employeeId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [drawer, setDrawer] = useState(null) // { editing } while open

  if (error) return <Alert variant="error" title="Could not load feedback">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
  if (isLoading && !data) return <div role="status" aria-label="Loading feedback"><Skeleton variant="card" /><Skeleton variant="row" /></div>

  const saved = (_feedback, wasEdit) => {
    setDrawer(null)
    showToast(wasEdit ? 'Feedback updated.' : 'Thank you. The feedback was saved.')
    reload()
  }
  return (
    <div className="fb-panel">
      <div className="fb-panel-head">
        <div><h2>Feedback about {employeeName}</h2><p className="fb-muted">Only your agency and our team see this. {data.can_write ? 'You can edit feedback for a week after saving it.' : 'Your agency owner writes the feedback.'}</p></div>
        {data.can_write && <Button type="button" onClick={() => setDrawer({ editing: null })}>Give feedback</Button>}
      </div>
      {data.feedback.length === 0 ? (
        <EmptyState title="No feedback yet" action={data.can_write ? <Button type="button" onClick={() => setDrawer({ editing: null })}>Give the first feedback</Button> : null}>
          {data.can_write ? 'Tell us how it went. It helps us match the right people to your work.' : 'Your agency owner has not given feedback about this employee yet.'}
        </EmptyState>
      ) : (
        <>
          <FeedbackSummary summary={data.summary} title="Your agency" />
          <FeedbackList items={data.feedback} onEdit={(item) => setDrawer({ editing: item })} onChanged={reload} />
        </>
      )}
      {drawer && <FeedbackFormDrawer key={drawer.editing?.id ?? 'new'} open onClose={() => setDrawer(null)} form={data.form} employeeId={employeeId} employeeName={employeeName} projects={data.projects} editing={drawer.editing} onSaved={saved} />}
    </div>
  )
}

// The administrator's view: everything, per agency, with the private notes and a way to remove feedback.
export function AdminFeedbackPanel({ employeeId, employeeName }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getAdminEmployeeFeedback(employeeId), [employeeId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [removing, setRemoving] = useState(null)

  if (error) return <Alert variant="error" title="Could not load feedback">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
  if (isLoading && !data) return <div role="status" aria-label="Loading feedback"><Skeleton variant="card" /></div>
  if (data.feedback.length === 0) return <EmptyState title="No feedback yet">No agency has given feedback about {employeeName}.</EmptyState>

  return (
    <div className="fb-panel">
      <FeedbackSummary summary={data.summary} title="All agencies" />
      {data.per_agency.length > 1 && (
        <Card title="By agency">
          <ul className="fb-agencies">{data.per_agency.map((row) => <li key={row.agency_id}><span>{row.agency_name}</span><strong>{row.average.toFixed(1)}</strong><small className="fb-muted">{row.count} {row.count === 1 ? 'review' : 'reviews'}</small></li>)}</ul>
        </Card>
      )}
      <FeedbackList items={data.feedback} showAgency onRemove={setRemoving} />
      {removing && <RemoveDialog item={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); showToast('Feedback removed.'); reload() }} />}
    </div>
  )
}

function RemoveDialog({ item, onClose, onDone }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try { await authService.removeFeedback(item.id, reason.trim()); await onDone() } catch (failure) { setError(failure); setSaving(false) }
  }
  return (
    <Modal open onClose={() => !saving && onClose()} title="Remove this feedback" footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <p className="ui-field-help">It leaves the averages and disappears for the agency and the employee. The record and your reason are kept for the audit trail.</p>
        <Textarea label="Why is it being removed?" required rows={3} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} error={error?.errors?.reason?.[0]} />
        {error && !error.errors && <ApiErrorMessage error={error} />}
        <FormActions submitLabel="Remove feedback" loadingLabel="Removing…" loading={saving} disabled={reason.trim().length < 5} onCancel={onClose} />
      </form>
    </Modal>
  )
}
