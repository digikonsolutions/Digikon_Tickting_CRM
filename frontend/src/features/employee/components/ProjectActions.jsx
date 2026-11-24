import { Button } from '../../../components/ui'
import { ProjectTimerButton } from '../../time'
import { useWorkspace } from '../useWorkspace'

// Start or finish one of my own projects, and time it directly when it has no tickets. The server checks the schedule
// is confirmed and the in-progress limit; the reasons here only explain a disabled button.
export function ProjectActions({ project }) {
  const { workspace, runningProjectId, runProject, busyProject, setLogProject } = useWorkspace()
  if (!['scheduled', 'in_progress'].includes(project.status)) return null
  const tickets = workspace.ticketsByProject.get(project.id) ?? []
  const openTickets = tickets.filter((ticket) => !['done', 'cancelled'].includes(ticket.status)).length
  const confirmed = (project.schedule_state ?? 'confirmed') === 'confirmed'
  const working = workspace.projects.some((other) => other.id !== project.id && other.status === 'in_progress')
  const startBlocked = !confirmed ? 'Waiting for the schedule to be confirmed.' : working ? 'Finish your current project first.' : null
  const finishBlocked = openTickets > 0 ? `${openTickets} open ticket${openTickets === 1 ? '' : 's'} to finish first.` : null

  return (
    <div className="ew-project-actions">
      {project.status === 'in_progress' && !workspace.hasTickets(project) && (
        <>
          <ProjectTimerButton project={project} running={runningProjectId === project.id} />
          <Button type="button" size="sm" variant="ghost" onClick={() => setLogProject(project)}>Log time</Button>
        </>
      )}
      {project.status === 'scheduled' ? (
        <>
          <Button type="button" size="sm" disabled={Boolean(startBlocked)} aria-describedby={startBlocked ? `start-why-${project.id}` : undefined} loading={busyProject === project.id} loadingText="Starting…" onClick={() => runProject(project, 'start')}>Start project</Button>
          {startBlocked && <small id={`start-why-${project.id}`} className="ew-muted">{startBlocked}</small>}
        </>
      ) : (
        <>
          <Button type="button" size="sm" variant="secondary" disabled={Boolean(finishBlocked)} aria-describedby={finishBlocked ? `finish-why-${project.id}` : undefined} loading={busyProject === project.id} loadingText="Finishing…" onClick={() => runProject(project, 'finish')}>Mark done</Button>
          {finishBlocked && <small id={`finish-why-${project.id}`} className="ew-muted">{finishBlocked}</small>}
        </>
      )}
    </div>
  )
}
