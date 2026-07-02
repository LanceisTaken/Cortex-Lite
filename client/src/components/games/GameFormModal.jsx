import { useEffect, useState } from 'react'
import { Button } from '../ui/Button'
import { FormError } from '../ui/FormError'
import { Input } from '../ui/Input'

const blankForm = {
  title: '',
  platform: '',
  genre: '',
  status: 'backlog',
  playtime_minutes: '0',
  last_played_at: '',
  steam_app_id: '',
  cover_url: '',
}

function toDatetimeLocal(value) {
  if (!value) return ''
  return new Date(value).toISOString().slice(0, 16)
}

function fromInitialGame(game) {
  if (!game) return blankForm
  return {
    title: game.title ?? '',
    platform: game.platform ?? '',
    genre: game.genre ?? '',
    status: game.status ?? 'backlog',
    playtime_minutes: String(game.playtime_minutes ?? 0),
    last_played_at: toDatetimeLocal(game.last_played_at),
    steam_app_id: game.steam_app_id ? String(game.steam_app_id) : '',
    cover_url: game.cover_url ?? '',
  }
}

function cleanPayload(form) {
  return {
    title: form.title,
    platform: form.platform || null,
    genre: form.genre || null,
    status: form.status,
    playtime_minutes: Number(form.playtime_minutes || 0),
    last_played_at: form.last_played_at || null,
    steam_app_id: form.steam_app_id ? Number(form.steam_app_id) : null,
    cover_url: form.cover_url || null,
  }
}

export function GameFormModal({ initialGame, onClose, onSubmit }) {
  const [form, setForm] = useState(() => fromInitialGame(initialGame))
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [busy, setBusy] = useState(false)
  const editing = Boolean(initialGame)

  useEffect(() => {
    setForm(fromInitialGame(initialGame))
    setErrors({})
    setFormError(null)
  }, [initialGame])

  function setField(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function submit(e) {
    e.preventDefault()
    setErrors({})
    setFormError(null)
    setBusy(true)
    try {
      await onSubmit(cleanPayload(form))
    } catch (err) {
      if (err?.response?.status === 422) {
        const fieldErrors = err.response.data?.errors ?? {}
        setErrors(Object.fromEntries(
          Object.entries(fieldErrors).map(([key, value]) => [key, value[0]])
        ))
      } else {
        setFormError('Could not save this game. Please try again.')
      }
      setBusy(false)
    }
  }

  return (
    <div role="dialog" aria-modal="true" className="fixed inset-0 z-20 flex items-center justify-center bg-black/40 p-4">
      <form onSubmit={submit} className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-md bg-white p-6 shadow-xl">
        <div className="mb-4 flex items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">{editing ? 'Edit game' : 'Add game'}</h2>
          <button type="button" onClick={onClose} className="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100">
            Close
          </button>
        </div>
        <div className="space-y-3">
          <FormError message={formError} />
          <Input id="game-title" label="Title" value={form.title}
            onChange={(e) => setField('title', e.target.value)} error={errors.title} required />
          <div className="grid gap-3 sm:grid-cols-2">
            <Input id="game-platform" label="Platform" value={form.platform}
              onChange={(e) => setField('platform', e.target.value)} error={errors.platform} />
            <Input id="game-genre" label="Genre" value={form.genre}
              onChange={(e) => setField('genre', e.target.value)} error={errors.genre} />
          </div>
          <label className="flex flex-col gap-1 text-sm font-medium">
            Status
            <select
              value={form.status}
              onChange={(e) => setField('status', e.target.value)}
              className={`rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 ${errors.status ? 'border-red-500' : 'border-slate-300'}`}
            >
              <option value="playing">Playing</option>
              <option value="backlog">Backlog</option>
              <option value="completed">Completed</option>
              <option value="dropped">Dropped</option>
            </select>
            {errors.status && <span className="text-xs text-red-600">{errors.status}</span>}
          </label>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input id="game-playtime" label="Playtime minutes" type="number" min="0" value={form.playtime_minutes}
              onChange={(e) => setField('playtime_minutes', e.target.value)} error={errors.playtime_minutes} />
            <Input id="game-last-played" label="Last played" type="datetime-local" value={form.last_played_at}
              onChange={(e) => setField('last_played_at', e.target.value)} error={errors.last_played_at} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input id="game-steam-app-id" label="Steam app ID" type="number" min="0" value={form.steam_app_id}
              onChange={(e) => setField('steam_app_id', e.target.value)} error={errors.steam_app_id} />
            <Input id="game-cover-url" label="Cover URL" type="url" value={form.cover_url}
              onChange={(e) => setField('cover_url', e.target.value)} error={errors.cover_url} />
          </div>
          <div className="flex gap-2 pt-2">
            <button type="button" onClick={onClose} className="flex-1 rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
              Cancel
            </button>
            <Button type="submit" disabled={busy} className="flex-1">
              {busy ? 'Saving...' : 'Save'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  )
}
