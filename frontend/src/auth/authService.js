import { ROLES, isKnownRole } from './roles'
import { DEFAULT_CAPACITY } from '../lib/constants'
import { isStrongPassword, normalizeName, PASSWORD_HINT } from '../lib/password'

const API_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/$/, '')
const AUTH_MODE = import.meta.env.VITE_AUTH_MODE ?? 'api'
const SAFE_ERROR_MESSAGE = 'Something went wrong. Please try again.'

export class ApiError extends Error {
  constructor(message, { status = 0, errors = {}, retryAfter = null, payload = null } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.retryAfter = retryAfter
    // The whole error body, for endpoints that explain more than a message (queue conflicts, rush impact).
    this.payload = payload
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
    window.dispatchEvent(new Event('session:expired'))
  }
}

function requestHeaders(options, isStateChanging) {
  const csrf = xsrfToken()
  // FormData bodies must not set a content type: the browser adds the multipart boundary itself.
  const isForm = typeof FormData !== 'undefined' && options.body instanceof FormData
  return {
    Accept: 'application/json',
    ...(isForm ? {} : { 'Content-Type': 'application/json' }),
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
    // The cookie only needs fetching when it is missing; an expired one still triggers the 419 retry below.
    if (isStateChanging && !xsrfToken()) await establishCsrfCookie()

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
        payload,
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

// Multipart upload with progress (fetch cannot report upload progress). Mirrors request(): CSRF cookie,
// credentials, and ApiError for failures. `onProgress` receives a 0 to 100 percentage.
async function uploadForm(path, formData, { onProgress, signal } = {}) {
  if (!xsrfToken()) await establishCsrfCookie()
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', `${API_URL}${path}`)
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')
    const csrf = xsrfToken()
    if (csrf) xhr.setRequestHeader('X-XSRF-TOKEN', csrf)
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) onProgress?.(Math.round((event.loaded / event.total) * 100))
    }
    xhr.onerror = () => reject(new ApiError('Cannot connect to the server. Check your connection and try again.'))
    xhr.onabort = () => reject(new ApiError('The upload was cancelled.'))
    xhr.onload = () => {
      let payload = null
      try { payload = JSON.parse(xhr.responseText) } catch { payload = null }
      if (xhr.status >= 200 && xhr.status < 300 && payload) {
        resolve(payload)
        return
      }
      if (xhr.status === 401) notifyUnauthorized(path)
      const message = xhr.status === 403 ? "You don't have access"
        : xhr.status === 413 ? 'That file is too large for the server.'
          : xhr.status === 429 ? 'Too many uploads. Please wait a moment and try again.'
            : xhr.status >= 500 ? 'The server is temporarily unavailable. Please try again later.'
              : payload?.message ?? SAFE_ERROR_MESSAGE
      reject(new ApiError(message, { status: xhr.status, errors: payload?.errors ?? {} }))
    }
    signal?.addEventListener('abort', () => xhr.abort())
    xhr.send(formData)
  })
}

