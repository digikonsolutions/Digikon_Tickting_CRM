import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLE_LABELS } from '../../../../../auth/roles'
import { useAsync } from '../../../../../hooks/useAsync'
import { formatDate } from '../../../../../lib/format'
import { Alert, Button, Card, EmptyState, Icon, KpiCard, ProjectStatusBadge } from '../../../../../components/ui'
import { RushRequestModal } from '../../../../queue/components/RushRequestModal'
import { NeedsAttentionCard } from '../../../../tickets'
import { TimelineChip } from '../../../../timeline/TimelineChip'
import { WelcomeBanner } from '../../../components'
import './agencyDashboard.css'

const OPEN = ['scheduled', 'in_progress']
const IN_REVIEW = ['proposed', 'in_review', 'changes_requested']

// The client's own dashboard: what they created, where each timeline request stands, what is waiting on them and
// what is due next. Contract, seats and hours belong to the owner, so none of that is shown here.
export function ClientDashboard() {
  const { user } = useAuth()
  const firstName = user.name?.split(' ')[0] ?? 'there'
  const [rush, setRush] = useState(false)
  const load = useCallback(async () => {
    const [projects, waiting, assignments] = await Promise.all([
      authService.listAgencyProjects({ perPage: 50, mine: true }),
      authService.listAgencyTickets(new URLSearchParams({ waiting: 'you', per_page: '1' })),
      authService.listMyAssignments(),
    ])
    return { projects: projects.projects ?? [], waiting: waiting.pagination?.total ?? (waiting.tickets ?? []).length, assignments: assignments.assignments ?? [] }
  }, [])
  const { data, error, isLoading, reload } = useAsync(load)
  const projects = data?.projects ?? []
  const open = projects.filter((project) => OPEN.includes(project.status))
  const inReview = open.filter((project) => IN_REVIEW.includes(project.timeline_status))
  const nextDue = open.filter((project) => project.schedule_state === 'confirmed').sort((a, b) => a.due_date.localeCompare(b.due_date))[0]
  const listed = [...open, ...projects.filter((project) => !OPEN.includes(project.status))].slice(0, 6)

  return (
    <section className="page dashboard agency-dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Your projects, tickets and requests at a glance.">
        <Link to="/agency/projects?new=1" className="ui-button ui-button-primary ui-button-sm">Create project</Link>
        <Link to="/agency/support/help" className="ui-button ui-button-secondary ui-button-sm">Get support</Link>
        <Link to="/agency/tickets?new=1" className="ui-button ui-button-secondary ui-button-sm">Create ticket</Link>
        <Button type="button" variant="secondary" size="sm" onClick={() => setRush(true)} disabled={!data || data.assignments.length === 0}>Request urgent help</Button>
      </WelcomeBanner>
      {error && (
        <Alert variant="error" title="Could not load your dashboard">
          {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      <div className="agd-kpis">
        <KpiCard title="Open projects" value={String(open.length)} subtext="Created by you" icon="queue" tone="info" />
        <KpiCard title="Timeline in review" value={String(inReview.length)} subtext={inReview.length ? 'Waiting for confirmation' : 'Nothing waiting'} icon="clock" tone={inReview.length ? 'warm' : 'success'} />
        <KpiCard title="Waiting on you" value={String(data?.waiting ?? 0)} subtext="Tickets to review or answer" icon="chat" tone={data?.waiting ? 'danger' : 'success'} />
        <KpiCard title="Next due" value={nextDue ? formatDate(nextDue.due_date) : '—'} subtext={nextDue ? nextDue.title : 'Shown once a schedule is confirmed'} icon="check" tone="info" />
      </div>

      <div className="adm-cols">
        <div className="adm-col">
          <NeedsAttentionCard />
          <Card>
            <div className="adm-card-body">
              <div className="adm-card-head">
                <div><h2>My projects</h2><p>Open work first, with where each timeline stands</p></div>
                <Link className="adm-link" to="/agency/projects">All projects</Link>
              </div>
              {listed.length === 0 ? (
                <EmptyState title={isLoading ? 'Loading…' : 'No projects yet'}>{isLoading ? '' : 'Send a project request and our team will plan the schedule.'}</EmptyState>
              ) : (
                <ul className="adm-recent" aria-label="My projects">
                  {listed.map((project) => (
                    <li key={project.id}>
                      <Link to={`/agency/projects/${project.id}`}>
                        <span className="adm-recent-main"><strong>{project.title}</strong><small>{project.employee?.name ?? project.employee_name ?? 'Unassigned'} · {project.schedule_state === 'confirmed' ? `due ${formatDate(project.due_date)}` : 'schedule to be confirmed'}</small></span>
                        <span className="adm-recent-meta"><ProjectStatusBadge project={project} />{OPEN.includes(project.status) && project.schedule_state !== 'awaiting' && <TimelineChip status={project.timeline_status} />}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </div>
        <div className="adm-col">
          <Card>
            <div className="adm-card-body">
              <div className="adm-card-head"><div><h2>Quick actions</h2></div></div>
              <ul className="adm-quick" aria-label="Quick actions">
                {[
                  { icon: 'plus', label: 'Create project', hint: 'Send a project request to our team', to: '/agency/projects?new=1' },
                  { icon: 'chat', label: 'Get support', hint: 'Report a problem, ask for help or request changes', to: '/agency/support/help' },
                  { icon: 'ticket', label: 'Create ticket', hint: 'Add a task to one of your projects', to: '/agency/tickets?new=1' },
                  { icon: 'users', label: 'Team and availability', hint: 'Who is free, what is next', to: '/agency/employees' },
                  { icon: 'chart', label: 'Reports', hint: 'Hours, projects and tickets', to: '/agency/reports' },
                ].map((action) => (
                  <li key={action.label}>
                    <Link to={action.to}>
                      <span className="adm-quick-icon"><Icon name={action.icon} size={18} /></span>
                      <span><strong>{action.label}</strong><small>{action.hint}</small></span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          </Card>
        </div>
      </div>
      {rush && (
        <RushRequestModal assignments={data?.assignments ?? []} onClose={() => setRush(false)} onSubmitted={() => { setRush(false); reload() }} />
      )}
    </section>
  )
}
