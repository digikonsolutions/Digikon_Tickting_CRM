import { Link } from 'react-router-dom'
import {
  Avatar, Badge, Button, CapacityBar, Card, DueText, EmptyState, FilterChips, Icon, KpiCard, RelativeTime, Skeleton, StatusBadge, Table,
} from '../../../../../components/ui'
import { formatDate, formatDateRange } from '../../../../../lib/format'
import { agencyColumns } from '../../../../admin/agencies/agencyColumns'
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

/** Polyline for the KPI sparkline. Returns null when every value is zero (nothing to show). */
function sparklinePath(values, width = 168, height = 24) {
  if (values.length < 2 || values.every((value) => !value)) return null
  const max = Math.max(...values, 1)
  const step = width / (values.length - 1)
  return values.map((value, index) => `${index === 0 ? 'M' : 'L'}${(index * step).toFixed(1)} ${(height - (value / max) * (height - 4) - 2).toFixed(1)}`).join(' ')
}

function KpiLink({ to, children }) {
  return <Link className="adm-kpi-link" to={to}>{children}</Link>
}

/** One row of seven KPIs. Work (projects, tickets) is folded in here instead of a separate summary card. */
export function KpiRow({ data }) {
  const { employees, agencies, work_summary: work } = data
  const bookedHours = (data.scheduled_hours ?? []).map((day) => Number(day.hours) || 0)
  const spark = sparklinePath(bookedHours)
  const utilTone = loadTone(employees.avg_utilization_percent)
  const activeProjects = work.projects.scheduled + work.projects.in_progress

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
      <KpiLink to="/admin/agencies">
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
      <KpiLink to="/admin/projects">
        <KpiCard title="Projects" value={work.projects.total} subtext={`${activeProjects} active · ${work.projects.completed} completed`} icon="queue" tone="info" />
      </KpiLink>
      <KpiLink to="/admin/tickets">
        <KpiCard title="Tickets" value={work.tickets.total} subtext="Across all projects" icon="ticket" tone="info" />
      </KpiLink>
    </div>
  )
}

/**
 * Support at a glance: four live tiles, each opening the matching view of the support queue. The first thing an
 * administrator sees, because a waiting agency is the most time-sensitive thing in the product.
 */
export function SupportBand({ support }) {
  const hot = (count) => (count > 0 ? 'danger' : 'success')
  const tiles = [
    { title: 'Open tickets', value: support.open, subtext: `${support.resolved_today} resolved today`, icon: 'inbox', tone: 'info', to: '/staff/tickets' },
    { title: 'Unassigned', value: support.unassigned, subtext: support.unassigned > 0 ? 'Waiting for an owner' : 'Everything has an owner', icon: 'userPlus', tone: support.unassigned > 0 ? 'warm' : 'success', to: '/staff/tickets?view=unassigned' },
    { title: 'Critical', value: support.critical, subtext: support.critical > 0 ? 'P1 tickets still open' : 'No P1 tickets open', icon: 'alert', tone: hot(support.critical), to: '/staff/tickets?view=critical' },
    { title: 'SLA overdue', value: support.breached_sla, subtext: support.breached_sla > 0 ? 'Past their deadline' : 'All within their deadlines', icon: 'clock', tone: hot(support.breached_sla), to: '/staff/tickets?sort=sla' },
  ]

  return (
    <section aria-label="Support at a glance">
      <div className="adm-section-head"><h2>Support</h2><Link className="adm-link" to="/staff/tickets">Open the queue</Link></div>
      <div className="adm-kpis adm-kpis-support">
        {tiles.map((tile) => (
          <KpiLink key={tile.title} to={tile.to}>
            <KpiCard title={tile.title} value={tile.value} subtext={tile.subtext} icon={tile.icon} tone={tile.tone} />
          </KpiLink>
        ))}
      </div>
    </section>
  )
}

