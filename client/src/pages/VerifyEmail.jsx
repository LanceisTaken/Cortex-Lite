import { useEffect, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { api } from '../lib/api'
import { useAuth } from '../context/AuthContext'

export default function VerifyEmail() {
  const { id, hash } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const { refresh } = useAuth()
  const [status, setStatus] = useState('verifying') // verifying | ok | error
  const [message, setMessage] = useState('')

  useEffect(() => {
    let mounted = true
    async function run() {
      try {
        // location.search contains ?signature=…&expires=… verbatim.
        await api.post(`/api/email/verify/${id}/${hash}${location.search}`)
        if (!mounted) return
        await refresh()
        setStatus('ok')
        setTimeout(() => navigate('/dashboard', { replace: true }), 1500)
      } catch (err) {
        if (!mounted) return
        setStatus('error')
        if (err?.response?.status === 403) {
          setMessage('This verification link is invalid or has expired.')
        } else if (err?.response?.status === 401) {
          setMessage('Please log in first, then click the verification link again.')
        } else {
          setMessage('Verification failed. Please try again.')
        }
      }
    }
    run()
    return () => { mounted = false }
  }, [id, hash, location.search, navigate, refresh])

  return (
    <div className="mx-auto mt-20 w-full max-w-sm px-4 text-center space-y-3">
      <h1 className="text-2xl font-semibold">Email verification</h1>
      {status === 'verifying' && <p className="text-slate-600">Verifying your email…</p>}
      {status === 'ok' && <p className="text-emerald-700">Email verified. Redirecting…</p>}
      {status === 'error' && <p className="text-red-700">{message}</p>}
    </div>
  )
}
