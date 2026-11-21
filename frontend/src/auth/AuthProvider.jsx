import { createContext, useCallback, useEffect, useMemo, useState } from 'react'
import { authService, isKnownRole } from './authService'

const AuthContext = createContext(null)

function sessionUser(user) {
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    agency_id: user.agency_id ?? null,
    agency_name: user.agency_name ?? null,
    photo_url: user.photo_url ?? null,
    branding: user.branding ?? null,
    monitoring_consent_required: Boolean(user.monitoring_consent_required),
  }
}

async function fetchSession() {
  if (authService.isMockMode) return { user: null, error: '' }

  try {
    const response = await authService.getCurrentUser()
    const user = response?.user ?? response
    if (!isKnownRole(user?.role)) throw new Error('Your account has no valid role assigned.')
    return { user: sessionUser(user), error: '' }
  } catch (error) {
    return { user: null, error: error.status === 401 ? '' : error.message }
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [authError, setAuthError] = useState('')

  const refreshSession = useCallback(async () => {
    setIsLoading(true)
    setAuthError('')
    const session = await fetchSession()
    setUser(session.user)
    setAuthError(session.error)
    setIsLoading(false)
  }, [])

  const updateUser = useCallback((updates) => {
    setUser((current) => current ? { ...current, ...updates } : current)
  }, [])

  const updateBranding = useCallback((branding) => {
    setUser((current) => current ? { ...current, branding: branding ?? null } : current)
  }, [])

  useEffect(() => {
    let active = true
    void fetchSession().then((session) => {
      if (!active) return
      setUser(session.user)
      setAuthError(session.error)
      setIsLoading(false)
    })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    function clearSession() {
      setUser(null)
      setAuthError('')
      setIsLoading(false)
    }

    window.addEventListener('clienthub:unauthorized', clearSession)
    return () => window.removeEventListener('clienthub:unauthorized', clearSession)
  }, [])

  const establishSession = (nextUser) => {
    if (!isKnownRole(nextUser?.role)) throw new Error('Your account has no valid role assigned.')
    setUser(sessionUser(nextUser))
    setAuthError('')
  }

  const value = useMemo(() => ({
    user,
    isLoading,
    authError,
    refreshSession,
    updateUser,
    updateBranding,
    signIn: async (credentials) => {
      const response = await authService.signIn(credentials)
      const nextUser = response?.user ?? response
      establishSession(nextUser)
      return nextUser
    },
    signOut: async () => {
      try {
        await authService.signOut()
      } finally {
        setUser(null)
        setAuthError('')
      }
    },
  }), [user, isLoading, authError, refreshSession, updateUser, updateBranding])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export { AuthContext }
