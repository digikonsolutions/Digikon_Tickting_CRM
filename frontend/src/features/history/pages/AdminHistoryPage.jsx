import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, FilterChips, Input, PageHeader, Select } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { todayStr } from '../../../lib/format'
import { HistoryOverview } from '../components/HistoryOverview'
import { HistoryTimeline } from '../components/HistoryTimeline'
import { EVENT_TYPE_OPTIONS, PRESETS, rangeFor } from '../historyData'

// Administrators only, strictly read-only: every request here is a GET and there is no way to change a record.
export function AdminHistoryPage() {
  const [preset, setPreset] = useState('7d')
  const [custom, setCustom] = useState({ from: todayStr(), to: todayStr() })
  const [employeeId, setEmployeeId] = useState('')
  const [agencyId, setAgencyId] = useState('')
  const [eventType, setEventType] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  // Opened from a ticket (?ticket_id=): everything recorded while that ticket was in progress, whatever the date.
  const [params, setParams] = useSearchParams()
  const ticketId = /^\d+$/.test(params.get('ticket_id') ?? '') ? params.get('ticket_id') : ''

  // Debounce the search box so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = window.setTimeout(() => { setQuery(search.trim()); setPage(1) }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  const range = rangeFor(preset, custom)
  const validRange = Boolean(ticketId) || Boolean(range.from && range.to && range.from <= range.to)
  const filters = useMemo(
    () => (ticketId
      ? { ticket_id: ticketId, employee_id: employeeId, event_type: eventType, q: query }
      : { from: range.from, to: range.to, employee_id: employeeId, agency_id: agencyId, event_type: eventType, q: query }),
    [ticketId, range.from, range.to, employeeId, agencyId, eventType, query],
  )

  const loadPeople = useCallback(async () => {
    const [employees, owners] = await Promise.all([
      authService.listAdminUsers({ role: 'employee', perPage: 100 }),
      authService.listAdminUsers({ role: 'agency_owner', perPage: 100 }),
    ])
    const agencies = new Map()
    owners.users.forEach((owner) => { if (owner.agency_id) agencies.set(owner.agency_id, owner.agency_name) })
    return { employees: employees.users, agencies: [...agencies].map(([id, name]) => ({ id, name })) }
  }, [])
  const people = useAsync(loadPeople)

  const loadSummary = useCallback(() => (validRange ? authService.getBrowserHistorySummary(filters) : Promise.resolve(null)), [filters, validRange])
  const loadEvents = useCallback(() => (validRange ? authService.getBrowserHistoryEvents(filters, { page }) : Promise.resolve(null)), [filters, validRange, page])
  const summary = useAsync(loadSummary)
  const events = useAsync(loadEvents)

  function changeFilter(setter) {
    return (event) => { setter(event.target.value); setPage(1) }
  }

  return (
    <section className="page history-page">
      <PageHeader
        eyebrow="Administration"
        title="Browser history"
        subtitle="Tab activity reported by employees' browser extension after they accepted the monitoring notice. Read-only."
      />

      <Card>
        <div className="history-filters">
          <FilterChips label="Date range" options={PRESETS} value={preset} onChange={(value) => { setPreset(value); setPage(1) }} />
          {preset === 'custom' && (
            <div className="history-custom">
              <Input label="From" type="date" value={custom.from} max={custom.to || undefined} onChange={(event) => { setCustom((current) => ({ ...current, from: event.target.value })); setPage(1) }} />
              <Input label="To" type="date" value={custom.to} min={custom.from || undefined} max={todayStr()} onChange={(event) => { setCustom((current) => ({ ...current, to: event.target.value })); setPage(1) }} />
            </div>
          )}
          <div className="history-filter-grid">
            <Input label="Search websites" type="search" value={search} placeholder="Domain or page title" onChange={(event) => setSearch(event.target.value)} />
            <Select label="Employee" value={employeeId} onChange={changeFilter(setEmployeeId)}>
              <option value="">All employees</option>
              {(people.data?.employees ?? []).map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
            </Select>
            <Select label="Agency" value={agencyId} onChange={changeFilter(setAgencyId)}>
              <option value="">All agencies</option>
              {(people.data?.agencies ?? []).map((agency) => <option key={agency.id} value={agency.id}>{agency.name}</option>)}
            </Select>
            <Select label="Activity type" value={eventType} onChange={changeFilter(setEventType)}>
              {EVENT_TYPE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
          </div>
        </div>
      </Card>

      {!validRange && <Alert variant="warning">Choose a start date that is not after the end date.</Alert>}
      {summary.error && <Alert variant="error" title="Could not load the overview">{summary.error.message}</Alert>}

      {ticketId && (
        <Alert variant="info" title="One ticket">
          Showing what was recorded while ticket {events.data?.events?.[0]?.ticket?.key ?? `#${ticketId}`} was in progress. The date range does not apply.
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={() => { setParams({}, { replace: true }); setPage(1) }}>Show all activity</Button></div>
        </Alert>
      )}
      <HistoryOverview summary={summary.data} isLoading={summary.isLoading} />
      <HistoryTimeline data={events.data} error={events.error} isLoading={events.isLoading} reload={events.reload} onPage={setPage} />
    </section>
  )
}
