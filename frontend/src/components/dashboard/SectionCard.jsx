export function SectionCard({ title, description, action, children, className = '' }) {
  return (
    <section className={`section-card ${className}`.trim()}>
      <header className="section-card-header">
        <div><h2>{title}</h2>{description && <p>{description}</p>}</div>
        {action}
      </header>
      {children}
    </section>
  )
}
