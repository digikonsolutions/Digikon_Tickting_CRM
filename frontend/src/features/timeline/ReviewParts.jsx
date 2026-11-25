import { Badge, Card, StatusBadge } from '../../components/ui'
import { formatShortDate } from '../../lib/format'
import '../agency/components/projectHours.css'
import { ProjectServicesView } from '../agency/components/projectCreate/ProjectServicesView'

const hrs = (value) => `${Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 2)} h`

const STEPS = [
  { key: 'proposed', label: 'Requested' },
  { key: 'in_review', label: 'Being reviewed' },
  { key: 'confirmed', label: 'Confirmed' },
]

// Where the request is, in three plain steps. "Changes requested" is a pause on the review step, not a step of its own.
export function ReviewStepper({ status }) {
  const index = status === 'changes_requested' ? 1 : Math.max(0, STEPS.findIndex((step) => step.key === status))
  return (
    <ol className="tl-steps" aria-label="Review progress">
      {STEPS.map((step, position) => (
        <li key={step.key} className={position < index ? 'is-done' : position === index ? 'is-now' : ''} aria-current={position === index ? 'step' : undefined}>
          <span className="tl-step-dot" aria-hidden="true">{position < index || status === 'confirmed' ? '✓' : position + 1}</span>
          <span>{step.key === 'in_review' && status === 'changes_requested' ? 'Waiting for the agency' : step.label}</span>
        </li>
      ))}
    </ol>
  )
}

// What the agency asked for: services with their answers, and their own words.
export function AskedCard({ project, requester, attachments, downloadUrl }) {
  return (
    <div className="tl-asked">
      {project.services?.length > 0
        ? <ProjectServicesView services={project.services.map((service) => ({ ...service, answers: Object.keys(service.details ?? {}).length }))} />
        : <Card title="What was asked"><p className="ui-muted">No services were listed. Read the description below.</p></Card>}
      <Card title="In their words">
        <p className="tl-notes">{project.description || 'No notes.'}</p>
        {requester?.note && <p className="tl-request-note">“{requester.note}”</p>}
        {attachments.length > 0 && <ul className="tl-files">{attachments.map((file) => <li key={file.id}><a href={downloadUrl(file.id)}>{file.name}</a></li>)}</ul>}
      </Card>
    </div>
  )
}

// The project's hours against its tickets, and the tickets with their service, hours and dates.
export function HoursCard({ hours, tickets, planned }) {
  const share = hours.total > 0 ? Math.min(100, (hours.allocated / hours.total) * 100) : 0
  return (
    <Card title="Hours" description="Hours the project has, and how the tickets use them.">
      <div className="ph-bar" role="img" aria-label={`${hrs(hours.allocated)} of ${hrs(hours.total)} given to tickets`}><span className="ph-bar-fill" style={{ width: `${share}%` }} /></div>
      <dl className="tl-hours">
        <div><dt>Project</dt><dd>{hrs(hours.total)}</dd></div>
        <div><dt>In the plan</dt><dd>{hrs(planned)}</dd></div>
        <div><dt>In tickets</dt><dd>{hrs(hours.allocated)}</dd></div>
        <div><dt>Left</dt><dd>{hrs(hours.remaining)}</dd></div>
      </dl>
      {tickets.length === 0 ? <p className="ui-muted">No tickets yet.</p> : (
        <ol className="tl-tickets">
          {tickets.map((ticket) => (
            <li key={ticket.id}>
              <span><strong>{ticket.key ?? 'Ticket'}</strong> {ticket.title}{ticket.service && <> <Badge variant="info">{ticket.service}</Badge></>}</span>
              <small>{hrs(ticket.estimated_hours)}{ticket.start_date ? ` · ${formatShortDate(ticket.start_date)}${ticket.due_date && ticket.due_date !== ticket.start_date ? ` – ${formatShortDate(ticket.due_date)}` : ''}` : ''}</small>
              <span><StatusBadge status={ticket.status} /></span>
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}
