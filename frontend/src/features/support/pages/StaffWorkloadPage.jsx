import { useCallback } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, PageHeader, RelativeTime, Skeleton } from '../../../components/ui'
import '../support.css'

// Open work per person, so the queue can be spread fairly. A bar per person with the numbers in the table beside it.
export function StaffWorkloadPage() {
  const load = useCallback(() => authService.getStaffWorkload(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const max = Math.max(1, ...(data?.people ?? []).map((person) => person.open))
  return (
    <section className="page">
      <PageHeader eyebrow="Staff" title="Workload" subtitle="Open tickets by owner." action={<Link className="ui-button ui-button-secondary" to="/staff/tickets?view=unassigned">{data ? `${data.unassigned} unassigned` : 'Unassigned'}</Link>} />
      <ApiErrorMessage error={error} />
      {error && <Button type="button" variant="secondary" onClick={reload}>Try again</Button>}
      {isLoading && !data ? <Skeleton variant="card" /> : data && (
        <Card title="By person">
          <div className="sup-bars" aria-hidden="true">{data.people.map((person) => <div className="sup-bar" key={person.id}><span>{person.name}</span><i style={{ width: `${(person.open / max) * 100}%` }} /><b>{person.open}</b></div>)}</div>
          <div className="sup-table-scroll">
            <table className="sup-queue"><caption className="visually-hidden">Open tickets by owner</caption>
              <thead><tr><th scope="col">Person</th><th scope="col">Open</th><th scope="col">Critical</th><th scope="col">Breached</th><th scope="col">Oldest open</th></tr></thead>
              <tbody>{data.people.map((person) => <tr key={person.id}><th scope="row"><Link to={`/staff/tickets?view=all&assigned_to_id=${person.id}`}>{person.name}</Link></th><td>{person.open}</td><td>{person.critical}</td><td>{person.breached}</td><td>{person.oldest ? <RelativeTime date={person.oldest} /> : 'None'}</td></tr>)}</tbody>
            </table>
          </div>
        </Card>
      )}
    </section>
  )
}
