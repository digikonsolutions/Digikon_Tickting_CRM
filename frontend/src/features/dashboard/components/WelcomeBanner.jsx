export function WelcomeBanner({ eyebrow, title, subtitle, children }) {
  return (
    <section className="welcome-banner">
      <div>
        {eyebrow && <p className="welcome-eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
      {children && <div className="welcome-actions">{children}</div>}
    </section>
  )
}
