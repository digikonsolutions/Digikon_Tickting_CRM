import { useCallback, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Avatar, Badge, Button, CapacityBar, Card, EmptyState, Icon, PageHeader, Skeleton, StatusBadge, Table, Tabs } from '../../../components/ui'
import { formatDate, formatHours } from '../../../lib/format'
import { AgencyBrandingModal } from '../agencies/AgencyBrandingModal'
import { AgencyContractModal } from '../agencies/AgencyContractModal'
import { AgencyEditModal } from '../agencies/AgencyEditModal'
import { AssignEmployeeModal, ReleaseEmployeeModal } from '../agencies/AgencyEmployeesModals'
import { AgencyStatusModal } from '../agencies/AgencyStatusModal'
import { UsageChart } from '../agencies/UsageChart'
import { AVAILABILITY, AVAILABILITY_VARIANT, labelOf } from '../employee/employeeForm'
import '../agencies/agencies.css'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'contract', label: 'Contract' },
  { id: 'usage', label: 'Usage' },
  { id: 'employees', label: 'Employees' },
  { id: 'projects', label: 'Projects' },
]
const TAB_IDS = TABS.map((tab) => tab.id)

const Fact = ({ label, children }) => <div><dt>{label}</dt><dd>{children ?? <span className="adm-muted">—</span>}</dd></div>

