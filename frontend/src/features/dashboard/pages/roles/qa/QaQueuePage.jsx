import { ProjectsView } from '../../ProjectsView'

export const QaQueuePage = () => (
  <ProjectsView
    eyebrow="Quality assurance"
    title="QA Queue"
    subtitle="Review finished work before it goes back to the agency."
    scope="qa"
    statuses={['QA Review']}
    emptyText="Nothing waiting for review."
  />
)
