import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Badge, Button, Card, PageHeader, Table } from '../../../components/ui'

export function AgencyEmployeesPage() {
  const [employees, setEmployees] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadEmployees = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      const response = await authService.listAgencyTeam()
      setEmployees(response.employees)
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    authService.listAgencyTeam()
      .then(({ employees: agencyEmployees }) => {
        if (active) setEmployees(agencyEmployees)
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

  const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'agency_name', label: 'Team' },
    {
      key: 'is_active',
      label: 'Status',
      render: (isActive) => <Badge variant={isActive ? 'success' : 'slate'}>{isActive ? 'Active' : 'Inactive'}</Badge>,
    },
  ]

  return (
    <section className="page agency-directory-page">
      <PageHeader
        eyebrow="Agency workspace"
        title="Employees"
        subtitle="Employees added by an administrator and available for assignments."
      />
      <Card title="Employees" description="View admin-added employees available for project assignments.">
        <ApiErrorMessage error={error} />
        {error && <Button type="button" variant="secondary" onClick={loadEmployees}>Try again</Button>}
        <Table
          columns={columns}
          rows={employees}
          loading={isLoading}
          emptyTitle="No employees found"
          emptyText="Employees will appear here when an administrator adds them."
        />
      </Card>
    </section>
  )
}
