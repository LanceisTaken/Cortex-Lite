import { api } from './api'

export async function searchGpus({ search, signal } = {}) {
  const { data } = await api.get('/api/hardware/gpus', {
    params: { search: search || undefined },
    signal,
  })
  return data
}

export async function searchCpus({ search, signal } = {}) {
  const { data } = await api.get('/api/hardware/cpus', {
    params: { search: search || undefined },
    signal,
  })
  return data
}
