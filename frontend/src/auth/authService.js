import { ROLES, isKnownRole } from './roles'
import { DEFAULT_CAPACITY } from '../lib/constants'

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

// Multipart upload with progress (fetch cannot report upload progress). Mirrors request(): CSRF cookie,
// credentials, and ApiError for failures. `onProgress` receives a 0 to 100 percentage.
async function uploadForm(path, formData, { onProgress, signal } = {}) {
  await establishCsrfCookie()
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
  // One-call admin dashboard summary: { today, projects (counts), tickets, scheduled_hours, recent_projects }.
  async getAdminDashboard({ status } = {}) {
    if (AUTH_MODE === 'mock') {
      return {
        today: '',
        projects: { total: 0, scheduled: 0, in_progress: 0, completed: 0, cancelled: 0 },
        tickets: { total: 0 },
        scheduled_hours: Array.from({ length: 7 }, () => ({ date: '', hours: '0.00' })),
        recent_projects: [],
      }
    }
    const query = status ? `?status=${encodeURIComponent(status)}` : ''
    return request(`/admin/dashboard${query}`)
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
  // Time tracking. Only the assigned specialist logs; admins correct entries with a reason.
  async getActiveTimer({ signal } = {}) {
    if (AUTH_MODE === 'mock') return { entry: null, server_time: new Date().toISOString() }
    return request('/time/active', { signal })
  },
  async startTimer(ticketId) {
    if (AUTH_MODE === 'mock') return { entry: { id: Date.now(), running: true }, stopped: null }
    return request(`/tickets/${encodeURIComponent(ticketId)}/time/start`, { method: 'POST' })
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
    if (AUTH_MODE === 'mock') return { projects: [], capacity: DEFAULT_CAPACITY }
    return request('/work-items/mine')
  },
}

export { isKnownRole }
