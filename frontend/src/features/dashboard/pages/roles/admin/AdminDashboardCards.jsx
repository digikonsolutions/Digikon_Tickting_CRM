import { Link } from 'react-router-dom'
import {
  Avatar, Badge, Button, CapacityBar, Card, EmptyState, FilterChips, Icon, KpiCard, Skeleton, Table,
} from '../../../../../components/ui'
import { formatDateRange } from '../../../../../lib/format'
import { DEPARTMENTS, labelOf } from '../../../../admin/employee/employeeForm'
import './adminDashboard.css'

/* Load colour: under 50% neutral, 50-84% teal, 85-99% amber, 100% and over red. */
function loadTone(percent) {
  if (percent >= 100) return 'hot'
  if (percent >= 85) return 'warm'
  if (percent >= 50) return 'ok'
  return 'neutral'
}
const KPI_TONE = { hot: 'danger', warm: 'warm', ok: 'teal', neutral: 'neutral' }

/** 7-point polyline for the KPI sparkline. Returns null when every value is zero (nothing to show). */
function sparklinePath(values, width = 168, height = 24) {
  if (values.length < 2 || values.every((value) => !value)) return null
  const max = Math.max(...values, 1)
  const step = width / (values.length - 1)
  return values.map((value, index) => `${index === 0 ? 'M' : 'L'}${(index * step).toFixed(1)} ${(height - (value / max) * (height - 4) - 2).toFixed(1)}`).join(' ')
}

function KpiLink({ to, children }) {
  return <Link className="adm-kpi-link" to={to}>{children}</Link>
}

export function KpiRow({ data }) {
  const { employees, agencies } = data
  const bookedHours = (data.scheduled_hours ?? []).map((day) => Number(day.hours) || 0)
  const spark = sparklinePath(bookedHours)
  const utilTone = loadTone(employees.avg_utilization_percent)

  return (
    <div className="adm-kpis">
      <KpiLink to="/admin/employees">
        <KpiCard title="Employees" value={employees.total} subtext={`${employees.active} active · ${employees.inactive} inactive`} icon="users" tone="info" />
      </KpiLink>
      <KpiLink to="/admin/employees">
        <KpiCard title="Available" value={employees.available} subtext="Active, not held by an agency" icon="userPlus" tone="success" />
      </KpiLink>
      <KpiLink to="/admin/assignments">
        <KpiCard title="Held" value={employees.held} subtext="Working for an agency" icon="briefcase" tone="info" />
      </KpiLink>
      <KpiLink to="/admin/agency-owners">
        <KpiCard title="Agencies" value={agencies.total} subtext={`${agencies.active} active · ${agencies.suspended} suspended`} icon="building" tone={agencies.suspended > 0 ? 'warm' : 'info'} />
      </KpiLink>
      <KpiLink to="/admin/assignments">
        <KpiCard title="Avg utilization" value={`${employees.avg_utilization_percent}%`} subtext="Of 36 h per active employee this week" icon="chart" tone={KPI_TONE[utilTone]}>
          {spark && (
            <svg className="ui-kpi-sparkline" viewBox="0 0 168 24" role="img" aria-label={`Hours booked over the next seven days: ${bookedHours.map((hours) => hours.toFixed(1)).join(', ')}`} preserveAspectRatio="none">
              <path d={spark} />
            </svg>
          )}
        </KpiCard>
      </KpiLink>
    </div>
  )
}

const EMPLOYEE_FILTERS = (employees) => [
  { value: 'all', label: `All ${employees.active}` },
  { value: 'available', label: `Available ${employees.available}` },
  { value: 'held', label: `Held ${employees.held}` },
  { value: 'overloaded', label: `Overloaded ${employees.overloaded}` },
]

