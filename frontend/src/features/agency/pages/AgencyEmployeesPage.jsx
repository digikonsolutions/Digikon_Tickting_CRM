import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Avatar, Badge, Button, CapacityBar, Card, Drawer,
  EmptyState, Modal, PageHeader, Skeleton, Table,
} from '../../../components/ui'

/* ── helpers ──────────────────────────────────────────────────── */

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function mondayOf(date) {
  const d = date ? new Date(date + 'T00:00:00') : new Date()
  const dayOfWeek = d.getDay() || 7
  d.setDate(d.getDate() - dayOfWeek + 1)
  return d
}

function toDateStr(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function currentWeekDate() {
  return toDateStr(mondayOf())
}

function formatRange(weekStr) {
  const mon = new Date(weekStr + 'T00:00:00')
  const sat = new Date(mon)
  sat.setDate(mon.getDate() + 5)
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const mLabel = months[mon.getMonth()]
  const sLabel = months[sat.getMonth()]
  const monDay = mon.getDate()
  const satDay = sat.getDate()
  if (mLabel === sLabel) return `${mLabel} ${monDay} – ${satDay}`
  return `${mLabel} ${monDay} – ${sLabel} ${satDay}`
}

function localToday() {
  const d = new Date()
  return toDateStr(d)
}

function shiftWeek(weekStr, delta) {
  const d = new Date(weekStr + 'T00:00:00')
  d.setDate(d.getDate() + delta * 7)
  return toDateStr(mondayOf(toDateStr(d)))
}

/* ── component ───────────────────────────────────────────────── */

export function AgencyEmployeesPage() {
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const [week, setWeek] = useState(currentWeekDate)
  const [confirm, setConfirm] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [actionSuccess, setActionSuccess] = useState('')
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [drawerSearch, setDrawerSearch] = useState('')
  const [impactData, setImpactData] = useState(null)

  /* auto-dismiss alerts */
  useEffect(() => {
    if (!actionSuccess) return
    const timer = setTimeout(() => setActionSuccess(''), 5000)
    return () => clearTimeout(timer)
  }, [actionSuccess])

  useEffect(() => {
    if (!actionError) return
    const timer = setTimeout(() => setActionError(null), 5000)
    return () => clearTimeout(timer)
  }, [actionError])

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

  const filteredAvailable = drawerSearch.trim()
    ? available.filter((e) => {
      const q = drawerSearch.toLowerCase()
      return e.name.toLowerCase().includes(q) || e.email.toLowerCase().includes(q)
    })
    : available

  /* ── compute impact for end-assignment ─────────────────────── */
  async function computeImpact(assignment) {
    try {
      const result = await authService.listAgencyProjects()
      const projects = result.projects ?? []
      const today = localToday()
      const affected = projects.filter(
        (p) => p.assignment_id === assignment.id
          && (p.status === 'scheduled' || p.status === 'in_progress'),
      )
      let futureHours = 0
      for (const p of affected) {
        for (const s of p.schedule ?? []) {
          if (s.work_date >= today) futureHours += Number(s.hours) || 0
        }
      }
      return { projectCount: affected.length, futureHours }
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
    setActionSuccess('')
    try {
      if (confirm.kind === 'assign') {
        await authService.createEmployeeAssignment(confirm.employee.id)
      } else {
        await authService.endEmployeeAssignment(confirm.assignment.id)
      }
      setActionSuccess(
        confirm.kind === 'assign'
          ? `${confirm.employee.name} is now exclusively assigned to you.`
          : `${confirm.assignment.employee.name}'s assignment has ended.`,
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

  /* ── agency table columns ──────────────────────────────────── */
  const agencyColumns = [
    {
      key: 'employee',
      label: 'Employee',
      render: (employee) => (
        <span style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <Avatar name={employee?.name} size="sm" />
          {employee?.name ?? 'Account removed'}
        </span>
      ),
    },
    { key: 'holder', label: 'Holder', render: (holder) => holder?.name ?? 'Account removed' },
    {
      key: 'started_at',
      label: 'Started',
      render: (date) => date ? new Date(date).toLocaleDateString() : '—',
    },
    {
      key: 'weekly_usage',
      label: 'Hours this week',
      render: (usage) => (
        <CapacityBar
          used={Number(usage?.used ?? 0)}
          budget={36}
          showRemaining={false}
        />
      ),
    },
    ...(isOwner
      ? [{
        key: 'actions',
        label: 'Actions',
        render: (_value, assignment) => (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="ae-danger-outline"
            onClick={() => openEndConfirm(assignment)}
          >
            End assignment
          </Button>
        ),
      }]
      : []),
  ]

  /* ── render ────────────────────────────────────────────────── */
  const hasTeam = mine.length > 0

  return (
    <section className="page ae-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="My employees"
        subtitle="Hold employees for your projects and track their weekly capacity."
        action={
          <Button type="button" onClick={() => setDrawerOpen(true)}>
            + Hold employee
          </Button>
        }
      />

      {/* Alerts */}
      <div aria-live="polite" className="ae-alerts">
        {actionSuccess && (
          <Alert
            variant="success"
            title="Done"
          >
            {actionSuccess}
            <button
              type="button"
              className="ae-alert-dismiss"
              aria-label="Dismiss"
              onClick={() => setActionSuccess('')}
            >
              ✕
            </button>
          </Alert>
        )}
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
            <div style={{ marginTop: 8 }}>
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
          Week of <strong>{formatRange(week)}</strong>
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
                  <CapacityBar used={usedNum} budget={36} />
                </div>

                <div className="ae-day-bars" aria-label="Hours used Monday through Saturday">
                  {(usage?.days ?? []).map((day, index) => {
                    const hours = Number(day.used)
                    const pct = Math.min(100, (hours / 6) * 100)
                    return (
                      <div className="ae-day-col" key={day.date} title={`${day.date}: ${hours} of 6 hours`}>
                        <div className="ae-day-track">
                          <span className="ae-day-fill" style={{ height: `${pct}%` }} />
                        </div>
                        <span className="ae-day-label">{WEEKDAYS[index]}</span>
                        <span className="ae-day-hours">{hours.toFixed(1)}h</span>
                      </div>
                    )
                  })}
                </div>

                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="ae-danger-outline"
                  onClick={() => openEndConfirm(assignment)}
                >
                  End assignment
                </Button>
              </Card>
            )
          })}
        </div>
      ) : (
        <EmptyState
          title="No employees held yet"
          action={
            <Button type="button" onClick={() => setDrawerOpen(true)}>
              Hold an employee
            </Button>
          }
        >
          Hold an employee to start assigning them to your projects.
        </EmptyState>
      )}

      {/* Agency assignments table */}
      {(isOwner || user.role === 'agency_client') && (
        <Card
          title="Agency employee selections"
          description="Active employee assignments held by clients and owners in your agency."
          className="ae-agency-table"
        >
          <Table
            columns={agencyColumns}
            rows={agencyAssignments}
            loading={isLoading}
            emptyTitle="No active employee selections"
            emptyText="Active employee assignments in your agency will appear here."
          />
        </Card>
      )}

      {isOwner && (
        <p className="ae-footnote">
          <Link to="/agency/projects">Review agency projects</Link>
        </p>
      )}

      {/* Hold employee drawer */}
      <Drawer
        open={drawerOpen}
        onClose={() => { setDrawerOpen(false); setDrawerSearch('') }}
        title="Hold an employee"
        description="Selecting an employee makes them exclusive to you until the assignment ends."
      >
        <input
          type="search"
          className="ae-drawer-search"
          placeholder="Search by name or email…"
          value={drawerSearch}
          onChange={(e) => setDrawerSearch(e.target.value)}
        />
        {filteredAvailable.length ? (
          <div className="ae-drawer-list">
            {filteredAvailable.map((employee) => (
              <div className="ae-drawer-row" key={employee.id}>
                <Avatar name={employee.name} size="md" />
                <div className="ae-drawer-row-info">
                  <strong>{employee.name}</strong>
                  <span>{employee.email}</span>
                </div>
                <Badge variant={employee.agency_id ? 'primary' : 'info'}>
                  {employee.agency_id ? 'Agency' : 'Shared'}
                </Badge>
                <Button
                  type="button"
                  size="sm"
                  onClick={() => setConfirm({ kind: 'assign', employee })}
                >
                  Select
                </Button>
              </div>
            ))}
          </div>
        ) : (
          <p className="ae-drawer-empty">
            {isLoading ? 'Loading…' : 'No unassigned employees are currently available.'}
          </p>
        )}
      </Drawer>

      {/* Confirm Modal */}
      <Modal
        open={Boolean(confirm)}
        onClose={() => !isSaving && (setConfirm(null), setImpactData(null))}
        title={confirm?.kind === 'assign' ? 'Assign employee?' : 'End employee assignment?'}
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
              {confirm?.kind === 'assign' ? 'Confirm selection' : 'End assignment'}
            </Button>
          </>
        }
      >
        {confirm?.kind === 'assign' ? (
          <p>
            <strong>{confirm.employee.name}</strong> will become exclusive to you.
            Other agency clients will no longer be able to select this employee.
          </p>
        ) : (
          <>
            <p>
              Ending <strong>{confirm?.assignment?.employee?.name}</strong>&apos;s assignment
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
