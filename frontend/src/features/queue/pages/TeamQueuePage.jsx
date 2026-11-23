import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, EmptyState, PageHeader, Skeleton, Tabs, useToast } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { ProjectFormModal } from '../../agency/components/ProjectFormModal'
import { QueueCard } from '../components/QueueCard'
import { RushRequestModal } from '../components/RushRequestModal'
import { RushRequestsPanel } from '../components/RushRequestsPanel'
import '../queue.css'

/**
 * Team queue: one card per employee the agency holds, with what they are doing now, what is next, and free time.
 * The owner reorders; clients see the same cards read-only and can add a task. A second tab lists urgent requests.
 */
export function TeamQueuePage() {
  const { user } = useAuth()
  const isOwner = user.role === 'agency_owner'
  const { showToast } = useToast()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'rush' ? 'rush' : 'queues'
  const [form, setForm] = useState(null)
  const [rush, setRush] = useState(null)
  const [actionError, setActionError] = useState(null)

  const load = useCallback(async () => {
    const [{ assignments }, { rush_requests: requests }] = await Promise.all([authService.listMyAssignments(), authService.listRushRequests()])
    const queues = await Promise.all(assignments.map(async (assignment) => [assignment.id, await authService.getAssignmentQueue(assignment.id)]))
    return { assignments, queues: Object.fromEntries(queues), requests }
  }, [])
  const { data, error, isLoading, reload } = useAsync(load)
  const assignments = data?.assignments ?? []
  const requests = data?.requests ?? []
  const open = requests.filter((request) => request.status === 'pending' || request.status === 'quoted')

  const tabs = [
    { id: 'queues', label: 'Team queue', count: assignments.length },
    { id: 'rush', label: 'Rush requests', count: open.length },
  ]
  const setTab = (id) => setParams(id === 'rush' ? { tab: 'rush' } : {}, { replace: true })

  function openRush(assignmentId, defaults) {
    setForm(null)
    setRush({ assignmentId, defaults })
  }

  async function cancel(request) {
    setActionError(null)
    try {
      await authService.cancelRushRequest(request.id)
      showToast('Request cancelled.')
      reload()
    } catch (reason) {
      setActionError(reason)
    }
  }

  return (
    <section className="page queue-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Team queue"
        subtitle="What each employee is doing now and next, and when they are free."
        action={<Button type="button" variant="secondary" onClick={() => openRush('')}>Request urgent help</Button>}
      />
      <Tabs tabs={tabs} activeTab={tab} onChange={setTab} label="Team queue sections" />
      <div aria-live="polite">
        {error && <Alert variant="error" title="Could not load the queue">{error.message}<div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>}
        {actionError && <ApiErrorMessage error={actionError} />}
      </div>

      {isLoading && !data ? (
        <div className="queue-grid"><Skeleton variant="card" /><Skeleton variant="card" /></div>
      ) : tab === 'queues' ? (
        assignments.length === 0 && !error ? (
          <EmptyState title="No employees on your team yet">Once an employee joins your team, their queue appears here.</EmptyState>
        ) : (
          <div className="queue-grid">
            {assignments.map((assignment) => data.queues[assignment.id] && (
              <QueueCard
                key={assignment.id}
                assignmentId={assignment.id}
                queue={data.queues[assignment.id]}
                isOwner={isOwner}
                userId={user.id}
                onAddTask={(id) => setForm({ assignmentId: id })}
                onRequestHelp={(id) => openRush(id)}
                onChanged={reload}
                onMessage={showToast}
              />
            ))}
          </div>
        )
      ) : (
        <RushRequestsPanel
          requests={requests}
          isOwner={isOwner}
          onCancel={cancel}
          onChanged={(updated, kind) => { showToast(kind === 'accept' ? 'Quote accepted and the work was scheduled.' : 'Quote declined.'); reload() }}
        />
      )}

      {form && (
        <ProjectFormModal
          assignments={assignments}
          assignmentsLoading={false}
          defaultAssignmentId={form.assignmentId}
          onClose={() => setForm(null)}
          onRequestUrgent={(values) => openRush(values.assignmentId, values.defaults)}
          onSaved={(project, { created, uploadNotice }) => {
            setForm(null)
            showToast(uploadNotice ? `Project created, but the files were not uploaded: ${uploadNotice}` : (created ? 'Task added to the end of the queue.' : 'Project updated.'), uploadNotice ? 'error' : undefined)
            reload()
          }}
        />
      )}
      {rush && (
        <RushRequestModal
          assignments={assignments}
          defaultAssignmentId={rush.assignmentId}
          defaults={rush.defaults}
          onClose={() => setRush(null)}
          onSubmitted={() => { setRush(null); showToast('Request sent. You will get a quote before anything is charged.'); setTab('rush'); reload() }}
        />
      )}
    </section>
  )
}
