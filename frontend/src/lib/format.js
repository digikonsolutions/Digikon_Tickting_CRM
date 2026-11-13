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
