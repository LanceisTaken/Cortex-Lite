import { api } from './api'

export async function getActiveSession({ signal } = {}) {
  const { data } = await api.get('/api/sessions/active', { signal })
  return data.data
}

export async function listHistory({ page, signal } = {}) {
  const { data } = await api.get('/api/sessions', {
    params: { page: page || undefined },
    signal,
  })
  return data
}

export async function startSession(gameId) {
  const { data } = await api.post('/api/sessions/start', { game_id: gameId })
  return data
}

export async function endSession(sessionId) {
  const { data } = await api.post(`/api/sessions/${sessionId}/end`)
  return data
}