export function AdminAgencyDetailPage() {
  const { id } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const requested = searchParams.get('tab')
  const tab = TAB_IDS.includes(requested) ? requested : 'overview'
  const [statusTarget, setStatusTarget] = useState(null)
  const [editing, setEditing] = useState(null)
  const [dialog, setDialog] = useState(null) // 'contract' | 'branding' | 'assign'
  const [releasing, setReleasing] = useState(null)
  const load = useCallback(() => authService.getAdminAgency(id), [id])
  const { data, error, isLoading, reload } = useAsync(load)
  const agency = data?.agency

  const selectTab = (next) => setSearchParams(next === 'overview' ? {} : { tab: next }, { replace: true })

  if (isLoading && !agency) {
    return (
      <section className="page agency-detail-page">
        <PageHeader eyebrow="Administration" title="Agency" />
        <Skeleton variant="card" /><Skeleton variant="card" />
      </section>
    )
  }
  if (error || !agency) {
    return (
      <section className="page agency-detail-page">
        <PageHeader eyebrow="Administration" title="Agency" action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/admin/agencies">Back to agencies</Link>} />
        <Alert variant="error" title="Could not load this agency">
          {error?.status === 404 ? 'No agency with that id.' : (error?.message ?? 'Something went wrong.')}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      </section>
    )
  }

  const suspending = agency.status === 'active'
  const draft = agency.status === 'draft'
  const statusLabel = draft ? 'Draft' : suspending ? 'Active' : 'Suspended'
  const projectColumns = [
    { key: 'title', label: 'Project', render: (_v, row) => <Link to={`/admin/projects/${row.id}`}>{row.title}</Link> },
    { key: 'status', label: 'Status', render: (value) => <StatusBadge status={value} /> },
    { key: 'employee_name', label: 'Employee', render: (value) => value ?? <span className="adm-muted">Unassigned</span> },
    { key: 'due_date', label: 'Due', render: (value) => (value ? formatDate(value) : '—') },
    { key: 'estimated_hours', label: 'Hours', render: (value) => formatHours(value) },
  ]

  return (
    <section className="page agency-detail-page">
      <nav className="adm-breadcrumb" aria-label="Breadcrumb">
        <ol><li>Admin</li><li><Link to="/admin/agencies">Agencies</Link></li><li aria-current="page">{agency.name}</li></ol>
      </nav>
      <PageHeader
        eyebrow="Agency"
        title={agency.name}
        subtitle={agency.owner ? `Owner: ${agency.owner.name} · ${agency.owner.email}` : 'No owner yet'}
        action={(
          <div className="adm-header-actions">
            <Badge variant={suspending ? 'success' : draft ? 'slate' : 'warning'}>{statusLabel}</Badge>
            {agency.slug && (suspending
              ? <a className="ui-button ui-button-secondary" href={`/a/${agency.slug}/login`} target="_blank" rel="noreferrer noopener" title="Opens the agency's own sign-in page. Use a private window if you are signed in here.">Open portal</a>
              : <Button type="button" variant="secondary" disabled title="The portal is available once the agency is active.">Open portal</Button>)}
            <Button type="button" variant="secondary" onClick={() => setEditing({ id: agency.id, name: agency.name })}>Edit agency</Button>
            <Button type="button" variant="secondary" onClick={() => setDialog('branding')}>Portal & branding</Button>
            <Button type="button" variant={suspending ? 'danger' : 'primary'} onClick={() => setStatusTarget({ id: agency.id, name: agency.name, status: agency.status })}>
              {draft ? 'Activate & send invite' : suspending ? 'Suspend agency' : 'Activate agency'}
            </Button>
          </div>
        )}
      />
      <Tabs tabs={TABS.map((item) => ({ ...item, count: item.id === 'employees' ? agency.employees.length : item.id === 'projects' ? agency.counts.projects_total : undefined }))} activeTab={tab} onChange={selectTab} label="Agency sections" />

      {tab === 'overview' && (
        <div className="agency-tab">
          <div className="agency-stats">
            <Card><div className="agency-stat"><span>Employees held</span><strong>{agency.counts.employees_held}</strong></div></Card>
            <Card><div className="agency-stat"><span>Open projects</span><strong>{agency.counts.open_projects}</strong></div></Card>
            <Card><div className="agency-stat"><span>Hours this week</span><strong>{agency.usage.week_hours.toFixed(1)} h</strong></div></Card>
            <Card><div className="agency-stat"><span>Client accounts</span><strong>{agency.counts.client_accounts}</strong></div></Card>
          </div>
          <Card title="Details">
            <dl className="agency-facts">
              <Fact label="Status">{statusLabel}</Fact>
              <Fact label="Owner">{agency.owner ? <><Link to={`/admin/agency-owners?search=${encodeURIComponent(agency.owner.email)}`}>{agency.owner.name}</Link> · {agency.owner.email}{agency.owner.is_active ? '' : ' (deactivated)'}</> : null}</Fact>
              <Fact label="Portal address">{agency.slug ? `/a/${agency.slug}` : null}</Fact>
              <Fact label="Ticket prefix">{agency.ticket_prefix}</Fact>
              <Fact label="Created">{agency.created_at ? formatDate(agency.created_at) : null}</Fact>
              <Fact label="Plan">{agency.contract?.plan_name ?? 'No contract'}</Fact>
            </dl>
          </Card>
          <Card title="Company" description="Visible to administrators only">
            <dl className="agency-facts">
              <Fact label="Legal name">{agency.profile.legal_name}</Fact>
              <Fact label="Brand name">{agency.profile.brand_name}</Fact>
              <Fact label="Website">{agency.profile.website ? <a href={/^https?:\/\//i.test(agency.profile.website) ? agency.profile.website : undefined} target="_blank" rel="noopener noreferrer">{agency.profile.website}</a> : null}</Fact>
              <Fact label="Industry">{agency.profile.industry}</Fact>
              <Fact label="Size">{agency.profile.company_size ? `${agency.profile.company_size} people` : null}</Fact>
              <Fact label="Location">{[agency.profile.city, agency.profile.country].filter(Boolean).join(', ') || null}</Fact>
              <Fact label="Address">{agency.profile.address}</Fact>
              <Fact label="Billing contact">{[agency.profile.billing_contact_name, agency.profile.billing_email].filter(Boolean).join(' · ') || null}</Fact>
              <Fact label="Tax number">{agency.profile.tax_number_masked}</Fact>
              <Fact label="Account manager">{agency.profile.account_manager?.name}</Fact>
              <Fact label="Internal notes">{agency.profile.internal_notes}</Fact>
            </dl>
          </Card>
        </div>
      )}

      {tab === 'contract' && (
        <div className="agency-tab">
          <div className="adm-header-actions"><Button type="button" variant="secondary" onClick={() => setDialog('contract')}>{agency.contract ? 'Edit contract' : 'Add contract'}</Button></div>
          {agency.contract ? (
            <Card title={agency.contract.plan_name ?? 'Contract'} description={agency.contract.status ? `Status: ${agency.contract.status}` : undefined}>
              <dl className="agency-facts">
                <Fact label="Starts">{agency.contract.starts_on ? formatDate(agency.contract.starts_on) : null}</Fact>
                <Fact label="Ends">{agency.contract.ends_on ? formatDate(agency.contract.ends_on) : null}</Fact>
                <Fact label="Days remaining">{agency.contract.days_remaining ?? null}</Fact>
                <Fact label="Auto renew">{agency.contract.auto_renew === undefined ? null : agency.contract.auto_renew ? 'Yes' : 'No'}</Fact>
                <Fact label="Payment terms">{agency.contract.payment_terms}</Fact>
                <Fact label="Price">{agency.contract.price != null ? `${agency.contract.price} ${agency.contract.currency ?? ''}` : null}</Fact>
                <Fact label="Seats">{agency.limits.seats}</Fact>
                <Fact label="Included hours">{agency.limits.hours}</Fact>
              </dl>
              {agency.contract.features?.length > 0 && (
                <>
                  <h3 className="agency-docs-title">What's included</h3>
                  <ul className="agency-docs agency-includes" aria-label="Included in this contract">{agency.contract.features.map((item) => <li key={item}>{item}</li>)}</ul>
                </>
              )}
              <h3 className="agency-docs-title">Contract files</h3>
              {agency.documents.length === 0
                ? <p className="adm-muted">No contract file uploaded yet.</p>
                : (
                  <ul className="agency-docs" aria-label="Contract files">
                    {agency.documents.map((doc) => (
                      <li key={doc.id}><Icon name="file" size={16} /> <a href={authService.contractDocumentUrl(agency.id, doc.id)}>{doc.name}</a> <span className="adm-muted">{formatDate(doc.created_at)}</span></li>
                    ))}
                  </ul>
                )}
            </Card>
          ) : (
            <Card>
              <EmptyState icon={<Icon name="file" size={22} />} title="No contract">
                This agency has no plan or contract yet, so there are no seat or hour limits to show. Requests for more hours or a renewal from the agency arrive as notifications.
              </EmptyState>
            </Card>
          )}
        </div>
      )}

      {tab === 'usage' && (
        <div className="agency-tab">
          <div className="agency-stats">
            <Card><div className="agency-stat"><span>Hours this week</span><strong>{agency.usage.week_hours.toFixed(1)} h</strong></div></Card>
            <Card><div className="agency-stat"><span>Hours this month</span><strong>{agency.usage.month_hours.toFixed(1)} h</strong></div></Card>
            <Card><div className="agency-stat"><span>Burn rate</span><strong>{agency.usage.burn_rate_per_day.toFixed(1)} h/day</strong></div></Card>
          </div>
          <Card title="Last 14 days" description="Hours booked on this agency's projects, per day">
            <UsageChart days={agency.usage.last_14_days} />
          </Card>
        </div>
      )}

      {tab === 'employees' && (
        <div className="agency-tab">
          <div className="adm-header-actions"><Button type="button" onClick={() => setDialog('assign')}>Add employee</Button></div>
          {agency.employees.length === 0 ? (
            <Card><EmptyState title="No employees held">This agency is not holding any employee right now.</EmptyState></Card>
          ) : (
            <ul className="agency-employees" aria-label="Employees held by this agency">
              {agency.employees.map((employee) => (
                <li key={employee.id}>
                  <Card>
                    <div className="agency-employee">
                      <Avatar name={employee.name} src={employee.photo_url} size="lg" />
                      <div className="agency-employee-main">
                        <Link to={`/admin/employees/${employee.id}`}><strong>{employee.name}</strong></Link>
                        <small>{employee.headline ?? 'No headline'}</small>
                        <span className="agency-chips">
                          <Badge variant={AVAILABILITY_VARIANT[employee.availability] ?? 'slate'}>{labelOf(AVAILABILITY, employee.availability)}</Badge>
                          {employee.top_skills.map((skill) => <Badge key={skill} variant="slate">{skill}</Badge>)}
                        </span>
                      </div>
                      <div className="agency-employee-load">
                        <CapacityBar used={employee.used_hours} budget={employee.budget_hours} showRemaining={false} ariaLabel={`${employee.name}: ${employee.utilization_percent}% of weekly capacity used for this agency`} />
                        <small>Since {employee.since ? formatDate(employee.since) : '—'}</small>
                        <Button type="button" size="sm" variant="ghost" aria-label={`Release ${employee.name}`} onClick={() => setReleasing(employee)}>Release</Button>
                      </div>
                    </div>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {tab === 'projects' && (
        <div className="agency-tab">
          <Card title="Recent projects" description={`${agency.counts.open_projects} open of ${agency.counts.projects_total}`}>
            <Table columns={projectColumns} rows={agency.projects} emptyTitle="No projects yet" emptyText="Projects created by this agency appear here." />
          </Card>
        </div>
      )}

      {dialog === 'contract' && <AgencyContractModal agency={agency} open onClose={() => setDialog(null)} onDone={reload} />}
      {dialog === 'branding' && <AgencyBrandingModal agency={agency} open onClose={() => setDialog(null)} onDone={reload} />}
      {dialog === 'assign' && <AssignEmployeeModal agency={agency} open onClose={() => setDialog(null)} onDone={reload} />}
      <ReleaseEmployeeModal agency={agency} employee={releasing} onClose={() => setReleasing(null)} onDone={reload} />
      <AgencyEditModal agency={editing} onClose={() => setEditing(null)} onDone={reload} />
      <AgencyStatusModal key={statusTarget?.id ?? 'none'} agency={statusTarget} onClose={() => setStatusTarget(null)} onDone={reload} />
    </section>
  )
}
