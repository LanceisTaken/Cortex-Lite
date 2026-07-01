import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState(null)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function onSubmit(e) {
    e.preventDefault()
    setErrors({})
    setFormError(null)
    setMessage(null)
    setBusy(true)
    try {
      const { data } = await api.post('/api/forgot-password', { email })
      setMessage(data.message)
    } catch (err) {
      if (err?.response?.status === 422) {
        const fieldErrors = err.response.data?.errors ?? {}
        setErrors(Object.fromEntries(
          Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])
        ))
      } else if (err?.response?.status === 429) {
        setFormError('Too many requests. Please wait a minute and try again.')
      } else {
        setFormError('Something went wrong. Please try again.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto mt-20 w-full max-w-sm space-y-4 px-4">
      <h1 className="text-2xl font-semibold">Reset your password</h1>
      <form onSubmit={onSubmit} className="space-y-3">
        <FormError message={formError} />
        {message && (
          <div role="status" className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">
            {message}
          </div>
        )}
        <Input id="email" label="Email" type="email" value={email}
          onChange={(e) => setEmail(e.target.value)} error={errors.email}
          autoComplete="email" required />
        <Button type="submit" disabled={busy}>
          {busy ? 'Sending…' : 'Send reset link'}
        </Button>
      </form>
      <div className="text-sm text-center">
        <Link to="/login" className="text-slate-600 hover:underline">Back to log in</Link>
      </div>
    </div>
  )
}
