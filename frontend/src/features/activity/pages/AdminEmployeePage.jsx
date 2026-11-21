import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, EmptyState, Input, KpiCard, PageHeader, Skeleton, Tabs } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { addDays, formatDate, formatHours, todayStr } from '../../../lib/format'
import { ActivityTimeline } from '../components/ActivityTimeline'
import { HoursChart } from '../components/HoursChart'
import { dayLabel, weekLabel } from '../chartLabels'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'activity', label: 'Employee activity' },
]

const LOGIN_LABELS = { late: 'Late', early: 'Early', on_time: 'On time' }

// Admin-only page for one employee. Employees themselves never see any of this.
export function AdminEmployeePage({ scope = 'admin' }) {
  const { id } = useParams()
  const [tab, setTab] = useState('overview')
  const [range, setRange] = useState(() => ({ from: addDays(todayStr(), -13), to: todayStr() }))
  const valid = Boolean(range.from && range.to && range.from <= range.to)
  const load = useCallback(
    () => (valid ? authService.getEmployeePerformance(id, { ...range, scope }) : Promise.resolve(null)),
    [id, range, valid, scope],
  )
  const { data, error, isLoading, reload } = useAsync(load)
  const totals = data?.totals

  return (
    <section className="page employee-activity-page">
      <PageHeader
        eyebrow={scope === 'admin' ? 'Administration' : 'Agency workspace'}
        title={data?.employee?.name ?? 'Employee'}
        subtitle={scope === 'admin'
          ? 'Work activity and performance. Visible to administrators and the employee\'s agency owner only.'
          : 'Work activity and performance since this employee joined your agency. Visible to you and administrators only.'}
        action={<Link className="ui-button ui-button-secondary ui-button-sm" to={scope === 'admin' ? '/admin/employees' : '/agency/employees'}>{scope === 'admin' ? 'Back to employees' : 'Back to employees'}</Link>}
      />

      <Card>
        <form className="time-summary-filters" onSubmit={(event) => event.preventDefault()}>
          <Input label="From" type="date" value={range.from} max={range.to || undefined} onChange={(event) => setRange((current) => ({ ...current, from: event.target.value }))} />
          <Input label="To" type="date" value={range.to} min={range.from || undefined} max={todayStr()} onChange={(event) => setRange((current) => ({ ...current, to: event.target.value }))} />
          <div className="time-summary-presets" role="group" aria-label="Quick ranges">
            {[[7, 'Last 7 days'], [14, 'Last 14 days'], [30, 'Last 30 days'], [90, 'Last 90 days']].map(([days, label]) => (
              <Button key={days} type="button" size="sm" variant="secondary" onClick={() => setRange({ from: addDays(todayStr(), -(days - 1)), to: todayStr() })}>{label}</Button>
            ))}
          </div>
        </form>
      </Card>

      {!valid && <Alert variant="warning">Choose a start date that is not after the end date.</Alert>}
      {error && (
        <Alert variant="error" title="Could not load this employee">
          {error.status === 404 ? 'No employee with that id.' : (error.errors?.to?.[0] ?? error.errors?.from?.[0] ?? error.message)}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      <Tabs tabs={TABS} activeTab={tab} onChange={setTab} />

      {valid && isLoading && !data && <Skeleton variant="card" />}

      {data && tab === 'overview' && (
        <div className="mw-kpi-row">
          <KpiCard hero icon="clock" title="Active in app" value={formatHours(totals.active_hours)} subtext={`${formatHours(totals.idle_hours)} idle`} />
          <KpiCard icon="check" title="Logged on tickets" value={formatHours(totals.logged_hours)} tone="teal" />
          <KpiCard icon="calendar" title="Scheduled" value={formatHours(totals.scheduled_hours)} tone="teal" />
          <KpiCard icon="ticket" title="Tickets completed" value={totals.tickets_completed} tone="teal" />
          <KpiCard icon="arrow" title="Avg logged per ticket" value={totals.avg_logged_hours_per_ticket === null ? '—' : formatHours(totals.avg_logged_hours_per_ticket)} tone="teal" />
          <KpiCard icon="alert" title="Late sign-ins" value={totals.late_logins} subtext={`${totals.early_logins} early · day starts ${data.workday_start}`} tone="teal" />
        </div>
      )}

      {data && tab === 'activity' && (
        <div className="employee-activity-body">
          <Card title="Hours worked">
            <HoursChart rows={data.days} labelOf={dayLabel} title="Daily hours" />
            {data.weeks.length > 1 && <HoursChart rows={data.weeks} labelOf={weekLabel} title="Weekly hours" />}
          </Card>

          <Card title="Scheduled vs actual" description="Active is time with the app open and in use; logged is time recorded on tickets.">
            <div className="time-summary-scroll" tabIndex={0} role="region" aria-label="Scheduled vs actual table">
              <table className="time-summary-table">
                <thead>
                  <tr><th scope="col">Day</th><th scope="col">Scheduled</th><th scope="col">Logged</th><th scope="col">Active</th><th scope="col">Idle</th><th scope="col">First sign-in</th></tr>
                </thead>
                <tbody>
                  {data.days.map((day) => (
                    <tr key={day.date}>
                      <th scope="row">{formatDate(day.date)}</th>
                      <td>{formatHours(day.scheduled_hours)}</td>
                      <td>{formatHours(day.logged_hours)}</td>
                      <td>{formatHours(day.active_hours)}</td>
                      <td>{formatHours(day.idle_hours)}</td>
                      <td>
                        {day.first_login ?? '—'}
                        {day.login_status && day.login_status !== 'on_time' && (
                          <span className={`login-flag is-${day.login_status}`}> {LOGIN_LABELS[day.login_status]} ({Math.abs(day.login_offset_minutes)} min)</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr>
                    <th scope="row">Total</th>
                    <td>{formatHours(totals.scheduled_hours)}</td>
                    <td>{formatHours(totals.logged_hours)}</td>
                    <td>{formatHours(totals.active_hours)}</td>
                    <td>{formatHours(totals.idle_hours)}</td>
                    <td />
                  </tr>
                </tfoot>
              </table>
            </div>
          </Card>

          <ActivityTimeline employeeId={id} range={range} scope={scope} />
        </div>
      )}

      {data && data.days.length === 0 && <EmptyState title="No data">Pick a range that includes at least one day.</EmptyState>}
    </section>
  )
}

