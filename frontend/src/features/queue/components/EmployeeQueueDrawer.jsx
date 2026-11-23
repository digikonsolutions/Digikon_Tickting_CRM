import { useCallback } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Drawer } from '../../../components/ui'
import { MyQueueSection } from './MyQueueSection'
import '../queue.css'

/** Admin: any employee's queue, read-only. */
export function EmployeeQueueDrawer({ employeeId, name, onClose }) {
  const load = useCallback(() => authService.getEmployeeQueueAsAdmin(employeeId), [employeeId])
  const { data, error } = useAsync(load)

  return (
    <Drawer open onClose={onClose} title={`${name ?? 'Employee'} · queue`} description="Read-only. The agency owner orders it.">
      {error && <Alert variant="error" title="Could not load the queue">{error.message}</Alert>}
      {data && <MyQueueSection queue={data} />}
      {!data && !error && <p role="status">Loading…</p>}
    </Drawer>
  )
}
