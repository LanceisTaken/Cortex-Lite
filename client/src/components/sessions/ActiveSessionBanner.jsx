import { useState } from 'react'
import { usePlaySession } from '../../context/playSessionContextValue'

function formatElapsed(seconds) {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) return `${h}h ${m}m`
  if (m > 0) return `${m}m ${s}s`
  return `${s}s`
}

export function ActiveSessionBanner() {
  const { active, elapsedSeconds, end, error } = usePlaySession()
  const [ending, setEnding] = useState(false)
  const [endError, setEndError] = useState(null)

  if (!active) return error ? (
    <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{error}</div>
  ) : null

  async function handleEnd() {
    setEnding(true)
    setEndError(null)
    try {
      await end()
    } catch {
      setEndError('Could not end the session. Please try again.')
    } finally {
      setEnding(false)
    }
  }

  return (
    <section className="flex flex-col gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-emerald-950 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="text-sm font-medium">In progress</p>
        <p className="truncate text-base font-semibold">{active.game?.title ?? 'Current session'}</p>
        <p className="text-sm text-emerald-800">{formatElapsed(elapsedSeconds)}</p>
        {endError ? <p className="mt-1 text-sm text-rose-700">{endError}</p> : null}
      </div>
      <button
        type="button"
        disabled={ending}
        onClick={handleEnd}
        className="rounded-md border border-emerald-700 px-3 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {ending ? 'Ending...' : 'End'}
      </button>
    </section>
  )
}
