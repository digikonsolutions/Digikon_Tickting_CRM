import { ROLES, isKnownRole } from './roles'
import { WEEKLY_HOURS_BUDGET } from '../lib/constants'

const API_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/$/, '')
const AUTH_MODE = import.meta.env.VITE_AUTH_MODE ?? 'api'
const SAFE_ERROR_MESSAGE = 'Something went wrong. Please try again.'

export class ApiError extends Error {
  constructor(message, { status = 0, errors = {}, retryAfter = null } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.retryAfter = retryAfter
  }
}

export function getErrorMessage(error) {
  return error instanceof Error ? error.message : SAFE_ERROR_MESSAGE
}

function xsrfToken() {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : null
}

async function establishCsrfCookie() {
  const response = await fetch(`${API_URL}/auth/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok || !xsrfToken()) {
    throw new Error('Unable to establish a secure session. Please try again.')
  }
}

function notifyUnauthorized(path) {
  if (path !== '/auth/me' && path !== '/auth/login') {
    window.dispatchEvent(new Event('clienthub:unauthorized'))
  }
}

function requestHeaders(options, isStateChanging) {
  const csrf = xsrfToken()
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(csrf && isStateChanging ? { 'X-XSRF-TOKEN': csrf } : {}),
    ...options.headers,
  }
}

async function send(path, options, isStateChanging) {
  return fetch(`${API_URL}${path}`, {
    ...options,
    credentials: 'include',
    headers: requestHeaders(options, isStateChanging),
  })
}

async function request(path, options = {}) {
  const method = options.method ?? 'GET'
  const isStateChanging = !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())

  try {
    if (isStateChanging) await establishCsrfCookie()

    let response = await send(path, options, isStateChanging)

    if (response.status === 419 && isStateChanging) {
      await establishCsrfCookie()
      response = await send(path, options, isStateChanging)
    }

    const isJson = response.headers.get('content-type')?.includes('application/json')
    const payload = isJson ? await response.json().catch(() => null) : null

    if (!response.ok) {
      const status = response.status
      if (status === 401) notifyUnauthorized(path)
      const message = status === 401
        ? 'Your session has expired. Please sign in again.'
        : status === 403
          ? "You don't have access"
          : status === 419
            ? 'Your session expired. Refresh the page and try again.'
            : status === 429
              ? 'Too many attempts. Please wait a moment and try again.'
              : status >= 500
                ? 'The server is temporarily unavailable. Please try again later.'
                : payload?.message ?? SAFE_ERROR_MESSAGE

      throw new ApiError(message, {
        status,
        errors: payload?.errors ?? {},
        retryAfter: response.headers.get('retry-after'),
      })
    }

    if (!isJson || payload === null) {
      throw new ApiError('The server returned an invalid response. Please try again.')
    }

    return payload
  } catch (error) {
    if (error instanceof ApiError) throw error
    if (error instanceof TypeError) {
      throw new ApiError('Cannot connect to the server. Check your connection and try again.')
    }
    throw new ApiError(SAFE_ERROR_MESSAGE)
  }
}

function mockUser(email) {
  const localPart = email.split('@')[0].toLowerCase()
  const role = Object.values(ROLES).find((candidate) => localPart.startsWith(candidate.replaceAll('_', '.'))) ?? ROLES.EMPLOYEE
  return { id: 'demo-user', name: localPart || 'Demo user', email, role, agency_id: null, agency_name: null }
}

export const authService = {
  isMockMode: AUTH_MODE === 'mock',
  async getCurrentUser() {
    if (AUTH_MODE === 'mock') return null
    return request('/auth/me')
  },
  async updateProfile(data) {
    if (AUTH_MODE === 'mock') return { user: data }
    return request('/auth/profile', {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
  async signIn({ email, password }) {
    if (AUTH_MODE === 'mock') {
      if (!password) throw new Error('Enter your password to continue.')
      return { user: mockUser(email) }
    }
    return request('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) })
  },
  async requestPasswordReset(email) {
    if (AUTH_MODE === 'mock') return { message: 'If an account exists, we sent password reset instructions.' }
    return request('/auth/forgot-password', { method: 'POST', body: JSON.stringify({ email }) })
  },
  async resetPassword({ token, email, password, passwordConfirmation }) {
    if (AUTH_MODE === 'mock') return { message: 'Your password has been reset.' }
    return request('/auth/reset-password', { method: 'POST', body: JSON.stringify({ token, email, password, password_confirmation: passwordConfirmation }) })
  },
  async signOut() {
    if (AUTH_MODE !== 'mock') await request('/auth/logout', { method: 'POST' })
  },
  async createUser({ name, email, role, agency_id, agency_name }) {
    if (AUTH_MODE === 'mock') return { user: { name, email, role, agency_id: agency_id ?? null, agency_name } }
    return request('/auth/users', {
      method: 'POST',
      body: JSON.stringify({
        name,
        email,
        role,
        ...(agency_id ? { agency_id: Number(agency_id) } : {}),
        ...(agency_name ? { agency_name } : {}),
      }),
    })
  },
  async listAgencyUsers() {
    if (AUTH_MODE === 'mock') return { users: [] }
    return request('/agency/users')
  },
  async listAgencyDirectory() {
    if (AUTH_MODE === 'mock') return { users: [] }
    return request('/agency/directory')
  },
  async listAgencyTeam() {
    if (AUTH_MODE === 'mock') return { employees: [] }
    return request('/agency/team')
  },
  async listAdminUsers({ page = 1, search = '', role } = {}) {
    if (AUTH_MODE === 'mock') return { users: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: '25' })
    if (role) query.set('role', role)
    if (search) query.set('search', search)
    return request(`/admin/users?${query.toString()}`)
  },
  async updateAdminUser(id, data) {
    if (AUTH_MODE === 'mock') return { user: { id, ...data } }
    return request(`/admin/users/${encodeURIComponent(id)}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
  async deleteAdminUser(id) {
    if (AUTH_MODE === 'mock') return { message: 'The account was permanently deleted.' }
    return request(`/admin/users/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  async createAgencyUser({ name, email, role }) {
    if (AUTH_MODE === 'mock') return { user: { id: `${Date.now()}`, name, email, role, is_active: true } }
    return request('/agency/users', {
      method: 'POST',
      body: JSON.stringify({ name, email, role }),
    })
  },
  async updateAgencyUser(id, data) {
    if (AUTH_MODE === 'mock') return { user: { id, ...data } }
    return request(`/agency/users/${encodeURIComponent(id)}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
  async deleteAgencyUser(id) {
    if (AUTH_MODE === 'mock') return { message: 'The account was permanently deleted.' }
    return request(`/agency/users/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  async listAvailableEmployees() {
    if (AUTH_MODE === 'mock') return { employees: [] }
    return request('/agency/employees/available')
  },
  async createEmployeeAssignment(employeeId) {
    if (AUTH_MODE === 'mock') return { assignment: { id: Date.now(), employee: { id: employeeId }, status: 'active' } }
    return request('/agency/assignments', {
      method: 'POST',
      body: JSON.stringify({ employee_id: Number(employeeId) }),
    })
  },
  async listMyAssignments({ week } = {}) {
    if (AUTH_MODE === 'mock') return { assignments: [] }
    const query = week ? `?week=${encodeURIComponent(week)}` : ''
    return request(`/agency/assignments${query}`)
  },
  async getAssignmentUsage(id, week) {
    if (AUTH_MODE === 'mock') return { assignment_id: id, used: '0.00', remaining: WEEKLY_HOURS_BUDGET.toFixed(2), days: [] }
    const query = week ? `?week=${encodeURIComponent(week)}` : ''
    return request(`/agency/assignments/${encodeURIComponent(id)}/usage${query}`)
  },
  async endEmployeeAssignment(id) {
    if (AUTH_MODE === 'mock') return { message: 'The employee assignment has ended.' }
    return request(`/agency/assignments/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  async endAdminAssignment(id) {
    if (AUTH_MODE === 'mock') return { message: 'The employee assignment has ended.' }
    return request(`/admin/assignments/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  // Paginated; resolves to { projects, pagination, summary } where summary counts the whole agency.
  async listAgencyProjects({ page = 1, perPage = 25, status, assignmentId, search } = {}) {
    if (AUTH_MODE === 'mock') {
      return {
        projects: [],
        pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 },
        summary: { total: 0, scheduled: 0, in_progress: 0, completed: 0, cancelled: 0 },
      }
    }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (status) query.set('status', status)
    if (assignmentId) query.set('assignment_id', String(assignmentId))
    if (search) query.set('search', search)
    return request(`/agency/projects?${query.toString()}`)
  },
  async getCapacity({ assignmentId, from, to }) {
    if (AUTH_MODE === 'mock') return { assignment_id: assignmentId, from, to, total_available_hours: '0.00', days: [] }
    const query = new URLSearchParams({ assignment_id: String(assignmentId), from, to })
    return request(`/agency/capacity?${query.toString()}`)
  },
  async getAgencyProject(id) {
    if (AUTH_MODE === 'mock') return { project: null }
    return request(`/agency/projects/${encodeURIComponent(id)}`)
  },
  async createAgencyProject(data) {
    if (AUTH_MODE === 'mock') return { project: { id: Date.now(), ...data, status: 'scheduled', schedule: [] } }
    return request('/agency/projects', { method: 'POST', body: JSON.stringify(data) })
  },
  async cancelAgencyProject(id) {
    if (AUTH_MODE === 'mock') return { project: { id, status: 'cancelled' } }
    return request(`/agency/projects/${encodeURIComponent(id)}/cancel`, { method: 'PATCH' })
  },
  async listProjectTickets(workItemId) {
    if (AUTH_MODE === 'mock') return { tickets: [] }
    return request(`/agency/projects/${encodeURIComponent(workItemId)}/tickets`)
  },
  async createProjectTicket(workItemId, data) {
    if (AUTH_MODE === 'mock') return { ticket: { id: Date.now(), work_item_id: workItemId, ...data, status: 'todo' } }
    return request(`/agency/projects/${encodeURIComponent(workItemId)}/tickets`, {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },
  async updateTicket(id, data) {
    if (AUTH_MODE === 'mock') return { ticket: { id, ...data } }
    return request(`/agency/tickets/${encodeURIComponent(id)}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
  async cancelTicket(id) {
    if (AUTH_MODE === 'mock') return { ticket: { id, status: 'cancelled' } }
    return request(`/agency/tickets/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  async reorderProjectTickets(workItemId, ticketIds) {
    if (AUTH_MODE === 'mock') return { tickets: [] }
    return request(`/agency/projects/${encodeURIComponent(workItemId)}/tickets/reorder`, {
      method: 'PATCH',
      body: JSON.stringify({ ticket_ids: ticketIds.map(Number) }),
    })
  },
  async listMyTickets() {
    if (AUTH_MODE === 'mock') return { tickets: [] }
    return request('/work-items/mine/tickets')
  },
  async updateTicketStatus(id, status) {
    if (AUTH_MODE === 'mock') return { ticket: { id, status } }
    return request(`/tickets/${encodeURIComponent(id)}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  },
  // Resolves to { comments, total } (the latest 200, oldest first).
  async listTicketComments(id) {
    if (AUTH_MODE === 'mock') return { comments: [], total: 0 }
    return request(`/tickets/${encodeURIComponent(id)}/comments`)
  },
  async createTicketComment(id, body) {
    if (AUTH_MODE === 'mock') return { comment: { id: Date.now(), ticket_id: id, body } }
    return request(`/tickets/${encodeURIComponent(id)}/comments`, {
      method: 'POST',
      body: JSON.stringify({ body }),
    })
  },
  async listAdminTickets({ page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { tickets: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    return request(`/admin/tickets?${query.toString()}`)
  },
  async listAdminAssignments({ page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { assignments: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    return request(`/admin/assignments?${query.toString()}`)
  },
  async listAdminProjects({ page = 1, search = '', perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { projects: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (search) query.set('search', search)
    return request(`/admin/projects?${query.toString()}`)
  },
  async getAdminProject(id) {
    if (AUTH_MODE === 'mock') return { project: null, tickets: [] }
    return request(`/admin/projects/${encodeURIComponent(id)}`)
  },
  async listMyWork() {
    if (AUTH_MODE === 'mock') return { projects: [] }
    return request('/work-items/mine')
  },
}

export { isKnownRole }
