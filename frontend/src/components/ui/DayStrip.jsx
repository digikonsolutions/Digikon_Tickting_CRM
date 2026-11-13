import { WORKING_WEEKDAYS } from '../../lib/constants'
import { addDays, formatDateRange, formatHours, mondayOf, toDateStr, todayStr } from '../../lib/format'

// Hours scheduled per working day (Mon to Sat), one row per week that has allocations.
// `schedule` is `[{work_date: 'YYYY-MM-DD', hours}]`.
export function DayStrip({ schedule = [], className = '', emptyText = 'No scheduled hours.', noun = 'scheduled' }) {
  if (!schedule.length) return <p className="ui-day-strip-empty">{emptyText}</p>

  const today = todayStr()
  const hoursByDate = new Map(schedule.map((item) => [item.work_date, Number(item.hours) || 0]))
  const weeks = [...new Set(schedule.map((item) => toDateStr(mondayOf(item.work_date))))].sort()

  return (
    <div className={`ui-day-strips ${className}`.trim()}>
      {weeks.map((week) => (
        <div className="ui-day-strip-week" key={week}>
          <span className="ui-day-strip-label">{formatDateRange(week, addDays(week, WORKING_WEEKDAYS.length - 1))}</span>
          <div className="ui-day-strip" role="group" aria-label={`Hours ${noun} per day, week of ${week}`}>
            {WORKING_WEEKDAYS.map((label, index) => {
              const date = addDays(week, index)
              const hours = hoursByDate.get(date) ?? 0
              return (
                <div
                  key={date}
                  className={`ui-day-cell${date === today ? ' is-today' : ''}${hours > 0 ? ' has-hours' : ''}`}
                  aria-label={`${label} ${date.slice(8)}: ${hours > 0 ? formatHours(hours) : 'no hours'}`}
                >
                  <span className="ui-day-cell-label">{label}</span>
                  <span className="ui-day-cell-num">{date.slice(8)}</span>
                  <span className="ui-day-cell-hours">{hours > 0 ? formatHours(hours) : '·'}</span>
                </div>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}
