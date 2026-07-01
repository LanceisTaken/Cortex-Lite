import axios from 'axios'

export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let csrfPromise = null

function hasXsrfCookie() {
  return document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))
}

export async function ensureCsrfCookie() {
  if (hasXsrfCookie()) return
  if (!csrfPromise) {
    csrfPromise = api.get('/sanctum/csrf-cookie').finally(() => {
      csrfPromise = null
    })
  }
  await csrfPromise
}

api.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase()
  if (method !== 'get' && method !== 'head' && method !== 'options') {
    await ensureCsrfCookie()
  }
  return config
})
