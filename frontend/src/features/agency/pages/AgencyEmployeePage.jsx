import { useCallback, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Avatar, Button, Card, CapacityBar, EmptyState, PageHeader, Skeleton, Tabs, useToast } from '../../../components/ui'
import { DEFAULT_CAPACITY, WORKING_WEEKDAYS } from '../../../lib/constants'
import { addDays, formatDate, formatDateRange, formatShortDate, mondayOf, toDateStr } from '../../../lib/format'
import { QueueCard } from '../../queue/components/QueueCard'
import { RushRequestModal } from '../../queue/components/RushRequestModal'
import { plannedRange } from '../../queue/queueFormat'
import { EmployeeProfileDrawer } from '../components/EmployeeProfileDrawer'
import { ProjectFormModal } from '../components/ProjectFormModal'
import '../../queue/queue.css'

// One employee on their own page: what they are doing, what waits (the queue, with reordering for the owner), their
// week, and their profile. The team table links here.
export function AgencyEmployeePage() {
  const { assignmentId } = useParams()
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const { showToast } = useToast()
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab')
  const tab = ['queue', 'schedule', 'profile', 'activity'].includes(requested) && (requested !== 'activity' || isOwner) ? requested : 'overview'
  const [week, setWeek] = useState(() => toDateStr(mondayOf()))
  const [taskForm, setTaskForm] = useState(false)
  const [rush, setRush] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)

  const load = useCallback(async () => {
    const [{ assignments }, queue] = await Promise.all([authService.listMyAssignments(), authService.getAssignmentQueue(assignmentId)])
    return { assignments, assignment: assignments.find((item) => String(item.id) === String(assignmentId)) ?? null, queue }
  }, [assignmentId])
  const { data, error, isLoading, reload } = useAsync(load)
  const loadWeek = useCallback(() => authService.getAssignmentUsage(assignmentId, week), [assignmentId, week])
  const usage = useAsync(loadWeek)

  const tabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'queue', label: 'Queue', count: data?.queue.up_next?.length ?? undefined },
    { id: 'schedule', label: 'Schedule' },
    { id: 'profile', label: 'Profile' },
    ...(isOwner ? [{ id: 'activity', label: 'Activity' }] : []),
  ]
  const setTab = (id) => setParams(id === 'overview' ? {} : { tab: id }, { replace: true })

  if (error) {
    return (
      <section className="page">
        <PageHeader eyebrow="Agency workspace" title="Employee" action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/agency/employees">Back to employees</Link>} />
        <Alert variant="error" title="Could not load this employee">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
      </section>
    )
  }
  if (isLoading && !data) return <section className="page"><Skeleton variant="card" /><Skeleton variant="card" /></section>
  if (!data?.assignment) {
    return (
      <section className="page">
        <PageHeader eyebrow="Agency workspace" title="Employee" action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/agency/employees">Back to employees</Link>} />
        <EmptyState title="Employee not found">This employee is not on your team any more.</EmptyState>
      </section>
    )
  }

  const { assignment, queue, assignments } = data
  const employee = assignment.employee
  const capacity = queue.capacity ?? {}
  const free = capacity.free_from
  const next = queue.up_next ?? []
  const weekly = usage.data
  const weekCapacity = assignment.capacity ?? DEFAULT_CAPACITY

  return (
    <section className="page ep-page">
      <PageHeader
        eyebrow="Agency workspace"
        title={employee.name}
        subtitle={`${employee.email ?? ''}${free ? ` · Free from ${formatShortDate(free.date)}` : ' · No free time in the next four months'}`}
        action={(
          <div className="tm-meta">
            <Button type="button" onClick={() => setTaskForm(true)}>Add task</Button>
            <Button type="button" variant="secondary" onClick={() => setRush(true)}>Request urgent help</Button>
          </div>
        )}
      />
      <Tabs tabs={tabs} activeTab={tab} onChange={setTab} label="Employee sections" />

      <div role="tabpanel" aria-label={tabs.find((item) => item.id === tab)?.label}>
        {tab === 'overview' && (
          <>
            <div className="ep-facts">
              <div><small>Now working on</small><strong>{queue.now ? `${queue.now.ticket_key ? `${queue.now.ticket_key} · ` : ''}${queue.now.title}` : 'Nothing in progress'}</strong></div>
              <div><small>Today</small><CapacityBar used={Number(capacity.hours_today_used ?? 0)} budget={Number(capacity.daily_hours ?? 6)} ariaLabel="Hours booked today" /></div>
              <div><small>This week</small><CapacityBar used={Number(capacity.hours_week_used ?? 0)} budget={Number(capacity.weekly_hours ?? 36)} ariaLabel="Hours booked this week" /></div>
              <div><small>Open tasks</small><strong>{next.length + (queue.now ? 1 : 0)}</strong></div>
            </div>
            <Card title="Up next" description="In the order they will be worked on.">
              {next.length === 0 ? (
                <EmptyState title="Nothing waiting">Add a task to give {employee.name} work.</EmptyState>
              ) : (
                <ol className="ep-queue" aria-label={`Projects waiting for ${employee.name}`}>
                  {next.slice(0, 5).map((item, index) => (
                    <li key={item.work_item_id}>
                      <span className="queue-item-number" aria-hidden="true">{index + 1}</span>
                      <span className="ep-queue-main"><strong>{item.title}</strong><small className="queue-muted">{item.client_name ? `${item.client_name} · ` : ''}{item.awaiting_schedule ? 'Awaiting schedule' : `${plannedRange(item.planned_start, item.planned_end)} · due ${formatDate(item.due_date)}`}</small></span>
                    </li>
                  ))}
                </ol>
              )}
              {next.length > 5 && <Button type="button" variant="ghost" size="sm" onClick={() => setTab('queue')}>See all {next.length}</Button>}
            </Card>
          </>
        )}

        {tab === 'queue' && (
          <QueueCard
            assignmentId={assignment.id}
            queue={queue}
            isOwner={isOwner}
            userId={user.id}
            onAddTask={() => setTaskForm(true)}
            onRequestHelp={() => setRush(true)}
            onChanged={reload}
            onMessage={showToast}
          />
        )}

        {tab === 'schedule' && (
          <Card title="Week" description="Hours booked, Monday to Saturday.">
            <div className="ae-week-picker">
              <button type="button" className="ae-week-arrow" aria-label="Previous week" onClick={() => setWeek((current) => toDateStr(mondayOf(addDays(current, -7))))}>‹</button>
              <span className="ae-week-label">Week of <strong>{formatDateRange(week, addDays(week, WORKING_WEEKDAYS.length - 1))}</strong></span>
              <button type="button" className="ae-week-arrow" aria-label="Next week" onClick={() => setWeek((current) => toDateStr(mondayOf(addDays(current, 7))))}>›</button>
            </div>
            {usage.isLoading && !weekly ? <Skeleton variant="row" /> : (
              <>
                <CapacityBar used={Number(weekly?.used ?? 0)} budget={weekCapacity.weekly_hours} ariaLabel="Hours booked this week" />
                <div className="ae-day-bars" aria-label="Hours used Monday through Saturday">
                  {(weekly?.days ?? []).map((day, index) => {
                    const hours = Number(day.used)
                    const pct = Math.min(100, (hours / weekCapacity.daily_hours) * 100)
                    return (
                      <div className="ae-day-col" key={day.date} title={`${day.date}: ${hours} of ${weekCapacity.daily_hours} hours`}>
                        <div className="ae-day-track"><span className="ae-day-fill" style={{ height: `${pct}%` }} /></div>
                        <span className="ae-day-label">{WORKING_WEEKDAYS[index]}</span>
                        <span className="ae-day-hours">{hours.toFixed(1)}h</span>
                      </div>
                    )
                  })}
                </div>
              </>
            )}
          </Card>
        )}

        {tab === 'profile' && (
          <Card title="Profile" description="Skills, experience and availability.">
            <div className="ep-profile">
              <Avatar name={employee.name} src={queue.employee?.photo_url} size="lg" />
              <div><strong>{employee.name}</strong><p className="queue-muted">{employee.email}</p></div>
              <Button type="button" variant="secondary" onClick={() => setProfileOpen(true)}>View full profile</Button>
            </div>
          </Card>
        )}

        {tab === 'activity' && isOwner && (
          <Card title="Work activity" description="When this employee worked, and on what.">
            <Link className="ui-button ui-button-secondary" to={`/agency/employees/${employee.id}/activity`}>Open work activity</Link>
          </Card>
        )}
      </div>

      {taskForm && (
        <ProjectFormModal
          assignments={assignments}
          assignmentsLoading={false}
          defaultAssignmentId={assignment.id}
          onClose={() => setTaskForm(false)}
          onRequestUrgent={() => { setTaskForm(false); setRush(true) }}
          onSaved={(project, { created, uploadNotice }) => {
            setTaskForm(false)
            showToast(uploadNotice ? `Project created, but the files were not uploaded: ${uploadNotice}` : (created ? 'Request sent for review.' : 'Project updated.'), uploadNotice ? 'error' : undefined)
            reload()
          }}
        />
      )}
      {rush && (
        <RushRequestModal assignments={assignments} defaultAssignmentId={assignment.id} onClose={() => setRush(false)} onSubmitted={() => { setRush(false); showToast('Request sent. You will get a quote before anything is charged.') }} />
      )}
      <EmployeeProfileDrawer employeeId={profileOpen ? employee.id : null} onClose={() => setProfileOpen(false)} />
    </section>
  )
}
