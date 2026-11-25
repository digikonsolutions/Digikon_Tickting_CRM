import { Link } from 'react-router-dom'
import { Avatar, Badge, Button } from '../../../components/ui'
import './projectEmployee.css'

const LEVEL = { expert: 'Expert', intermediate: 'Intermediate', beginner: 'Beginner' }

// Who is working on the project: photo, name, job title and main skills. `onProfile` opens the full profile;
// `feedbackTo` (owners) links to the feedback tab of this employee.
export function ProjectEmployee({ employee, onProfile, feedbackTo }) {
  if (!employee) return <span>—</span>
  return (
    <div className="pe-card">
      <Avatar name={employee.name} src={employee.photo_url} size="lg" />
      <div className="pe-body">
        <strong className="pe-name">{employee.name}</strong>
        <span className="pe-title">{employee.headline || employee.department || 'Employee'}</span>
        {employee.skills?.length > 0 && (
          <ul className="pe-skills" aria-label="Skills">
            {employee.skills.map((skill) => <li key={skill.name}><Badge variant={skill.level === 'expert' ? 'primary' : 'slate'} title={LEVEL[skill.level] ?? undefined}>{skill.name}</Badge></li>)}
          </ul>
        )}
        <span className="pe-actions">
          {onProfile && <Button type="button" size="sm" variant="secondary" onClick={onProfile}>View profile</Button>}
          {feedbackTo && <Link className="ui-button ui-button-ghost ui-button-sm" to={feedbackTo}>Give feedback</Link>}
        </span>
      </div>
    </div>
  )
}