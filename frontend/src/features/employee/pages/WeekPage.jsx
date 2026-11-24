import { Link, useSearchParams } from 'react-router-dom'
import { Button, CapacityBar, Card, PageHeader } from '../../../components/ui'
import { addDays, formatHours, formatShortDate, formatWeekOf, mondayOf, toDateStr, weekdayLabel } from '../../../lib/format'
import { plannedByDay, sumHours, weekDays } from '../workspace'
import { useWorkspace } from '../useWorkspace'

// /my-work/week: the planned hours for any week (?week=YYYY-MM-DD), per day against the daily limit.
export function WeekPage() {
  const { workspace, clock, openTicket } = useWorkspace()
  const [params, setParams] = useSearchParams()
  const thisMonday = toDateStr(mondayOf(clock.today))
  const requested = /^\d{4}-\d{2}-\d{2}$/.test(params.get('week') ?? '') ? params.get('week') : thisMonday
  const monday = toDateStr(mondayOf(requested))
  const dates = weekDays(monday, clock.working_days)
  const byDay = plannedByDay(workspace, dates)
  const weekTotal = sumHours([...byDay.values()].flat())
  const go = (date) => setParams(date === thisMonday ? {} : { week: date }, { replace: true })

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="Week planner" subtitle="Planned hours per day. Your agency sets the order; dates follow from it." />
      <Card>
        <div className="ew-week-nav">
          <Button type="button" size="sm" variant="secondary" onClick={() => go(addDays(monday, -7))} aria-label="Previous week">← Previous</Button>
          <h2 className="ew-week-label" aria-live="polite">{formatWeekOf(monday)}</h2>
          <Button type="button" size="sm" variant="secondary" onClick={() => go(addDays(monday, 7))} aria-label="Next week">Next →</Button>
          {monday !== thisMonday && <Button type="button" size="sm" variant="ghost" onClick={() => go(thisMonday)}>This week</Button>}
        </div>
        <CapacityBar used={weekTotal} budget={clock.weekly_hours} label="planned this week" ariaLabel="Hours planned this week" />
      </Card>

      <ol className="ew-week" aria-label={formatWeekOf(monday)}>
        {dates.map((date) => {
          const entries = byDay.get(date) ?? []
          const hours = sumHours(entries)
          const isToday = date === clock.today
          return (
            <li key={date} className={`ew-day${isToday ? ' is-today' : ''}`} aria-current={isToday ? 'date' : undefined}>
              <h3 className="ew-day-head"><span>{weekdayLabel(date)}</span> <span className="ew-muted">{formatShortDate(date)}</span>{isToday && <span className="ew-today-tag">Today</span>}</h3>
              <CapacityBar used={hours} budget={clock.daily_hours} showRemaining={false} ariaLabel={`${weekdayLabel(date)}: ${hours} of ${clock.daily_hours} hours planned`} />
              {entries.length === 0 ? <p className="ew-muted ew-day-empty">Nothing planned</p> : (
                <ul className="ew-day-items">
                  {entries.map((entry) => (
                    <li key={`${entry.kind}-${entry.item.id}`}>
                      {entry.kind === 'ticket' ? (
                        <button type="button" className="ew-block" onClick={() => openTicket(entry.item)}>
                          <span className="ew-block-title">{entry.item.title}</span>
                          <span className="ew-block-meta">{entry.item.project_title} · {formatHours(entry.hours)}</span>
                        </button>
                      ) : (
                        <Link className="ew-block ew-block-project" to={`/my-work/projects/${entry.item.id}`}>
                          <span className="ew-block-title">{entry.item.title}</span>
                          <span className="ew-block-meta">Project · {formatHours(entry.hours)}</span>
                        </Link>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )
        })}
      </ol>
    </section>
  )
}
