import { EmptyState, PageHeader } from '../../../components/ui'
import { TicketList } from '../components/TicketItem'
import { useWorkspace } from '../useWorkspace'

const GROUPS = [
  ['changes', 'Changes requested', 'The client asked for changes. Start rework when you pick it up.'],
  ['blocked', 'Blocked', 'You marked these blocked. Unblock them when you can carry on.'],
  ['waiting', 'Waiting on the client', 'You asked the client something. They reply on the ticket.'],
  ['review', 'In review', 'Sent for approval. The client approves or asks for changes.'],
]

// /my-work/requests: every ticket waiting on a person, me or the client, with the action that moves it on.
export function RequestsPage() {
  const { workspace } = useWorkspace()
  const total = GROUPS.reduce((sum, [key]) => sum + workspace.requests[key].length, 0)

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="Requests and inputs" subtitle="Tickets waiting on you or on the client, with their reasons." />
      {total === 0 && <EmptyState title="Nothing is waiting">No changes requested, nothing blocked and no questions open.</EmptyState>}
      {GROUPS.map(([key, title, description]) => workspace.requests[key].length > 0 && (
        <section key={key} className="ew-section" aria-label={title}>
          <header className="ew-section-head">
            <h2>{title}<span className="ew-count">{workspace.requests[key].length}</span></h2>
            <p className="ew-muted">{description}</p>
          </header>
          <TicketList tickets={workspace.requests[key]} label={title} />
        </section>
      ))}
    </section>
  )
}
