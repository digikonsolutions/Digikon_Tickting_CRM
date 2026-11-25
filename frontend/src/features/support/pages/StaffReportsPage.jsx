import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, Input, KpiCard, PageHeader, Skeleton } from '../../../components/ui'
import '../support.css'

const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)
const minutes = (value) => (value === null || value === undefined ? '—' : value >= 60 ? `${Math.floor(value / 60)}h ${value % 60}m` : `${value}m`)
const percent = (value) => (value === null || value === undefined ? '—' : `${value}%`)
const noData = (value, otherwise) => (value === null || value === undefined ? 'Nothing to measure yet' : otherwise)

// A bar per row. The name and number are real text (a list), so nothing depends on the picture.
function Bars({ title, rows }) {
  const entries = Object.entries(rows ?? {})
  const max = Math.max(1, ...entries.map(([, value]) => value))
  if (entries.length === 0) return <Card title={title}><p className="ui-field-help">No tickets in this range.</p></Card>
  return (
    <Card title={title}>
      <div className="sup-bars" role="list" aria-label={title}>{entries.map(([name, value]) => <div key={name} role="listitem" className="sup-bar"><span>{name}</span><i aria-hidden="true" style={{ width: `${(value / max) * 100}%` }} /><b>{value}</b></div>)}</div>
    </Card>
  )
}

// The KPIs of the spec for a date range, with the spec's targets next to the numbers they judge.
export function StaffReportsPage() {
  const [range, setRange] = useState({ from: day(-29), to: day(0) })
  const load = useCallback(() => authService.getStaffDashboard(range), [range])
  const { data, error, isLoading, reload } = useAsync(load)
  const k = data?.kpis

  return (
    <section className="page">
      <PageHeader eyebrow="Staff" title="Support reports" subtitle="How we are doing, for the dates you choose." />
      <div className="sup-filters">
        <Input label="From" type="date" value={range.from} max={range.to} onChange={(event) => setRange({ ...range, from: event.target.value })} />
        <Input label="To" type="date" value={range.to} min={range.from} onChange={(event) => setRange({ ...range, to: event.target.value })} />
      </div>
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {isLoading && !k ? <Skeleton variant="card" /> : k && (
        <>
          <div className="sup-kpis sup-kpis-wide">
            <KpiCard title="Tickets" value={k.volume.total} subtext="Opened in this range" />
            <KpiCard title="First response" value={minutes(k.first_response_minutes)} subtext={noData(k.first_response_minutes, 'Average, business time')} />
            <KpiCard title="SLA compliance" value={percent(k.sla_compliance_percent)} subtext={noData(k.sla_compliance_percent, 'Deadlines met')} />
            <KpiCard title="Reopen rate" value={percent(k.reopen_rate_percent)} subtext={noData(k.reopen_rate_percent, 'Reopened after closing')} />
            <KpiCard title="First-pass QA" value={percent(k.first_pass_qa_percent)} subtext={k.first_pass_qa_percent == null ? 'Nothing to measure yet' : `Target ${k.targets.first_pass_qa}% or more`} />
            <KpiCard title="On-time delivery" value={percent(k.on_time_percent)} subtext={k.on_time_percent == null ? 'Nothing to measure yet' : `Target ${k.targets.on_time}% or more`} />
            <KpiCard title="Critical defects" value={k.critical_defects} subtext={`Target ${k.targets.critical_defects}`} />
          </div>
          <Card title="Resolution time by priority">
            <table className="sup-queue"><thead><tr><th scope="col">Priority</th><th scope="col">Average (business time)</th></tr></thead>
              <tbody>{Object.entries(k.resolution_minutes ?? {}).map(([priority, value]) => <tr key={priority}><td>{priority}</td><td>{minutes(value)}</td></tr>)}{Object.keys(k.resolution_minutes ?? {}).length === 0 && <tr><td colSpan="2">No resolved tickets in this range.</td></tr>}</tbody></table>
          </Card>
          <div className="sup-grid2">
            <Bars title="Volume by agency" rows={k.volume.by_agency} />
            <Bars title="Volume by service" rows={k.volume.by_service} />
            <Bars title="Volume by type" rows={k.volume.by_type} />
            <Bars title="Volume by priority" rows={k.volume.by_priority} />
          </div>
        </>
      )}
    </section>
  )
}
