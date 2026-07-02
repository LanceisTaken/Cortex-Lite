import { useCallback, useEffect, useMemo, useState } from 'react'
import { endSession, getActiveSession, startSession } from '../lib/playSessions'
import { useAuth } from './AuthContext'
import { PlaySessionContext } from './playSessionContextValue'

export function PlaySessionProvider({ children }) {
  const { user } = useAuth()
  const [active, setActive] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [nowTick, setNowTick] = useState(() => Date.now())

  const refresh = useCallback(async (signal) => {
    if (!user) {
      setActive(null)
      setLoading(false)
      return null
    }

    setLoading(true)
    setError(null)
    try {
      const session = await getActiveSession({ signal })
      setActive(session)
      return session
    } catch (err) {
      if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return null
      setError('Could not load your active session.')
      return null
    } finally {
      setLoading(false)
    }
  }, [user])

  useEffect(() => {
    const controller = new AbortController()
    refresh(controller.signal)
    return () => controller.abort()
  }, [refresh])

  useEffect(() => {
    if (!active) return undefined
    const timer = window.setInterval(() => setNowTick(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [active])

  const start = useCallback(async (gameId) => {
    setError(null)
    const session = await startSession(gameId)
    setActive(session)
    return session
  }, [])

  const end = useCallback(async () => {
    if (!active) return null
    setError(null)
    const ended = await endSession(active.id)
    setActive(null)
    return ended
  }, [active])

  const elapsedSeconds = useMemo(() => {
    if (!active?.started_at) return 0
    return Math.max(0, Math.floor((nowTick - new Date(active.started_at).getTime()) / 1000))
  }, [active, nowTick])

  return (
    <PlaySessionContext.Provider value={{ active, loading, error, elapsedSeconds, refresh, start, end }}>
      {children}
    </PlaySessionContext.Provider>
  )
}