export function EmployeeCapacityCard({ data, filter, onFilter, busy }) {
  const { employees, employee_capacity: rows } = data
  const matching = { all: employees.active, available: employees.available, held: employees.held, overloaded: employees.overloaded }[filter]

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Employee capacity</h2>
            <p>{data.week_start ? `Week of ${formatDateRange(data.week_start, data.week_end)}` : 'This week'} · {data.weekly_budget_hours} h budget each</p>
          </div>
          <Link className="adm-link" to="/admin/employees">View all employees</Link>
        </div>
        <FilterChips label="Filter employees" options={EMPLOYEE_FILTERS(employees)} value={filter} onChange={onFilter} disabled={busy} />
        {rows.length === 0 ? (
          <EmptyState
            title={employees.active === 0 ? 'No employees yet' : 'No employees match this filter'}
            action={employees.active === 0 ? <Link className="ui-button ui-button-primary ui-button-sm" to="/admin/employees/new">Create employee</Link> : <Button type="button" variant="secondary" size="sm" onClick={() => onFilter('all')}>Show all</Button>}
          >
            {employees.active === 0 ? 'Add your first employee to start planning capacity.' : 'Overloaded means over 90% of the weekly budget.'}
          </EmptyState>
        ) : (
          <ul className="adm-emp-list" aria-label="Employees by utilization">
            {rows.map((row) => (
              <li key={row.id}>
                <Link className="adm-emp-row" to={`/admin/employees/${row.id}`}>
                  <span className="adm-person">
                    <Avatar name={row.name} src={row.photo_url} size="md" />
                    <span>
                      <strong>{row.name}</strong>
                      <small>{row.department ? labelOf(DEPARTMENTS, row.department) : 'No department'}</small>
                    </span>
                  </span>
                  <span className="adm-status">
                    {row.holder
                      ? <Badge variant="info" title={`${row.holder.name} · ${row.holder.agency_name}`}>Held by {row.holder.name} · {row.holder.agency_name}</Badge>
                      : <Badge variant="success">Available</Badge>}
                  </span>
                  <span className="adm-load">
                    <CapacityBar used={row.used_hours} budget={row.budget_hours} showRemaining={false} tone={loadTone(row.utilization_percent)} ariaLabel={`${row.name}: ${row.utilization_percent}% of weekly capacity`} />
                    <strong>{row.utilization_percent}%</strong>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
        {matching > rows.length && (
          <div className="adm-more"><Link className="adm-link" to="/admin/employees">Showing {rows.length} of {matching}. View all employees</Link></div>
        )}
      </div>
    </Card>
  )
}

export function AgenciesCard({ data, onToggle }) {
  const { agencies, agency_rows: rows } = data
  const columns = [
    {
      key: 'name',
      label: 'Agency',
      render: (_value, row) => <span className="adm-agency-name"><strong>{row.name}</strong><small>{row.owner_name ? `Owner: ${row.owner_name}` : 'No owner'}</small></span>,
    },
    { key: 'status', label: 'Status', render: (value) => <Badge variant={value === 'active' ? 'success' : 'warning'}>{value === 'active' ? 'Active' : 'Suspended'}</Badge> },
    { key: 'employees_held', label: 'Employees held', render: (value) => <span className="adm-num">{value}</span> },
    { key: 'open_projects', label: 'Open projects', render: (value) => <span className="adm-num">{value}</span> },
    { key: 'hours_booked_this_week', label: 'Hours this week', render: (value) => <span className="adm-num">{Number(value).toFixed(1)} h</span> },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, row) => (
        <Button type="button" size="sm" variant="secondary" aria-label={`${row.status === 'active' ? 'Suspend' : 'Activate'} ${row.name}`} onClick={() => onToggle(row)}>
          {row.status === 'active' ? 'Suspend' : 'Activate'}
        </Button>
      ),
    },
  ]

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Agencies</h2>
            <p>{agencies.active} active · {agencies.suspended} suspended{agencies.total > rows.length ? ` · top ${rows.length} by hours this week` : ''}</p>
          </div>
          <Link className="adm-link" to="/admin/agency-owners">Manage agency owners</Link>
        </div>
        <Table columns={columns} rows={rows} emptyTitle="No agencies yet" emptyText="Invite an agency owner to create the first agency." />
      </div>
    </Card>
  )
}

const SEVERITY_ICON = { danger: 'alert', warning: 'alert', info: 'info' }
const SEVERITY_TEXT = { danger: 'Urgent', warning: 'Warning', info: 'For your information' }

export function AttentionCard({ items }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Needs attention</h2>
            <p>{items.length ? `${items.length} thing${items.length === 1 ? '' : 's'} to look at, most urgent first` : 'All clear'}</p>
          </div>
        </div>
        {items.length === 0 ? (
          <EmptyState icon={<Icon name="check" size={22} />} title="Nothing needs attention">Employees, agencies and projects are all on track.</EmptyState>
        ) : (
          <ul className="adm-attention" aria-label="Items that need attention">
            {items.map((item) => (
              <li key={item.type}>
                <Link className={`is-${item.severity}`} to={item.link}>
                  <span className="adm-attention-icon"><Icon name={SEVERITY_ICON[item.severity] ?? 'info'} size={16} /></span>
                  <span><span className="sr-only">{SEVERITY_TEXT[item.severity]}: </span>{item.label}</span>
                  <span className="adm-attention-arrow" aria-hidden="true"><Icon name="arrow" size={16} /></span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

// Colour carries meaning here: blue is waiting, teal is active work, green is done, grey is cancelled.
const PROJECT_SEGMENTS = [
  { key: 'scheduled', label: 'Scheduled', tone: 'scheduled' },
  { key: 'in_progress', label: 'In progress', tone: 'active' },
  { key: 'completed', label: 'Completed', tone: 'done' },
  { key: 'cancelled', label: 'Cancelled', tone: 'cancelled' },
]

export function WorkSummaryCard({ summary }) {
  const { projects, tickets } = summary
  const total = projects.total
  const percent = (value) => (total > 0 ? Math.round((value / total) * 100) : 0)
  const description = PROJECT_SEGMENTS.map((segment) => `${projects[segment.key] ?? 0} ${segment.label.toLowerCase()}`).join(', ')

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Work summary</h2>
            <p>{total} project{total === 1 ? '' : 's'} · {tickets.total} ticket{tickets.total === 1 ? '' : 's'}</p>
          </div>
          <Link className="adm-link" to="/admin/projects">All projects</Link>
        </div>
        {total === 0 ? (
          <p className="adm-summary-empty">No projects yet. They appear here once an agency creates one.</p>
        ) : (
          <>
            <div className="adm-stack" role="img" aria-label={`Projects by status: ${description}`}>
              {PROJECT_SEGMENTS.map((segment) => (projects[segment.key] > 0 && (
                <span key={segment.key} className={`adm-stack-seg is-${segment.tone}`} style={{ flexGrow: projects[segment.key] }} title={`${segment.label}: ${projects[segment.key]}`} />
              )))}
            </div>
            <ul className="adm-legend" aria-label="Projects by status">
              {PROJECT_SEGMENTS.map((segment) => (
                <li key={segment.key}>
                  <Link to={`/admin/projects?status=${segment.key}`} aria-label={`${segment.label}: ${projects[segment.key] ?? 0} projects. View them`}>
                    <span className={`adm-dot is-${segment.tone}`} aria-hidden="true" />
                    <span className="adm-legend-label">{segment.label}</span>
                    <strong>{projects[segment.key] ?? 0}</strong>
                    <small>{percent(projects[segment.key] ?? 0)}%</small>
                  </Link>
                </li>
              ))}
              <li className="adm-legend-tickets">
                <Link to="/admin/tickets" aria-label={`Tickets: ${tickets.total}. View them`}>
                  <span className="adm-dot is-ticket" aria-hidden="true" />
                  <span className="adm-legend-label">Tickets</span>
                  <strong>{tickets.total}</strong>
                  <small>all projects</small>
                </Link>
              </li>
            </ul>
          </>
        )}
      </div>
    </Card>
  )
}

/** Same grid as the loaded page, so nothing jumps when the data arrives. */
export function DashboardSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading dashboard" role="status">
      <div className="adm-kpis">{Array.from({ length: 5 }, (_, index) => <Skeleton key={index} variant="card" className="adm-skeleton-kpi" />)}</div>
      <div className="adm-grid" style={{ marginTop: 'var(--space-4)' }}>
        <div className="adm-skeleton-rows">{Array.from({ length: 6 }, (_, index) => <Skeleton key={index} variant="row" />)}</div>
        <Skeleton variant="card" className="adm-skeleton-card" />
      </div>
    </div>
  )
}
