import { PageHeader } from '../../components/ui'

export function PlaceholderPage({ title }) {
  return (
    <section className="page">
      <PageHeader eyebrow="Coming soon" title={title} subtitle="This page is coming soon." />
    </section>
  )
}
