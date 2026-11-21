import { Link } from 'react-router-dom'
import { Badge, Button, Card, EmptyState, KpiCard, Skeleton } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { BarChart, DonutChart, LineChart } from '../../../components/charts'

export function OverviewTab({
  data,
  isLoading,
  error,
  onRetry,
  scope = 'admin',
}) {
  if (isLoading && !data) {
    return (
      <div className="rp-overview-tab">
        <div className="rp-kpi-grid">
          <Skeleton style={{ height: '100px', borderRadius: '0.75rem' }} />
          <Skeleton style={{ height: '100px', borderRadius: '0.75rem' }} />
          <Skeleton style={{ height: '100px', borderRadius: '0.75rem' }} />
          <Skeleton style={{ height: '100px', borderRadius: '0.75rem' }} />
        </div>
        <div className="rp-charts-grid">
          <Skeleton style={{ height: '260px', borderRadius: '0.75rem' }} />
          <Skeleton style={{ height: '260px', borderRadius: '0.75rem' }} />
        </div>
      </div>
    )
  }

  if (error && !data) {
    return (
      <Card>
        <ApiErrorMessage error={error} />
        <div style={{ marginTop: '1rem' }}>
          <Button type="button" variant="secondary" onClick={onRetry}>
            Retry loading overview
          </Button>
        </div>
      </Card>
    )
  }

  if (!data) {
    return <EmptyState title="No overview data" description="Try selecting a different date range." />
  }

  const projects = data.projects ?? {}
  const openProjects = (projects.scheduled || 0) + (projects.in_progress || 0)
  const completionRate = data.completion_rate
  const overdueCount = data.overdue_projects ?? 0
  const tickets = data.tickets ?? {}
  const agencies = data.agencies
  const employees = data.employees ?? {}
  const series = data.series ?? {}
  const attention = data.attention ?? {}

  const overdueProjects = attention.overdue_projects ?? []
  const unassignedEmployees = attention.unassigned_employees?.employees ?? []
  const unassignedCount = attention.unassigned_employees?.count ?? 0
  const overCapacityWeeks = attention.over_capacity ?? []

  const ticketSlices = [
    { label: 'Done', value: tickets.done || 0, color: '#0d9488' },
    { label: 'In progress', value: tickets.in_progress || 0, color: '#0284c7' },
    { label: 'In review', value: tickets.in_review || 0, color: '#6366f1' },
    { label: 'Changes requested', value: tickets.changes_requested || 0, color: '#f59e0b' },
    {
      label: 'Blocked / Waiting',
      value: (tickets.blocked || 0) + (tickets.waiting_on_client || 0),
      color: '#ef4444',
    },
    { label: 'Todo', value: tickets.todo || 0, color: '#94a3b8' },
  ]

  const totalTickets = ticketSlices.reduce((sum, s) => sum + s.value, 0)

  return (
    <div className="rp-overview-tab">
      {/* KPI Cards Row */}
      <section className="rp-kpi-grid" aria-label="Key performance indicators">
        <KpiCard
          title="Open projects"
          value={openProjects}
          subtext={`${projects.completed || 0} completed · ${projects.cancelled || 0} cancelled`}
          icon="briefcase"
          tone="teal"
        />

        <KpiCard
          title="Completion rate"
          value={completionRate !== null && completionRate !== undefined ? `${completionRate}%` : '—'}
          subtext={`${projects.completed || 0} of ${openProjects + (projects.completed || 0)} projects`}
          icon="check"
          tone="teal"
        />

        <KpiCard
          title="Overdue projects"
          value={overdueCount}
          subtext={overdueCount > 0 ? 'Past agreed due date' : 'All delivery schedules on track'}
          icon="alert"
          tone={overdueCount > 0 ? 'rose' : 'teal'}
        />

        <KpiCard
          title="Tickets in flight"
          value={totalTickets}
          subtext={`${tickets.done || 0} completed · ${tickets.in_progress || 0} active`}
          icon="ticket"
          tone="slate"
        />

        {scope === 'admin' && agencies && (
          <KpiCard
            title="Agencies"
            value={agencies.active ?? 0}
            subtext={`${agencies.suspended ?? 0} suspended · ${(agencies.active || 0) + (agencies.suspended || 0)} total`}
            icon="building"
            tone="teal"
          />
        )}

        {scope === 'admin' && employees.active !== null && (
          <KpiCard
            title="Available specialists"
            value={employees.available ?? 0}
            subtext={`${employees.held ?? 0} assigned of ${employees.active ?? 0} active`}
            icon="users"
            tone="teal"
          />
        )}

        {scope !== 'admin' && (
          <KpiCard
            title="Active specialists"
            value={employees.held ?? 0}
            subtext="Assigned specialists across projects"
            icon="users"
            tone="teal"
          />
        )}
      </section>

      {/* Charts Row */}
      <section className="rp-charts-grid" aria-label="Visual trends">
        <BarChart
          title="Projects completed per week"
          labels={series.projects_completed_per_week?.labels ?? []}
          values={series.projects_completed_per_week?.values ?? []}
          unit="projects"
          barColor="var(--brand)"
        />

        <LineChart
          title="Scheduled capacity hours per day"
          labels={series.scheduled_hours_per_day?.labels ?? []}
          values={series.scheduled_hours_per_day?.values ?? []}
          unit="h"
          strokeColor="var(--brand)"
        />

        <DonutChart
          title="Ticket status distribution"
          slices={ticketSlices}
          centerLabel="Tickets"
          centerValue={totalTickets}
        />
      </section>

      {/* Needs Attention Section */}
      <section aria-label="Items needing attention">
        <Card title="Needs attention" description="Overdue schedules, staffing availability, and capacity peaks in this period.">
          <div className="rp-attention-grid">
            {/* Overdue projects */}
            <div className="rp-attention-col">
              <h4 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.5rem', color: 'var(--color-heading)' }}>
                Overdue projects ({overdueProjects.length})
              </h4>
              {overdueProjects.length === 0 ? (
                <p className="rp-attention-empty">No overdue projects in this range.</p>
              ) : (
                <ul className="rp-attention-list">
                  {overdueProjects.map((p) => (
                    <li key={p.id} className="rp-attention-item">
                      <div className="rp-attention-item-info">
                        <Link
                          to={scope === 'admin' ? `/admin/projects/${p.id}` : `/agency/projects/${p.id}`}
                          className="rp-attention-item-title"
                          style={{ color: 'var(--brand)', textDecoration: 'none' }}
                        >
                          {p.title}
                        </Link>
                        <span className="rp-attention-item-sub">
                          {p.agency ? `${p.agency} · ` : ''}Due {p.due_date}
                        </span>
                      </div>
                      <Badge variant="danger">{p.days_late}d late</Badge>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            {/* Unassigned specialists (Admin only) */}
            {scope === 'admin' && (
              <div className="rp-attention-col">
                <h4 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.5rem', color: 'var(--color-heading)' }}>
                  Unassigned specialists ({unassignedCount})
                </h4>
                {unassignedEmployees.length === 0 ? (
                  <p className="rp-attention-empty">All active specialists are currently assigned.</p>
                ) : (
                  <ul className="rp-attention-list">
                    {unassignedEmployees.map((emp) => (
                      <li key={emp.id} className="rp-attention-item">
                        <div className="rp-attention-item-info">
                          <Link
                            to={`/admin/employees/${emp.id}/activity`}
                            className="rp-attention-item-title"
                            style={{ color: 'var(--brand)', textDecoration: 'none' }}
                          >
                            {emp.name}
                          </Link>
                          <span className="rp-attention-item-sub">{emp.email}</span>
                        </div>
                        <Badge variant="info">Available</Badge>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}

            {/* Over-capacity weeks */}
            <div className="rp-attention-col">
              <h4 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.5rem', color: 'var(--color-heading)' }}>
                Over-capacity weeks ({overCapacityWeeks.length})
              </h4>
              {overCapacityWeeks.length === 0 ? (
                <p className="rp-attention-empty">No specialists exceed weekly capacity (36h limit).</p>
              ) : (
                <ul className="rp-attention-list">
                  {overCapacityWeeks.map((oc, idx) => (
                    <li key={idx} className="rp-attention-item">
                      <div className="rp-attention-item-info">
                        <span className="rp-attention-item-title">{oc.employee}</span>
                        <span className="rp-attention-item-sub">Week {oc.week}</span>
                      </div>
                      <Badge variant="danger">{oc.hours}h / 36h</Badge>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </Card>
      </section>
    </div>
  )
}
