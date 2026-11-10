import { ROLES, isKnownRole } from './roles'

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
  async listAgencyEmployees(filters) {
    if (AUTH_MODE === 'mock') return { employees: [] }
    const query = new URLSearchParams(filters)
    return request(`/agency/employees?${query.toString()}`)
  },
  async listAgencyProjects() {
    if (AUTH_MODE === 'mock') return { projects: [] }
    return request('/agency/projects')
  },
  async createAgencyProject(data) {
    if (AUTH_MODE === 'mock') return { project: { id: Date.now(), ...data, status: 'scheduled', schedule: [] } }
    return request('/agency/projects', { method: 'POST', body: JSON.stringify(data) })
  },
  async listAdminProjects({ page = 1, search = '', perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { projects: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (search) query.set('search', search)
    return request(`/admin/projects?${query.toString()}`)
  },
  async listMyWork() {
    if (AUTH_MODE === 'mock') return { projects: [] }
    return request('/work-items/mine')
  },
  async updateMyWorkStatus(id, status) {
    if (AUTH_MODE === 'mock') return { project: { id, status } }
    return request(`/work-items/${encodeURIComponent(id)}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  },
}

export { isKnownRole }
