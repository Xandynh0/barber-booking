import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { login as apiLogin, logout as apiLogout, me as apiMe } from '../api/auth'
import { ApiError } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  // 'checking' | 'authenticated' | 'guest'
  const [status, setStatus] = useState('checking')
  const [sessionExpired, setSessionExpired] = useState(false)

  useEffect(() => {
    let cancelled = false

    apiMe()
      .then((body) => {
        if (cancelled) return
        setUser(body.data)
        setStatus('authenticated')
      })
      .catch((error) => {
        if (cancelled) return
        setUser(null)
        setStatus('guest')
        if (error instanceof ApiError && error.status === 419) {
          setSessionExpired(true)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  const login = useCallback(async (credentials) => {
    const body = await apiLogin(credentials)
    setUser(body.data)
    setStatus('authenticated')
    setSessionExpired(false)
  }, [])

  const logout = useCallback(async () => {
    // Best-effort: clear local state regardless of the server response — an
    // already expired/invalid session shouldn't trap the user on the admin
    // screen, and there is no retry loop here.
    try {
      await apiLogout()
    } catch {
      // Ignored on purpose — the client proceeds to the logged-out state
      // whether or not the server request succeeded.
    } finally {
      setUser(null)
      setStatus('guest')
    }
  }, [])

  return (
    <AuthContext.Provider value={{ user, status, sessionExpired, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }

  return context
}
