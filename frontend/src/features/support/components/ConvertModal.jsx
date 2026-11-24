import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, FormActions, Input, Modal, Select, Skeleton } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'

// Convert a ticket into a project, or mark it as a change request that needs an estimate. Shows what carries over first.
export function ConvertModal({ ticket, onClose, onDone }) {
  const [to, setTo] = useState('project')
  const [assignmentId, setAssignmentId] = useState('')
  const [title, setTitle] = useState(ticket.title)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [result, setResult] = useState(null)
  const load = useCallback(() => authService.listTicketAssignments(ticket.ref), [ticket.ref])
  const assignments = useAsync(load)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const response = await authService.convertStaffTicket(ticket.ref, to === 'project' ? { to, assignment_id: Number(assignmentId), title } : { to })
      setResult(response)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
    }
  }

  if (result) {
    return (
      <Modal open onClose={() => onDone(result.ticket)} title="Converted" footer={null}>
        <Alert variant="success">{result.project ? <>Created project &ldquo;{result.project.title}&rdquo; from {ticket.ref}. <Link to={`/admin/projects/${result.project.id}`}>Open the project</Link>.</> : `${ticket.ref} is now a change request that needs an estimate.`}</Alert>
        <FormActions submitLabel="Done" onCancel={null} />
      </Modal>
    )
  }

  return (
    <Modal open onClose={() => !busy && onClose()} title="Convert this ticket" footer={null}>
      <form className="ui-form" onSubmit={submit} noValidate>
        <Select label="Convert to" value={to} onChange={(event) => setTo(event.target.value)}>
          <option value="project">A project</option>
          <option value="change_request">A change request (estimate and approval first)</option>
        </Select>
        {to === 'project' && (
          <>
            {assignments.isLoading && !assignments.data ? <Skeleton variant="row" /> : (
              <Select label="Employee" required value={assignmentId} onChange={(event) => setAssignmentId(event.target.value)} error={error?.errors?.assignment_id?.[0]}>
                <option value="">Choose an employee assignment</option>
                {(assignments.data?.assignments ?? []).map((item) => <option key={item.id} value={item.id}>{item.employee}</option>)}
              </Select>
            )}
            <Input label="Project title" maxLength={160} value={title} onChange={(event) => setTitle(event.target.value)} />
            <p className="ui-field-help">Carries over: the description and expected result, a link back to {ticket.ref}, and the agency. The ticket keeps its whole history and closes as &ldquo;Converted to project&rdquo;.</p>
          </>
        )}
        <ApiErrorMessage error={error && !error.errors ? error : null} />
        <FormActions submitLabel="Convert" loadingLabel="Converting…" loading={busy} disabled={to === 'project' && !assignmentId} disabledReason="Choose an employee assignment." onCancel={onClose} />
      </form>
    </Modal>
  )
}
