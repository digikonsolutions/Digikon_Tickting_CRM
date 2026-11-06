import { useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { ROLE_HOME, ROLE_LABELS, ROLES } from './roles'
import { ApiErrorMessage } from '../components/auth/ApiErrorMessage'
import { Badge, Button, Icon } from '../components/ui'

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
    { to: '/admin/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/admin/employees', label: 'Employees', icon: 'users' },
    { to: '/admin/agency-owners', label: 'Agency owners', icon: 'users' },
    { to: '/admin/agency-clients', label: 'Agency clients', icon: 'users' },
    { to: '/admin/settings', label: 'Settings', icon: 'settings' },
  ],
  [ROLES.TEAM_LEAD]: [
    { to: '/team/queue', label: 'Team Queue', icon: 'queue' },
    { to: '/my-tickets', label: 'My Tickets', icon: 'ticket' },
  ],
  [ROLES.EMPLOYEE]: [{ to: '/my-tickets', label: 'My Tickets', icon: 'ticket' }],
  [ROLES.QA]: [{ to: '/qa/queue', label: 'QA Queue', icon: 'check' }],
  [ROLES.AGENCY_OWNER]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/users', label: 'Employees & clients', icon: 'users' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
  [ROLES.AGENCY_MEMBER]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
  [ROLES.AGENCY_EMPLOYEE]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
  [ROLES.AGENCY_CLIENT]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
})

export function AppLayout() {
  const { user, signOut } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [signOutError, setSignOutError] = useState(null)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [isNavOpen, setIsNavOpen] = useState(false)
  const visibleItems = SIDEBAR_ITEMS[user.role] ?? []
  const pageTitle = visibleItems.find((item) => location.pathname === item.to)?.label
    ?? ({
      '/admin/users': 'Employees',
      '/admin/employees': 'Employees',
      '/admin/employees/new': 'Create Employee',
      '/admin/agency-owners': 'Agency owners',
      '/admin/agency-owners/new': 'Create Agency Owner',
      '/admin/agency-clients': 'Agency clients',
      '/admin/settings': 'Settings',
      '/admin/dashboard': 'Dashboard',
      '/agency/dashboard': 'Dashboard',
      '/agency/users': 'Employees & clients',
      '/agency/directory': 'Agency directory',
    }[location.pathname] ?? 'Workspace')

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
    <div className={`app-shell${isNavOpen ? ' app-nav-open' : ''}`}>
      {isNavOpen && <button className="sidebar-backdrop" type="button" aria-label="Close navigation" onClick={() => setIsNavOpen(false)} />}
      <aside className="sidebar" id="primary-navigation">
        <NavLink className="brand" to={ROLE_HOME[user.role]}><span className="brand-mark">C</span>ClientHub</NavLink>
        <nav aria-label="Dashboard">
          {visibleItems.map((item) => (
            <NavLink key={item.to} to={item.to} onClick={() => setIsNavOpen(false)}><Icon name={item.icon} size={18} /><span>{item.label}</span></NavLink>
          ))}
        </nav>
      </aside>
      <div className="app-main">
        <header className="dashboard-header">
          <div className="dashboard-title-area">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="mobile-nav-toggle"
              aria-expanded={isNavOpen}
              aria-controls="primary-navigation"
              onClick={() => setIsNavOpen((open) => !open)}
            >
              {isNavOpen ? 'Close menu' : 'Menu'}
            </Button>
            <h1>{pageTitle}</h1>
          </div>
          <div className="user-summary">
            <strong>{user.name}</strong>
            <Badge variant={user.role === ROLES.ADMIN ? 'primary' : 'slate'}>{ROLE_LABELS[user.role]}</Badge>
            {user.agency_name && <span>{user.agency_name}</span>}
          </div>
          <Button type="button" variant="secondary" size="sm" onClick={handleSignOut} loading={isSigningOut} loadingText="Signing out…">
            Logout
          </Button>
        </header>
        <main className="dashboard-content">
          <ApiErrorMessage error={signOutError} />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
