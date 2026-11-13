import { Link } from 'react-router-dom'
import { DEFAULT_CAPACITY } from '../../../lib/constants'

export function AuthLayout({ eyebrow, title, description, children, footer }) {
  return (
    <main className="auth-page">
      <div className="auth-shell" aria-label="ClientHub account access">
        <aside className="auth-showcase">
          <Link className="brand" to="/login">
            <span className="brand-mark" aria-hidden="true">C</span>
            <span className="brand-name">ClientHub</span>
          </Link>

          <div className="showcase-content">
            <h2 className="showcase-title">Every request, in the right hands.</h2>
            <p className="showcase-subtitle">
              Plan capacity, manage client requests, and deliver tickets without the friction.
            </p>

            <div className="auth-preview-card" aria-hidden="true">
              <div className="preview-card-header">
                <span className="preview-card-title">This week</span>
                <span className="preview-card-hours">
                  <strong>24</strong> / {DEFAULT_CAPACITY.weekly_hours} h
                </span>
              </div>
              <div className="preview-progress-track">
                <div className="preview-progress-fill" style={{ width: '67%' }} />
              </div>
              <div className="preview-ticket-list">
                <div className="preview-ticket-row">
                  <span className="preview-ticket-name">Client portal onboarding</span>
                  <span className="preview-badge preview-badge-in-progress">In progress</span>
                </div>
                <div className="preview-ticket-row">
                  <span className="preview-ticket-name">Stripe billing webhooks</span>
                  <span className="preview-badge preview-badge-in-review">In review</span>
                </div>
                <div className="preview-ticket-row">
                  <span className="preview-ticket-name">SSO authentication flow</span>
                  <span className="preview-badge preview-badge-done">Done</span>
                </div>
              </div>
            </div>
          </div>

          <p className="showcase-caption">A calm, shared workspace for your entire team.</p>
        </aside>

        <div className="auth-main">
          <section className="auth-card">
            <Link className="brand brand-mobile" to="/login">
              <span className="brand-mark" aria-hidden="true">C</span>
              <span className="brand-name">ClientHub</span>
            </Link>

            <div className="auth-heading">
              {eyebrow && (
                <span className="auth-eyebrow">
                  <span className="auth-status-dot" aria-hidden="true" />
                  {eyebrow}
                </span>
              )}
              <h1>{title}</h1>
              {description && <p>{description}</p>}
            </div>

            {children}

            {footer && <p className="auth-footer">{footer}</p>}

            <div className="auth-form-footer">
              <p className="auth-privacy">
                <svg
                  className="auth-lock-icon"
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                  <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                Your workspace is private and protected
              </p>
              <p className="auth-copyright">
                © 2026 ClientHub <span aria-hidden="true">·</span> Built for better teamwork
              </p>
            </div>
          </section>
        </div>
      </div>
    </main>
  )
}
