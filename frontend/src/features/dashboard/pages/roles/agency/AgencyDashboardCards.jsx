import { Link } from 'react-router-dom'
import {
  Alert, Avatar, Badge, Button, CapacityBar, Card, EmptyState, Icon, KpiCard, Skeleton, StatusBadge,
} from '../../../../../components/ui'
import { authService } from '../../../../../auth/authService'
import { formatDate, formatDateRange } from '../../../../../lib/format'
import { UsageChart } from '../../../../admin/agencies/UsageChart'
import { AVAILABILITY, AVAILABILITY_VARIANT, labelOf } from '../../../../admin/employee/employeeForm'
import { limitState, limitWarnings } from './agencyLimits'
import '../admin/adminDashboard.css'
import './agencyDashboard.css'

const hours = (value) => `${Number(value).toFixed(1)} h`
const fraction = (limit, format = String) => (limit.allowed != null ? `${format(limit.used)} / ${format(limit.allowed)}` : format(limit.used))
const toneOf = (limit) => (limit.reached ? 'danger' : limit.nearly ? 'warm' : 'info')

export function LimitWarnings({ dashboard, onRequest }) {
  const items = limitWarnings(dashboard)
  if (items.length === 0) return null
  return (
    <div className="agd-warnings" role="region" aria-label="Plan limits">
      {items.map((item) => (
        <Alert key={item.key} variant={item.variant} title={item.title}>
          {item.text}
          {item.upgrade && <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={() => onRequest(item.key === 'hours' ? 'more_hours' : 'upgrade')}>{item.key === 'hours' ? 'Request more hours' : 'Request upgrade'}</Button></div>}
        </Alert>
      ))}
    </div>
  )
}

/** Plan, status and renewal on one line. Without a contract it says so, so nobody wonders why limits are missing. */
export function ContractStrip({ dashboard }) {
  const { contract } = dashboard
  if (!contract) {
    return (
      <div className="agd-strip is-empty" role="status">
        <Icon name="file" size={18} />
        <span><strong>No contract on file.</strong> Your administrator has not set a plan yet, so no seat or hour limits apply.</span>
      </div>
    )
  }
  const days = contract.days_remaining
  const closing = days != null && days >= 0 && days <= 30
  return (
    <div className={`agd-strip${closing ? ' is-closing' : ''}`}>
      <span className="agd-strip-item"><small>Plan</small><strong>{contract.plan_name ?? '—'}</strong></span>
      <span className="agd-strip-item"><small>Status</small><Badge variant={contract.status === 'active' ? 'success' : 'warning'}>{contract.status ?? 'unknown'}</Badge></span>
      <span className="agd-strip-item"><small>Renewal</small><strong>{contract.renews_on ?? contract.ends_on ? formatDate(contract.renews_on ?? contract.ends_on) : '—'}</strong></span>
      <span className="agd-strip-item"><small>Days remaining</small><strong className={closing ? 'is-warning' : undefined}>{days ?? '—'}</strong></span>
    </div>
  )
}

export function AgencyKpiRow({ dashboard }) {
  const { limits, weekly } = dashboard
  const isOwner = dashboard.role === 'agency_owner'
  const remaining = limits.hours.remaining
  const hoursLeft = {
    title: 'Hours left',
    value: remaining != null ? hours(remaining) : 'No limit',
    hint: remaining != null
      ? (limits.hours.projected_run_out ? `Lasts until about ${formatDate(limits.hours.projected_run_out)}` : 'Of the included hours')
      : `${hours(weekly.remaining_hours)} free this week`,
    icon: 'check',
    tone: limits.hours.reached ? 'danger' : limits.hours.nearly ? 'warm' : 'success',
  }
  const cards = isOwner
    ? [
      { title: 'Employees held', value: fraction(limits.employees), hint: limits.employees.allowed != null ? 'Seats in use' : 'No seat limit', icon: 'users', tone: toneOf(limits.employees) },
      {
        title: 'Hours used',
        value: limits.hours.allowed != null ? `${hours(limits.hours.used)} / ${hours(limits.hours.allowed)}` : hours(limits.hours.used),
        hint: `${formatDateRange(limits.hours.period_start, limits.hours.period_end)}${limits.hours.allowed == null ? ' · no hour limit' : ''}`,
        icon: 'clock',
        tone: toneOf(limits.hours),
      },
      hoursLeft,
      { title: 'Open projects', value: fraction(limits.open_projects), hint: limits.open_projects.allowed != null ? 'Allowed at once' : 'No project limit', icon: 'queue', tone: toneOf(limits.open_projects) },
      { title: 'Client accounts', value: fraction(limits.client_accounts), hint: limits.client_accounts.allowed != null ? 'Accounts in use' : 'No account limit', icon: 'userPlus', tone: toneOf(limits.client_accounts) },
    ]
    : [
      { title: 'Employees held', value: String(limits.employees.used), hint: 'Held by you', icon: 'users', tone: 'info' },
      hoursLeft,
      { title: 'Open projects', value: fraction(limits.open_projects), hint: 'Your projects, allowed at once', icon: 'queue', tone: toneOf(limits.open_projects) },
    ]

  return (
    <div className={`agd-kpis${isOwner ? ' is-owner' : ''}`}>
      {cards.map((card) => <KpiCard key={card.title} title={card.title} value={card.value} subtext={card.hint} icon={card.icon} tone={card.tone} />)}
    </div>
  )
}

