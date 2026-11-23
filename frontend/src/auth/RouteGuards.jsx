import { useEffect, useState } from 'react'
import { Link, Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { BrandMark } from '../branding/BrandMark'
import { useBranding } from '../branding/useBranding'
import { isAgencyRole, postLoginPath, ROLE_HOME, ROLE_LABELS, ROLES } from './roles'
import { getStoredPortal, portalPath } from './portal'
import { ApiErrorMessage } from '../features/auth/components/ApiErrorMessage'
import { Badge, Button, Icon, Loader } from '../components/ui'
import { NotificationBell } from '../features/notifications'
import { TimerPill } from '../features/time'
import { MonitoringConsent, useHeartbeat } from '../features/activity'
import { AssistantWidget } from '../features/assistant'
import { useUrgentCounts } from '../features/admin/pages/useUrgentCounts'
import { useOpenRushCount } from '../features/queue/useOpenRushCount'

export function ProtectedRoute() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  const location = useLocation()
  if (isLoading) return <Loader label="Checking your session…" />
  if (authError) return <div className="ui-loader-page" role="alert"><p>{authError}</p><Button type="button" variant="secondary" onClick={refreshSession}>Retry</Button></div>
  // Agency users who were signed in go back to their own portal, not the platform login.
  if (!user) return <Navigate to={portalPath(getStoredPortal())} replace state={{ from: `${location.pathname}${location.search}` }} />
  // Employees see nothing else until they accept the monitoring notice.
  if (user.role === ROLES.EMPLOYEE && user.monitoring_consent_required) return <MonitoringConsent />
  return <Outlet />
}

export function PublicOnlyRoute() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  const location = useLocation()
  if (isLoading) return <Loader label="Checking your session…" />
  if (authError) return <div className="ui-loader-page" role="alert"><p>{authError}</p><Button type="button" variant="secondary" onClick={refreshSession}>Retry</Button></div>
  return user ? <Navigate to={postLoginPath(user, location.state?.from)} replace /> : <Outlet />
}

export function HomeRedirect() {
  const { user, isLoading, authError, refreshSession } = useAuth()
  if (isLoading) return <Loader label="Checking your session…" />
  if (authError) return <div className="ui-loader-page" role="alert"><p>{authError}</p><Button type="button" variant="secondary" onClick={refreshSession}>Retry</Button></div>
  return <Navigate to={user ? ROLE_HOME[user.role] : portalPath(getStoredPortal())} replace />
}

export function RoleRoute({ allowedRoles }) {
  const { user } = useAuth()
  // Agency users who open a page that is not theirs land on their dashboard; everyone else sees the unauthorized page.
  return allowedRoles.includes(user?.role) ? <Outlet /> : <Navigate to={isAgencyRole(user?.role) ? '/agency/dashboard' : '/unauthorized'} replace />
}

