import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { api } from '../lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get('/api/me')
      setUser(data)
    } catch (err) {
      const status = err?.response?.status
      if (status === 401 || status === 419 || status === 409) {
        // 409 = unverified; still known-user, but /me won't return until verified.
        // Fall back to no user for now; verification page handles the case.
        setUser(null)
      } else {
        setUser(null)
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  const login = useCallback(async (email, password) => {
    const { data } = await api.post('/api/login', { email, password })
    setUser(data)
    return data
  }, [])

  const register = useCallback(async (payload) => {
    const { data } = await api.post('/api/register', payload)
    setUser(data)
    return data
  }, [])

  const logout = useCallback(async () => {
    await api.post('/api/logout')
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
