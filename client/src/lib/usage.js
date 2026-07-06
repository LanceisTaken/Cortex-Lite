import { api } from './api'

export async function getUsage({ signal } = {}) {
  const { data } = await api.get('/api/usage', { signal })
  return data.data
}

export async function startCheckout() {
  const { data } = await api.post('/api/checkout')
  return data.url
}
