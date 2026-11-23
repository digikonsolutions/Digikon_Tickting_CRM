import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Avatar, Badge, Button, CapacityBar, Card, Drawer,
  EmptyState, Input, Modal, PageHeader, Skeleton, useToast,
} from '../../../components/ui'
import { AVAILABILITY, AVAILABILITY_VARIANT, labelOf } from '../../admin/employee/employeeForm'
import { EmployeeProfileDrawer } from '../components/EmployeeProfileDrawer'
import { RequestModal } from '../components/RequestModal'
import { DEFAULT_CAPACITY, WORKING_WEEKDAYS } from '../../../lib/constants'
import { addDays, formatDateRange, formatShortDate, mondayOf, pluralize, toDateStr, todayStr } from '../../../lib/format'
import { getAgencyLabels } from '../../../lib/labels'

/* helpers */

function currentWeekDate() {
  return toDateStr(mondayOf())
}

function shiftWeek(weekStr, delta) {
  return toDateStr(mondayOf(addDays(weekStr, delta * 7)))
}

/* ── component ───────────────────────────────────────────────── */

export function AgencyEmployeesPage() {
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const labels = getAgencyLabels(user.role)
  const [week, setWeek] = useState(currentWeekDate)
  const [confirm, setConfirm] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  const { showToast } = useToast()
  // The dashboard's "Hold employee" opens this page with the list already showing (?hold=1).
  const [searchParams] = useSearchParams()
  const [drawerOpen, setDrawerOpen] = useState(() => searchParams.get('hold') === '1')
  const [drawerSearch, setDrawerSearch] = useState('')
  const [impactData, setImpactData] = useState(null)
  const [profileId, setProfileId] = useState(null)
  const [requesting, setRequesting] = useState(false)

  /* auto-dismiss the error alert */
  useEffect(() => {
    if (!actionError) return
    const timer = setTimeout(() => setActionError(null), 5000)
    return () => clearTimeout(timer)
  }, [actionError])

  const loadData = useCallback(async () => {
    // The server decides who may add employees (plan, seats, role); only then is the list of free employees needed.
    const [summary, mine] = await Promise.all([
      authService.getAgencyEmployeesSummary(),
      authService.listMyAssignments({ week }),
    ])
    const available = summary.can_add ? (await authService.listAvailableEmployees()).employees : []
    return { available, mine: mine.assignments, summary }
  }, [week])

  const { data, error, isLoading, reload } = useAsync(loadData)
  const available = data?.available ?? []
  const mine = data?.mine ?? []
  const summary = data?.summary
  const seats = summary?.seats
  const canAdd = Boolean(summary?.can_add)
  // Owners manage their own employees on a Custom plan, or when the admin assigned nobody; otherwise it is read-only.
  const ownerManaged = isOwner && Boolean(seats && (seats.custom_plan || !seats.admin_assigned))
  const addBlockedReason = isOwner && ownerManaged && !canAdd ? seats?.reason : null

  // Only an employee who can still be held offers "select" from inside the profile (team members cannot).
  const profileCandidate = available.find((employee) => employee.id === profileId) ?? null

  const filteredAvailable = drawerSearch.trim()
    ? available.filter((e) => {
      const q = drawerSearch.toLowerCase()
      return e.name.toLowerCase().includes(q) || e.email.toLowerCase().includes(q)
    })
    : available

  /* ── compute impact for end-assignment ─────────────────────── */
  async function computeImpact(assignment) {
    try {
      // The server filters to this assignment's open projects; the total gives the true count
      // even if there are more than one page of them.
      const result = await authService.listAgencyProjects({
        assignmentId: assignment.id,
        status: 'open',
        perPage: 100,
      })
      const affected = result.projects ?? []
      const today = todayStr()
      let futureHours = 0
      for (const p of affected) {
        for (const s of p.schedule ?? []) {
          if (s.work_date >= today) futureHours += Number(s.hours) || 0
        }
      }
      return { projectCount: result.pagination?.total ?? affected.length, futureHours }
    } catch {
      return null
    }
  }

  async function openEndConfirm(assignment) {
    setConfirm({ kind: 'end', assignment })
    const impact = await computeImpact(assignment)
    setImpactData(impact)
  }

  /* ── actions ───────────────────────────────────────────────── */
  async function confirmAction() {
    if (!confirm || isSaving) return
    setIsSaving(true)
    setActionError(null)
    try {
      if (confirm.kind === 'assign') {
        await authService.createEmployeeAssignment(confirm.employee.id)
      } else {
        await authService.endEmployeeAssignment(confirm.assignment.id)
      }
      showToast(
        confirm.kind === 'assign'
          ? `${confirm.employee.name} was added to your employees.`
          : `${confirm.assignment.employee.name} was released.`,
      )
      setConfirm(null)
      setImpactData(null)
      setDrawerOpen(false)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  /* ── render ────────────────────────────────────────────────── */
  const hasTeam = mine.length > 0

  return (
    <section className="page ae-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Employees"
        subtitle={ownerManaged ? 'Employees working for your agency, with their weekly capacity.' : 'Employees are assigned by your account manager. Their weekly capacity is shown below.'}
        action={isOwner && (ownerManaged
          ? <Button type="button" disabled={!canAdd} title={addBlockedReason ?? undefined} onClick={() => setDrawerOpen(true)}>+ Add employee</Button>
          : <Button type="button" onClick={() => setRequesting(true)}>Request an employee</Button>)}
      />
      {seats && (
        <p className="ae-seats" aria-live="polite">
          {seats.seats != null ? `${seats.used} of ${seats.seats} employee ${pluralize(seats.seats, 'seat')} used` : `${seats.used} ${pluralize(seats.used, 'employee')} assigned`}
          {addBlockedReason ? ` · ${addBlockedReason}` : ''}
          {isOwner && !ownerManaged ? ' · Employees are assigned by your account manager.' : ''}
        </p>
      )}

      {/* Alerts */}
      <div aria-live="polite" className="ae-alerts">
        {(actionError || error) && (
          <Alert variant="error" title="Error">
            {String(actionError?.message ?? error?.message ?? actionError ?? error)}
            <button
              type="button"
              className="ae-alert-dismiss"
              aria-label="Dismiss"
              onClick={() => setActionError(null)}
            >
              ✕
            </button>
            <div className="ui-retry">
              <Button type="button" variant="secondary" size="sm" onClick={() => { setActionError(null); reload() }}>
                Try again
              </Button>
            </div>
          </Alert>
        )}
      </div>

      {/* Week picker */}
      <div className="ae-week-picker">
        <button
          type="button"
          className="ae-week-arrow"
          aria-label="Previous week"
          onClick={() => setWeek((w) => shiftWeek(w, -1))}
        >
          ‹
        </button>
        <label htmlFor="ae-week-input" className="ae-week-label">
          Week of <strong>{formatDateRange(week, addDays(week, WORKING_WEEKDAYS.length - 1))}</strong>
        </label>
        <input
          id="ae-week-input"
          type="date"
          className="ae-week-date"
          value={week}
          onChange={(event) => setWeek(toDateStr(mondayOf(event.target.value)))}
        />
        <button
          type="button"
          className="ae-week-arrow"
          aria-label="Next week"
          onClick={() => setWeek((w) => shiftWeek(w, 1))}
        >
          ›
        </button>
      </div>

      {/* My Team */}
      {isLoading ? (
        <div className="ae-team-grid">
          <Skeleton variant="card" />
          <Skeleton variant="card" />
          <Skeleton variant="card" />
        </div>
      ) : hasTeam ? (
        <div className="ae-team-grid">
          {mine.map((assignment) => {
            const usage = assignment.weekly_usage
            const usedNum = Number(usage?.used ?? 0)
            const capacity = assignment.capacity ?? DEFAULT_CAPACITY
            return (
              <Card key={assignment.id} className="ae-team-card">
                <div className="ae-team-card-header">
                  <Avatar name={assignment.employee.name} size="lg" />
                  <div className="ae-team-card-info">
                    <h3>{assignment.employee.name}</h3>
                    <p>{assignment.employee.email}</p>
                  </div>
                  <Badge variant="success">Active</Badge>
                </div>

                <div className="ae-team-capacity">
                  <CapacityBar used={usedNum} budget={capacity.weekly_hours} />
                </div>

                <div className="ae-day-bars" aria-label="Hours used Monday through Saturday">
                  {(usage?.days ?? []).map((day, index) => {
                    const hours = Number(day.used)
                    const pct = Math.min(100, (hours / capacity.daily_hours) * 100)
                    return (
                      <div className="ae-day-col" key={day.date} title={`${day.date}: ${hours} of ${capacity.daily_hours} hours`}>
                        <div className="ae-day-track">
                          <span className="ae-day-fill" style={{ height: `${pct}%` }} />
                        </div>
                        <span className="ae-day-label">{WORKING_WEEKDAYS[index]}</span>
                        <span className="ae-day-hours">{hours.toFixed(1)}h</span>
                      </div>
                    )
                  })}
                </div>

                <Button type="button" variant="secondary" size="sm" onClick={() => setProfileId(assignment.employee.id)}>
                  View profile
                </Button>
                {isOwner && (
                  <Link className="ui-button ui-button-secondary ui-button-sm" to={`/agency/employees/${assignment.employee.id}/activity`}>
                    Work activity
                  </Link>
                )}
                {ownerManaged && (
                  <Button type="button" variant="ghost" size="sm" className="ae-danger-outline" onClick={() => openEndConfirm(assignment)}>
                    Release employee
                  </Button>
                )}
              </Card>
            )
          })}
        </div>
      ) : (
        <EmptyState
          title="No employees yet"
          action={isOwner && (ownerManaged
            ? canAdd && <Button type="button" onClick={() => setDrawerOpen(true)}>Add employee</Button>
            : <Button type="button" onClick={() => setRequesting(true)}>Request an employee</Button>)}
        >
          {isOwner ? (ownerManaged ? 'Add an employee to start assigning them to your projects.' : 'Employees are assigned by your account manager. Request one and tell us what you need.') : 'Your agency owner manages employees.'}
        </EmptyState>
      )}

      {isOwner && summary?.requests?.length > 0 && (
        <Card title="Your employee requests" description="What you asked your account manager for.">
          <ul className="ae-requests">
            {summary.requests.map((item) => (
              <li key={item.id}>
                <strong>{formatShortDate(item.created_at)}</strong> · {item.status === 'open' ? 'Open' : item.status}
                {item.message && <p>{item.message}</p>}
                {item.attachments.length > 0 && <p className="ae-request-files">{item.attachments.map((file) => <a key={file.id} href={authService.requestAttachmentUrl(file.id)}>{file.name}</a>)}</p>}
              </li>
            ))}
          </ul>
        </Card>
      )}
      {isOwner && (
        <p className="ae-footnote">
          <Link to="/agency/projects">Review agency projects</Link>
        </p>
      )}
      {requesting && <RequestModal type="employee" onClose={() => setRequesting(false)} onSent={reload} />}

      {/* Hold employee drawer */}
      {/* The profile drawer replaces the hold drawer while it is open (two stacked drawers would both
          answer Escape and fight over focus). Closing the profile brings the list back as it was. */}
      <Drawer
        open={drawerOpen && !profileId && canAdd}
        onClose={() => { setDrawerOpen(false); setDrawerSearch('') }}
        title={labels.holdEmployee}
        description="Choosing an employee makes them exclusive to you until you release them."
      >
        <Input
          id="employee-search"
          label="Search employees"
          type="search"
          placeholder="Name or email"
          value={drawerSearch}
          onChange={(e) => setDrawerSearch(e.target.value)}
        />
        {filteredAvailable.length ? (
          <div className="ae-drawer-list">
            {filteredAvailable.map((employee) => (
              <div className="ae-drawer-row" key={employee.id}>
                <Avatar name={employee.name} src={employee.photo_url} size="md" />
                <div className="ae-drawer-row-info">
                  <strong>{employee.name}</strong>
                  {employee.headline && <span className="ae-drawer-headline">{employee.headline}</span>}
                  <span>{employee.email}</span>
                  <div className="ae-drawer-row-extra">
                    <Badge variant={AVAILABILITY_VARIANT[employee.availability] ?? 'slate'}>{labelOf(AVAILABILITY, employee.availability)}</Badge>
                    {(employee.top_skills ?? []).map((skill) => <Badge key={skill} variant="slate">{skill}</Badge>)}
                  </div>
                </div>
                <Badge variant="info">
                  Team member
                </Badge>
                <div className="ae-drawer-row-actions">
                  <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    aria-label={`View ${employee.name}'s profile`}
                    onClick={() => setProfileId(employee.id)}
                  >
                    View profile
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    onClick={() => setConfirm({ kind: 'assign', employee })}
                  >
                    {labels.selectEmployee}
                  </Button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="ae-drawer-empty">
            {isLoading ? 'Loading…' : 'No employees are currently available.'}
          </p>
        )}
      </Drawer>

      <EmployeeProfileDrawer
        employeeId={profileId}
        onClose={() => setProfileId(null)}
        footer={profileCandidate && (
          <>
            <Button type="button" variant="secondary" onClick={() => setProfileId(null)}>Back to list</Button>
            <Button type="button" onClick={() => { setProfileId(null); setConfirm({ kind: 'assign', employee: profileCandidate }) }}>
              {labels.selectEmployee}
            </Button>
          </>
        )}
      />

      {/* Confirm Modal */}
      <Modal
        open={Boolean(confirm)}
        onClose={() => !isSaving && (setConfirm(null), setImpactData(null))}
        title={confirm?.kind === 'assign' ? 'Add this employee?' : 'Release this employee?'}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={isSaving}
              onClick={() => { setConfirm(null); setImpactData(null) }}
            >
              Keep
            </Button>
            <Button
              type="button"
              variant={confirm?.kind === 'assign' ? 'primary' : 'danger'}
              loading={isSaving}
              loadingText="Saving…"
              onClick={confirmAction}
            >
              {confirm?.kind === 'assign' ? 'Add employee' : 'Release employee'}
            </Button>
          </>
        }
      >
        {confirm?.kind === 'assign' ? (
          <p>
            <strong>{confirm.employee.name}</strong> will work for your agency and uses one employee seat.
          </p>
        ) : (
          <>
            <p>
              Releasing <strong>{confirm?.assignment?.employee?.name}</strong>
              will cancel their open projects and release future work allocations.
              Past allocations are kept.
            </p>
            {impactData && (
              <p className="ae-impact">
                This will cancel <strong>{impactData.projectCount}</strong> open project{impactData.projectCount !== 1 ? 's' : ''} and
                release <strong>{impactData.futureHours.toFixed(1)}</strong> scheduled hours.
              </p>
            )}
          </>
        )}
      </Modal>
    </section>
  )
}