export function ContractCard({ dashboard, onRequest }) {
  const { contract } = dashboard
  const rows = contract && [
    ['Plan', contract.plan_name],
    ['Status', contract.status],
    ['Starts', contract.starts_on && formatDate(contract.starts_on)],
    ['Ends', contract.ends_on && formatDate(contract.ends_on)],
    ['Auto renew', contract.auto_renew == null ? null : contract.auto_renew ? 'Yes' : 'No'],
    ['Payment terms', contract.payment_terms],
    ['Price', contract.price != null ? `${contract.price} ${contract.currency ?? ''}`.trim() : null],
  ]
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Contract details</h2><p>{contract ? 'Your current plan' : 'No plan yet'}</p></div></div>
        {contract ? (
          <dl className="agd-facts">
            {rows.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? <span className="adm-muted">—</span>}</dd></div>)}
          </dl>
        ) : (
          <EmptyState icon={<Icon name="file" size={22} />} title="No contract">Plans, included hours and renewal dates appear here once your administrator sets up a contract.</EmptyState>
        )}
        {contract?.features?.length > 0 && (
          <div>
            <h3 className="agd-includes-title">What's included</h3>
            <ul className="agd-includes">{contract.features.map((item) => <li key={item}>{item}</li>)}</ul>
          </div>
        )}
        <div className="agd-contract-actions">
          {contract?.document_available
            ? <a className="ui-button ui-button-secondary ui-button-md" href={authService.agencyContractDocumentUrl()}><Icon name="download" size={16} /> Download contract</a>
            : <Button type="button" variant="secondary" disabled title="No contract document is available yet"><Icon name="download" size={16} /> Download contract</Button>}
          <Button type="button" onClick={() => onRequest('renewal')}>Request renewal</Button>
        </div>
        {!contract?.document_available && <p className="adm-muted">The contract document is not available to download yet.</p>}
      </div>
    </Card>
  )
}

