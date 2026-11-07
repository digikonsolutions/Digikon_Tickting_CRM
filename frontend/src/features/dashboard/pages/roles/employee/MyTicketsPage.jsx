import { TicketsView } from '../../TicketsView'

export const MyTicketsPage = () => (
  <TicketsView
    eyebrow="My work"
    title="My Tickets"
    subtitle="Everything currently assigned to you."
    scope="mine"
    emptyText="You have no tickets assigned."
  />
)
