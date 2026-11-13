import { Link, useNavigate } from 'react-router-dom'
import { ROLE_HOME } from '../../../auth/roles'
import { useAuth } from '../../../auth/useAuth'
import { Button } from '../../../components/ui'

export function UnauthorizedPage() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const home = ROLE_HOME[user.role]
  // Roles without a workspace (team lead, QA) have no home to go back to.
  const hasHome = home && home !== '/unauthorized'

  async function handleSignOut() {
    try {
      await signOut()
    } finally {
      navigate('/login', { replace: true })
    }
  }

  return (
    <main className="status-page">
      <section className="status-card">
        <p className="eyebrow">Access restricted</p>
        <h1>You don't have access</h1>
        <p className="page-copy">
          {hasHome
            ? 'Your account is not permitted to view this page.'
            : 'Your role does not have a workspace in this version of ClientHub. Ask an administrator to update your account.'}
        </p>
        {hasHome
          ? <Link className="ui-button ui-button-primary" to={home}>Back to your home</Link>
          : <Button type="button" onClick={handleSignOut}>Sign out</Button>}
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
        <Link className="ui-button ui-button-primary" to="/">Go to home</Link>
      </section>
    </main>
  )
}
