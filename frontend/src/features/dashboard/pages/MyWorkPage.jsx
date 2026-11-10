import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Table } from '../../../components/ui'
import { ProjectDetailModal } from '../components'

export function MyWorkPage() {
  const [projects, setProjects] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [updatingId, setUpdatingId] = useState(null)
  const [selectedProject, setSelectedProject] = useState(null)

  const loadProjects = useCallback(async () => {
    try {
      const response = await authService.listMyWork()
      setProjects(response.projects)
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listMyWork()
      .then(({ projects: assignedProjects }) => {
        if (active) setProjects(assignedProjects)
      })
      .catch((reason) => {
        if (active) setError(reason)
      })
      .finally(() => {
        if (active) setIsLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  async function updateStatus(project, status) {
    setUpdatingId(project.id)
    setError(null)
    try {
      await authService.updateMyWorkStatus(project.id, status)
      await loadProjects()
    } catch (reason) {
      setError(reason)
    } finally {
      setUpdatingId(null)
    }
  }

  const columns = [
    { key: 'title', label: 'Project' },
    { key: 'agency_name', label: 'Agency' },
    { key: 'client', label: 'Client', render: (client) => client?.name ?? 'Account removed' },
    { key: 'estimated_hours', label: 'Hours', render: (hours) => `${hours}h` },
    { key: 'due_date', label: 'Due date' },
    { key: 'status', label: 'Status', render: (status) => <Badge variant={status === 'completed' ? 'success' : 'primary'}>{status.replace('_', ' ')}</Badge> },
    {
      key: 'details',
      label: 'Task details',
      render: (_value, project) => (
        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedProject(project)}>
          View details
        </Button>
      ),
    },
    {
      key: 'actions',
      label: 'Update',
      render: (_value, project) => project.status === 'scheduled'
        ? <Button type="button" size="sm" variant="secondary" loading={updatingId === project.id} onClick={() => updateStatus(project, 'in_progress')}>Start</Button>
        : project.status === 'in_progress'
          ? <Button type="button" size="sm" loading={updatingId === project.id} onClick={() => updateStatus(project, 'completed')}>Complete</Button>
          : '—',
    },
  ]

  return (
    <section className="page">
      <PageHeader
        eyebrow="Employee"
        title="My assignments"
        subtitle="Projects assigned to you across agencies. Update progress as you work."
      />
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={() => { setError(null); loadProjects() }}>Try again</Button>}
      <Card title="My work">
        <Table columns={columns} rows={projects} loading={isLoading} emptyTitle="No assignments" emptyText="Projects assigned to you will appear here." />
      </Card>
      <ProjectDetailModal ticket={selectedProject} onClose={() => setSelectedProject(null)} />
    </section>
  )
}

export function InternalEmployeeWorkPage() {
  return <MyWorkPage />
}
