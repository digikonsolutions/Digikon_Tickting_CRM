import { useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { ROLE_HOME, ROLE_LABELS, ROLES } from './roles'
import { ApiErrorMessage } from '../components/auth/ApiErrorMessage'

export function ProtectedRoute() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  const location = useLocation()
  if (isLoading) return <p role="status">Checking your session…</p>
  if (authError) return <p role="alert">{authError} <button type="button" onClick={refreshSession}>Retry</button></p>
  return user ? <Outlet /> : <Navigate to="/login" replace state={{ from: location.pathname }} />
}

export function PublicOnlyRoute() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  if (isLoading) return <p role="status">Checking your session…</p>
  if (authError) return <p role="alert">{authError} <button type="button" onClick={refreshSession}>Retry</button></p>
  return user ? <Navigate to={ROLE_HOME[user.role]} replace /> : <Outlet />
}

export function HomeRedirect() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  if (isLoading) return <p role="status">Checking your session…</p>
  if (authError) return <p role="alert">{authError} <button type="button" onClick={refreshSession}>Retry</button></p>
  return <Navigate to={user ? ROLE_HOME[user.role] : '/login'} replace />
}

export function RoleRoute({ allowedRoles }) {
  const { user } = useAuth()
  return allowedRoles.includes(user?.role) ? <Outlet /> : <Navigate to="/unauthorized" replace />
}

const SIDEBAR_ITEMS = Object.freeze({
  [ROLES.ADMIN]: [
    { to: '/admin/dashboard', label: 'Dashboard' },
    { to: '/admin/users/new', label: 'Create User' },
    { to: '/admin/settings', label: 'Settings' },
  ],
  [ROLES.TEAM_LEAD]: [
    { to: '/team/queue', label: 'Team Queue' },
    { to: '/my-tickets', label: 'My Tickets' },
  ],
  [ROLES.EMPLOYEE]: [{ to: '/my-tickets', label: 'My Tickets' }],
  [ROLES.QA]: [{ to: '/qa/queue', label: 'QA Queue' }],
  [ROLES.AGENCY_OWNER]: [{ to: '/agency/dashboard', label: 'Dashboard' }],
  [ROLES.AGENCY_MEMBER]: [{ to: '/agency/dashboard', label: 'Dashboard' }],
})

export function AppLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const [signOutError, setSignOutError] = useState(null)
  const [isSigningOut, setIsSigningOut] = useState(false)

  async function handleSignOut() {
    setSignOutError(null)
    setIsSigningOut(true)
    let error = null
    try {
      await signOut()
    } catch (reason) {
      error = reason
      setSignOutError(reason)
    } finally {
      setIsSigningOut(false)
      navigate('/login', {
        replace: true,
        state: error ? { message: error.message } : undefined,
      })
    }
  }

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <NavLink className="brand" to={ROLE_HOME[user.role]}>ClientHub</NavLink>
        <nav aria-label="Dashboard">
          {(SIDEBAR_ITEMS[user.role] ?? []).map((item) => (
            <NavLink key={item.to} to={item.to}>{item.label}</NavLink>
          ))}
        </nav>
      </aside>
      <div className="app-main">
        <header className="dashboard-header">
          <div className="user-summary">
            <strong>{user.name}</strong>
            <span>{ROLE_LABELS[user.role]}</span>
            {user.agency_name && <span>{user.agency_name}</span>}
          </div>
          <button className="text-button logout-button" type="button" onClick={handleSignOut} disabled={isSigningOut}>
            {isSigningOut ? 'Signing out…' : 'Logout'}
          </button>
        </header>
        <main className="dashboard-content">
          <ApiErrorMessage error={signOutError} />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
