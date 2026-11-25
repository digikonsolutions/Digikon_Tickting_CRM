import { useCallback, useMemo, useState } from 'react'
import { Outlet } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { Alert, Button, Skeleton, useToast } from '../../components/ui'
import { LogTimeModal, useTimerChanged } from '../time'
import { TicketDrawer } from './components/TicketDrawer'
import { buildWorkspace, clockFrom } from './workspace'
import './employee.css'
import '../queue/queue.css'

/**
 * The employee workspace (/my-work and its sub-pages). Loads the employee's projects and tickets once, works out
 * "today" from the API (app timezone), and shares the ticket drawer, the actions and an announcer for screen readers.
 */
export function EmployeeWorkspaceLayout() {
  const { showToast } = useToast()
  const [drawerId, setDrawerId] = useState(null)
  const [busyTicket, setBusyTicket] = useState(null)
  const [busyProject, setBusyProject] = useState(null)
  const [logProject, setLogProject] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [announcement, setAnnouncement] = useState('')

  const load = useCallback(async () => {
    const [work, tickets, timer] = await Promise.all([
      authService.listMyWork(),
      authService.listMyTickets(),
      authService.getActiveTimer().catch(() => ({ entry: null })),
    ])
    return { work, tickets, runningProjectId: timer.entry?.ticket_id == null ? (timer.entry?.project?.id ?? null) : null }
  }, [])
  const { data, error, isLoading, reload } = useAsync(load)
  useTimerChanged(reload)

  const clock = useMemo(() => clockFrom(data?.tickets, data?.work), [data])
  const workspace = useMemo(
    () => buildWorkspace(data?.work?.projects ?? [], data?.tickets?.tickets ?? [], clock.today),
    [data, clock.today],
  )

  const say = useCallback((message) => {
    setAnnouncement(message)
    showToast(message)
  }, [showToast])

  const runAction = useCallback(async (ticket, action) => {
    setBusyTicket(ticket.id)
    setActionError(null)
    try {
      await authService.transitionTicket(ticket.id, action.action)
      say(`${ticket.key ?? ticket.title}: ${action.label.toLowerCase()} done.`)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyTicket(null)
    }
  }, [reload, say])

  const runProject = useCallback(async (project, kind) => {
    setBusyProject(project.id)
    setActionError(null)
    try {
      await (kind === 'start' ? authService.startMyProject(project.id) : authService.finishMyProject(project.id))
      say(kind === 'start' ? `Started ${project.title}.` : `Finished ${project.title}.`)
      await reload()
    } catch (reason) {
      setActionError(reason)
    } finally {
      setBusyProject(null)
    }
  }, [reload, say])

  const drawerTicket = workspace.tickets.find((ticket) => ticket.id === drawerId) ?? null
  const context = {
    clock,
    workspace,
    reload,
    isLoading,
    runningProjectId: data?.runningProjectId ?? null,
    openTicket: (ticket) => setDrawerId(ticket.id),
    runAction,
    busyTicket,
    runProject,
    busyProject,
    setLogProject,
  }

  return (
    <div className="ew-workspace">
      <p className="ew-sr-only" role="status" aria-live="polite">{announcement}</p>
      {(actionError || (error && data)) && (
        <Alert variant="error" title={actionError ? 'That did not work' : 'Could not refresh'}>
          {/* The server's own reason (for example the project's hours), not just "The given data was invalid." */}
          {Object.values((actionError ?? error).errors ?? {}).flat()[0] ?? (actionError ?? error).message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={() => { setActionError(null); reload() }}>Try again</Button></div>
        </Alert>
      )}
      {error && !data ? (
        <Alert variant="error" title="Could not load your work">
          {error.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      ) : !data ? (
        <div className="ew-skeleton" role="status" aria-label="Loading your work">
          <Skeleton variant="card" /><Skeleton variant="card" /><Skeleton variant="card" />
        </div>
      ) : (
        <Outlet context={context} />
      )}

      {drawerTicket && (
        <TicketDrawer
          ticket={drawerTicket}
          project={workspace.projectsById.get(drawerTicket.work_item_id)}
          today={clock.today}
          busy={busyTicket === drawerTicket.id}
          onAction={async (ticket, action) => { await runAction(ticket, action) }}
          onClose={() => setDrawerId(null)}
          onCommentPosted={reload}
          onChanged={reload}
          onMessage={say}
        />
      )}
      {logProject && (
        <LogTimeModal project={logProject} onClose={() => setLogProject(null)} onLogged={async () => { setLogProject(null); say('Time logged.'); await reload() }} />
      )}
    </div>
  )
}