// One navigation for the agency workspace. Each item lists the roles that may see it, so the owner and the client
// share the same sections, order, icons and labels and only the visible items differ. Hidden items are also blocked
// at the route level (see AppRoutes) and by the API. `also` keeps the item highlighted on pages that belong to it.
const AGENCY_NAV = Object.freeze([
  { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard', section: 'Workspace', roles: [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT] },
  { to: '/agency/clients', label: 'Clients', icon: 'user', section: 'Workspace', roles: [ROLES.AGENCY_OWNER] },
  { to: '/agency/projects', label: 'Projects', icon: 'briefcase', section: 'Workspace', roles: [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT] },
  { to: '/agency/tickets', label: 'Tickets', icon: 'ticket', section: 'Workspace', roles: [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT], also: ['/tickets/'] },
  { to: '/agency/employees', label: 'Employees', icon: 'users', section: 'Manage', roles: [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT], badgeKey: 'rush' },
  { to: '/agency/reports', label: 'Reports', icon: 'chart', section: 'Manage', roles: [ROLES.AGENCY_OWNER, ROLES.AGENCY_CLIENT] },
  { to: '/agency/directory', label: 'Agency directory', icon: 'users', section: 'Manage', roles: [ROLES.AGENCY_CLIENT] },
  { to: '/agency/time', label: 'Time summary', icon: 'clock', section: 'Manage', roles: [ROLES.AGENCY_OWNER] },
  { to: '/agency/branding', label: 'Branding', icon: 'palette', section: 'Manage', roles: [ROLES.AGENCY_OWNER] },
])
const agencyNav = (role) => AGENCY_NAV.filter((item) => item.roles.includes(role)).map(({ roles: _roles, ...item }) => item)
const SIDEBAR_ITEMS = Object.freeze({
  [ROLES.ADMIN]: [
    { to: '/admin/dashboard', label: 'Dashboard', icon: 'dashboard', section: 'Work' },
    { to: '/admin/projects', label: 'All projects', icon: 'briefcase', section: 'Work' },
    { to: '/admin/tickets', label: 'All tickets', icon: 'ticket', section: 'Work' },
    { to: '/admin/reports', label: 'Reports', icon: 'chart', section: 'Work' },
    { to: '/admin/time', label: 'Time report', icon: 'clock', section: 'Work' },
    { to: '/admin/plans', label: 'Plans', icon: 'file', section: 'Work' },
    { to: '/admin/history', label: 'Browser history', icon: 'search', section: 'People' },
    { to: '/admin/assignments', label: 'Assignments', icon: 'calendar', section: 'People' },
    { to: '/admin/rush-requests', label: 'Urgent requests', icon: 'alert', section: 'Work', badgeKey: 'urgent' },
    { to: '/admin/employees', label: 'Employees', icon: 'user', section: 'People' },
    { to: '/admin/agencies', label: 'Agencies', icon: 'building', section: 'People' },
    { to: '/admin/agency-owners', label: 'Agency owners', icon: 'user', section: 'People' },
    { to: '/admin/agency-clients', label: 'Agency clients', icon: 'users', section: 'People' },
  ],
  [ROLES.TEAM_LEAD]: [{ to: '/profile', label: 'My profile', icon: 'user' }],
  [ROLES.QA]: [{ to: '/profile', label: 'My profile', icon: 'user' }],
  [ROLES.EMPLOYEE]: [{ to: '/my-work', label: 'My work', icon: 'ticket' }],
  [ROLES.AGENCY_OWNER]: agencyNav(ROLES.AGENCY_OWNER),
  [ROLES.AGENCY_MEMBER]: [
    { to: '/agency/dashboard', label: 'Dashboard', icon: 'dashboard' },
    { to: '/agency/directory', label: 'Agency directory', icon: 'users' },
  ],
  [ROLES.AGENCY_CLIENT]: agencyNav(ROLES.AGENCY_CLIENT),
})

// Detail pages show "Parent / Page" in the header; the parent is a link back to the list.
const BREADCRUMB_PARENTS = [
  [/^\/agency\/projects\/\d+$/, { label: 'Projects', to: '/agency/projects' }],
  [/^\/agency\/clients\/\d+$/, { label: 'Clients', to: '/agency/clients' }],
  [/^\/agency\/employees\/\d+\/activity$/, { label: 'Employees', to: '/agency/employees' }],
  [/^\/admin\/projects\/\d+$/, { label: 'All projects', to: '/admin/projects' }],
  [/^\/admin\/agencies\/\d+$/, { label: 'Agencies', to: '/admin/agencies' }],
  [/^\/admin\/employees\/\d+(\/activity)?$/, { label: 'Employees', to: '/admin/employees' }],
  [/^\/admin\/(employees|agency-owners)\/new$/, null],
]
function breadcrumbParent(pathname) {
  for (const [pattern, parent] of BREADCRUMB_PARENTS) if (pattern.test(pathname)) return parent
  return null
}

export function AppLayout() {
  const { user, signOut } = useAuth()
  const { displayName } = useBranding()
  const location = useLocation()
  const navigate = useNavigate()
  const [signOutError, setSignOutError] = useState(null)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [isNavOpen, setIsNavOpen] = useState(false)
  // Silent for employees only: no panel, no indicator. Everything it records is visible to admins alone.
  useHeartbeat(user.role === ROLES.EMPLOYEE)
  // Admins see how many urgent requests are waiting for a quote next to the menu entry.
  const urgentCounts = useUrgentCounts(user.role === ROLES.ADMIN)
  // Agency owners and clients see how many of their urgent requests are still open next to Employees.
  const openRush = useOpenRushCount(user.role === ROLES.AGENCY_OWNER || user.role === ROLES.AGENCY_CLIENT)
  const badges = { urgent: urgentCounts.pending, rush: openRush }
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem('ui:sidebar-collapsed') === 'true'
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
    ?? (location.pathname.startsWith('/tickets/')
      ? 'Ticket'
      : /^\/admin\/employees\/\d+$/.test(location.pathname)
      ? 'Employee'
      : /^\/admin\/agencies\/\d+$/.test(location.pathname)
      ? 'Agency'
      : /^\/agency\/clients\/\d+$/.test(location.pathname)
      ? 'Client'
      : /^\/agency\/employees\/\d+\/activity$/.test(location.pathname)
      ? 'Employee activity'
      : location.pathname.startsWith('/admin/projects/') || location.pathname.startsWith('/agency/projects/')
      ? 'Project details'
      : ({
      '/profile': 'My profile',
      '/notifications': 'Notifications',
      '/settings/notifications': 'Notification settings',
      '/admin/users': 'Employees',
      '/admin/employees': 'Employees',
      '/admin/employees/new': 'Create Employee',
      '/admin/agency-owners': 'Agency owners',
      '/admin/agency-owners/new': 'Create Agency Owner',
      '/admin/agency-clients': 'Agency clients',
      '/admin/dashboard': 'Dashboard',
      '/admin/projects': 'All projects',
      '/admin/tickets': 'All tickets',
      '/admin/reports': 'Reports',
      '/admin/assignments': 'Assignments',
      '/admin/rush-requests': 'Urgent requests',
      '/admin/time': 'Time report',
      '/agency/time': 'Time summary',
      '/agency/dashboard': 'Dashboard',
      '/agency/projects': 'Projects',
      '/agency/reports': 'Reports',
      '/agency/employees': 'Employees',
      '/agency/clients': 'Clients',
      '/agency/tickets': 'Tickets',
      '/agency/branding': 'Branding',
      '/agency/directory': 'Agency directory',
    }[location.pathname] ?? 'Workspace'))

  const breadcrumb = breadcrumbParent(location.pathname)

  // "Agencies · ClientHub" in the tab and in history; falls back to the plain name when the page has no title.
  useEffect(() => {
    const brand = displayName || 'ClientHub'
    document.title = pageTitle && pageTitle !== 'Workspace' ? `${pageTitle} · ${brand}` : brand
    return () => { document.title = 'ClientHub' }
  }, [pageTitle, displayName])

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
      navigate(isAgencyRole(user.role) ? portalPath(user.agency?.slug ?? getStoredPortal()) : '/login', {
        replace: true,
        state: error ? { message: error.message } : undefined,
      })
    }
  }

  // The mobile drawer behaves like a dialog: focus moves in and stays in, Escape closes it, the page behind does
  // not scroll, and focus returns to the Menu button when it closes.
  useEffect(() => {
    if (!isNavOpen) return undefined
    const sidebar = document.getElementById('primary-navigation')
    const toggle = document.querySelector('.mobile-nav-toggle')
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    sidebar?.querySelector('a[href], button:not([disabled])')?.focus()

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        setIsNavOpen(false)
        return
      }
      if (event.key !== 'Tab' || !sidebar) return
      const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled])')]
      if (focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (!sidebar.contains(document.activeElement)) {
        event.preventDefault()
        first.focus()
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
      toggle?.focus()
    }
  }, [isNavOpen])

  function toggleSidebar() {
    const next = !isSidebarCollapsed
    setIsSidebarCollapsed(next)
    try {
      window.localStorage.setItem('ui:sidebar-collapsed', String(next))
    } catch {
      return
    }
  }

  const userInitial = user.name?.trim().charAt(0).toUpperCase() || 'U'

  return (
    <div className={`app-shell${isNavOpen ? ' app-nav-open' : ''}${isSidebarCollapsed ? ' sidebar-collapsed' : ''}`}>
      {isNavOpen && <button className="sidebar-backdrop" type="button" aria-label="Close navigation" onClick={() => setIsNavOpen(false)} />}
      <aside className="sidebar" id="primary-navigation">
        <div className="sidebar-brand-row">
          <NavLink className="brand" to={ROLE_HOME[user.role]} aria-label={`${displayName || 'ClientHub'} home`}>
            <BrandMark collapsed={isSidebarCollapsed} />
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
                  className={({ isActive }) => (isActive || item.also?.some((prefix) => location.pathname.startsWith(prefix)) ? 'active' : undefined)}
                  aria-label={badges[item.badgeKey] ? `${item.label}, ${badges[item.badgeKey]} waiting` : item.label}
                  title={item.label}
                  onClick={() => setIsNavOpen(false)}
                >
                  <Icon name={item.icon} size={18} /><span>{item.label}</span>
                  {badges[item.badgeKey] > 0 && <Badge variant="warning" className="sidebar-badge">{badges[item.badgeKey]}</Badge>}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
        <div className="sidebar-user">
          <Link className="sidebar-user-profile" to="/profile" title="My profile" onClick={() => setIsNavOpen(false)}>
            {user.photo_url
              ? <img className="sidebar-user-avatar" src={user.photo_url} alt="" />
              : <span className="sidebar-user-avatar" aria-hidden="true">{userInitial}</span>}
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
            {/* Not a heading: every page renders its own <h1>, and two per page break the outline. */}
            {breadcrumb
              ? (
                <nav className="dashboard-breadcrumb" aria-label="Breadcrumb">
                  <Link to={breadcrumb.to}>{breadcrumb.label}</Link>
                  <span aria-hidden="true">/</span>
                  <span className="dashboard-page-title" aria-current="page">{pageTitle}</span>
                </nav>
              )
              : <p className="dashboard-page-title">{pageTitle}</p>}
          </div>
          <div className="dashboard-header-tools">
            {user.role === ROLES.EMPLOYEE && <TimerPill />}
            <NotificationBell />
            <details className="header-user-menu">
              <summary aria-label="Open user menu" title="User menu">
                {user.photo_url
                  ? <img className="header-menu-avatar" src={user.photo_url} alt="" />
                  : <span className="header-menu-avatar" aria-hidden="true">{userInitial}</span>}
              </summary>
              <div className="header-user-menu-popover">
                <strong>{user.name}</strong>
                <span>{ROLE_LABELS[user.role]}</span>
                <Link to="/profile">My profile</Link>
                <Link to="/notifications">Notifications</Link>
                <Link to="/settings/notifications">Notification settings</Link>
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
      <AssistantWidget />
    </div>
  )
}