export function EmployeesSection({ dashboard, onProfile, holdDisabled, holdReason }) {
  const { employees } = dashboard
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div><h2>{dashboard.role === 'agency_owner' ? 'Our employees' : 'My employees'}</h2><p>{employees.length ? `${employees.length} held · hours booked on your projects this week` : 'Nobody held yet'}</p></div>
          <Link className="adm-link" to="/agency/employees">Manage employees</Link>
        </div>
        {employees.length === 0 ? (
          <EmptyState title="No employees yet" action={holdDisabled
            ? <Button type="button" size="sm" disabled title={holdReason}>Hold employee</Button>
            : <Link className="ui-button ui-button-primary ui-button-sm" to="/agency/employees?hold=1">Hold employee</Link>}>
            {holdDisabled ? holdReason : 'Hold an employee to start scheduling work with them.'}
          </EmptyState>
        ) : (
          <ul className="agd-employees" aria-label="Held employees">
            {employees.map((employee) => (
              <li key={employee.id} className="agd-employee">
                <div className="agd-employee-head">
                  <Avatar name={employee.name} src={employee.photo_url} size="lg" />
                  <div>
                    <strong>{employee.name}</strong>
                    <small>{employee.headline ?? 'No headline'}</small>
                  </div>
                </div>
                <div className="agd-chips">
                  <Badge variant={AVAILABILITY_VARIANT[employee.availability] ?? 'slate'}>{labelOf(AVAILABILITY, employee.availability)}</Badge>
                  {employee.top_skills.map((skill) => <Badge key={skill} variant="slate">{skill}</Badge>)}
                </div>
                <CapacityBar used={employee.used_hours} budget={employee.budget_hours} showRemaining={false} ariaLabel={`${employee.name}: ${employee.utilization_percent}% of this week used`} label="this week" />
                <Button type="button" size="sm" variant="secondary" aria-label={`View ${employee.name}'s profile`} onClick={() => onProfile(employee.id)}>View profile</Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

export function HoursUsageCard({ dashboard, onRequest }) {
  const { limits, usage } = dashboard
  const h = limits.hours
  const percent = h.allowed ? Math.min(100, Math.round((h.used / h.allowed) * 100)) : null
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Hours usage</h2><p>{formatDateRange(h.period_start, h.period_end)}</p></div></div>
        {percent != null ? (
          <div className="agd-month">
            <div className="agd-month-meta"><strong>{hours(h.used)} of {hours(h.allowed)}</strong><span>{percent}%</span></div>
            <div className={`adm-minibar agd-monthbar${percent >= 100 ? ' is-hot' : percent >= 80 ? ' is-warm' : ''}`} role="progressbar" aria-label="Included hours used this period" aria-valuemin="0" aria-valuemax={h.allowed} aria-valuenow={h.used}><span style={{ width: `${percent}%` }} /></div>
          </div>
        ) : (
          <p className="agd-nolimit"><strong>{hours(h.used)}</strong> booked this month. <span className="adm-muted">No hour limit is set, so there is no progress bar or run-out date.</span></p>
        )}
        <dl className="agd-facts is-two">
          <div><dt>Burn rate</dt><dd>{hours(h.burn_rate_per_day)} per day <span className="adm-muted">(last 14 days)</span></dd></div>
          <div><dt>Projected run-out</dt><dd>{h.projected_run_out ? formatDate(h.projected_run_out) : <span className="adm-muted">{h.allowed == null ? 'Needs a contract' : 'Not at this pace'}</span>}</dd></div>
        </dl>
        <UsageChart days={usage.last_14_days} label="Hours booked per day over the last 14 days" />
        {h.allowed != null && (h.nearly || h.reached) && <Button type="button" variant="secondary" size="sm" onClick={() => onRequest('more_hours')}>Request more hours</Button>}
      </div>
    </Card>
  )
}

export function RecentProjectsCard({ dashboard }) {
  const isOwner = dashboard.role === 'agency_owner'
  const rows = dashboard.recent_projects
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head">
          <div><h2>{isOwner ? 'Recent projects' : 'My recent projects'}</h2><p>Latest work</p></div>
          <Link className="adm-link" to={isOwner ? '/agency/projects' : '/agency/my-projects'}>All projects</Link>
        </div>
        {rows.length === 0 ? (
          <EmptyState title="No projects yet">Projects you create appear here.</EmptyState>
        ) : (
          <ul className="adm-recent" aria-label="Recent projects">
            {rows.map((project) => (
              <li key={project.id}>
                <Link to={`/agency/projects/${project.id}`}>
                  <span className="adm-recent-main"><strong>{project.title}</strong><small>{project.employee_name ?? 'Unassigned'}{project.due_date ? ` · due ${formatDate(project.due_date)}` : ''}</small></span>
                  <span className="adm-recent-meta"><StatusBadge status={project.status} /></span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}

export function AgencyQuickActions({ dashboard }) {
  const state = limitState(dashboard)
  const projectsTo = state.isOwner ? '/agency/projects' : '/agency/my-projects'
  const actions = [
    { icon: 'plus', label: 'Create project', hint: 'Schedule work with a held employee', to: projectsTo, disabled: state.createDisabled, reason: state.createReason },
    { icon: 'userPlus', label: 'Hold employee', hint: 'Browse and add employees', to: '/agency/employees?hold=1', disabled: state.holdDisabled, reason: state.holdReason },
    { icon: 'users', label: 'Employees', hint: 'Profiles and weekly capacity', to: '/agency/employees' },
    { icon: 'chart', label: 'Reports', hint: 'Hours, projects and tickets', to: '/agency/reports' },
    ...(state.isOwner ? [{ icon: 'palette', label: 'Branding', hint: 'Logo and colours', to: '/agency/branding' }] : []),
  ]
  return (
    <Card>
      <div className="adm-card-body">
        <div className="adm-card-head"><div><h2>Quick actions</h2></div></div>
        <ul className="adm-quick" aria-label="Quick actions">
          {actions.map((action) => (
            <li key={action.label}>
              {action.disabled ? (
                <span className="agd-quick-disabled" aria-disabled="true">
                  <span className="adm-quick-icon"><Icon name={action.icon} size={18} /></span>
                  <span><strong>{action.label}</strong><small>{action.reason}</small></span>
                </span>
              ) : (
                <Link to={action.to}>
                  <span className="adm-quick-icon"><Icon name={action.icon} size={18} /></span>
                  <span><strong>{action.label}</strong><small>{action.hint}</small></span>
                </Link>
              )}
            </li>
          ))}
        </ul>
      </div>
    </Card>
  )
}

export function AgencyDashboardSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading dashboard" role="status" className="adm-skeleton">
      <Skeleton variant="row" />
      <div className="agd-kpis is-owner">{Array.from({ length: 5 }, (_, index) => <Skeleton key={index} variant="card" className="adm-skeleton-kpi" />)}</div>
      <div className="adm-row"><Skeleton variant="card" className="adm-skeleton-card" /><Skeleton variant="card" className="adm-skeleton-card" /></div>
      <div className="adm-row"><Skeleton variant="card" className="adm-skeleton-card" /><Skeleton variant="card" className="adm-skeleton-card" /></div>
    </div>
  )
}
