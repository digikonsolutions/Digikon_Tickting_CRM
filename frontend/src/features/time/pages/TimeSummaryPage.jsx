import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, EmptyState, Input, PageHeader, Select, Skeleton } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { addDays, formatHours, mondayOf, toDateStr, todayStr } from '../../../lib/format'

const GROUPS = {
  admin: [['employee', 'Specialist'], ['agency', 'Agency'], ['project', 'Project']],
  agency: [['employee', 'Specialist'], ['project', 'Project']],
}

function presetRange(preset) {
  const today = todayStr()
  switch (preset) {
    case 'week': return { from: toDateStr(mondayOf()), to: today }
    case 'month': return { from: `${today.slice(0, 8)}01`, to: today }
    case '7': return { from: addDays(today, -6), to: today }
    default: return { from: addDays(today, -29), to: today }
  }
}

// Logged vs scheduled vs estimated hours. `scope` is 'admin' (every agency) or 'agency' (the owner's own agency).
export function TimeSummaryPage({ scope }) {
  const [range, setRange] = useState(() => presetRange('month'))
  const [groupBy, setGroupBy] = useState('employee')
  const valid = Boolean(range.from && range.to && range.from <= range.to)
  const load = useCallback(
    () => (valid ? authService.getTimeSummary(scope, { ...range, groupBy }) : Promise.resolve(null)),
    [scope, range, groupBy, valid],
  )
  const { data, error, isLoading, reload } = useAsync(load)
  const groups = GROUPS[scope]
  const groupLabel = groups.find(([value]) => value === groupBy)?.[1] ?? 'Group'

  return (
    <section className="page time-summary-page">
      <PageHeader
        eyebrow={scope === 'admin' ? 'Admin' : 'Agency'}
        title="Time summary"
        subtitle="Hours logged by specialists against what was scheduled and estimated."
      />

      <Card>
        <form className="time-summary-filters" onSubmit={(event) => event.preventDefault()}>
          <Input label="From" type="date" value={range.from} max={range.to || undefined} onChange={(event) => setRange((current) => ({ ...current, from: event.target.value }))} />
          <Input label="To" type="date" value={range.to} min={range.from || undefined} onChange={(event) => setRange((current) => ({ ...current, to: event.target.value }))} />
          <Select label="Group by" value={groupBy} onChange={(event) => setGroupBy(event.target.value)}>
            {groups.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
          <div className="time-summary-presets" role="group" aria-label="Quick ranges">
            {[['week', 'This week'], ['7', 'Last 7 days'], ['month', 'This month'], ['30', 'Last 30 days']].map(([id, label]) => (
              <Button key={id} type="button" size="sm" variant="secondary" onClick={() => setRange(presetRange(id))}>{label}</Button>
            ))}
          </div>
        </form>
      </Card>

      {!valid && <Alert variant="warning">Choose a start date that is not after the end date.</Alert>}
      {error && (
        <Alert variant="error" title="Could not load the summary">
          {error.errors?.to?.[0] ?? error.message}
          <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      {valid && isLoading && !data && <Skeleton variant="card" />}
      {data && (
        <Card
          header={(
            <div className="time-summary-header">
              <h2>{groupLabel} totals</h2>
              <a className="ui-button ui-button-secondary ui-button-sm" href={authService.timeSummaryCsvUrl(scope, { ...range, groupBy })} download>
                Download CSV
              </a>
            </div>
          )}
        >
          {data.rows.length === 0
            ? <EmptyState title="No time in this range">Try a wider date range.</EmptyState>
            : (
              <div className="time-summary-scroll">
                <table className="time-summary-table">
                  <thead>
                    <tr>
                      <th scope="col">{groupLabel}</th>
                      <th scope="col">Logged</th>
                      <th scope="col">Scheduled</th>
                      <th scope="col">Estimated</th>
                      <th scope="col">Utilization</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.rows.map((row) => (
                      <tr key={row.group.id ?? row.group.name}>
                        <th scope="row">{row.group.name}</th>
                        <td>{formatHours(row.logged_hours)}</td>
                        <td>{formatHours(row.scheduled_hours)}</td>
                        <td>{formatHours(row.estimated_hours)}</td>
                        <td>{row.utilization === null ? '—' : `${row.utilization}%`}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr>
                      <th scope="row">Total</th>
                      <td>{formatHours(data.totals.logged_hours)}</td>
                      <td>{formatHours(data.totals.scheduled_hours)}</td>
                      <td>{formatHours(data.totals.estimated_hours)}</td>
                      <td>{data.totals.utilization === null ? '—' : `${data.totals.utilization}%`}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
        </Card>
      )}
    </section>
  )
}
