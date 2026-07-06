import { api } from './api'

export async function requestRecommendation(payload) {
  const { data } = await api.post('/api/recommend', payload)
  return data.data
}

export async function requestReverseDiff(payload) {
  const { data } = await api.post('/api/reverse', payload)
  return data.data
}

export function quotaError(error) {
  if (error.response?.status === 402 && error.response?.data?.error_code === 'quota_exceeded') {
    return error.response.data
  }
  return null
}