function historyQuery(filters) {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value))
  }
  return query.toString()
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
  // The single self-service profile endpoint (every role). current is only used in mock mode, to return
  // the same shape the API does: the full user payload with the new name.
  async updateProfile(data, current = {}) {
    if (AUTH_MODE === 'mock') {
      const name = normalizeName(data.name)
      if (!name || name.length > 120) {
        throw new ApiError('The given data was invalid.', { status: 422, errors: { name: [name ? 'Your name may not be longer than 120 characters.' : 'Enter your name.'] } })
      }
      return { user: { id: current.id ?? 1, name, email: current.email ?? 'mock@example.com', role: current.role ?? 'employee', agency_id: current.agency_id ?? null, agency_name: current.agency_name ?? null } }
    }
    return request('/auth/profile', {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
  // Profile photo (jpg, png, webp). Only the photo changes; the name and other fields are untouched.
  async uploadProfilePhoto(file, current = {}) {
    if (AUTH_MODE === 'mock') return { user: { ...current, photo_url: URL.createObjectURL(file) } }
    const form = new FormData()
    form.append('photo', file, file.name)
    return uploadForm('/auth/profile/photo', form)
  },
  async deleteProfilePhoto(current = {}) {
    if (AUTH_MODE === 'mock') return { user: { ...current, photo_url: null } }
    return request('/auth/profile/photo', { method: 'DELETE' })
  },
  // Signed-in password change. The server ends every other session and keeps this one.
  async changePassword({ currentPassword, password, passwordConfirmation }) {
    if (AUTH_MODE === 'mock') {
      if (!isStrongPassword(password)) throw new ApiError('The given data was invalid.', { status: 422, errors: { password: [PASSWORD_HINT] } })
      if (password !== passwordConfirmation) throw new ApiError('The given data was invalid.', { status: 422, errors: { password: ['The new password and its confirmation do not match.'] } })
      return { message: 'Your password has been changed. Your other devices were signed out.' }
    }
    return request('/auth/password', {
      method: 'PUT',
      body: JSON.stringify({ current_password: currentPassword, password, password_confirmation: passwordConfirmation }),
    })
  },
  async getPublicBranding(slug) {
    if (AUTH_MODE === 'mock') return { branding: { slug, display_name: 'Portal', tagline: null, logo_url: null, favicon_url: null, primary_color: null, support_email: null } }
    return request(`/public/agencies/${encodeURIComponent(slug)}/branding`)
  },
  async signIn({ email, password, agencySlug }) {
    if (AUTH_MODE === 'mock') {
      if (!password) throw new Error('Enter your password to continue.')
      return { user: mockUser(email) }
    }
    return request('/auth/login', { method: 'POST', body: JSON.stringify({ email, password, ...(agencySlug ? { agency_slug: agencySlug } : {}) }) })
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
  // Employee profiles. Admin only, except getEmployeePublicProfile (agency owners and clients), which returns
  // a limited profile without contact details, CNIC, finance or documents.
  async createEmployee(data) {
    if (AUTH_MODE === 'mock') return { employee: { id: 'demo-employee', name: data.name, email: data.email } }
    return request('/admin/employees', { method: 'POST', body: JSON.stringify(data) })
  },
  async getAdminEmployee(id) {
    if (AUTH_MODE === 'mock') return { employee: null }
    return request(`/admin/employees/${encodeURIComponent(id)}`)
  },
  async updateAdminEmployee(id, data) {
    if (AUTH_MODE === 'mock') return { employee: null }
    return request(`/admin/employees/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  // The full CNIC. Each call is audited on the server.
  async revealEmployeeCnic(id) {
    if (AUTH_MODE === 'mock') return { cnic: null }
    return request(`/admin/employees/${encodeURIComponent(id)}/reveal-cnic`, { method: 'POST' })
  },
  async uploadEmployeePhoto(id, file) {
    if (AUTH_MODE === 'mock') return { employee: null }
    const form = new FormData()
    form.append('photo', file, file.name)
    return uploadForm(`/admin/employees/${encodeURIComponent(id)}/photo`, form)
  },
  async deleteEmployeePhoto(id) {
    if (AUTH_MODE === 'mock') return { employee: null }
    return request(`/admin/employees/${encodeURIComponent(id)}/photo`, { method: 'DELETE' })
  },
  // One document per request so each file shows its own progress and error.
  async uploadEmployeeDocument(id, { file, type, expiresAt, onProgress, signal }) {
    if (AUTH_MODE === 'mock') return { document: null, completeness: null }
    const form = new FormData()
    form.append('file', file, file.name)
    form.append('type', type)
    if (expiresAt) form.append('expires_at', expiresAt)
    return uploadForm(`/admin/employees/${encodeURIComponent(id)}/documents`, form, { onProgress, signal })
  },
  async verifyEmployeeDocument(id, documentId, verified) {
    if (AUTH_MODE === 'mock') return { document: null }
    return request(`/admin/employees/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}/verify`, { method: 'PATCH', body: JSON.stringify({ verified }) })
  },
  async deleteEmployeeDocument(id, documentId) {
    if (AUTH_MODE === 'mock') return { message: 'The document was removed.', completeness: null }
    return request(`/admin/employees/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}`, { method: 'DELETE' })
  },
  employeeDocumentUrl(id, documentId, { inline = false } = {}) {
    return `${API_URL}/admin/employees/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}${inline ? '?inline=1' : ''}`
  },
  async getEmployeePublicProfile(id) {
    if (AUTH_MODE === 'mock') return { employee: null }
    return request(`/agency/employees/${encodeURIComponent(id)}/profile`)
  },
  // Agency branding. Reading is open to the whole agency; everything else is owner-only (the server enforces it).
  // Every call resolves to { branding }. `kind` is 'logo', 'logo-collapsed' or 'favicon'.
  async getAgencyBranding() {
    if (AUTH_MODE === 'mock') return { branding: null }
    return request('/agency/branding')
  },
  async updateAgencyBranding(data) {
    if (AUTH_MODE === 'mock') return { branding: data }
    return request('/agency/branding', { method: 'PUT', body: JSON.stringify(data) })
  },
  // Multipart: the browser sets the Content-Type boundary itself.
  async uploadBrandingAsset(kind, file, { onProgress, signal } = {}) {
    if (AUTH_MODE === 'mock') return { branding: null }
    const form = new FormData()
    form.append('file', file, file.name)
    return uploadForm(`/agency/branding/${encodeURIComponent(kind)}`, form, { onProgress, signal })
  },
  async deleteBrandingAsset(kind) {
    if (AUTH_MODE === 'mock') return { branding: null }
    return request(`/agency/branding/${encodeURIComponent(kind)}`, { method: 'DELETE' })
  },
  async resetAgencyBranding() {
    if (AUTH_MODE === 'mock') return { branding: null }
    return request('/agency/branding', { method: 'DELETE' })
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
  async listAdminUsers({ page = 1, search = '', role, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { users: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
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
  // The owner's Clients tab. Filters: search, status (active|inactive), page, perPage.
  async listAgencyClients({ search = '', status = '', page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { clients: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 }, limit: { used: 0, allowed: null, reached: false } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (search) query.set('search', search)
    if (status) query.set('status', status)
    return request(`/agency/clients?${query.toString()}`)
  },
  async getAgencyClient(id) {
    if (AUTH_MODE === 'mock') return { client: { id } }
    return request(`/agency/clients/${encodeURIComponent(id)}`)
  },
  async createAgencyClient(data) {
    if (AUTH_MODE === 'mock') return { client: { id: `${Date.now()}`, ...data } }
    return request('/agency/clients', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateAgencyClient(id, data) {
    if (AUTH_MODE === 'mock') return { client: { id, ...data } }
    return request(`/agency/clients/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async inviteAgencyClient(id) {
    if (AUTH_MODE === 'mock') return { message: 'Invitation sent.' }
    return request(`/agency/clients/${encodeURIComponent(id)}/invite`, { method: 'POST' })
  },
  async deleteAgencyClient(id) {
    if (AUTH_MODE === 'mock') return { message: 'The client was permanently deleted.' }
    return request(`/agency/clients/${encodeURIComponent(id)}`, { method: 'DELETE' })
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
  // Team queue: an employee's open projects in order, with what is happening now and the capacity left.
  // More hours on a ticket: the employee asks, the owner or a client answers.
  async requestMoreHours(ticketId, { hours, reason }) {
    if (AUTH_MODE === 'mock') return { hours_request: { id: Date.now(), status: 'pending', hours, reason } }
    return request(`/tickets/${encodeURIComponent(ticketId)}/hours-requests`, { method: 'POST', body: JSON.stringify({ hours, reason }) })
  },
  async cancelHoursRequest(id) {
    if (AUTH_MODE === 'mock') return { hours_request: { id, status: 'cancelled' } }
    return request(`/hours-requests/${encodeURIComponent(id)}/cancel`, { method: 'PATCH' })
  },
  async approveHoursRequest(id) {
    if (AUTH_MODE === 'mock') return { hours_request: { id, status: 'approved' } }
    return request(`/agency/hours-requests/${encodeURIComponent(id)}/approve`, { method: 'POST' })
  },
  async declineHoursRequest(id, note) {
    if (AUTH_MODE === 'mock') return { hours_request: { id, status: 'declined' } }
    return request(`/agency/hours-requests/${encodeURIComponent(id)}/decline`, { method: 'POST', body: JSON.stringify({ note: note || null }) })
  },
  async getAssignmentQueue(assignmentId) {
    if (AUTH_MODE === 'mock') return { now: null, up_next: [], pinned_work_item_ids: [], capacity: {} }
    return request(`/agency/assignments/${encodeURIComponent(assignmentId)}/queue`)
  },
  async previewQueueOrder(assignmentId, orderedWorkItemIds) {
    if (AUTH_MODE === 'mock') return { items: [], conflicts: [], fits: true }
    return request(`/agency/assignments/${encodeURIComponent(assignmentId)}/queue/preview`, { method: 'POST', body: JSON.stringify({ ordered_work_item_ids: orderedWorkItemIds }) })
  },
  async reorderQueue(assignmentId, orderedWorkItemIds) {
    if (AUTH_MODE === 'mock') return { now: null, up_next: [], pinned_work_item_ids: [], capacity: {} }
    return request(`/agency/assignments/${encodeURIComponent(assignmentId)}/queue/reorder`, { method: 'PATCH', body: JSON.stringify({ ordered_work_item_ids: orderedWorkItemIds }) })
  },
  async getMyQueue() {
    if (AUTH_MODE === 'mock') return { now: null, up_next: [], pinned_work_item_ids: [], capacity: {} }
    return request('/work-items/mine/queue')
  },
  async getEmployeeQueueAsAdmin(employeeId) {
    if (AUTH_MODE === 'mock') return { now: null, up_next: [], pinned_work_item_ids: [], capacity: {} }
    return request(`/admin/employees/${encodeURIComponent(employeeId)}/queue`)
  },
  // Urgent (rush) requests. Prices are quoted first; the owner alone accepts.
  async listRushRequests() {
    if (AUTH_MODE === 'mock') return { rush_requests: [] }
    return request('/agency/rush-requests')
  },
  async getRushRequest(id) {
    if (AUTH_MODE === 'mock') return { rush_request: null }
    return request(`/agency/rush-requests/${encodeURIComponent(id)}`)
  },
  async createRushRequest(data) {
    if (AUTH_MODE === 'mock') return { rush_request: { id: Date.now(), status: 'pending', ...data } }
    return request('/agency/rush-requests', { method: 'POST', body: JSON.stringify(data) })
  },
  async cancelRushRequest(id) {
    if (AUTH_MODE === 'mock') return { rush_request: { id, status: 'cancelled' } }
    return request(`/agency/rush-requests/${encodeURIComponent(id)}/cancel`, { method: 'PATCH' })
  },
  async acceptRushRequest(id, { confirmImpact = false } = {}) {
    if (AUTH_MODE === 'mock') return { rush_request: { id, status: 'fulfilled' } }
    return request(`/agency/rush-requests/${encodeURIComponent(id)}/accept`, { method: 'POST', body: JSON.stringify({ confirm_impact: confirmImpact }) })
  },
  async declineRushRequest(id) {
    if (AUTH_MODE === 'mock') return { rush_request: { id, status: 'cancelled' } }
    return request(`/agency/rush-requests/${encodeURIComponent(id)}/decline`, { method: 'POST' })
  },
  async listAdminRushRequests({ status, type, agencyId, page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { rush_requests: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 }, counts: { pending: 0, quoted: 0 } }
    return request(`/admin/rush-requests?${historyQuery({ status, type, agency_id: agencyId, page, per_page: perPage })}`)
  },
  async quoteRushRequest(id, data) {
    if (AUTH_MODE === 'mock') return { rush_request: { id, status: 'quoted' } }
    return request(`/admin/rush-requests/${encodeURIComponent(id)}/quote`, { method: 'POST', body: JSON.stringify(data) })
  },
  async rejectRushRequest(id, reason) {
    if (AUTH_MODE === 'mock') return { rush_request: { id, status: 'rejected' } }
    return request(`/admin/rush-requests/${encodeURIComponent(id)}/reject`, { method: 'POST', body: JSON.stringify({ reason }) })
  },
  async listAdminCharges({ status, agencyId, from, to, page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { charges: [], totals: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    return request(`/admin/charges?${historyQuery({ status, agency_id: agencyId, from, to, page, per_page: perPage })}`)
  },
  async listAvailableEmployeesAsAdmin() {
    if (AUTH_MODE === 'mock') return { employees: [] }
    return request('/admin/employees/available')
  },  async getAssignmentUsage(id, week) {
    if (AUTH_MODE === 'mock') return { assignment_id: id, used: '0.00', remaining: DEFAULT_CAPACITY.weekly_hours.toFixed(2), days: [] }
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
  async listAgencyProjects({ page = 1, perPage = 25, status, assignmentId, search, mine } = {}) {
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
    if (mine) query.set('mine', '1')
    return request(`/agency/projects?${query.toString()}`)
  },
  async getCapacity({ assignmentId, from, to }) {
    if (AUTH_MODE === 'mock') return { assignment_id: assignmentId, from, to, total_available_hours: '0.00', days: [] }
    const query = new URLSearchParams({ assignment_id: String(assignmentId), from, to })
    return request(`/agency/capacity?${query.toString()}`)
  },
  // One-call admin dashboard summary: { today, projects (counts), tickets, scheduled_hours, recent_projects }.
  // Agency onboarding wizard (admin). The contract document and logo upload after the agency exists, one request each.
  async createAgencyOwner(payload) {
    if (AUTH_MODE === 'mock') return { agency: { id: 'demo-agency', name: payload.agency?.name, status: 'active' }, owner: payload.owner, contract: null, warnings: [] }
    return request('/admin/agency-owners', { method: 'POST', body: JSON.stringify(payload) })
  },
  async activateAgency(id) {
    if (AUTH_MODE === 'mock') return { agency: { id, status: 'active' }, owner: null }
    return request(`/admin/agencies/${encodeURIComponent(id)}/activate`, { method: 'POST' })
  },
  async uploadContractDocument(agencyId, { file, onProgress, signal }) {
    if (AUTH_MODE === 'mock') return { document: null }
    const form = new FormData()
    form.append('file', file, file.name)
    return uploadForm(`/admin/agencies/${encodeURIComponent(agencyId)}/contract-documents`, form, { onProgress, signal })
  },
  contractDocumentUrl(agencyId, documentId) {
    return `${API_URL}/admin/agencies/${encodeURIComponent(agencyId)}/contract-documents/${encodeURIComponent(documentId)}`
  },
  agencyContractDocumentUrl() {
    return `${API_URL}/agency/contract/document`
  },
  async uploadAgencyLogo(agencyId, file, { onProgress, signal } = {}) {
    if (AUTH_MODE === 'mock') return { logo_url: null }
    const form = new FormData()
    form.append('file', file, file.name)
    return uploadForm(`/admin/agencies/${encodeURIComponent(agencyId)}/logo`, form, { onProgress, signal })
  },
  async listPlans({ activeOnly = false } = {}) {
    if (AUTH_MODE === 'mock') return { plans: [] }
    return request(`/admin/plans${activeOnly ? '?active=1' : ''}`)
  },
  async createPlan(data) {
    if (AUTH_MODE === 'mock') return { plan: data }
    return request('/admin/plans', { method: 'POST', body: JSON.stringify(data) })
  },
  async updatePlan(id, data) {
    if (AUTH_MODE === 'mock') return { plan: data }
    return request(`/admin/plans/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  // Employees nobody holds, and admins or team leads who can manage an agency.
  async listAssignableEmployees({ search = '' } = {}) {
    if (AUTH_MODE === 'mock') return { employees: [] }
    return request(`/admin/employees/available${search ? `?search=${encodeURIComponent(search)}` : ''}`)
  },
  async listAccountManagers() {
    if (AUTH_MODE === 'mock') return { managers: [] }
    return request('/admin/account-managers')
  },  // Admin: the Agencies list and one agency's detail (contract fields are null until contracts exist).
  async listAdminAgencies({ page = 1, search = '', perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { agencies: [], pagination: { current_page: 1, last_page: 1, total: 0 } }
    return request(`/admin/agencies?${historyQuery({ page, search, per_page: perPage })}`)
  },
  async getAdminAgency(id) {
    if (AUTH_MODE === 'mock') return { agency: null }
    return request(`/admin/agencies/${encodeURIComponent(id)}`)
  },
  // Agency owner and client dashboard (owners get the full view, clients a reduced one).
  async getAgencyDashboard() {
    if (AUTH_MODE === 'mock') return { dashboard: null }
    return request('/agency/dashboard')
  },
  // Requests to the administrators: type is 'more_hours', 'employee', 'upgrade' or 'renewal'; notes and files are optional.
  // The GHL service types and their fields (one definition on the server, shared by every form).
  async getServiceCatalog() {
    if (AUTH_MODE === 'mock') return { types: [], employee_request: { roles: {}, max_hours_per_week: 36, max_duration_weeks: 52 } }
    return request('/agency/service-catalog')
  },
  async sendAgencyRequest({ type, message, files = [], details = null }) {
    if (AUTH_MODE === 'mock') return { message: 'Your request was sent.', notified: 0 }
    if (files.length > 0) {
      const form = new FormData()
      form.append('type', type)
      if (message) form.append('message', message)
      if (details) Object.entries(details).forEach(([key, value]) => form.append(`details[${key}]`, String(value)))
      files.forEach((file) => form.append('files[]', file, file.name))
      return request('/agency/requests', { method: 'POST', body: form })
    }
    return request('/agency/requests', { method: 'POST', body: JSON.stringify({ type, ...(message ? { message } : {}), ...(details ? { details } : {}) }) })
  },
  async listAgencyRequests({ type } = {}) {
    if (AUTH_MODE === 'mock') return { requests: [] }
    return request(`/agency/requests${type ? `?type=${encodeURIComponent(type)}` : ''}`)
  },
  requestAttachmentUrl(id) {
    return `${API_URL}/agency/request-attachments/${encodeURIComponent(id)}/download`
  },
  // Timeline review. `scope` is 'admin', 'employee' or 'agency' and picks the audience's own endpoints.
  async listTimelineReviews({ status, agency, search, page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { reviews: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 }, summary: { pending: 0, overdue: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (status) query.set('status', status)
    if (agency) query.set('agency', String(agency))
    if (search) query.set('search', search)
    return request(`/admin/timeline-reviews?${query.toString()}`)
  },
  async getTimelineReviewCount() {
    if (AUTH_MODE === 'mock') return { pending: 0, overdue: 0 }
    return request('/admin/timeline-reviews/count')
  },
  async listAwaitingTimelines() {
    if (AUTH_MODE === 'mock') return { awaiting: [] }
    return request('/work-items/awaiting-timeline')
  },
  timelinePath(scope, id) {
    const base = scope === 'admin' ? '/admin/projects' : scope === 'employee' ? '/work-items' : '/agency/projects'
    return `${base}/${encodeURIComponent(id)}/timeline`
  },
  async getTimeline(scope, id) {
    if (AUTH_MODE === 'mock') return null
    return request(this.timelinePath(scope, id))
  },
  async startTimelineReview(id) {
    return request(`${this.timelinePath('admin', id)}/start-review`, { method: 'POST' })
  },
  async saveTimeline(scope, id, { days, dueDate, note }) {
    return request(this.timelinePath(scope, id), { method: 'PATCH', body: JSON.stringify({ days, ...(dueDate ? { due_date: dueDate } : {}), ...(note ? { note } : {}) }) })
  },
  async confirmTimeline(scope, id) {
    return request(`${this.timelinePath(scope, id)}/confirm`, { method: 'POST' })
  },
  async requestTimelineChanges(id, message) {
    return request(`${this.timelinePath('admin', id)}/request-changes`, { method: 'POST', body: JSON.stringify({ message }) })
  },
  async respondToTimeline(id, { note, files = [] }) {
    if (files.length > 0) {
      const form = new FormData()
      if (note) form.append('note', note)
      files.forEach((file) => form.append('files[]', file, file.name))
      return request(`${this.timelinePath('agency', id)}/respond`, { method: 'POST', body: form })
    }
    return request(`${this.timelinePath('agency', id)}/respond`, { method: 'POST', body: JSON.stringify({ note }) })
  },
  async listAgencyTickets(query = new URLSearchParams()) {
    if (AUTH_MODE === 'mock') return { tickets: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    return request(`/agency/tickets?${query.toString()}`)
  },
  async getAgencyEmployeesSummary() {
    if (AUTH_MODE === 'mock') return { seats: null, can_add: false, requests: [] }
    return request('/agency/employees/summary')
  },
  // Admin agency status (active or suspended). Suspending ends every session of that agency's users.
  async updateAdminAgency(id, status) {
    if (AUTH_MODE === 'mock') return { agency: { id, status } }
    return request(`/admin/agencies/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify({ status }) })
  },
  // Admin edit of an agency's name and company details. A blank tax number keeps the stored one.
  async updateAdminAgencyDetails(id, data) {
    if (AUTH_MODE === 'mock') return { agency: { id, ...data } }
    return request(`/admin/agencies/${encodeURIComponent(id)}/details`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  // Admin edit of an agency's contract (created on first save) and its held employees.
  async updateAdminAgencyContract(id, data) {
    if (AUTH_MODE === 'mock') return { contract: null, contract_form: data }
    return request(`/admin/agencies/${encodeURIComponent(id)}/contract`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async assignAdminAgencyEmployee(id, employeeId) {
    if (AUTH_MODE === 'mock') return { assignment: { id: Date.now(), employee_id: employeeId } }
    return request(`/admin/agencies/${encodeURIComponent(id)}/employees`, { method: 'POST', body: JSON.stringify({ employee_id: employeeId }) })
  },
  async updateAdminAgencyBranding(id, data) {
    if (AUTH_MODE === 'mock') return { branding: data }
    return request(`/admin/agencies/${encodeURIComponent(id)}/branding`, { method: 'PATCH', body: JSON.stringify(data) })
  },  async getAdminDashboard({ status, week, employeeFilter } = {}) {
    if (AUTH_MODE === 'mock') {
      return {
        today: '',
        week_start: '',
        week_end: '',
        generated_at: new Date().toISOString(),
        weekly_budget_hours: 36,
        employees: { total: 0, active: 0, inactive: 0, available: 0, held: 0, overloaded: 0, avg_utilization_percent: 0 },
        agencies: { total: 0, active: 0, suspended: 0 },
        employee_capacity: [],
        agency_rows: [],
        attention: [],
        work_summary: { projects: { total: 0, scheduled: 0, in_progress: 0, completed: 0 }, tickets: { total: 0 } },
        projects: { total: 0, scheduled: 0, in_progress: 0, completed: 0, cancelled: 0 },
        tickets: { total: 0 },
        scheduled_hours: Array.from({ length: 7 }, () => ({ date: '', hours: '0.00' })),
        recent_projects: [],
      }
    }
    // employeeFilter ('available', 'held', 'overloaded') narrows only the employee capacity list.
    const query = historyQuery({ status, week, employee_filter: employeeFilter && employeeFilter !== 'all' ? employeeFilter : undefined })
    return request(`/admin/dashboard${query ? `?${query}` : ''}`)
  },
  // Notifications for the signed-in user. `signal` lets callers abort stale polls.
  async getNotifications({ unread = false, cursor, signal } = {}) {
    if (AUTH_MODE === 'mock') return { notifications: [], next_cursor: null, unread_count: 0 }
    const query = new URLSearchParams()
    if (unread) query.set('unread', '1')
    if (cursor) query.set('cursor', cursor)
    const suffix = query.toString() ? `?${query.toString()}` : ''
    return request(`/notifications${suffix}`, { signal })
  },
  async getNotificationUnreadCount({ signal } = {}) {
    if (AUTH_MODE === 'mock') return { count: 0 }
    return request('/notifications/unread-count', { signal })
  },
  async markNotificationRead(id) {
    if (AUTH_MODE === 'mock') return { notification: { id }, unread_count: 0 }
    return request(`/notifications/${encodeURIComponent(id)}/read`, { method: 'POST' })
  },
  async markAllNotificationsRead() {
    if (AUTH_MODE === 'mock') return { unread_count: 0 }
    return request('/notifications/read-all', { method: 'POST' })
  },
  async getNotificationPreferences() {
    if (AUTH_MODE === 'mock') return { preferences: [] }
    return request('/notifications/preferences')
  },
  // `preferences` is [{type, in_app?, email?}]; locked rows always keep in-app on.
  async updateNotificationPreferences(preferences) {
    if (AUTH_MODE === 'mock') return { preferences }
    return request('/notifications/preferences', { method: 'PUT', body: JSON.stringify({ preferences }) })
  },
  // Attachments: private files on tickets. Downloads go through attachmentUrl() so the browser sends the session cookie.
  async listTicketAttachments(ticketId) {
    if (AUTH_MODE === 'mock') return { attachments: [], limits: null }
    return request(`/tickets/${encodeURIComponent(ticketId)}/attachments`)
  },
  // Sends files (and an optional comment) as multipart. `onProgress(0-100)` reports the upload.
  async uploadTicketAttachments(ticketId, { files, comment, isInternal = false, onProgress, signal } = {}) {
    if (AUTH_MODE === 'mock') return { attachments: [] }
    const form = new FormData()
    files.forEach((file) => form.append('files[]', file, file.name))
    if (comment) form.append('comment', comment)
    if (isInternal) form.append('is_internal', '1')
    return uploadForm(`/tickets/${encodeURIComponent(ticketId)}/attachments`, form, { onProgress, signal })
  },
  async deleteAttachment(id) {
    if (AUTH_MODE === 'mock') return { message: 'The file was removed.' }
    return request(`/attachments/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  attachmentUrl(id, { inline = false } = {}) {
    return `${API_URL}/attachments/${encodeURIComponent(id)}/download${inline ? '?inline=1' : ''}`
  },
  // Work activity. The heartbeat only says whether the tab was idle; the server measures the time.
  async sendHeartbeat({ idle = false, signal } = {}) {
    if (AUTH_MODE === 'mock') return { interval_seconds: 60, idle_after_minutes: 5 }
    return request('/employee/heartbeat', { method: 'POST', body: JSON.stringify({ idle }), signal })
  },
  // Employee consent notice. Returns the notice text only, never any recorded activity.
  async getMonitoringPolicy() {
    if (AUTH_MODE === 'mock') return { policy: { version: '0', title: '', summary: '', categories: [], not_collected: [], viewers: '', retention: '' }, consent: { accepted: true } }
    return request('/employee/monitoring-policy')
  },
  async acceptMonitoringPolicy(policyVersion) {
    if (AUTH_MODE === 'mock') return { consent: { accepted: true } }
    return request('/employee/monitoring-policy/accept', { method: 'POST', body: JSON.stringify({ policy_version: policyVersion }) })
  },
  // Admins and agency owners only. Owners use the /agency route, which is limited to their own agency's employees.
  async getEmployeeActivity(id, { from, to, eventType, page = 1, scope = 'admin' } = {}) {
    if (AUTH_MODE === 'mock') return { employee: { id, name: '' }, events: [], pagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }
    const query = new URLSearchParams({ page: String(page) })
    if (from) query.set('from', from)
    if (to) query.set('to', to)
    if (eventType) query.set('event_type', eventType)
    return request(`/${scope}/employees/${encodeURIComponent(id)}/activity?${query}`)
  },
  async getEmployeePerformance(id, { from, to, scope = 'admin' } = {}) {
    if (AUTH_MODE === 'mock') return { employee: { id, name: '' }, days: [], weeks: [], totals: {} }
    const query = new URLSearchParams()
    if (from) query.set('from', from)
    if (to) query.set('to', to)
    return request(`/${scope}/employees/${encodeURIComponent(id)}/performance?${query}`)
  },
  // Browser tab history: administrators only, read-only (there is deliberately no write method here).
  // The employee's own view: is the extension connected, and is browser activity being recorded right now?
  async getBrowserTrackingStatus() {
    if (AUTH_MODE === 'mock') return { consented: false, extension_connected: false, active_work: null, recording: false, retention_days: 365 }
    return request('/browser-history/me')
  },
  async getBrowserHistorySummary(filters = {}) {
    if (AUTH_MODE === 'mock') return { totals: { visits: 0, unique_domains: 0, tabs_opened: 0, seconds: 0 }, top_domains: [], daily: [] }
    return request(`/admin/browser-history/summary?${historyQuery(filters)}`)
  },
  async getBrowserHistoryEvents(filters = {}, { page = 1, perPage = 50 } = {}) {
    if (AUTH_MODE === 'mock') return { events: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    return request(`/admin/browser-history/events?${historyQuery(filters)}&page=${page}&per_page=${perPage}`)
  },
  // Files on a project brief.
  async listProjectAttachments(projectId) {
    if (AUTH_MODE === 'mock') return { attachments: [], limits: null, can_upload: false }
    return request(`/projects/${encodeURIComponent(projectId)}/attachments`)
  },
  async uploadProjectAttachments(projectId, files, { onProgress, signal } = {}) {
    if (AUTH_MODE === 'mock') return { attachments: [] }
    const form = new FormData()
    files.forEach((file) => form.append('files[]', file, file.name))
    return uploadForm(`/projects/${encodeURIComponent(projectId)}/attachments`, form, { onProgress, signal })
  },
  async deleteProjectAttachment(id) {
    if (AUTH_MODE === 'mock') return { message: 'The file was removed.' }
    return request(`/project-attachments/${encodeURIComponent(id)}`, { method: 'DELETE' })
  },
  projectAttachmentUrl(id, { inline = false } = {}) {
    return `${API_URL}/project-attachments/${encodeURIComponent(id)}/download${inline ? '?inline=1' : ''}`
  },
  // Time tracking. Only the assigned employee logs; admins correct entries with a reason.
  async getActiveTimer({ signal } = {}) {
    if (AUTH_MODE === 'mock') return { entry: null, server_time: new Date().toISOString() }
    return request('/time/active', { signal })
  },
  async startTimer(ticketId) {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), running: true }, stopped: null }
    return request(`/tickets/${encodeURIComponent(ticketId)}/time/start`, { method: 'POST' })
  },
  // Time straight on a project that has no tickets.
  async startProjectTimer(projectId) {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), running: true }, stopped: null }
    return request(`/work-items/${encodeURIComponent(projectId)}/time/start`, { method: 'POST' })
  },
  async logProjectTime(projectId, { entryDate, minutes, note }) {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), minutes, note } }
    return request(`/work-items/${encodeURIComponent(projectId)}/time`, { method: 'POST', body: JSON.stringify({ entry_date: entryDate, minutes, note: note || null }) })
  },
  async stopTimer() {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), running: false } }
    return request('/time/stop', { method: 'POST' })
  },
  async logTime(ticketId, { entryDate, minutes, note }) {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), minutes, note } }
    return request(`/tickets/${encodeURIComponent(ticketId)}/time`, {
      method: 'POST',
      body: JSON.stringify({ entry_date: entryDate, minutes, note: note || null }),
    })
  },
  async updateTimeEntry(id, data) {
    if (AUTH_MODE === 'mock') return { entry: { id, ...data } }
    return request(`/time/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async deleteTimeEntry(id, reason) {
    if (AUTH_MODE === 'mock') return { message: 'The time entry was removed.' }
    return request(`/time/${encodeURIComponent(id)}`, { method: 'DELETE', body: JSON.stringify(reason ? { reason } : {}) })
  },
  async getTicketTime(ticketId) {
    if (AUTH_MODE === 'mock') return { entries: [], entries_visible: true, totals: { logged_hours: 0, estimated_hours: 0, over_estimate: false, percent: null } }
    return request(`/tickets/${encodeURIComponent(ticketId)}/time`)
  },
  async getTimesheetWeek(week) {
    if (AUTH_MODE === 'mock') return { week_start: week, week_end: week, days: [], tickets: [], totals: { logged_hours: 0, scheduled_hours: 0 } }
    return request(`/time/week${week ? `?week=${encodeURIComponent(week)}` : ''}`)
  },
  // Logged vs scheduled vs estimated hours. `scope` is 'admin' or 'agency'.
  async getTimeSummary(scope, { from, to, groupBy }) {
    if (AUTH_MODE === 'mock') return { from, to, group_by: groupBy, rows: [], totals: { logged_hours: 0, scheduled_hours: 0, estimated_hours: 0, utilization: null } }
    return request(`/${scope === 'admin' ? 'admin' : 'agency'}/time-summary?${new URLSearchParams({ from, to, group_by: groupBy })}`)
  },
  timeSummaryCsvUrl(scope, { from, to, groupBy }) {
    return `${API_URL}/${scope === 'admin' ? 'admin' : 'agency'}/time-summary?${new URLSearchParams({ from, to, group_by: groupBy, format: 'csv' })}`
  },
  async getAgencyProject(id) {
    if (AUTH_MODE === 'mock') return { project: null }
    return request(`/agency/projects/${encodeURIComponent(id)}`)
  },
  async createAgencyProject(data) {
    if (AUTH_MODE === 'mock') return { project: { id: Date.now(), ...data, status: 'scheduled', schedule: [] } }
    return request('/agency/projects', { method: 'POST', body: JSON.stringify(data) })
  },
  // Agency owners only. Never sends `status`: it follows ticket completion or the cancel action.
  async updateAgencyProject(id, data) {
    if (AUTH_MODE === 'mock') return { project: { id, ...data } }
    return request(`/agency/projects/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  // The project's employee timeline (agency owners, own agency only).
  async getProjectActivity(id, { eventType, page = 1 } = {}) {
    if (AUTH_MODE === 'mock') return { employee: { id: 0, name: '', photo_url: null }, project: { id, title: '' }, events: [], pagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }
    const query = new URLSearchParams({ page: String(page) })
    if (eventType) query.set('event_type', eventType)
    return request(`/agency/projects/${encodeURIComponent(id)}/activity?${query}`)
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
  // `isInternal` posts an internal note (admin and the assigned employee only).
  async createTicketComment(id, body, { isInternal = false } = {}) {
    if (AUTH_MODE === 'mock') return { comment: { id: Date.now(), ticket_id: id, body, is_internal: isInternal } }
    return request(`/tickets/${encodeURIComponent(id)}/comments`, {
      method: 'POST',
      body: JSON.stringify({ body, ...(isInternal ? { is_internal: true } : {}) }),
    })
  },
  // Resolves to { ticket, project: {id, title, status, agency_name}, timeline: [first page of events] }.
  async getTicketByKey(key) {
    if (AUTH_MODE === 'mock') return { ticket: null, project: null, timeline: [] }
    return request(`/tickets/key/${encodeURIComponent(key)}`)
  },
  // Support module: the agency portal (`/agency/support/*`) and the staff console (`/staff/*`). Tickets are addressed by ref (DG-1048).
  async getSupportForm() {
    if (AUTH_MODE === 'mock') return { types: [], services: [], priorities: [], end_clients: [], projects: [] }
    return request('/agency/support/form')
  },
  async suggestSupportPriority(data) {
    if (AUTH_MODE === 'mock') return { priority: 'normal', label: 'P3 Normal' }
    return request('/agency/support/suggest-priority', { method: 'POST', body: JSON.stringify(data) })
  },
  async scanSupportText(text) {
    if (AUTH_MODE === 'mock') return { found: [] }
    return request('/support/scan-text', { method: 'POST', body: JSON.stringify({ text }) })
  },
  async listSupportTickets(params = {}) {
    if (AUTH_MODE === 'mock') return { tickets: [], counts: {}, pagination: { current_page: 1, last_page: 1, total: 0 } }
    return request(`/agency/support/tickets?${new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value != null))}`)
  },
  async createSupportTicket(data) {
    if (AUTH_MODE === 'mock') return { ticket: { id: Date.now(), ref: 'DG-0000' } }
    return request('/agency/support/tickets', { method: 'POST', body: JSON.stringify(data) })
  },
  async getSupportTicket(ref) {
    if (AUTH_MODE === 'mock') return { ticket: null }
    return request(`/agency/support/tickets/${encodeURIComponent(ref)}`)
  },
  async reopenSupportTicket(ref, reason) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/agency/support/tickets/${encodeURIComponent(ref)}/reopen`, { method: 'POST', body: JSON.stringify({ reason }) })
  },
  async confirmSupportResolution(ref) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/agency/support/tickets/${encodeURIComponent(ref)}/confirm`, { method: 'POST' })
  },
  async addTicketLink(ticketId, { url, title, isInternal = false }) {
    if (AUTH_MODE === 'mock') return { attachment: { id: Date.now(), url, title } }
    return request(`/tickets/${encodeURIComponent(ticketId)}/links`, { method: 'POST', body: JSON.stringify({ url, title: title || undefined, ...(isInternal ? { is_internal: true } : {}) }) })
  },
  async listStaffTickets(params = {}) {
    if (AUTH_MODE === 'mock') return { tickets: [], counts: {}, pagination: { current_page: 1, last_page: 1, total: 0 } }
    return request(`/staff/tickets?${new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value != null))}`)
  },
  async getStaffTicket(ref) {
    if (AUTH_MODE === 'mock') return { ticket: null }
    return request(`/staff/tickets/${encodeURIComponent(ref)}`)
  },
  async transitionStaffTicket(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/transition`, { method: 'POST', body: JSON.stringify(data) })
  },
  async assignStaffTicket(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/assign`, { method: 'POST', body: JSON.stringify(data) })
  },
  async updateStaffTicket(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async getStaffTicketTime(ref) {
    if (AUTH_MODE === 'mock') return { time: { entries: [], actual_minutes: 0, billable_minutes: 0, estimate_hours: null } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/time`)
  },
  async logStaffTicketTime(ref, data) {
    if (AUTH_MODE === 'mock') return { time: { entries: [], actual_minutes: 0, billable_minutes: 0, estimate_hours: null } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/time`, { method: 'POST', body: JSON.stringify(data) })
  },
  async classifyStaffTicket(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/scope`, { method: 'POST', body: JSON.stringify(data) })
  },
  async approveStaffTicketScope(ref) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/scope/approve`, { method: 'POST' })
  },
  async listCannedResponses() {
    if (AUTH_MODE === 'mock') return { responses: [] }
    return request('/staff/canned-responses')
  },
  async submitSupportFeedback(ref, data) {
    if (AUTH_MODE === 'mock') return { message: 'ok' }
    return request(`/agency/support/tickets/${encodeURIComponent(ref)}/feedback`, { method: 'POST', body: JSON.stringify(data) })
  },
  async sendTicketQuote(ref, data) {
    if (AUTH_MODE === 'mock') return { quotes: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/quote`, { method: 'POST', body: JSON.stringify(data) })
  },
  async withdrawTicketQuote(ref) {
    if (AUTH_MODE === 'mock') return { quotes: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/quote`, { method: 'DELETE' })
  },
  async answerSupportQuote(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref } }
    return request(`/agency/support/tickets/${encodeURIComponent(ref)}/quote`, { method: 'POST', body: JSON.stringify(data) })
  },
  async bulkStaffTickets(data) {
    if (AUTH_MODE === 'mock') return { results: [], done: 0 }
    return request('/staff/tickets/bulk', { method: 'POST', body: JSON.stringify(data) })
  },
  async listSavedViews() {
    if (AUTH_MODE === 'mock') return { views: [] }
    return request('/staff/saved-views')
  },
  async saveSavedView(data) {
    if (AUTH_MODE === 'mock') return { views: [] }
    return request('/staff/saved-views', { method: 'POST', body: JSON.stringify(data) })
  },
  async deleteSavedView(id) {
    if (AUTH_MODE === 'mock') return { views: [] }
    return request(`/staff/saved-views/${id}`, { method: 'DELETE' })
  },
  async getStaffWorkload() {
    if (AUTH_MODE === 'mock') return { people: [], unassigned: 0 }
    return request('/staff/workload')
  },
  async getAgencyEntitlement(agencyId) {
    if (AUTH_MODE === 'mock') return { entitlement: null }
    return request(`/staff/agencies/${agencyId}/entitlement`)
  },
  async saveAgencyEntitlement(agencyId, data) {
    if (AUTH_MODE === 'mock') return { entitlement: data }
    return request(`/staff/agencies/${agencyId}/entitlement`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async listRecurringTickets() {
    if (AUTH_MODE === 'mock') return { schedules: [] }
    return request('/agency/support/recurring')
  },
  async createRecurringTicket(data) {
    if (AUTH_MODE === 'mock') return { id: 1 }
    return request('/agency/support/recurring', { method: 'POST', body: JSON.stringify(data) })
  },
  async deleteRecurringTicket(id) {
    if (AUTH_MODE === 'mock') return { message: 'ok' }
    return request(`/agency/support/recurring/${id}`, { method: 'DELETE' })
  },
  async getTicketQa(ref) {
    if (AUTH_MODE === 'mock') return { library: {}, runs: [], bug: {} }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/qa`)
  },
  async recordTicketQa(ref, data) {
    if (AUTH_MODE === 'mock') return { status: 'resolved', runs: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/qa`, { method: 'POST', body: JSON.stringify(data) })
  },
  async saveTicketBug(ref, data) {
    if (AUTH_MODE === 'mock') return { bug: data }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/bug`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async listTicketApprovals(ref) {
    if (AUTH_MODE === 'mock') return { approvals: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/approvals`)
  },
  async requestTicketApproval(ref, data) {
    if (AUTH_MODE === 'mock') return { approvals: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/approvals`, { method: 'POST', body: JSON.stringify(data) })
  },
  async decideTicketApproval(ref, id, data) {
    if (AUTH_MODE === 'mock') return { approvals: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/approvals/${id}`, { method: 'POST', body: JSON.stringify(data) })
  },
  async getTicketRelations(ref) {
    if (AUTH_MODE === 'mock') return { relations: [], watching: false, watchers: 0 }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/relations`)
  },
  async addTicketRelation(ref, data) {
    if (AUTH_MODE === 'mock') return { relations: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/relations`, { method: 'POST', body: JSON.stringify(data) })
  },
  async removeTicketRelation(ref, id) {
    if (AUTH_MODE === 'mock') return { relations: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/relations/${id}`, { method: 'DELETE' })
  },
  async watchTicket(ref, on) {
    if (AUTH_MODE === 'mock') return { watching: on }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/watch`, { method: 'POST', body: JSON.stringify({ on }) })
  },
  async getSupportSettings() {
    if (AUTH_MODE === 'mock') return { settings: {} }
    return request('/staff/settings')
  },
  async saveSupportSettings(data) {
    if (AUTH_MODE === 'mock') return { settings: data }
    return request('/staff/settings', { method: 'PUT', body: JSON.stringify(data) })
  },
  async listTicketAssignments(ref) {
    if (AUTH_MODE === 'mock') return { assignments: [] }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/assignments`)
  },
  async convertStaffTicket(ref, data) {
    if (AUTH_MODE === 'mock') return { ticket: { ref }, project: null }
    return request(`/staff/tickets/${encodeURIComponent(ref)}/convert`, { method: 'POST', body: JSON.stringify(data) })
  },
  async getStaffDashboard({ from, to } = {}) {
    if (AUTH_MODE === 'mock') return { kpis: null }
    return request(`/staff/dashboard?${new URLSearchParams(Object.entries({ from, to }).filter(([, value]) => value))}`)
  },
  async listStaffPeople() {
    if (AUTH_MODE === 'mock') return { people: [] }
    return request('/staff/people')
  },
  // GoHighLevel brief and ticket work orders. The brief replaces whole sections; the checklist and delivery calls
  // return the refreshed `work_order`.
  async updateProjectBrief(projectId, ghlBrief) {
    if (AUTH_MODE === 'mock') return { ghl_brief: ghlBrief }
    return request(`/agency/projects/${encodeURIComponent(projectId)}/brief`, { method: 'PATCH', body: JSON.stringify({ ghl_brief: ghlBrief }) })
  },
  async getTicketTemplates() {
    if (AUTH_MODE === 'mock') return { templates: [] }
    return request('/agency/ticket-templates')
  },
  async duplicateTicket(projectId, ticketId, data = {}) {
    if (AUTH_MODE === 'mock') return { ticket: { id: Date.now() } }
    return request(`/agency/projects/${encodeURIComponent(projectId)}/tickets/${encodeURIComponent(ticketId)}/duplicate`, { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTicketDelivery(id, data) {
    if (AUTH_MODE === 'mock') return { work_order: { delivery: data } }
    return request(`/tickets/${encodeURIComponent(id)}/delivery`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async updateTicketBlocked(id, data) {
    if (AUTH_MODE === 'mock') return { work_order: { blocked: data } }
    return request(`/tickets/${encodeURIComponent(id)}/blocked`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async toggleChecklistItem(ticketId, itemId, done) {
    if (AUTH_MODE === 'mock') return { work_order: { checklist: [] } }
    return request(`/tickets/${encodeURIComponent(ticketId)}/checklist/${encodeURIComponent(itemId)}`, { method: 'PATCH', body: JSON.stringify({ done }) })
  },
  // Cursor-paginated, newest first: { events, next_cursor }.
  async getTicketTimeline(id, { cursor } = {}) {
    if (AUTH_MODE === 'mock') return { events: [], next_cursor: null }
    const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''
    return request(`/tickets/${encodeURIComponent(id)}/timeline${query}`)
  },
  // Runs a workflow action (see ticket.allowed_actions). `comment` is required by some actions.
  async transitionTicket(id, action, comment) {
    if (AUTH_MODE === 'mock') return { ticket: { id, action } }
    return request(`/tickets/${encodeURIComponent(id)}/transitions`, {
      method: 'POST',
      body: JSON.stringify({ action, ...(comment ? { comment } : {}) }),
    })
  },
  // Agency: tickets waiting on the client, longest-waiting first ({ tickets, total }).
  async getAgencyAttention() {
    if (AUTH_MODE === 'mock') return { tickets: [], total: 0 }
    return request('/agency/attention')
  },
  // Filters are applied by the server: status, agencyId, employeeId, waitingOn (client|employee|team), search (key or title).
  async listAdminTickets({ page = 1, perPage = 25, status, agencyId, employeeId, waitingOn, search } = {}) {
    if (AUTH_MODE === 'mock') return { tickets: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (status) query.set('status', status)
    if (agencyId) query.set('agency_id', String(agencyId))
    if (employeeId) query.set('employee_id', String(employeeId))
    if (waitingOn) query.set('waiting_on', waitingOn)
    if (search) query.set('search', search)
    return request(`/admin/tickets?${query.toString()}`)
  },
  async listAdminAssignments({ page = 1, perPage = 25 } = {}) {
    if (AUTH_MODE === 'mock') return { assignments: [], pagination: { current_page: 1, last_page: 1, per_page: perPage, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    return request(`/admin/assignments?${query.toString()}`)
  },
  async listAdminProjects({ page = 1, search = '', perPage = 25, status } = {}) {
    if (AUTH_MODE === 'mock') return { projects: [], pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (search) query.set('search', search)
    if (status) query.set('status', status)
    return request(`/admin/projects?${query.toString()}`)
  },
  async getAdminProject(id) {
    if (AUTH_MODE === 'mock') return { project: null, tickets: [] }
    return request(`/admin/projects/${encodeURIComponent(id)}`)
  },
  // The assigned employee starts or finishes their own project; tickets are optional.
  async startMyProject(id) {
    if (AUTH_MODE === 'mock') return { project: { id, status: 'in_progress' } }
    return request(`/work-items/${encodeURIComponent(id)}/start`, { method: 'POST' })
  },
  async finishMyProject(id) {
    if (AUTH_MODE === 'mock') return { project: { id, status: 'completed' } }
    return request(`/work-items/${encodeURIComponent(id)}/finish`, { method: 'POST' })
  },
  async listMyWork() {
    if (AUTH_MODE === 'mock') return { projects: [], capacity: DEFAULT_CAPACITY }
    return request('/work-items/mine')
  },
  // In-app assistant: the signed-in role's preset questions ({ categories }) and their answers from live data.
  async getAssistantQuestions() {
    if (AUTH_MODE === 'mock') return { categories: [] }
    return request('/assistant/questions')
  },
  async askAssistant(questionKey, params = {}) {
    if (AUTH_MODE === 'mock') return { question_key: questionKey, title: '', generated_at: new Date().toISOString(), blocks: [], empty_message: 'The assistant needs the API.' }
    return request('/assistant/ask', { method: 'POST', body: JSON.stringify({ question_key: questionKey, params }) })
  },
  // Reports: computed in SQL, scoped by authenticated user.
  async getReport(scope, type, params = {}) {
    if (AUTH_MODE === 'mock') {
      return {
        type,
        range: { from: params.from ?? '', to: params.to ?? '', days: 30 },
        rows: [],
        pagination: { current_page: 1, last_page: 1, total: 0 },
        summary: {},
        totals: null,
      }
    }
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
    )
    const query = new URLSearchParams(cleanParams).toString()
    return request(`/${scope}/reports/${encodeURIComponent(type)}${query ? `?${query}` : ''}`)
  },
  async downloadReport(scope, type, params = {}) {
    const cleanParams = Object.fromEntries(
      Object.entries({ ...params, format: 'csv' }).filter(([, v]) => v !== undefined && v !== null && v !== '')
    )
    const query = new URLSearchParams(cleanParams).toString()
    const path = `/${scope}/reports/${encodeURIComponent(type)}${query ? `?${query}` : ''}`

    if (AUTH_MODE === 'mock') {
      const blob = new Blob(['Metric,Value\nMock,1'], { type: 'text/csv;charset=utf-8;' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `report-${type}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      return
    }

    const response = await fetch(`${API_URL}${path}`, {
      credentials: 'include',
      headers: {
        Accept: 'text/csv, application/json',
      },
    })

    if (!response.ok) {
      if (response.status === 401) notifyUnauthorized(path)
      let message = 'Failed to download report.'
      try {
        const json = await response.json()
        if (json?.message) message = json.message
      } catch {
        // non-json error
      }
      throw new ApiError(message, { status: response.status })
    }

    const blob = await response.blob()
    let filename = `report-${type}.csv`
    const disposition = response.headers.get('content-disposition')
    if (disposition && disposition.includes('filename=')) {
      const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
      if (match?.[1]) {
        filename = match[1].replace(/['"]/g, '').trim()
      }
    }

    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  },
}

export { isKnownRole }

  