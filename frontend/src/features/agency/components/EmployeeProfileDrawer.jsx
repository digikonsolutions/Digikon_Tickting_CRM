import { useCallback } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Avatar, Badge, Button, Drawer, Skeleton } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import { AVAILABILITY, AVAILABILITY_VARIANT, DEPARTMENTS, SKILL_LEVELS, labelOf } from '../../admin/employee/employeeForm'
import '../../admin/employee/employee.css'

const noop = () => Promise.resolve(null)

// Only http(s) links are rendered as links, so a stored "javascript:" value can never become clickable.
const safeUrl = (value) => (/^https?:\/\//i.test(value ?? '') ? value : null)

const period = (start, end) => [start, end].filter(Boolean).map((date) => formatDate(date)).join(' to ')

// The limited, public view of an employee. The server returns nothing private on this route, so there is
// nothing here to hide: no phone, email, CNIC, address, pay or documents.
export function EmployeeProfileDrawer({ employeeId, onClose, footer }) {
  const load = useCallback(() => (employeeId ? authService.getEmployeePublicProfile(employeeId) : noop()), [employeeId])
  const { data, error, isLoading, reload } = useAsync(load)
  // After switching employees the previous person's data lingers until the new response arrives; never show it.
  const person = data?.employee?.id === employeeId ? data.employee : null

  return (
    <Drawer open={Boolean(employeeId)} onClose={onClose} title="Employee profile" description="Public details only. Contact and personal information stay private." width="lg" footer={footer}>
      {isLoading && !person && (
        <div aria-busy="true"><Skeleton variant="card" /><Skeleton variant="card" /></div>
      )}
      {error && (
        <Alert variant="error" title="Could not load this profile">
          {error.status === 404 ? 'This employee is not available.' : error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {person && !error && <PublicProfile person={person} />}
    </Drawer>
  )
}

export function PublicProfile({ person }) {
  const links = [
    ['Portfolio', safeUrl(person.portfolio_url)],
    ['GitHub', safeUrl(person.github_url)],
    ['LinkedIn', safeUrl(person.linkedin_url)],
  ].filter(([, url]) => url)
  const facts = [
    ['Time zone', person.timezone],
    ['Preferred hours', person.preferred_hours],
    ['English', person.english_level],
    ['Weekly capacity', `${person.weekly_capacity_hours} hours`],
  ].filter(([, value]) => value)

  return (
    <div className="pub-profile">
      <div className="pub-head">
        <Avatar name={person.name} src={person.photo_url} size="xl" />
        <div>
          <h3>{person.name}</h3>
          {person.headline && <p>{person.headline}</p>}
          <div className="pub-badges">
            <Badge variant={AVAILABILITY_VARIANT[person.availability] ?? 'slate'}>{labelOf(AVAILABILITY, person.availability)}</Badge>
            {person.department && <Badge variant="info">{labelOf(DEPARTMENTS, person.department)}</Badge>}
          </div>
        </div>
      </div>

      {person.bio && <section className="pub-section"><h4>About</h4><p>{person.bio}</p></section>}

      {facts.length > 0 && (
        <section className="pub-section">
          <h4>Working details</h4>
          <dl className="pub-facts">{facts.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
        </section>
      )}

      {person.skills.length > 0 && (
        <section className="pub-section">
          <h4>Skills</h4>
          <ul className="pub-skills">
            {person.skills.map((skill) => (
              <li className={`pub-skill${skill.level === 'expert' ? ' is-expert' : ''}`} key={skill.name}>
                {skill.name} <small>{labelOf(SKILL_LEVELS, skill.level)}{skill.years_experience ? ` · ${skill.years_experience} yr` : ''}</small>
              </li>
            ))}
          </ul>
        </section>
      )}

      {person.experiences.length > 0 && (
        <section className="pub-section">
          <h4>Experience</h4>
          <ul className="pub-timeline">
            {person.experiences.map((item, index) => (
              <li key={index}>
                <strong>{item.title} · {item.company}</strong>
                {period(item.start_date, item.end_date) && <span>{period(item.start_date, item.end_date)}</span>}
                {item.description && <p>{item.description}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {person.educations.length > 0 && (
        <section className="pub-section">
          <h4>Education</h4>
          <ul className="pub-timeline">
            {person.educations.map((item, index) => (
              <li key={index}>
                <strong>{item.degree} · {item.institution}</strong>
                {period(item.start_date, item.end_date) && <span>{period(item.start_date, item.end_date)}</span>}
                {item.description && <p>{item.description}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {person.certifications.length > 0 && (
        <section className="pub-section">
          <h4>Certifications</h4>
          <ul className="pub-timeline">
            {person.certifications.map((item, index) => (
              <li key={index}>
                <strong>{item.name}{item.issuer ? ` · ${item.issuer}` : ''}</strong>
                {(item.issued_at || item.expires_at) && <span>{item.issued_at ? `Issued ${formatDate(item.issued_at)}` : ''}{item.issued_at && item.expires_at ? ' · ' : ''}{item.expires_at ? `Expires ${formatDate(item.expires_at)}` : ''}</span>}
                {safeUrl(item.credential_url) && <a href={safeUrl(item.credential_url)} target="_blank" rel="noopener noreferrer">View credential</a>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {links.length > 0 && (
        <section className="pub-section">
          <h4>Links</h4>
          <div className="pub-links">
            {links.map(([label, url]) => <a key={label} href={url} target="_blank" rel="noopener noreferrer">{label}</a>)}
          </div>
        </section>
      )}

      {!person.bio && person.skills.length === 0 && person.experiences.length === 0 && (
        <p className="pub-note">This employee has not added profile details yet.</p>
      )}
    </div>
  )
}
