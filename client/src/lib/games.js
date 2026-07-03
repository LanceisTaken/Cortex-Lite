import { api } from './api'

export async function listGames({ status, metadataStatus, search, sort, page, signal } = {}) {
  const { data } = await api.get('/api/games', {
    params: {
      status: status || undefined,
      metadata_status: metadataStatus || undefined,
      search: search || undefined,
      sort: sort || undefined,
      page: page || undefined,
    },
    signal,
  })
  return data
}

export async function createGame(payload) {
  const { data } = await api.post('/api/games', payload)
  return data
}

export async function updateGame(id, payload) {
  const { data } = await api.put(`/api/games/${id}`, payload)
  return data
}

export async function deleteGame(id) {
  await api.delete(`/api/games/${id}`)
}
