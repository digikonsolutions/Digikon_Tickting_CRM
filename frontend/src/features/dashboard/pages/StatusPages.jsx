import { Link } from 'react-router-dom'
import { ROLE_HOME } from '../../../auth/roles'
import { useAuth } from '../../../auth/useAuth'

export function UnauthorizedPage() {
  const { user } = useAuth()

  return (
    <main className="status-page">
      <section className="status-card">
        <p className="eyebrow">Access restricted</p>
        <h1>You don't have access</h1>
        <p className="page-copy">Your account is not permitted to view this page.</p>
        <Link className="primary-button status-link" to={ROLE_HOME[user.role]}>Back to your home</Link>
      </section>
    </main>
  )
}

export function NotFoundPage() {
  return (
    <main className="status-page">
      <section className="status-card">
        <p className="eyebrow">404</p>
        <h1>Page not found</h1>
        <p className="page-copy">The page you’re looking for doesn’t exist.</p>
        <Link className="primary-button status-link" to="/">Go to home</Link>
      </section>
    </main>
  )
}
