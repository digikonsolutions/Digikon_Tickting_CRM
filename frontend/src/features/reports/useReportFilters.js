import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

export const PRESET_OPTIONS = [
  { key: 'this_week', label: 'This week' },
  { key: 'last_7_days', label: 'Last 7 days' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_30_days', label: 'Last 30 days' },
  { key: 'custom', label: 'Custom' },
]

export function formatDateYmd(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function getPresetDates(key) {
  const now = new Date()
  switch (key) {
    case 'this_week': {
      const day = now.getDay()
      const diffToMonday = (day === 0 ? -6 : 1) - day
      const monday = new Date(now)
      monday.setDate(now.getDate() + diffToMonday)
      return { from: formatDateYmd(monday), to: formatDateYmd(now) }
    }
    case 'last_7_days': {
      const start = new Date(now)
      start.setDate(now.getDate() - 6)
      return { from: formatDateYmd(start), to: formatDateYmd(now) }
    }
    case 'this_month': {
      const start = new Date(now.getFullYear(), now.getMonth(), 1)
      return { from: formatDateYmd(start), to: formatDateYmd(now) }
    }
    case 'last_30_days':
    default: {
      const start = new Date(now)
      start.setDate(now.getDate() - 29)
      return { from: formatDateYmd(start), to: formatDateYmd(now) }
    }
  }
}

export function useReportFilters({ initialTab = 'overview', allowedTabs = [] } = {}) {
  const [searchParams, setSearchParams] = useSearchParams()

  const rawTab = searchParams.get('tab') || initialTab
  const tab = allowedTabs.length > 0 && !allowedTabs.includes(rawTab) ? initialTab : rawTab

  const preset = searchParams.get('preset') || 'last_30_days'
  const defaultDates = useMemo(() => getPresetDates(preset === 'custom' ? 'last_30_days' : preset), [preset])

  const from = searchParams.get('from') || defaultDates.from
  const to = searchParams.get('to') || defaultDates.to
  const status = searchParams.get('status') || ''
  const agencyId = searchParams.get('agency_id') || ''
  const employeeId = searchParams.get('employee_id') || ''
  const clientId = searchParams.get('client_id') || ''
  const page = Math.max(1, parseInt(searchParams.get('page') || '1', 10))
  const perPage = Math.min(100, Math.max(1, parseInt(searchParams.get('per_page') || '25', 10)))
  const sort = searchParams.get('sort') || ''
  const direction = searchParams.get('direction') || ''

  const updateParams = useCallback((updates) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      Object.entries(updates).forEach(([key, val]) => {
        if (val === null || val === undefined || val === '') {
          next.delete(key)
        } else {
          next.set(key, String(val))
        }
      })
      return next
    }, { replace: true })
  }, [setSearchParams])

  const setTab = useCallback((nextTab) => {
    updateParams({
      tab: nextTab,
      status: null,
      sort: null,
      direction: null,
      page: 1,
    })
  }, [updateParams])

  const setPreset = useCallback((nextPreset) => {
    if (nextPreset === 'custom') {
      updateParams({ preset: 'custom', page: 1 })
    } else {
      const dates = getPresetDates(nextPreset)
      updateParams({
        preset: nextPreset,
        from: dates.from,
        to: dates.to,
        page: 1,
      })
    }
  }, [updateParams])

  const setDates = useCallback(({ from: nextFrom, to: nextTo }) => {
    updateParams({
      preset: 'custom',
      from: nextFrom,
      to: nextTo,
      page: 1,
    })
  }, [updateParams])

  const setStatus = useCallback((nextStatus) => {
    updateParams({ status: nextStatus, page: 1 })
  }, [updateParams])

  const setAgencyId = useCallback((nextId) => {
    updateParams({ agency_id: nextId, page: 1 })
  }, [updateParams])

  const setEmployeeId = useCallback((nextId) => {
    updateParams({ employee_id: nextId, page: 1 })
  }, [updateParams])

  const setClientId = useCallback((nextId) => {
    updateParams({ client_id: nextId, page: 1 })
  }, [updateParams])

  const setPage = useCallback((nextPage) => {
    updateParams({ page: nextPage })
  }, [updateParams])

  const setPerPage = useCallback((nextPerPage) => {
    updateParams({ per_page: nextPerPage, page: 1 })
  }, [updateParams])

  const setSort = useCallback((nextSort, nextDirection) => {
    updateParams({
      sort: nextSort,
      direction: nextDirection,
      page: 1,
    })
  }, [updateParams])

  const resetFilters = useCallback(() => {
    const dates = getPresetDates('last_30_days')
    updateParams({
      preset: 'last_30_days',
      from: dates.from,
      to: dates.to,
      status: null,
      agency_id: null,
      employee_id: null,
      client_id: null,
      sort: null,
      direction: null,
      page: 1,
    })
  }, [updateParams])

  const apiParams = useMemo(() => {
    const params = {
      from,
      to,
      page,
      per_page: perPage,
    }
    if (status) params.status = status
    if (agencyId) params.agency_id = agencyId
    if (employeeId) params.employee_id = employeeId
    if (clientId) params.client_id = clientId
    if (sort) {
      params.sort = sort
      if (direction) params.direction = direction
    }
    return params
  }, [from, to, page, perPage, status, agencyId, employeeId, clientId, sort, direction])

  const hasActiveFilters = Boolean(
    status || agencyId || employeeId || clientId || preset !== 'last_30_days' || sort
  )

  return {
    tab,
    setTab,
    preset,
    setPreset,
    from,
    to,
    setDates,
    status,
    setStatus,
    agencyId,
    setAgencyId,
    employeeId,
    setEmployeeId,
    clientId,
    setClientId,
    page,
    setPage,
    perPage,
    setPerPage,
    sort,
    direction,
    setSort,
    resetFilters,
    apiParams,
    hasActiveFilters,
  }
}
