import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { useAsync } from '../../../../../hooks/useAsync'
import { formatDate, formatDateRange, pluralize } from '../../../../../lib/format'
import { Alert, Avatar, Button, CapacityBar, Card, EmptyState, Icon, RelativeTime, StatusBadge } from '../../../../../components/ui'
import { EmployeeProfileDrawer } from '../../../../agency/components/EmployeeProfileDrawer'
import { RequestModal } from '../../../../agency/components/RequestModal'
import { AgencyDashboardSkeleton } from './AgencyDashboardCards'
import './agencyDashboard.css'
import './ownerDashboard.css'

const hours = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 1)} h`
const SEVERITY = { danger: { label: 'Urgent', icon: 'alert' }, warning: { label: 'Soon', icon: 'clock' }, info: { label: 'Info', icon: 'info' } }
const TYPE_ICON = { tickets_waiting: 'ticket', overdue_projects: 'briefcase', due_soon_projects: 'calendar', no_employee: 'users', employee_at_capacity: 'users', employee_near_capacity: 'users', contract_ends_soon: 'file' }
const HEALTH = { on_track: ['On track', 'ok'], at_risk: ['At risk', 'warn'], overdue: ['Overdue', 'bad'] }

function greeting() {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
}

function NeedsAttention({ items, onRequest }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Needs attention</h2><p>{items.length ? `${items.length} ${pluralize(items.length, 'thing')} to look at` : 'Nothing is waiting on you'}</p></div></div>
        {items.length === 0 ? (
          <EmptyState icon={<Icon name="check" size={22} />} title="You're all caught up">Projects, tickets, hours and your plan are all on track.</EmptyState>
        ) : (
          <ul className="od-attention" aria-label="Needs attention">
            {items.map((item) => {
              const severity = SEVERITY[item.severity] ?? SEVERITY.info
              return (
                <li key={item.type} className={`od-attention-row is-${item.severity}`}>
                  <span className="od-attention-icon" aria-hidden="true"><Icon name={TYPE_ICON[item.type] ?? severity.icon} size={18} /></span>
                  <span className="od-attention-text">
                    <strong><span className="od-severity">{severity.label}</span> {item.label}</strong>
                  </span>
                  {item.count > 1 && <span className="od-count" aria-label={`${item.count} items`}>{item.count}</span>}
                  {item.action.to
                    ? <Link className="ui-button ui-button-secondary ui-button-sm" to={item.action.to}>{item.action.label}</Link>
                    : <Button type="button" size="sm" variant="secondary" onClick={() => onRequest(item.action.request)}>{item.action.label}</Button>}
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </Card>
  )
}

function Kpis({ kpis, clientLimit, employeeLimit }) {
  const cards = [
    { label: 'Open projects', value: kpis.open_projects, to: '/agency/projects', icon: 'briefcase' },
    { label: 'Tickets in progress', value: kpis.tickets_in_progress, to: '/agency/tickets?status=in_progress', icon: 'ticket' },
    { label: 'Tickets waiting on you', value: kpis.tickets_waiting_on_you, to: '/agency/tickets?waiting=you', icon: 'chat', hot: kpis.tickets_waiting_on_you > 0 },
    { label: 'Employees', value: employeeLimit?.used ?? 0, to: '/agency/employees', icon: 'users' },
    { label: 'Clients', value: clientLimit?.allowed != null ? `${kpis.clients} / ${clientLimit.allowed}` : kpis.clients, to: '/agency/clients', icon: 'user' },
  ]
  return (
    <ul className="od-kpis" aria-label="Key numbers">
      {cards.map((card) => (
        <li key={card.label}>
          <Link to={card.to} className={`od-kpi${card.hot ? ' is-hot' : ''}`}>
            <span className="od-kpi-icon" aria-hidden="true"><Icon name={card.icon} size={18} /></span>
            <strong>{card.value}</strong>
            <span>{card.label}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

function PlanCard({ dashboard, onRequest }) {
  const { contract, limits, seats } = dashboard
  const h = limits.hours
  const percent = h.allowed ? Math.min(100, Math.round((h.used / h.allowed) * 100)) : null
  const days = contract?.days_remaining
  const renews = contract?.renews_on ?? contract?.ends_on
  const seatsText = seats.seats != null ? `${seats.used} of ${seats.seats} ${pluralize(seats.seats, 'employee')} assigned` : `${seats.used} ${pluralize(seats.used, 'employee')} assigned`
  const facts = contract && [
    ['Status', contract.status], ['Starts', contract.starts_on && formatDate(contract.starts_on)], ['Ends', contract.ends_on && formatDate(contract.ends_on)],
    ['Auto renew', contract.auto_renew == null ? null : contract.auto_renew ? 'Yes' : 'No'], ['Payment terms', contract.payment_terms],
    ['Price', contract.price != null ? `${contract.price} ${contract.currency ?? ''}`.trim() : null],
  ]
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Plan and hours</h2><p>{contract?.plan_name ?? 'No plan on file'}</p></div></div>
        {percent != null ? (
          <div className="agd-month">
            <div className="agd-month-meta"><strong>{hours(h.used)} used · {hours(h.remaining)} left</strong><span>{percent}%</span></div>
            <div className={`adm-minibar agd-monthbar${percent >= 100 ? ' is-hot' : percent >= 80 ? ' is-warm' : ''}`} role="progressbar" aria-label="Included hours used this period" aria-valuemin="0" aria-valuemax={h.allowed} aria-valuenow={h.used}><span style={{ width: `${percent}%` }} /></div>
            <p className="od-sub">{hours(h.allowed)} included · {formatDateRange(h.period_start, h.period_end)}</p>
          </div>
        ) : (
          <p className="od-sub"><strong>{hours(h.used)}</strong> booked this month. No hour limit is set on your plan.</p>
        )}
        <dl className="od-plan-facts">
          <div><dt>Renews</dt><dd>{renews ? `${formatDate(renews)}${days != null && days >= 0 ? ` · ${days} ${pluralize(days, 'day')} left` : ''}` : '—'}</dd></div>
          <div><dt>Employees</dt><dd>{seatsText}</dd></div>
        </dl>
        <div className="agd-contract-actions">
          {h.allowed != null && <Button type="button" variant="secondary" size="sm" onClick={() => onRequest('more_hours')}>Request more hours</Button>}
          {contract && <Button type="button" variant="secondary" size="sm" onClick={() => onRequest('renewal')}>Request renewal</Button>}
        </div>
        {contract && (
          <details className="od-details">
            <summary>Contract details</summary>
            <dl className="agd-facts">{facts.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? <span className="adm-muted">—</span>}</dd></div>)}</dl>
            {contract.features?.length > 0 && <ul className="agd-includes">{contract.features.map((item) => <li key={item}>{item}</li>)}</ul>}
            {contract.document_available && <a className="ui-button ui-button-secondary ui-button-sm" href={authService.agencyContractDocumentUrl()}><Icon name="download" size={16} /> Download contract</a>}
          </details>
        )}
      </div>
    </Card>
  )
}

function ActiveProjects({ projects }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Active projects</h2><p>Soonest due first</p></div><Link className="adm-link" to="/agency/projects">View all</Link></div>
        {projects.length === 0 ? (
          <EmptyState title="No active projects">Create a project to schedule work with your employee.</EmptyState>
        ) : (
          <ul className="od-projects" aria-label="Active projects">
            {projects.map((project) => {
              const [healthLabel, healthTone] = HEALTH[project.health]
              return (
                <li key={project.id}>
                  <Link to={`/agency/projects/${project.id}`} className="od-project">
                    <span className="od-project-main">
                      <strong>{project.title}</strong>
                      <small>{project.client_name ?? 'No client'}{project.due_date ? ` · due ${formatDate(project.due_date)}` : ''}</small>
                    </span>
                    {project.employee && <Avatar name={project.employee.name} size="sm" />}
                    <span className={`od-health is-${healthTone}`}>{healthLabel}</span>
                    <span className="od-progress" role="progressbar" aria-label={`${project.title}: ${project.progress_percent}% of ticket hours done`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={project.progress_percent}>
                      <span style={{ width: `${project.progress_percent}%` }} />
                    </span>
                    <small className="od-progress-text">{project.progress_percent}% · {hours(project.ticket_hours_done)} of {hours(project.ticket_hours_total)}</small>
                    <StatusBadge status={project.status} />
                  </Link>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </Card>
  )
}

function Employees({ employees, seats, onProfile, onRequest }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Employees</h2><p>Hours booked this week</p></div><Link className="adm-link" to="/agency/employees">Manage</Link></div>
        {employees.length === 0 ? (
          <EmptyState title="No employees yet" action={<Button type="button" size="sm" onClick={() => onRequest('employee')}>Request an employee</Button>}>Employees are assigned by your account manager.</EmptyState>
        ) : (
          <ul className="od-employees" aria-label="Employees">
            {employees.map((employee) => (
              <li key={employee.id} className="od-employee">
                <Avatar name={employee.name} src={employee.photo_url} />
                <div className="od-employee-main">
                  <button type="button" className="ui-link-button" onClick={() => onProfile(employee.id)}>{employee.name}</button>
                  <CapacityBar used={employee.used_hours} budget={employee.budget_hours} showRemaining={false} ariaLabel={`${employee.name}: ${employee.used_hours} of ${employee.budget_hours} hours this week`} />
                  <div className="od-days" role="img" aria-label={`Hours per day: ${employee.days.map((day) => `${formatDate(day.date)} ${hours(day.hours)}`).join(', ')}`}>
                    {employee.days.map((day) => <span key={day.date} title={`${formatDate(day.date)}: ${hours(day.hours)}`}><i style={{ height: `${Math.min(100, (day.hours / 6) * 100)}%` }} /></span>)}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
        {seats.seats != null && <p className="od-sub">{seats.used} of {seats.seats} employee {pluralize(seats.seats, 'seat')} in use.</p>}
      </div>
    </Card>
  )
}

function Activity({ items }) {
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Recent activity</h2><p>Latest 10 events</p></div></div>
        {items.length === 0 ? <EmptyState title="No activity yet">Ticket updates, comments and new clients show up here.</EmptyState> : (
          <ul className="od-activity" aria-label="Recent activity">
            {items.map((item) => (
              <li key={item.key}>
                {item.to ? <Link to={item.to}>{item.text}</Link> : <span>{item.text}</span>}
                <RelativeTime date={item.at} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

// The agency owner's home: what needs doing now, the plan and hours, then the work.
export function OwnerDashboard() {
  const { user } = useAuth()
  const load = useCallback(() => authService.getAgencyDashboard(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const [requestType, setRequestType] = useState(null)
  const [profileId, setProfileId] = useState(null)
  const dashboard = data?.dashboard
  const firstName = user.name?.split(' ')[0] ?? 'there'

  if (!dashboard) {
    return (
      <section className="page dashboard agency-dashboard owner-dashboard">
        <div className="od-top"><div><h1>{greeting()}, {firstName}</h1><p>Loading your workspace…</p></div></div>
        {error
          ? <Alert variant="error" title="Could not load the dashboard">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
          : isLoading && <AgencyDashboardSkeleton />}
      </section>
    )
  }

  const projectsFull = dashboard.limits.open_projects.reached
  const hoursUsedUp = dashboard.limits.hours.reached

  return (
    <section className="page dashboard agency-dashboard owner-dashboard">
      <div className="od-top">
        <div><h1>{greeting()}, {firstName}</h1><p>{dashboard.agency.name}</p></div>
        <div className="od-top-actions">
          <Link className={`ui-button ui-button-primary ui-button-sm${projectsFull || hoursUsedUp ? ' is-disabled' : ''}`} to="/agency/projects?new=1" aria-disabled={projectsFull || hoursUsedUp} title={projectsFull ? 'Open project limit reached' : hoursUsedUp ? 'All included hours are used' : undefined}>Create project</Link>
          <Link className="ui-button ui-button-secondary ui-button-sm" to="/agency/tickets?new=1">Create ticket</Link>
          <Button type="button" variant="secondary" size="sm" onClick={() => setRequestType('more_hours')}>Request more hours</Button>
        </div>
      </div>
      {error && <Alert variant="warning" title="Could not refresh">Showing the last data we loaded. {error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>}

      <NeedsAttention items={dashboard.needs_attention} onRequest={setRequestType} />
      <Kpis kpis={dashboard.kpis} clientLimit={dashboard.limits.client_accounts} employeeLimit={dashboard.limits.employees} />
      <div className="od-cols">
        <div className="od-col">
          <ActiveProjects projects={dashboard.active_projects} />
          <Activity items={dashboard.activity} />
        </div>
        <div className="od-col">
          <PlanCard dashboard={dashboard} onRequest={setRequestType} />
          <Employees employees={dashboard.employees} seats={dashboard.seats} onProfile={setProfileId} onRequest={setRequestType} />
        </div>
      </div>
      <EmployeeProfileDrawer employeeId={profileId} onClose={() => setProfileId(null)} />
      <RequestModal key={requestType ?? 'none'} type={requestType} onClose={() => setRequestType(null)} />
    </section>
  )
}
