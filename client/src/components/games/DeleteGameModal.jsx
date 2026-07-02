import { useState } from 'react'
import { FormError } from '../ui/FormError'
import { Input } from '../ui/Input'

export function DeleteGameModal({ game, onClose, onConfirm }) {
  const [typedTitle, setTypedTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const confirmed = typedTitle === game.title

  async function submit(e) {
    e.preventDefault()
    if (!confirmed) return
    setBusy(true)
    setError(null)
    try {
      await onConfirm()
    } catch {
      setError('Could not delete this game. Please try again.')
      setBusy(false)
    }
  }

  return (
    <div role="dialog" aria-modal="true" className="fixed inset-0 z-20 flex items-center justify-center bg-black/40 p-4">
      <form onSubmit={submit} className="w-full max-w-sm rounded-md bg-white p-6 shadow-xl">
        <div className="space-y-3">
          <h2 className="text-lg font-semibold">Delete game</h2>
          <p className="text-sm text-slate-600">
            Type <strong>{game.title}</strong> to confirm deletion.
          </p>
          <FormError message={error} />
          <Input id="delete-game-title" value={typedTitle}
            onChange={(e) => setTypedTitle(e.target.value)} />
          <div className="flex gap-2 pt-2">
            <button type="button" onClick={onClose} className="flex-1 rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
              Cancel
            </button>
            <button
              type="submit"
              disabled={!confirmed || busy}
              className="flex-1 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? 'Deleting...' : 'Delete'}
            </button>
          </div>
        </div>
      </form>
    </div>
  )
}
