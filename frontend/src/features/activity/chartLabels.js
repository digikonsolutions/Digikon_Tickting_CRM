import { formatDate } from '../../lib/format'

export const dayLabel = (row) => formatDate(row.date).replace(/^(\w{3}), /, '$1 ')

export const weekLabel = (row) => `Wk ${formatDate(row.week_start).replace(/^\w{3}, /, '')}`
