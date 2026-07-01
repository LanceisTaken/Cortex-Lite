import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../lib/api'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'

export default function Account() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [modalOpen, setModalOpen] = useState(false)
  const [typedEmail, setTypedEmail] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function confirmDelete() {
    if (typedEmail !== user.email) {
      setError('Email does not match.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      await api.delete('/api/account')
      // Server already invalidated session; clear client state and go home.
      await logout().catch(() => {})
      navigate('/login', { replace: true })
    } catch {
      setError('Delete failed. Please try again.')
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto mt-10 w-full max-w-2xl space-y-6 px-4">
      <h1 className="text-2xl font-semibold">Account</h1>
      <dl className="rounded-md border border-slate-200 p-6 space-y-2 text-sm">
        <div className="flex justify-between"><dt className="text-slate-500">Name</dt><dd>{user.name}</dd></div>
        <div className="flex justify-between"><dt className="text-slate-500">Email</dt><dd>{user.email}</dd></div>
        <div className="flex justify-between">
          <dt className="text-slate-500">Verified</dt>
          <dd>{user.email_verified_at ? 'Yes' : 'No'}</dd>
        </div>
      </dl>
      <section className="rounded-md border border-red-200 p-6 space-y-3">
        <h2 className="font-medium text-red-700">Danger zone</h2>
        <p className="text-sm text-slate-600">
          Delete your account and all associated data. This cannot be undone.
        </p>
        <button
          onClick={() => setModalOpen(true)}
          className="rounded-md bg-red-600 px-4 py-2 text-white text-sm hover:bg-red-700"
        >
          Delete account
        </button>
      </section>
      {modalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          className="fixed inset-0 bg-black/40 flex items-center justify-center p-4"
        >
          <div className="w-full max-w-sm rounded-md bg-white p-6 space-y-3">
            <h3 className="font-medium">Confirm deletion</h3>
            <p className="text-sm text-slate-600">
              Type your email <strong>{user.email}</strong> to confirm.
            </p>
            <FormError message={error} />
            <Input id="confirm-email" value={typedEmail}
              onChange={(e) => setTypedEmail(e.target.value)} />
            <div className="flex gap-2">
              <button
                onClick={() => { setModalOpen(false); setTypedEmail(''); setError(null); }}
                className="flex-1 rounded-md border px-4 py-2 text-sm">
                Cancel
              </button>
              <button
                onClick={confirmDelete}
                disabled={busy}
                className="flex-1 rounded-md bg-red-600 px-4 py-2 text-white text-sm hover:bg-red-700 disabled:opacity-50">
                {busy ? 'Deleting…' : 'Delete'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
