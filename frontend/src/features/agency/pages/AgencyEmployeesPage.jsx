import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import {
  Alert, Avatar, Badge, Button, CapacityBar, Card, Drawer,
  EmptyState, Modal, PageHeader, Skeleton,
} from '../../../components/ui'
import { DAILY_HOURS_CAP, WEEKLY_HOURS_BUDGET, WORKING_WEEKDAYS } from '../../../lib/constants'
import { addDays, formatDateRange, mondayOf, toDateStr, todayStr } from '../../../lib/format'

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
    const [available, mine] = await Promise.all([
      authService.listAvailableEmployees(),
      authService.listMyAssignments({ week }),
    ])
    return { available: available.employees, mine: mine.assignments }
  }, [week])

  const { data, error, isLoading, reload } = useAsync(loadData)
  const available = data?.available ?? []
  const mine = data?.mine ?? []

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

  /* ── render ────────────────────────────────────────────────── */
  const hasTeam = mine.length > 0

  return (
    <section className="page ae-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="My employees"
        subtitle="Employees you hold for your projects, with their weekly capacity."
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
                  <CapacityBar used={usedNum} budget={WEEKLY_HOURS_BUDGET} />
                </div>

                <div className="ae-day-bars" aria-label="Hours used Monday through Saturday">
                  {(usage?.days ?? []).map((day, index) => {
                    const hours = Number(day.used)
                    const pct = Math.min(100, (hours / DAILY_HOURS_CAP) * 100)
                    return (
                      <div className="ae-day-col" key={day.date} title={`${day.date}: ${hours} of ${DAILY_HOURS_CAP} hours`}>
                        <div className="ae-day-track">
                          <span className="ae-day-fill" style={{ height: `${pct}%` }} />
                        </div>
                        <span className="ae-day-label">{WORKING_WEEKDAYS[index]}</span>
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
          These are the employees you hold. Hold an employee to start assigning them to your projects.
        </EmptyState>
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
