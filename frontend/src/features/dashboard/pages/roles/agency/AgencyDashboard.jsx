import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../../../auth/useAuth'
import { authService } from '../../../../../auth/authService'
import { ROLES, ROLE_LABELS } from '../../../../../auth/roles'
import { Alert, Button, Modal, Textarea, useToast } from '../../../../../components/ui'
import { ApiErrorMessage } from '../../../../auth/components/ApiErrorMessage'
import { WelcomeBanner } from '../../../components'
import { NeedsAttentionCard } from '../../../../tickets'
import { EmployeeProfileDrawer } from '../../../../agency/components/EmployeeProfileDrawer'
import { useAsync } from '../../../../../hooks/useAsync'
import { AttentionCard } from '../admin/AdminDashboardCards'
import {
  AgencyDashboardSkeleton, AgencyKpiRow, AgencyQuickActions, ContractCard, ContractStrip, EmployeesSection, HoursUsageCard,
  LimitWarnings, RecentProjectsCard,
} from './AgencyDashboardCards'
import { limitState } from './agencyLimits'
import './agencyDashboard.css'

// Legacy agency members only get a welcome card and the directory: they have no projects or holds.
function AgencyMemberDashboard() {
  const { user } = useAuth()
  const firstName = user.name?.split(' ')[0] ?? 'there'

  return (
    <section className="page dashboard">
      <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Find the people in your agency.">
        <Link to="/agency/directory" className="ui-button ui-button-secondary ui-button-sm">Agency directory</Link>
      </WelcomeBanner>
    </section>
  )
}

const REQUEST_COPY = {
  more_hours: { title: 'Request more hours', hint: 'Tell us how many hours you need and for what.', send: 'Send request' },
  upgrade: { title: 'Request a plan upgrade', hint: 'Tell us what you need: more seats, hours, projects or client accounts.', send: 'Send request' },
  renewal: { title: 'Request a renewal', hint: 'Anything we should know about your renewal?', send: 'Send request' },
}

// Sends one request to the administrators (an in-app notification for each of them).
function RequestModal({ type, onClose }) {
  const { showToast } = useToast()
  const [message, setMessage] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState(null)
  const copy = REQUEST_COPY[type] ?? REQUEST_COPY.more_hours

  async function send(event) {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      await authService.sendAgencyRequest({ type, message: message.trim() })
      showToast('Request sent. An administrator will get back to you.')
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSending(false)
    }
  }

  return (
    <Modal
      open={Boolean(type)}
      onClose={() => !sending && onClose()}
      title={copy.title}
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={sending} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-request-form" loading={sending} loadingText="Sending…">{copy.send}</Button>
        </>
      )}
    >
      <form id="agency-request-form" className="ui-form" onSubmit={send}>
        <Textarea
          id="agency-request-message"
          label="Message (optional)"
          rows="4"
          maxLength="500"
          value={message}
          helperText={`${copy.hint} ${message.length} of 500 characters.`}
          onChange={(event) => setMessage(event.target.value)}
          error={error?.errors?.message?.[0]}
        />
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
      </form>
    </Modal>
  )
}

// Owner and client dashboard: contract and limits, what needs attention, employees, hours and recent work.
function AgencyWorkspaceDashboard() {
  const { user } = useAuth()
  const load = useCallback(() => authService.getAgencyDashboard(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const [requestType, setRequestType] = useState(null)
  const [profileId, setProfileId] = useState(null)
  const dashboard = data?.dashboard
  const firstName = user.name?.split(' ')[0] ?? 'there'

  if (!dashboard) {
    return (
      <section className="page dashboard agency-dashboard">
        <WelcomeBanner eyebrow={ROLE_LABELS[user.role]} title={`Welcome back, ${firstName}`} subtitle="Your contract, employees and hours at a glance." />
        {error
          ? (
            <Alert variant="error" title="Could not load the dashboard">
              {error.message}
              <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
            </Alert>
          )
          : isLoading && <AgencyDashboardSkeleton />}
      </section>
    )
  }

  const state = limitState(dashboard)
  const { isOwner } = state

  return (
    <section className="page dashboard agency-dashboard">
      <WelcomeBanner eyebrow={dashboard.agency.name} title={`Welcome back, ${firstName}`} subtitle="Your contract, employees and hours at a glance.">
        {state.holdDisabled
          ? <Button type="button" variant="secondary" size="sm" disabled aria-describedby="agd-hold-reason" title={state.holdReason}>Hold employee</Button>
          : <Link to="/agency/employees?hold=1" className="ui-button ui-button-secondary ui-button-sm">Hold employee</Link>}
        <Button type="button" variant="secondary" size="sm" onClick={() => setRequestType('more_hours')}>Request more hours</Button>
      </WelcomeBanner>
      {state.holdDisabled && <p id="agd-hold-reason" className="agd-reason">{state.holdReason}</p>}
      {error && (
        <Alert variant="warning" title="Could not refresh">
          Showing the last data we loaded. {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}

      <LimitWarnings dashboard={dashboard} onRequest={setRequestType} />
      {(isOwner || dashboard.contract) && <ContractStrip dashboard={dashboard} />}
      <AgencyKpiRow dashboard={dashboard} />

      <div className="adm-cols">
        <div className="adm-col">
          <AttentionCard items={dashboard.attention} emptyText="Your projects and plan are on track." />
          <NeedsAttentionCard />
          <EmployeesSection dashboard={dashboard} onProfile={setProfileId} holdDisabled={state.holdDisabled} holdReason={state.holdReason} />
          <RecentProjectsCard dashboard={dashboard} />
        </div>
        <div className="adm-col">
          {isOwner && <ContractCard dashboard={dashboard} onRequest={setRequestType} />}
          {isOwner && <HoursUsageCard dashboard={dashboard} onRequest={setRequestType} />}
          <AgencyQuickActions dashboard={dashboard} />
        </div>
      </div>
      <EmployeeProfileDrawer employeeId={profileId} onClose={() => setProfileId(null)} />
      <RequestModal key={requestType ?? 'none'} type={requestType} onClose={() => setRequestType(null)} />
    </section>
  )
}

export const AgencyDashboard = () => {
  const { user } = useAuth()
  return user.role === ROLES.AGENCY_MEMBER ? <AgencyMemberDashboard /> : <AgencyWorkspaceDashboard />
}
