import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Avatar, Badge, Button, CapacityBar, Card, Drawer,
  EmptyState, Input, Modal, PageHeader, Skeleton, Tabs, useToast,
} from '../../../components/ui'
import { QueueCard } from '../../queue/components/QueueCard'
import { RushRequestModal } from '../../queue/components/RushRequestModal'
import { RushRequestsPanel } from '../../queue/components/RushRequestsPanel'
import { TeamMemberCard } from '../../queue/components/TeamMemberCard'
import { RUSH_CHANGED_EVENT } from '../../queue/useOpenRushCount'
import { ProjectFormModal } from '../components/ProjectFormModal'
import '../../queue/queue.css'
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
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = ['rush', 'week'].includes(searchParams.get('tab')) ? searchParams.get('tab') : 'team'
  const [taskForm, setTaskForm] = useState(null)
  const [rush, setRush] = useState(null)
  const [queueView, setQueueView] = useState(null)
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

  // The team tab and the rush tab share one load: each employee's queue and the agency's urgent requests.
  const loadQueues = useCallback(async () => {
    const [{ assignments: list }, { rush_requests: requestList }] = await Promise.all([authService.listMyAssignments(), authService.listRushRequests()])
    const queues = await Promise.all(list.map(async (assignment) => [assignment.id, await authService.getAssignmentQueue(assignment.id)]))
    return { assignments: list, queues: Object.fromEntries(queues), requests: requestList }
  }, [])
  const { data: queueData, error: queueError, isLoading: queuesLoading, reload: reloadQueues } = useAsync(loadQueues)
  const assignments = queueData?.assignments ?? []
  const requests = queueData?.requests ?? []
  const openRequests = requests.filter((request) => request.status === 'pending' || request.status === 'quoted')
  // Keep the sidebar badge in step with what this page just changed.
  useEffect(() => { if (queueData) window.dispatchEvent(new Event(RUSH_CHANGED_EVENT)) }, [queueData])

  const { data, error: weekError, isLoading, reload: reloadWeek } = useAsync(loadData)
  const error = weekError ?? queueError
  const reload = useCallback(() => { reloadWeek(); reloadQueues() }, [reloadWeek, reloadQueues])
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
  const emptyTeam = (
    <EmptyState
      title="No employees yet"
      action={isOwner && (ownerManaged
        ? canAdd && <Button type="button" onClick={() => setDrawerOpen(true)}>Add employee</Button>
        : <Button type="button" onClick={() => setRequesting(true)}>Request an employee</Button>)}
    >
      {isOwner ? (ownerManaged ? 'Add an employee to start assigning them to your projects.' : 'Employees are assigned by your account manager. Request one and tell us what you need.') : 'Your agency owner manages employees.'}
    </EmptyState>
  )
  const tabs = [
    { id: 'team', label: 'Team', count: assignments.length },
    { id: 'rush', label: 'Rush requests', count: openRequests.length },
    { id: 'week', label: 'Week view' },
  ]
  const setTab = (id) => setSearchParams(id === 'team' ? {} : { tab: id }, { replace: true })

  function openRush(assignmentId, defaults) {
    setTaskForm(null)
    setRush({ assignmentId, defaults })
  }

  async function cancelRush(request) {
    setActionError(null)
    try {
      await authService.cancelRushRequest(request.id)
      showToast('Request cancelled.')
      reloadQueues()
    } catch (reason) {
      setActionError(reason)
    }
  }

  return (
    <section className="page ae-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Employees"
        subtitle={ownerManaged ? 'Your team: what each employee is doing now and next, and their capacity.' : 'Employees are assigned by your account manager. See what each is doing now and next.'}
        action={(
          <div className="tm-meta">
            <Button type="button" variant="secondary" onClick={() => openRush('')}>Request urgent help</Button>
            {isOwner && (ownerManaged
              ? <Button type="button" disabled={!canAdd} title={addBlockedReason ?? undefined} onClick={() => setDrawerOpen(true)}>+ Add employee</Button>
              : <Button type="button" onClick={() => setRequesting(true)}>Request an employee</Button>)}
          </div>
        )}
      />
      {seats && (
        <div className="tm-meta" aria-live="polite">
          <span className="tm-seat-pill">
            {seats.seats != null ? `${seats.used} of ${seats.seats} ${pluralize(seats.seats, 'seat')} used` : `${seats.used} ${pluralize(seats.used, 'employee')} assigned`}
          </span>
          {(addBlockedReason || (isOwner && !ownerManaged)) && (
            <span className="ae-seats">{addBlockedReason ?? 'Employees are assigned by your account manager.'}</span>
          )}
        </div>
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

      <Tabs tabs={tabs} activeTab={tab} onChange={setTab} label="Employees sections" />

      <div role="tabpanel" aria-label={tabs.find((item) => item.id === tab)?.label}>
        {tab === 'rush' ? (
          queuesLoading && !queueData ? (
            <div className="queue-grid"><Skeleton variant="card" /></div>
          ) : (
            <RushRequestsPanel
              requests={requests}
              isOwner={isOwner}
              onCancel={cancelRush}
              onChanged={(updated, kind) => { showToast(kind === 'accept' ? 'Quote accepted and the work was scheduled.' : 'Quote declined.'); reloadQueues() }}
            />
          )
        ) : tab === 'week' ? (
          <>
            <div className="ae-week-picker">
              <button type="button" className="ae-week-arrow" aria-label="Previous week" onClick={() => setWeek((w) => shiftWeek(w, -1))}>‹</button>
              <label htmlFor="ae-week-input" className="ae-week-label">
                Week of <strong>{formatDateRange(week, addDays(week, WORKING_WEEKDAYS.length - 1))}</strong>
              </label>
              <input id="ae-week-input" type="date" className="ae-week-date" value={week} onChange={(event) => setWeek(toDateStr(mondayOf(event.target.value)))} />
              <button type="button" className="ae-week-arrow" aria-label="Next week" onClick={() => setWeek((w) => shiftWeek(w, 1))}>›</button>
            </div>
            {isLoading ? (
              <div className="tm-week-list"><Skeleton variant="card" /><Skeleton variant="card" /></div>
            ) : hasTeam ? (
              <div className="tm-week-list">
                {mine.map((assignment) => {
                  const usage = assignment.weekly_usage
                  const usedNum = Number(usage?.used ?? 0)
                  const capacity = assignment.capacity ?? DEFAULT_CAPACITY
                  return (
                    <Card key={assignment.id} className="ae-team-card">
                      <div className="tm-week-head">
                        <Avatar name={assignment.employee.name} src={assignment.employee.photo_url} size="md" />
                        <h3 className="tm-name">{assignment.employee.name}</h3>
                      </div>
                      <div className="ae-team-capacity">
                        <CapacityBar used={usedNum} budget={capacity.weekly_hours} />
                      </div>
                      <div className="ae-day-bars" aria-label={`Hours used Monday through Saturday, ${assignment.employee.name}`}>
                        {(usage?.days ?? []).map((day, index) => {
                          const hours = Number(day.used)
                          const pct = Math.min(100, (hours / capacity.daily_hours) * 100)
                          return (
                            <div className="ae-day-col" key={day.date} title={`${day.date}: ${hours} of ${capacity.daily_hours} hours`}>
                              <div className="ae-day-track"><span className="ae-day-fill" style={{ height: `${pct}%` }} /></div>
                              <span className="ae-day-label">{WORKING_WEEKDAYS[index]}</span>
                              <span className="ae-day-hours">{hours.toFixed(1)}h</span>
                            </div>
                          )
                        })}
                      </div>
                    </Card>
                  )
                })}
              </div>
            ) : emptyTeam}
          </>
        ) : queuesLoading && !queueData ? (
          <div className="queue-grid"><Skeleton variant="card" /><Skeleton variant="card" /><Skeleton variant="card" /></div>
        ) : assignments.length > 0 ? (
          <div className="queue-grid">
            {assignments.map((assignment) => queueData.queues[assignment.id] && (
              <TeamMemberCard
                key={assignment.id}
                queue={queueData.queues[assignment.id]}
                showReorder={isOwner}
                onAddTask={() => setTaskForm({ assignmentId: assignment.id })}
                onOpenQueue={() => setQueueView(assignment.id)}
                menuItems={[
                  { label: 'View profile', onClick: () => setProfileId(assignment.employee.id) },
                  ...(isOwner ? [{ label: 'Work activity', to: `/agency/employees/${assignment.employee.id}/activity` }] : []),
                  { label: 'Request urgent help', onClick: () => openRush(assignment.id) },
                  ...(ownerManaged ? [{ label: 'Release employee', danger: true, separator: true, onClick: () => openEndConfirm(assignment) }] : []),
                ]}
              />
            ))}
          </div>
        ) : (!queuesLoading && emptyTeam)}
      </div>

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
      {taskForm && (
        <ProjectFormModal
          assignments={assignments}
          assignmentsLoading={false}
          defaultAssignmentId={taskForm.assignmentId}
          onClose={() => setTaskForm(null)}
          onRequestUrgent={(values) => openRush(values.assignmentId, values.defaults)}
          onSaved={(project, { created, uploadNotice }) => {
            setTaskForm(null)
            showToast(uploadNotice ? `Project created, but the files were not uploaded: ${uploadNotice}` : (created ? 'Task added to the end of the queue.' : 'Project updated.'), uploadNotice ? 'error' : undefined)
            reload()
          }}
        />
      )}
      {rush && (
        <RushRequestModal
          assignments={assignments}
          defaultAssignmentId={rush.assignmentId}
          defaults={rush.defaults}
          onClose={() => setRush(null)}
          onSubmitted={() => { setRush(null); showToast('Request sent. You will get a quote before anything is charged.'); setTab('rush'); reloadQueues() }}
        />
      )}
      <Modal open={Boolean(queueView && queueData?.queues[queueView])} onClose={() => setQueueView(null)} title="Queue order">
        {queueView && queueData?.queues[queueView] && (
          <QueueCard
            assignmentId={queueView}
            queue={queueData.queues[queueView]}
            isOwner={isOwner}
            userId={user.id}
            onAddTask={(id) => { setQueueView(null); setTaskForm({ assignmentId: id }) }}
            onRequestHelp={(id) => { setQueueView(null); openRush(id) }}
            onChanged={reloadQueues}
            onMessage={showToast}
          />
        )}
      </Modal>

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
