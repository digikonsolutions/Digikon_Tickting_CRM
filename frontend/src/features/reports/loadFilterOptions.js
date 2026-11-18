import { authService } from '../../auth/authService'

export async function loadFilterOptions(scope, role) {
  if (scope === 'admin') {
    try {
      const [owners, employees, clients] = await Promise.all([
        authService.listAdminUsers({ role: 'agency_owner', perPage: 100 }),
        authService.listAdminUsers({ role: 'employee', perPage: 100 }),
        authService.listAdminUsers({ role: 'agency_client', perPage: 100 }),
      ])
      const agenciesMap = new Map()
      owners.users?.forEach((owner) => {
        if (owner.agency_id) {
          agenciesMap.set(owner.agency_id, owner.agency_name ?? `Agency ${owner.agency_id}`)
        }
      })
      return {
        agencies: [...agenciesMap.entries()],
        employees: employees.users ?? [],
        clients: clients.users ?? [],
      }
    } catch {
      return { agencies: [], employees: [], clients: [] }
    }
  }

  // Agency scope (owner or client)
  try {
    const promises = [authService.listAgencyTeam()]
    if (role === 'agency_owner') {
      promises.push(authService.listAgencyUsers())
    }
    const [teamRes, usersRes] = await Promise.all(promises)
    const employees = teamRes.employees ?? []
    const clients = (usersRes?.users ?? []).filter((u) => u.role === 'agency_client')

    return {
      agencies: [],
      employees,
      clients,
    }
  } catch {
    return { agencies: [], employees: [], clients: [] }
  }
}
