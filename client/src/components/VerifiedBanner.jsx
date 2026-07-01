import { useState } from 'react'
import { api } from '../lib/api'

export function VerifiedBanner({ user }) {
  const [state, setState] = useState('idle') // idle | sending | sent | error
  if (!user || user.email_verified_at) return null

  async function resend() {
    setState('sending')
    try {
      await api.post('/api/email/verification-notification')
      setState('sent')
    } catch {
      setState('error')
    }
  }

  return (
    <div role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 flex items-center gap-3">
      <span className="flex-1">
        Please verify your email address to unlock all features.
      </span>
      <button
        onClick={resend}
        disabled={state === 'sending' || state === 'sent'}
        className="rounded-md bg-amber-900 px-3 py-1 text-white text-xs disabled:opacity-50"
      >
        {state === 'sent' ? 'Sent' : state === 'sending' ? 'Sending…' : 'Resend link'}
      </button>
      {state === 'error' && <span className="text-red-700">Try again later.</span>}
    </div>
  )
}
