import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'

export default function ResetPassword() {
  const { token } = useParams()
  const [searchParams] = useSearchParams()
  const emailFromLink = useMemo(() => searchParams.get('email') ?? '', [searchParams])
  const navigate = useNavigate()
  const [email, setEmail] = useState(emailFromLink)
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function onSubmit(e) {
    e.preventDefault()
    setErrors({})
    setFormError(null)
    setBusy(true)
    try {
      await api.post('/api/reset-password', {
        token, email, password, password_confirmation: passwordConfirmation,
      })
      navigate('/login', { replace: true })
    } catch (err) {
      if (err?.response?.status === 422) {
        const fieldErrors = err.response.data?.errors ?? {}
        setErrors(Object.fromEntries(
          Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])
        ))
        if (fieldErrors.email && !fieldErrors.password) {
          setFormError('This reset link is invalid or expired. Request a new one.')
        }
      } else {
        setFormError('Something went wrong. Please try again.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto mt-20 w-full max-w-sm space-y-4 px-4">
      <h1 className="text-2xl font-semibold">Choose a new password</h1>
      <form onSubmit={onSubmit} className="space-y-3">
        <FormError message={formError} />
        <Input id="email" label="Email" type="email" value={email}
          onChange={(e) => setEmail(e.target.value)} error={errors.email}
          autoComplete="email" required />
        <Input id="password" label="New password" type="password" value={password}
          onChange={(e) => setPassword(e.target.value)} error={errors.password}
          autoComplete="new-password" required />
        <Input id="password_confirmation" label="Confirm new password" type="password"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          autoComplete="new-password" required />
        <Button type="submit" disabled={busy}>
          {busy ? 'Updating…' : 'Update password'}
        </Button>
      </form>
      <div className="text-sm text-center">
        <Link to="/login" className="text-slate-600 hover:underline">Back to log in</Link>
      </div>
    </div>
  )
}
