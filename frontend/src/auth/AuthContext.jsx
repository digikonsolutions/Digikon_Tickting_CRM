import { createContext, useCallback, useEffect, useMemo, useState } from 'react'
import { authService, isKnownRole } from './authService'

const AuthContext = createContext(null)

async function fetchSession() {
  try {
    const { user } = await authService.getCurrentUser()
    if (!isKnownRole(user?.role)) throw new Error('Your account has no valid role assigned.')
    return { user, error: '' }
  } catch (error) {
    return { user: null, error: error.status === 401 ? '' : error.message }
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    if (!authService.isMockMode) return null
    const saved = sessionStorage.getItem('client-session')
    if (!saved) return null
    try {
      const candidate = JSON.parse(saved)
      return isKnownRole(candidate.role) ? candidate : null
    } catch {
      return null
    }
  })
  const [isLoading, setIsLoading] = useState(!authService.isMockMode)
  const [authError, setAuthError] = useState('')

  const refreshSession = useCallback(async () => {
    setIsLoading(true)
    setAuthError('')
    const session = await fetchSession()
    setUser(session.user)
    setAuthError(session.error)
    setIsLoading(false)
  }, [])

  useEffect(() => {
    if (authService.isMockMode) return undefined

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

  const establishSession = (nextUser) => {
    if (!isKnownRole(nextUser?.role)) throw new Error('Your account has no valid role assigned.')
    setUser(nextUser)
    setAuthError('')
    if (authService.isMockMode) sessionStorage.setItem('client-session', JSON.stringify(nextUser))
  }

  const value = useMemo(() => ({
    user,
    isLoading,
    authError,
    refreshSession,
    signIn: async (credentials) => {
      setIsLoading(true)
      try { establishSession((await authService.signIn(credentials)).user) } finally { setIsLoading(false) }
    },
    signOut: async () => {
      await authService.signOut()
      setUser(null)
      if (authService.isMockMode) sessionStorage.removeItem('client-session')
    },
  }), [user, isLoading, authError, refreshSession])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export { AuthContext }
