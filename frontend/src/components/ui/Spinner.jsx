export function Spinner({ size = 'md', label = 'Loading' }) {
  return <span className={`ui-spinner ui-spinner-${size}`} role="status" aria-label={label} />
}

export function Loader({ label = 'Loading your workspace…' }) {
  return (
    <main className="ui-loader-page">
      <Spinner size="lg" label={label} />
      <p>{label}</p>
    </main>
  )
}
