import { ProjectsView } from '../../ProjectsView'

export const MyTicketsPage = () => (
  <ProjectsView
    eyebrow="My work"
    title="My Tickets"
    subtitle="Everything currently assigned to you."
    scope="mine"
    emptyText="You have no tickets assigned."
  />
)
