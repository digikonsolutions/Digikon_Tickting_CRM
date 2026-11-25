import { useCallback } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Card, Icon, PageHeader } from '../../../components/ui'
import '../support.css'

// "How can we help?": the front door for support. Each choice opens the request form with that type already chosen, so
// people describe the problem in their own words and never have to know our categories.
const GROUPS = [
  { title: 'Something is wrong', description: 'A page, form, automation or integration is not doing what it should.', choices: [
    { type: 'bug', label: 'Report a bug or error', hint: 'Tell us what happens and how to see it' },
    { type: 'technical_support', label: 'I need technical help', hint: 'Something is not working and you are not sure why' },
    { type: 'integration_api', label: 'A connection is failing', hint: 'An integration, webhook or API call' },
  ] },
  { title: 'I need something built or changed', description: 'New work, or changes to something we already delivered.', choices: [
    { type: 'fulfillment_request', label: 'Request new work', hint: 'Build or set up something for you' },
    { type: 'revision_request', label: 'Change something we delivered', hint: 'A page, workflow or pipeline you already have' },
    { type: 'workflow_automation', label: 'Automation or workflow', hint: 'Build or fix an automation' },
    { type: 'ai_agent', label: 'AI agent (chat or voice)', hint: 'Set up or change a chatbot or voice agent' },
    { type: 'change_request', label: 'Something beyond what we agreed', hint: 'We will send an estimate for your approval first' },
  ] },
  { title: 'Account, billing and learning', description: 'Access, invoices, and getting people up to speed.', choices: [
    { type: 'account_access', label: 'Access or users', hint: 'Never include a password here' },
    { type: 'billing', label: 'Billing or subscription', hint: 'A question about an invoice or plan' },
    { type: 'training', label: 'Training or advice', hint: 'A walkthrough or a recommendation' },
  ] },
]

export function AgencySupportHelpPage() {
  const load = useCallback(() => authService.listSupportTickets({ tab: 'waiting', per_page: 3 }), [])
  const { data } = useAsync(load)
  const waiting = data?.counts?.waiting ?? 0

  return (
    <section className="page">
      <PageHeader eyebrow="Support" title="How can we help?" subtitle="Choose what fits best. We will ask only what we need, and you can see the status of every request." action={<Link className="ui-button ui-button-secondary" to="/agency/support">Your tickets</Link>} />
      {waiting > 0 && <Alert variant="warning">{waiting} of your tickets {waiting === 1 ? 'is' : 'are'} waiting for your reply. <Link to="/agency/support?tab=waiting">Open them</Link>.</Alert>}
      <div className="sup-urgent" role="region" aria-label="Urgent problems">
        <Icon name="alert" size={20} />
        <div><strong>Something important is down right now?</strong><span>Live customers affected, lead capture or booking not working. We treat this as critical and alert a manager straight away.</span></div>
        <Link className="ui-button ui-button-danger" to="/agency/support/new?type=critical_incident">Report a critical incident</Link>
      </div>
      {GROUPS.map((group) => (
        <Card key={group.title} title={group.title} description={group.description}>
          <ul className="sup-choices">
            {group.choices.map((choice) => (
              <li key={choice.type}><Link to={`/agency/support/new?type=${choice.type}`}><strong>{choice.label}</strong><span>{choice.hint}</span></Link></li>
            ))}
          </ul>
        </Card>
      ))}
      <Card title="Good to know">
        <ul className="sup-tips">
          <li>One problem per ticket keeps things moving.</li>
          <li>Screenshots, a Loom recording or a link to the page help most. Please keep passwords and customer data out of them.</li>
          <li>Tell us which of your customers it is about, so we find the right account quickly.</li>
          <li>Every ticket shows its status and what happens next. You will be notified when something changes.</li>
        </ul>
      </Card>
    </section>
  )
}
