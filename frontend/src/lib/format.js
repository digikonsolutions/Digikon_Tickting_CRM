import { AT_RISK_DAYS } from './constants'

// All date strings are `YYYY-MM-DD` and are parsed as LOCAL dates.
// Never use `new Date('2026-11-14')`: that is parsed as UTC and shifts by timezone.

const MS_PER_DAY = 86400000
const OPEN_STATUSES = ['scheduled', 'in_progress', 'todo', 'in_review']

function parseLocal(dateStr) {
  if (typeof dateStr !== 'string') return null
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(dateStr)
  if (!match) return null
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

export function toDateStr(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function todayStr() {
  return toDateStr(new Date())
}

// Monday (as a local Date) of the week containing `dateStr`, or of the current week.
export function mondayOf(dateStr) {
  const d = (dateStr && parseLocal(dateStr)) || parseLocal(todayStr())
  const dayOfWeek = d.getDay() || 7
  d.setDate(d.getDate() - dayOfWeek + 1)
  return d
}

// `YYYY-MM-DD` shifted by `days` (may be negative).
export function addDays(dateStr, days) {
  const d = parseLocal(dateStr)
  d.setDate(d.getDate() + days)
  return toDateStr(d)
}

function shortDate(date, { weekday }) {
  const options = { month: 'short', day: 'numeric' }
  if (weekday) options.weekday = 'short'
  if (date.getFullYear() !== new Date().getFullYear()) options.year = 'numeric'
  return new Intl.DateTimeFormat('en-US', options).format(date)
}

// "Fri, Nov 14"; the year is added only when it is not the current year.
export function formatDate(dateStr) {
  const date = parseLocal(dateStr)
  return date ? shortDate(date, { weekday: true }) : '—'
}

// "Nov 10 – Nov 14"
export function formatDateRange(start, due) {
  const startDate = parseLocal(start)
  const dueDate = parseLocal(due)
  if (startDate && dueDate) return `${shortDate(startDate, {})} – ${shortDate(dueDate, {})}`
  if (startDate || dueDate) return shortDate(startDate ?? dueDate, {})
  return '—'
}

// 6 -> "6 h", 6.5 -> "6.5 h", "6.00" -> "6 h", null/NaN -> "—"
export function formatHours(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return `${Number(number.toFixed(2))} h`
}

function plural(count, word) {
  return `${count} ${word}${count === 1 ? '' : 's'}`
}

// { label, tone } where tone is 'danger' | 'warning' | 'neutral'.
export function getDueInfo(dueDateStr, status) {
  const due = parseLocal(dueDateStr)
  if (!due) return { label: 'No due date', tone: 'neutral' }
  if (!OPEN_STATUSES.includes(status)) return { label: formatDate(dueDateStr), tone: 'neutral' }

  const today = parseLocal(todayStr())
  const days = Math.round((due - today) / MS_PER_DAY)
  if (days < 0) return { label: `${plural(-days, 'day')} overdue`, tone: 'danger' }
  if (days === 0) return { label: 'Due today', tone: 'warning' }
  if (days <= AT_RISK_DAYS) return { label: `Due in ${plural(days, 'day')}`, tone: 'warning' }
  if (days <= 14) return { label: `Due in ${plural(days, 'day')}`, tone: 'neutral' }
  return { label: `Due ${formatDate(dueDateStr)}`, tone: 'neutral' }
}

// "3/5 tickets done" from a project payload's ticket progress fields.
export function formatTicketProgress(project) {
  const total = Number(project?.tickets_total)
  if (!Number.isFinite(total) || total <= 0) return 'No tickets yet'
  return `${Number(project.tickets_done) || 0}/${total} ticket${total === 1 ? '' : 's'} done`
}

// Full local date and time for tooltips, e.g. "Fri, Nov 14, 3:05 PM".
export function formatDateTime(value) {
  const date = value ? new Date(value) : null
  if (!date || Number.isNaN(date.getTime())) return ''
  const options = { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }
  if (date.getFullYear() !== new Date().getFullYear()) options.year = 'numeric'
  return new Intl.DateTimeFormat('en-US', options).format(date)
}

// "just now", "5 min ago", "3 h ago", "yesterday", otherwise the date. `value` is an ISO timestamp.
export function formatRelativeTime(value, now = new Date()) {
  const date = value ? new Date(value) : null
  if (!date || Number.isNaN(date.getTime())) return ''
  const seconds = Math.floor((now - date) / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const dateStr = toDateStr(date)
  if (dateStr === toDateStr(now)) return `${Math.floor(minutes / 60)} h ago`
  const yesterday = new Date(now)
  yesterday.setDate(yesterday.getDate() - 1)
  if (dateStr === toDateStr(yesterday)) return 'yesterday'
  return formatDate(dateStr)
}

// How long ago an ISO timestamp was, in coarse units: "less than an hour", "3 hours", "2 days".
export function formatElapsed(value, now = new Date()) {
  const date = value ? new Date(value) : null
  if (!date || Number.isNaN(date.getTime())) return ''
  const hours = Math.floor((now - date) / 3600000)
  if (hours < 1) return 'less than an hour'
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'}`
  const days = Math.floor(hours / 24)
  return `${days} day${days === 1 ? '' : 's'}`
}
