import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, Modal, PageHeader, Table } from '../../../components/ui'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function currentWeekDate() {
  const date = new Date()
  const day = date.getDay() || 7
  date.setDate(date.getDate() - day + 1)
  return date.toISOString().slice(0, 10)
}

function AssignmentUsage({ usage }) {
  return (
    <div className="assignment-usage">
      <div className="assignment-usage-summary">
        <strong>{usage?.used ?? '0.00'} / 36 h</strong>
        <span>{usage?.remaining ?? '36.00'} h remaining</span>
      </div>
      <div className="assignment-day-bars" aria-label="Hours used Monday through Saturday">
        {(usage?.days ?? []).map((day, index) => {
          const hours = Number(day.used)
          return (
            <div className="assignment-day" key={day.date}>
              <span>{WEEKDAYS[index]}</span>
              <div className="assignment-day-track" title={`${day.date}: ${hours} of 6 hours`}>
                <span style={{ width: `${Math.min(100, (hours / 6) * 100)}%` }} />
              </div>
              <small>{hours.toFixed(1)}h</small>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function AgencyEmployeesPage() {
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const [week, setWeek] = useState(currentWeekDate)
  const [confirm, setConfirm] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [actionSuccess, setActionSuccess] = useState('')
  const loadData = useCallback(async () => {
    const [available, mine, agency] = await Promise.all([
      authService.listAvailableEmployees(),
      authService.listMyAssignments({ week }),
      authService.listAgencyAssignments({ week }),
    ])
    return { available: available.employees, mine: mine.assignments, agency: agency.assignments }
  }, [week])
  const { data, error, isLoading, reload } = useAsync(loadData)
  const available = data?.available ?? []
  const mine = data?.mine ?? []
  const agencyAssignments = data?.agency ?? []

  async function confirmAction() {
    if (!confirm || isSaving) return
    setIsSaving(true)
    setActionError(null)
    setActionSuccess('')
    try {
      if (confirm.kind === 'assign') await authService.createEmployeeAssignment(confirm.employee.id)
      else await authService.endEmployeeAssignment(confirm.assignment.id)
      setActionSuccess(confirm.kind === 'assign'
        ? `${confirm.employee.name} is now exclusively assigned to you.`
        : `${confirm.assignment.employee.name}'s assignment has ended.`)
      setConfirm(null)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  const agencyColumns = [
    { key: 'employee', label: 'Employee', render: (employee) => employee?.name ?? 'Account removed' },
    { key: 'holder', label: 'Holder', render: (holder) => holder?.name ?? 'Account removed' },
    { key: 'started_at', label: 'Started', render: (date) => date ? new Date(date).toLocaleDateString() : '—' },
    { key: 'weekly_usage', label: 'Hours this week', render: (usage) => `${usage?.used ?? '0.00'} / 36 h` },
    ...(isOwner ? [{
        key: 'actions',
        label: 'Actions',
        render: (_value, assignment) => (
          <Button
            type="button"
            size="sm"
            variant="danger"
            onClick={() => setConfirm({ kind: 'end', assignment })}
          >
            End assignment
          </Button>
        ),
      }] : []),
  ]

  return (
    <section className="page agency-employees-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="My employees"
        subtitle="Hold employees for your projects and track their weekly capacity."
      />
      {actionSuccess && <p className="form-success" role="status">{actionSuccess}</p>}
      <ApiErrorMessage error={actionError ?? error} />
      {(actionError || error) && (
        <Button type="button" variant="secondary" onClick={() => { setActionError(null); reload() }}>Try again</Button>
      )}
      <Card
        title="My team"
        description="Each employee has a 36-hour weekly budget, shared by all projects assigned to that employee."
      >
        <div className="assignment-week-picker">
          <label htmlFor="assignment-week">Week of</label>
          <input id="assignment-week" type="date" value={week} onChange={(event) => setWeek(event.target.value)} />
        </div>
        {isLoading
          ? <p role="status">Loading your assignments…</p>
          : mine.length
            ? <div className="employee-assignment-grid">
              {mine.map((assignment) => (
                <article className="employee-assignment-card" key={assignment.id}>
                  <div className="employee-assignment-heading">
                    <div><h3>{assignment.employee.name}</h3><p>{assignment.employee.email}</p></div>
                    <Badge variant="success">Active</Badge>
                  </div>
                  <AssignmentUsage usage={assignment.weekly_usage} />
                  <Button
                    type="button"
                    variant="danger"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'end', assignment })}
                  >
                    End assignment
                  </Button>
                </article>
              ))}
            </div>
            : <p className="assignment-empty">No employees are assigned to you yet.</p>}
      </Card>
      {(isOwner || user.role === 'agency_client') && (
        <Card title="Agency employee selections" description="Active employee assignments held by clients and owners in your agency.">
          <Table
            columns={agencyColumns}
            rows={agencyAssignments}
            loading={isLoading}
            emptyTitle="No active employee selections"
            emptyText="Active employee assignments in your agency will appear here."
          />
        </Card>
      )}
      <Card title="Available employees" description="Selecting an employee makes them exclusive to you until the assignment ends.">
        {available.length
          ? <div className="available-employee-list">
            {available.map((employee) => (
              <article className="available-employee-row" key={employee.id}>
                <div><h3>{employee.name}</h3><p>{employee.email} · {employee.agency_id ? 'Agency employee' : 'Shared employee'}</p></div>
                <Button type="button" size="sm" onClick={() => setConfirm({ kind: 'assign', employee })}>Select</Button>
              </article>
            ))}
          </div>
          : <p className="assignment-empty">{isLoading ? 'Loading available employees…' : 'No unassigned employees are currently available.'}</p>}
      </Card>
      <Modal
        open={Boolean(confirm)}
        onClose={() => !isSaving && setConfirm(null)}
        title={confirm?.kind === 'assign' ? 'Assign employee?' : 'End employee assignment?'}
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={isSaving} onClick={() => setConfirm(null)}>Keep</Button>
            <Button
              type="button"
              variant={confirm?.kind === 'assign' ? 'primary' : 'danger'}
              loading={isSaving}
              loadingText="Saving…"
              onClick={confirmAction}
            >
              {confirm?.kind === 'assign' ? 'Confirm selection' : 'End assignment'}
            </Button>
          </>
        )}
      >
        {confirm?.kind === 'assign'
          ? <p>{confirm.employee.name} will become exclusive to you. Other agency clients will no longer be able to select this employee.</p>
          : <p>Ending {confirm?.assignment?.employee?.name}'s assignment will cancel their open projects and release future work allocations. Past allocations are kept.</p>}
      </Modal>
      {isOwner && <p className="agency-employees-footnote"><Link to="/agency/projects">Review agency projects</Link></p>}
    </section>
  )
}
