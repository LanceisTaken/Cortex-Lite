import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
import { FormError } from '../components/ui/FormError'
import { listHistory } from '../lib/playSessions'

function formatDuration(seconds) {
  if (!seconds) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatDate(value) {
  if (!value) return ''
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function groupByGame(sessions) {
  const map = new Map()
  for (const session of sessions) {
    const key = session.game?.id ?? 'unknown'
    if (!map.has(key)) {
      map.set(key, {
        game: session.game,
        total: session.game?.tracked_duration_seconds_total ?? 0,
        sessions: [],
      })
    }
    const entry = map.get(key)
    entry.sessions.push(session)
  }
  return Array.from(map.values())
}

export default function History() {
  const [sessions, setSessions] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const fetchPage = useCallback(async (signal) => {
    setError(null)
    try {
      const payload = await listHistory({ page, signal })
      setSessions(payload.data)
      setMeta(payload.meta)
    } catch (err) {
      if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return
      setError('Could not load your session history. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [page])

  useEffect(() => {
    setLoading(true)
    const controller = new AbortController()
    fetchPage(controller.signal)
    return () => controller.abort()
  }, [fetchPage])

  const grouped = groupByGame(sessions)

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link to="/dashboard" className="text-sm text-slate-500 hover:underline">Dashboard</Link>
          <h1 className="text-2xl font-semibold">Play history</h1>
        </div>
        <Link to="/library" className="text-sm text-slate-600 hover:underline">Library</Link>
      </header>

      <ActiveSessionBanner />
      <FormError message={error} />

      {loading && sessions.length === 0 ? (
        <div className="rounded-md border border-slate-200 p-8 text-center text-sm text-slate-500">Loading...</div>
      ) : meta.total === 0 ? (
        <section className="rounded-md border border-slate-200 p-8 text-center">
          <h2 className="text-lg font-medium">No sessions yet.</h2>
          <p className="mt-1 text-sm text-slate-600">
            Start a session from the <Link to="/library" className="underline">library</Link> to see it here.
          </p>
        </section>
      ) : (
        <section className="space-y-4">
          {grouped.map(({ game, total, sessions: rows }) => (
            <article key={game?.id ?? 'unknown'} className="rounded-md border border-slate-200 p-4">
              <header className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-medium">{game?.title ?? 'Unknown game'}</h2>
                <span className="text-sm text-slate-600">Total: {formatDuration(total)}</span>
              </header>
              <ul className="mt-3 divide-y divide-slate-100">
                {rows.map((session) => (
                  <li key={session.id} className="flex items-center justify-between py-2 text-sm">
                    <span className="text-slate-700">{formatDate(session.ended_at)}</span>
                    <span className="text-slate-600">{formatDuration(session.duration_seconds)}</span>
                  </li>
                ))}
              </ul>
            </article>
          ))}
        </section>
      )}

      {meta.last_page > 1 && (
        <nav className="flex items-center justify-between text-sm">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((current) => current - 1)}
            className="rounded-md border border-slate-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Previous
          </button>
          <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
          <button
            type="button"
            disabled={page >= meta.last_page}
            onClick={() => setPage((current) => current + 1)}
            className="rounded-md border border-slate-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Next
          </button>
        </nav>
      )}
    </div>
  )
}
