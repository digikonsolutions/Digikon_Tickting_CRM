import { useState } from 'react'
import { Link, Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { ROLE_HOME, ROLE_LABELS, ROLES } from './roles'
import { ApiErrorMessage } from '../features/auth/components/ApiErrorMessage'
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
    { to: '/admin/dashboard', label: 'Dashboard', icon: 'dashboard', section: 'Work' },
    { to: '/admin/projects', label: 'All projects', icon: 'briefcase', section: 'Work' },
    { to: '/admin/tickets', label: 'All tickets', icon: 'ticket', section: 'Work' },
    { to: '/admin/assignments', label: 'Assignments', icon: 'calendar', section: 'People' },
    { to: '/admin/employees', label: 'Employees', icon: 'user', section: 'People' },
    { to: '/admin/agency-owners', label: 'Agency owners', icon: 'building', section: 'People' },
    { to: '/admin/agency-clients', label: 'Agency clients', icon: 'users', section: 'People' },
    { to: '/admin/settings', label: 'Settings', icon: 'settings', section: 'Admin' },
  ],
  [ROLES.TEAM_LEAD]: [
    { to: '/team/queue', label: 'Team Queue', icon: 'queue' },
    { to: '/my-tickets', label: 'My Tickets', icon: 'ticket' },
  ],
  [ROLES.EMPLOYEE]: [{ to: '/my-work', label: 'My work', icon: 'ticket' }],
  [ROLES.QA]: [{ to: '/qa/queue', label: 'QA Queue', icon: 'check' }],
  [ROLES.AGENCY_OWNER]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/projects', label: 'Projects', icon: 'ticket' },
    { to: '/agency/employees', label: 'Employees held', icon: 'users' },
    { to: '/agency/users', label: 'Agency accounts', icon: 'users' },
  ],
  [ROLES.AGENCY_MEMBER]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
  [ROLES.AGENCY_CLIENT]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/my-projects', label: 'Agency projects', icon: 'ticket' },
    { to: '/agency/employees', label: 'Employees held', icon: 'users' },
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
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem('clienthub:sidebar-collapsed') === 'true'
    } catch {
      return false
    }
  })
  const visibleItems = [
    ...(SIDEBAR_ITEMS[user.role] ?? []),
  ]
  const sidebarGroups = visibleItems.reduce((groups, item) => {
    const section = item.section ?? 'Work'
    const group = groups.find((candidate) => candidate.label === section)
    if (group) group.items.push(item)
    else groups.push({ label: section, items: [item] })
    return groups
  }, [])
  const pageTitle = visibleItems.find((item) => location.pathname === item.to)?.label
    ?? (location.pathname.startsWith('/admin/projects/')
      ? 'Project details'
      : ({
      '/profile': 'My profile',
      '/admin/users': 'Employees',
      '/admin/employees': 'Employees',
      '/admin/employees/new': 'Create Employee',
      '/admin/agency-owners': 'Agency owners',
      '/admin/agency-owners/new': 'Create Agency Owner',
      '/admin/agency-clients': 'Agency clients',
      '/admin/settings': 'Settings',
      '/admin/dashboard': 'Dashboard',
      '/admin/projects': 'All projects',
      '/admin/tickets': 'All tickets',
      '/admin/assignments': 'Assignments',
      '/agency/dashboard': 'Dashboard',
      '/agency/projects': 'Projects',
      '/agency/my-projects': 'Agency projects',
      '/agency/employees': 'Employees',
      '/agency/users': 'Agency accounts',
      '/agency/directory': 'Agency directory',
    }[location.pathname] ?? 'Workspace'))

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

  function toggleSidebar() {
    const next = !isSidebarCollapsed
    setIsSidebarCollapsed(next)
    try {
      window.localStorage.setItem('clienthub:sidebar-collapsed', String(next))
    } catch {
      return
    }
  }

  const userInitial = user.name?.trim().charAt(0).toUpperCase() || 'U'
  const showDashboardAction = user.role === ROLES.ADMIN && location.pathname === '/admin/dashboard'

  return (
    <div className={`app-shell${isNavOpen ? ' app-nav-open' : ''}${isSidebarCollapsed ? ' sidebar-collapsed' : ''}`}>
      {isNavOpen && <button className="sidebar-backdrop" type="button" aria-label="Close navigation" onClick={() => setIsNavOpen(false)} />}
      <aside className="sidebar" id="primary-navigation">
        <div className="sidebar-brand-row">
          <NavLink className="brand" to={ROLE_HOME[user.role]} aria-label="ClientHub home">
            <span className="brand-mark">C</span><span className="sidebar-brand-name">ClientHub</span>
          </NavLink>
          <button
            className="sidebar-collapse-button"
            type="button"
            aria-label={isSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-pressed={isSidebarCollapsed}
            onClick={toggleSidebar}
          >
            <Icon name={isSidebarCollapsed ? 'expand' : 'collapse'} size={18} />
          </button>
        </div>
        <nav aria-label="Main navigation">
          {sidebarGroups.map((group) => (
            <div className="sidebar-nav-group" key={group.label}>
              <p className="sidebar-group-label">{group.label}</p>
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  aria-label={item.label}
                  title={item.label}
                  onClick={() => setIsNavOpen(false)}
                >
                  <Icon name={item.icon} size={18} /><span>{item.label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
        <div className="sidebar-user">
          <Link className="sidebar-user-profile" to="/profile" title="My profile" onClick={() => setIsNavOpen(false)}>
            <span className="sidebar-user-avatar" aria-hidden="true">{userInitial}</span>
            <span className="sidebar-user-details">
              <strong>{user.name}</strong>
              <Badge variant={user.role === ROLES.ADMIN ? 'primary' : 'slate'}>{ROLE_LABELS[user.role]}</Badge>
            </span>
            <span className="sr-only">My profile</span>
          </Link>
          <button
            className="sidebar-logout-button"
            type="button"
            aria-label={isSigningOut ? 'Signing out' : 'Logout'}
            title="Logout"
            onClick={handleSignOut}
            disabled={isSigningOut}
          >
            <Icon name="logout" size={18} />
          </button>
        </div>
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
          <div className="dashboard-header-tools">
            {showDashboardAction && (
              <Link className="ui-button ui-button-primary header-primary-action" to="/admin/employees/new">
                <Icon name="plus" size={17} />
                Create employee
              </Link>
            )}
            <details className="header-user-menu">
              <summary aria-label="Open user menu" title="User menu">
                <span className="header-menu-avatar" aria-hidden="true">{userInitial}</span>
              </summary>
              <div className="header-user-menu-popover">
                <strong>{user.name}</strong>
                <span>{ROLE_LABELS[user.role]}</span>
                <button type="button" onClick={handleSignOut} disabled={isSigningOut}>
                  <Icon name="logout" size={17} />
                  {isSigningOut ? 'Signing out…' : 'Logout'}
                </button>
              </div>
            </details>
          </div>
        </header>
        <main className="dashboard-content">
          <ApiErrorMessage error={signOutError} />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
