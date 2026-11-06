import { useCallback, useMemo, useState } from 'react'
import { PageHeader } from '../../components/ui'
import { SectionCard, TicketDetailModal, TicketFilters, TicketList } from '../../components/dashboard'
import { useAsync } from '../../hooks/useAsync'
import { dashboardService } from '../../services/dashboardService'

const STATUSES = ['New', 'Assigned', 'In Progress', 'Waiting for Agency', 'QA Review', 'Resolved']

// Reusable ticket workspace: Team Queue, My Tickets and QA Queue differ only by props.
export function TicketsView({ eyebrow, title, subtitle, scope, statuses = STATUSES, showAgency = true, emptyText }) {
  const loader = useCallback(() => dashboardService.getTickets({ scope }), [scope])
  const { data, isLoading, error, reload } = useAsync(loader)
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('All')
  const [selected, setSelected] = useState(null)

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return (data ?? []).filter((t) =>
      (status === 'All' || t.status === status)
      && (!needle || `${t.id} ${t.title} ${t.agency}`.toLowerCase().includes(needle)))
  }, [data, query, status])

  return (
    <section className="page">
      <PageHeader eyebrow={eyebrow} title={title} subtitle={subtitle} />
      <SectionCard title={`${visible.length} ticket${visible.length === 1 ? '' : 's'}`}>
        <TicketFilters query={query} onQueryChange={setQuery} status={status} onStatusChange={setStatus} statuses={statuses} />
        {error
          ? <p role="alert" className="form-error">Could not load tickets. <button type="button" className="inline-link" onClick={reload}>Try again</button></p>
          : <TicketList tickets={visible} isLoading={isLoading} showAgency={showAgency} onSelect={setSelected} emptyTitle="No matching tickets" emptyText={emptyText ?? 'Try a different search or filter.'} />}
      </SectionCard>
      <TicketDetailModal ticket={selected} onClose={() => setSelected(null)} />
    </section>
  )
}
