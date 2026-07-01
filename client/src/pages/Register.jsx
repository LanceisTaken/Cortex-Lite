import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'

export default function Register() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({
    name: '', email: '', password: '', password_confirmation: '',
  })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [busy, setBusy] = useState(false)

  function update(field) {
    return (e) => setForm((f) => ({ ...f, [field]: e.target.value }))
  }

  async function onSubmit(e) {
    e.preventDefault()
    setErrors({})
    setFormError(null)
    setBusy(true)
    try {
      await register(form)
      navigate('/dashboard')
    } catch (err) {
      if (err?.response?.status === 422) {
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
      <h1 className="text-2xl font-semibold">Create account</h1>
      <form onSubmit={onSubmit} className="space-y-3">
        <FormError message={formError} />
        <Input id="name" label="Name" value={form.name} onChange={update('name')}
          error={errors.name} autoComplete="name" required />
        <Input id="email" label="Email" type="email" value={form.email}
          onChange={update('email')} error={errors.email} autoComplete="email" required />
        <Input id="password" label="Password" type="password" value={form.password}
          onChange={update('password')} error={errors.password}
          autoComplete="new-password" required />
        <Input id="password_confirmation" label="Confirm password" type="password"
          value={form.password_confirmation} onChange={update('password_confirmation')}
          autoComplete="new-password" required />
        <Button type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</Button>
      </form>
      <div className="text-sm text-slate-600 text-center">
        Already have an account? <Link to="/login" className="hover:underline">Log in</Link>
      </div>
    </div>
  )
}
