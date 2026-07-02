import { createContext, useContext } from 'react'

export const PlaySessionContext = createContext(null)

export function usePlaySession() {
  const ctx = useContext(PlaySessionContext)
  if (!ctx) throw new Error('usePlaySession must be used inside <PlaySessionProvider>')
  return ctx
}
