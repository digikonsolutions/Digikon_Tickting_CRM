import { Link } from 'react-router-dom'

export function AuthLayout({ eyebrow, title, description, children, footer }) {
  return (
    <main className="auth-page">
      <section className="auth-shell" aria-label="ClientHub account access">
        <aside className="auth-showcase">
          <Link className="brand brand-light" to="/login"><span className="brand-mark" aria-hidden="true">C</span>ClientHub</Link>
          <div className="showcase-copy">
            <p className="showcase-kicker">ONE PLACE FOR THE WORK THAT MATTERS</p>
            <h2>Make every handoff feel effortless.</h2>
            <p>Give your team a calmer way to handle client requests, keep work visible, and move every ticket forward.</p>
          </div>
          <div className="showcase-card" aria-hidden="true">
            <span className="showcase-card-label">WORKSPACE PULSE</span>
            <div className="pulse-row"><span className="pulse-dot" /> Everything in its right place</div>
            <div className="pulse-bars"><i /><i /><i /><i /><i /><i /><i /><i /><i /><i /><i /><i /></div>
            <div className="pulse-footer"><span>Clear ownership</span><span>Less follow-up</span></div>
          </div>
          <p className="showcase-caption">A shared workspace for your whole team.</p>
        </aside>
        <div className="auth-main">
          <section className="auth-card">
            <Link className="brand brand-mobile" to="/login"><span className="brand-mark" aria-hidden="true">C</span>ClientHub</Link>
            <div className="auth-heading">
              <span className="auth-eyebrow"><span className="status-dot" /> {eyebrow ?? 'SECURE WORKSPACE'}</span>
              <h1>{title}</h1>
              <p>{description}</p>
            </div>
            {children}
            {footer && <p className="auth-footer">{footer}</p>}
            <p className="auth-privacy"><span aria-hidden="true">●</span> Your workspace is private and protected</p>
          </section>
          <p className="auth-copyright">© 2026 ClientHub <span>·</span> Built for better teamwork</p>
        </div>
      </section>
    </main>
  )
}
