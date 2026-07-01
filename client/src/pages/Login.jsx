import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [retryAfter, setRetryAfter] = useState(0)

  useEffect(() => {
    if (retryAfter <= 0) return
    const t = setTimeout(() => setRetryAfter((s) => s - 1), 1000)
    return () => clearTimeout(t)
  }, [retryAfter])

  const disabled = busy || retryAfter > 0

  async function onSubmit(e) {
    e.preventDefault()
    setErrors({})
    setFormError(null)
    setBusy(true)
    try {
      await login(email, password)
      navigate('/dashboard')
    } catch (err) {
      const status = err?.response?.status
      if (status === 429) {
        const secs = Number(err.response.headers['retry-after'] ?? 60)
        setRetryAfter(secs)
        setFormError(`Too many attempts. Try again in ${secs}s.`)
      } else if (status === 422) {
        const fieldErrors = err.response.data?.errors ?? {}
        setErrors(Object.fromEntries(
          Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])
        ))
      } else {
        setFormError('Something went wrong. Please try again.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto mt-20 w-full max-w-sm space-y-4 px-4">
      <h1 className="text-2xl font-semibold">Log in</h1>
      <form onSubmit={onSubmit} className="space-y-3">
        <FormError message={formError} />
        <Input id="email" label="Email" type="email" value={email}
          onChange={(e) => setEmail(e.target.value)} error={errors.email}
          autoComplete="email" required />
        <Input id="password" label="Password" type="password" value={password}
          onChange={(e) => setPassword(e.target.value)} error={errors.password}
          autoComplete="current-password" required />
        <Button type="submit" disabled={disabled}>
          {retryAfter > 0 ? `Wait ${retryAfter}s` : busy ? 'Logging in…' : 'Log in'}
        </Button>
      </form>
      <div className="flex justify-between text-sm">
        <Link to="/forgot-password" className="text-slate-600 hover:underline">Forgot password?</Link>
        <Link to="/register" className="text-slate-600 hover:underline">Create account</Link>
      </div>
    </div>
  )
}
