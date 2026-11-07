import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { ROLES } from '../../auth/roles'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Table } from '../../components/ui'

export function MyWorkPage() {
  const { user } = useAuth()
  const isAgencyEmployee = user.role === ROLES.AGENCY_EMPLOYEE
  const [projects, setProjects] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [updatingId, setUpdatingId] = useState(null)

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
        eyebrow={isAgencyEmployee ? 'Agency employee' : 'Employee'}
        title={isAgencyEmployee ? 'Agency assignments' : 'My assignments'}
        subtitle={isAgencyEmployee
          ? 'Projects assigned to you by your agency owner or its clients. Update progress as you work.'
          : 'Projects assigned to you across agencies. Update progress as you work.'}
      />
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={() => { setError(null); loadProjects() }}>Try again</Button>}
      <Card title={isAgencyEmployee ? 'My agency work' : 'My work'}>
        <Table columns={columns} rows={projects} loading={isLoading} emptyTitle="No assignments" emptyText="Projects assigned to you will appear here." />
      </Card>
    </section>
  )
}

export function InternalEmployeeWorkPage() {
  return <MyWorkPage />
}

export function AgencyEmployeeWorkPage() {
  return <MyWorkPage />
}