/** The latest changes across all support tickets, newest first; each line opens its ticket. */
export function SupportActivityCard({ support }) {
  const items = support.recent_activity ?? []

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Support activity</h2>
            <p>{items.length ? 'The latest changes across all support tickets' : 'Nothing has happened yet'}</p>
          </div>
          <Link className="adm-link" to="/staff/tickets">Open queue</Link>
        </div>
        {items.length === 0 ? (
          <EmptyState icon={<Icon name="inbox" size={22} />} title="No support activity yet">New tickets, status changes and overdue deadlines show up here.</EmptyState>
        ) : (
          <ul className="adm-feed" aria-label="Recent support activity">
            {items.map((item) => (
              <li key={item.id}>
                <Link to={`/staff/tickets/${item.ticket_ref}`}>
                  <span className="adm-feed-dot" aria-hidden="true" />
                  <span className="adm-feed-body">
                    <strong>{item.ticket_ref} · {item.ticket_title}</strong>
                    <span>{item.text}{item.agency_name ? ` · ${item.agency_name}` : ''}{item.actor_name ? ` · by ${item.actor_name}` : ''}</span>
                  </span>
                  <small className="adm-feed-when"><RelativeTime date={item.created_at} /></small>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

const SEVERITY_ICON = { danger: 'alert', warning: 'alert', info: 'info' }
const SEVERITY_TEXT = { danger: 'Urgent', warning: 'Warning', info: 'For your information' }

// Also used by the agency dashboard, which passes its own words for the empty state.
export function AttentionCard({ items, emptyText = 'Employees, agencies and projects are all on track.' }) {
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
          <EmptyState icon={<Icon name="check" size={22} />} title="Nothing needs attention">{emptyText}</EmptyState>
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

const QUICK_ACTIONS = [
  { to: '/staff/tickets', icon: 'inbox', label: 'Support queue', hint: 'Triage and answer agency tickets' },
  { to: '/admin/employees/new', icon: 'userPlus', label: 'New employee', hint: 'Add someone to the shared pool' },
  { to: '/admin/agency-owners/new', icon: 'building', label: 'New agency owner', hint: 'Create an agency and send an invitation' },
  { to: '/admin/assignments', icon: 'calendar', label: 'Review assignments', hint: 'Who holds whom, and for how long' },
  { to: '/admin/reports', icon: 'chart', label: 'Business reports', hint: 'Utilization, agencies, projects and tickets' },
]

export function QuickActionsCard() {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Quick actions</h2><p>Common administration tasks</p></div></div>
        <ul className="adm-quick" aria-label="Quick actions">
          {QUICK_ACTIONS.map((action) => (
            <li key={action.to}>
              <Link to={action.to}>
                <span className="adm-quick-icon"><Icon name={action.icon} size={18} /></span>
                <span><strong>{action.label}</strong><small>{action.hint}</small></span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </Card>
  )
}

export function AgenciesCard({ data, onToggle }) {
  const { agencies, agency_rows: rows } = data

  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Agencies</h2>
            <p>{agencies.active} active · {agencies.suspended} suspended{agencies.total > rows.length ? ` · top ${rows.length} by hours this week` : ''}</p>
          </div>
          <Link className="adm-link" to="/admin/agencies">View all agencies</Link>
        </div>
        <Table columns={agencyColumns({ onToggle })} rows={rows} emptyTitle="No agencies yet" emptyText="Invite an agency owner to create the first agency." />
      </div>
    </Card>
  )
}

/** Plans and renewals. Without contracts it says so plainly; with them it lists what ends soonest. */
export function ContractsCard({ contracts }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div>
            <h2>Contracts expiring</h2>
            <p>{contracts.available ? `${contracts.with_contract} with a contract · ${contracts.without_contract} without` : 'Plan usage and renewals'}</p>
          </div>
        </div>
        {contracts.expiring.length > 0 ? (
          <ul className="adm-expiring" aria-label="Contracts ending soon">
            {contracts.expiring.map((item) => (
              <li key={item.agency_id}>
                <Link to={`/admin/agencies/${item.agency_id}?tab=contract`}>
                  <strong>{item.name}</strong>
                  <span>{item.ends_on ? `Ends ${formatDate(item.ends_on)}` : 'No end date'}</span>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={<Icon name="file" size={22} />} title={contracts.available ? 'No contracts ending soon' : 'No contracts yet'}>
            {contracts.available
              ? 'Nothing renews in the next 30 days.'
              : `${contracts.without_contract} agenc${contracts.without_contract === 1 ? 'y has' : 'ies have'} no contract. Plans, seats and renewal dates will show here once contracts are set up.`}
          </EmptyState>
        )}
      </div>
    </Card>
  )
}

export function RecentProjectsCard({ projects }) {
  const rows = projects.slice(0, 4)
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div><h2>Recent projects</h2><p>Latest work across all agencies</p></div>
          <Link className="adm-link" to="/admin/projects">All projects</Link>
        </div>
        {rows.length === 0 ? (
          <EmptyState title="No projects yet">Projects appear here once an agency creates one.</EmptyState>
        ) : (
          <ul className="adm-recent" aria-label="Recent projects">
            {rows.map((project) => (
              <li key={project.id}>
                <Link to={`/admin/projects/${project.id}`}>
                  <span className="adm-recent-main">
                    <strong>{project.title}</strong>
                    <small>{[project.agency_name, project.employee?.name].filter(Boolean).join(' · ') || 'Agency project'}</small>
                  </span>
                  <span className="adm-recent-meta">
                    <StatusBadge status={project.status} />
                    <small><DueText dueDate={project.due_date} status={project.status} /></small>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

const EMPLOYEE_FILTERS = (employees) => [
  { value: 'all', label: `All ${employees.active}` },
  { value: 'available', label: `Available ${employees.available}` },
  { value: 'held', label: `Held ${employees.held}` },
  { value: 'overloaded', label: `Overloaded ${employees.overloaded}` },
]
// With only a handful of employees a filter is clutter: the whole list already fits.
const FILTER_MIN_EMPLOYEES = 5

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
        {employees.active >= FILTER_MIN_EMPLOYEES && (
          <FilterChips label="Filter employees" options={EMPLOYEE_FILTERS(employees)} value={filter} onChange={onFilter} disabled={busy} />
        )}
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
                    {row.holder ? (
                      <>
                        <span className="adm-held-label">Held by</span>
                        <Badge variant="info" title={`Held by ${row.holder.name} · ${row.holder.agency_name}`}>{row.holder.agency_name}</Badge>
                      </>
                    ) : <Badge variant="success">Available</Badge>}
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

/** Same grid as the loaded page, so nothing jumps when the data arrives. */
export function DashboardSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading dashboard" role="status" className="adm-skeleton">
      <div className="adm-kpis">{Array.from({ length: 7 }, (_, index) => <Skeleton key={index} variant="card" className="adm-skeleton-kpi" />)}</div>
      <div className="adm-row"><Skeleton variant="card" className="adm-skeleton-card" /><Skeleton variant="card" className="adm-skeleton-card" /></div>
      <div className="adm-row"><Skeleton variant="card" className="adm-skeleton-card" /><Skeleton variant="card" className="adm-skeleton-card" /></div>
      <div className="adm-skeleton-rows">{Array.from({ length: 4 }, (_, index) => <Skeleton key={index} variant="row" />)}</div>
    </div>
  )
}
