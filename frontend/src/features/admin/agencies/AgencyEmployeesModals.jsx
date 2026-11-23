import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Avatar, Badge, Button, EmptyState, Input, Modal, Skeleton, useToast } from '../../../components/ui'

// Give an agency an employee nobody holds. The server checks seats and availability and says so in plain words.
export function AssignEmployeeModal({ agency, open, onClose, onDone }) {
  const { showToast } = useToast()
  const [search, setSearch] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [error, setError] = useState(null)
  const load = useCallback(() => (open ? authService.listAssignableEmployees({ search }) : Promise.resolve({ employees: [] })), [open, search])
  const { data, isLoading } = useAsync(load)
  const employees = (data?.employees ?? []).filter((employee) => employee.role !== 'admin')

  async function assign(employee) {
    setBusyId(employee.id)
    setError(null)
    try {
      await authService.assignAdminAgencyEmployee(agency.id, employee.id)
      showToast(`${employee.name} was added to ${agency.name}.`)
      onDone?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <Modal open={open} onClose={() => busyId === null && onClose()} title={`Add an employee to ${agency?.name ?? 'this agency'}`}>
      <Input id="assign-search" label="Search employees" type="search" placeholder="Name" value={search} onChange={(event) => setSearch(event.target.value)} />
      <ApiErrorMessage error={error} />
      {isLoading && !data ? <Skeleton variant="row" /> : employees.length === 0 ? (
        <EmptyState title="No free employees">Every active employee is already held by an agency.</EmptyState>
      ) : (
        <ul className="agency-assign-list" aria-label="Employees nobody holds">
          {employees.map((employee) => (
            <li key={employee.id} className="agency-assign-row">
              <Avatar name={employee.name} src={employee.photo_url} size="md" />
              <span className="agency-employee-main">
                <strong>{employee.name}</strong>
                <small>{employee.headline ?? 'No headline'}</small>
                {(employee.top_skills ?? []).length > 0 && <span className="agency-chips">{employee.top_skills.map((skill) => <Badge key={skill} variant="slate">{skill}</Badge>)}</span>}
              </span>
              <Button type="button" size="sm" loading={busyId === employee.id} loadingText="Adding…" disabled={busyId !== null} onClick={() => assign(employee)}>Add</Button>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

// Release a held employee after saying what it does. Uses the existing admin end-assignment endpoint.
export function ReleaseEmployeeModal({ agency, employee, onClose, onDone }) {
  const { showToast } = useToast()
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  async function release() {
    setSaving(true)
    setError(null)
    try {
      await authService.endAdminAssignment(employee.assignment_id)
      showToast(`${employee.name} was released from ${agency.name}.`)
      onDone?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={Boolean(employee)}
      onClose={() => !saving && onClose()}
      title="Release this employee?"
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Keep</Button>
          <Button type="button" variant="danger" loading={saving} loadingText="Releasing…" onClick={release}>Release employee</Button>
        </>
      )}
    >
      {employee && <p>Releasing <strong>{employee.name}</strong> cancels their open projects for {agency.name} and frees their future hours. Past work is kept.</p>}
      <ApiErrorMessage error={error} />
    </Modal>
  )
}
