import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Alert, Button, DayStrip, Skeleton, TicketKey } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { addDays, formatDateRange, formatHours, mondayOf, toDateStr, todayStr } from '../../../lib/format'

// Monday-to-Saturday timesheet: what was logged against what was scheduled, per day and per ticket.
export function TimesheetTab({ refreshKey = 0 }) {
  const [weekStart, setWeekStart] = useState(() => toDateStr(mondayOf()))
  const load = useCallback(() => authService.getTimesheetWeek(weekStart), [weekStart, refreshKey]) // eslint-disable-line react-hooks/exhaustive-deps
  const { data, error, isLoading, reload } = useAsync(load)
  const isThisWeek = weekStart === toDateStr(mondayOf(todayStr()))

  if (error && !data) {
    return (
      <Alert variant="error" title="Could not load your timesheet">
        {error.message}
        <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
      </Alert>
    )
  }

  const days = data?.days ?? []
  const asSchedule = (key) => days.filter((day) => Number(day[key]) > 0).map((day) => ({ work_date: day.date, hours: day[key] }))
  const logged = data?.totals.logged_hours ?? 0
  const scheduled = data?.totals.scheduled_hours ?? 0
  const delta = Math.round((logged - scheduled) * 100) / 100

  return (
    <div className="timesheet">
      <div className="timesheet-nav">
        <Button type="button" size="sm" variant="secondary" onClick={() => setWeekStart((week) => addDays(week, -7))} aria-label="Previous week">←</Button>
        <strong aria-live="polite">{formatDateRange(weekStart, addDays(weekStart, 5))}</strong>
        <Button type="button" size="sm" variant="secondary" onClick={() => setWeekStart((week) => addDays(week, 7))} aria-label="Next week">→</Button>
        {!isThisWeek && <Button type="button" size="sm" variant="ghost" onClick={() => setWeekStart(toDateStr(mondayOf()))}>This week</Button>}
      </div>

      {isLoading && !data
        ? <div role="status" aria-label="Loading timesheet"><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : (
          <>
            <dl className="timesheet-totals">
              <div><dt>Logged</dt><dd>{formatHours(logged)}</dd></div>
              <div><dt>Scheduled</dt><dd>{formatHours(scheduled)}</dd></div>
              <div><dt>Difference</dt><dd>{delta > 0 ? '+' : ''}{formatHours(delta)}</dd></div>
            </dl>

            <h3 className="timesheet-heading">Logged</h3>
            <DayStrip schedule={asSchedule('logged_hours')} emptyText="Nothing logged this week." noun="logged" />
            <h3 className="timesheet-heading">Scheduled</h3>
            <DayStrip schedule={asSchedule('scheduled_hours')} emptyText="Nothing scheduled this week." />

            {data.tickets.length > 0 && (
              <table className="timesheet-table">
                <caption className="sr-only">Hours per ticket this week</caption>
                <thead><tr><th scope="col">Ticket</th><th scope="col">Logged</th><th scope="col">Scheduled</th></tr></thead>
                <tbody>
                  {data.tickets.map((ticket) => (
                    <tr key={ticket.ticket_id}>
                      <th scope="row"><Link to={`/tickets/${ticket.key}`}><TicketKey ticketKey={ticket.key} /> {ticket.title}</Link></th>
                      <td>{formatHours(ticket.logged_hours)}</td>
                      <td>{formatHours(ticket.scheduled_hours)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        )}
    </div>
  )
}
