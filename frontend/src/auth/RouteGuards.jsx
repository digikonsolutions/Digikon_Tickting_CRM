import { useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { ROLE_LABELS } from './roles'
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
  return user ? <Navigate to="/dashboard" replace /> : <Outlet />
}

export function RoleRoute({ allowedRoles }) {
  return allowedRoles.includes(useAuth().user.role) ? <Outlet /> : <Navigate to="/dashboard" replace />
}

const items = [
  { to: '/dashboard', label: 'Overview' },
  { to: '/clients', label: 'Clients' },
  { to: '/team', label: 'Team', roles: ['admin', 'agency_owner'] },
  { to: '/reports', label: 'Reports', roles: ['admin', 'agency_owner'] },
  { to: '/settings', label: 'Settings', roles: ['admin'] },
]

export function AppLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const [signOutError, setSignOutError] = useState(null)
  const visibleItems = items.filter((item) => !item.roles || item.roles.includes(user.role))
  async function handleSignOut() {
    setSignOutError(null)
    try {
      await signOut()
      navigate('/login', { replace: true })
    } catch (error) {
      setSignOutError(error)
    }
  }
  return (
    <div className="app-shell">
      <aside className="sidebar">
        <NavLink className="brand" to="/dashboard">ClientHub</NavLink>
        <nav aria-label="Dashboard">{visibleItems.map((item) => <NavLink key={item.to} to={item.to}>{item.label}</NavLink>)}</nav>
        <div className="account-summary"><strong>{user.name}</strong><span>{ROLE_LABELS[user.role]}</span><button className="text-button" type="button" onClick={handleSignOut}>Sign out</button><ApiErrorMessage error={signOutError} /></div>
      </aside>
      <main className="dashboard-content"><Outlet /></main>
    </div>
  )
}
