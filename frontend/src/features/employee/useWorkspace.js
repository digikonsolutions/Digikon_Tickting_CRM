import { useOutletContext } from 'react-router-dom'

// Everything the employee workspace pages share: the loaded work, today's date and limits from the API, the drawer
// and the actions. Provided by EmployeeWorkspaceLayout through the router outlet.
export function useWorkspace() {
  return useOutletContext()
}
