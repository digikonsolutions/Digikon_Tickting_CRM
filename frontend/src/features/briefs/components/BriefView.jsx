import { Link } from 'react-router-dom'
import { PLATFORMS, safeUrl } from '../briefData'

function LinkValue({ value }) {
  const href = safeUrl(value)
  return href ? <a href={href} target="_blank" rel="noopener noreferrer">{value}</a> : <span>{value}</span>
}

function Row({ label, children }) {
  if (children === null || children === undefined || children === '' || (Array.isArray(children) && !children.length)) return null
  return <div className="brief-row"><dt>{label}</dt><dd>{children}</dd></div>
}

// Read-only project brief. Empty fields are skipped, so a project without a brief shows nothing.
export function ProjectBriefView({ brief }) {
  if (!brief) return null
  const platform = PLATFORMS.find((option) => option.value === brief.platform)?.label
  const empty = !Object.entries(brief).some(([key, value]) => key !== 'has_access_details' && (Array.isArray(value) ? value.length : value))
  if (empty && !brief.has_access_details) return null

  return (
    <dl className="brief-view">
      <Row label="Objective">{brief.objective}</Row>
      <Row label="Deliverables">
        {brief.deliverables?.length ? <ul className="brief-checklist">{brief.deliverables.map((item, index) => <li key={index}>{item}</li>)}</ul> : null}
      </Row>
      <Row label="Acceptance criteria">{brief.acceptance_criteria}</Row>
      <Row label="Platform">{platform && brief.platform ? platform : null}</Row>
      <Row label="Tech stack">{brief.tech_stack}</Row>
      <Row label="Live site">{brief.target_url && <LinkValue value={brief.target_url} />}</Row>
      <Row label="Staging">{brief.staging_url && <LinkValue value={brief.staging_url} />}</Row>
      <Row label="Repository">{brief.repo_url && <LinkValue value={brief.repo_url} />}</Row>
      <Row label="Brand assets">{brief.brand_assets_url && <LinkValue value={brief.brand_assets_url} />}</Row>
      <Row label="References">
        {brief.reference_links?.length ? <ul>{brief.reference_links.map((link) => <li key={link}><LinkValue value={link} /></li>)}</ul> : null}
      </Row>
      <Row label="Budget">{brief.budget}</Row>
      <Row label="Priority">{brief.brief_priority}</Row>
      <Row label="Contact">{[brief.contact_name, brief.contact_email].filter(Boolean).join(' · ')}</Row>
      {brief.has_access_details && (
        <Row label="Access details">
          {brief.access_details !== undefined
            ? <pre className="brief-secret">{brief.access_details}</pre>
            : <span>Provided (encrypted)</span>}
        </Row>
      )}
    </dl>
  )
}

// Read-only ticket detail. Returns null when the ticket has none.
export function TicketBriefView({ brief, related = [] }) {
  if (!brief) return null
  const has = ['steps_to_reproduce', 'expected_result', 'actual_result', 'affected_url', 'environment', 'severity', 'due_preference'].some((key) => brief[key]) || related.length > 0
  if (!has) return null

  return (
    <dl className="brief-view">
      <Row label="Steps to reproduce">{brief.steps_to_reproduce && <p className="brief-pre">{brief.steps_to_reproduce}</p>}</Row>
      <Row label="Expected">{brief.expected_result}</Row>
      <Row label="Actual">{brief.actual_result}</Row>
      <Row label="Affected page">{brief.affected_url && <LinkValue value={brief.affected_url} />}</Row>
      <Row label="Environment">{brief.environment}</Row>
      <Row label="Severity">{brief.severity}</Row>
      <Row label="Due preference">{brief.due_preference?.replace('_', ' ')}</Row>
      <Row label="Related tickets">
        {related.length ? <ul>{related.map((ticket) => <li key={ticket.id}><Link to={`/tickets/${ticket.key}`}>{ticket.key}</Link> {ticket.title}</li>)}</ul> : null}
      </Row>
    </dl>
  )
}
