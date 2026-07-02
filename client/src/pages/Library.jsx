import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui/Button'
import { FormError } from '../components/ui/FormError'
import { DeleteGameModal } from '../components/games/DeleteGameModal'
import { GameFormModal } from '../components/games/GameFormModal'
import { LibraryFilters } from '../components/games/LibraryFilters'
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
import { usePlaySession } from '../context/playSessionContextValue'
import { createGame, deleteGame, listGames, updateGame } from '../lib/games'

const defaultFilters = { status: '', search: '', sort: 'last_played_desc' }

function formatPlaytime(minutes) {
  if (!minutes) return '-'
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60
  return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`
}

function formatDate(value) {
  if (!value) return 'Never'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value))
}

function statusLabel(status) {
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function highResSteamCoverUrl(game) {
  if (!game.steam_app_id) return game.cover_url

  if (!game.cover_url || game.cover_url.includes('/steamcommunity/public/images/apps/')) {
    return `https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/${game.steam_app_id}/library_600x900.jpg`
  }

  return game.cover_url
}

function CoverImage({ game }) {
  const [failed, setFailed] = useState(false)
  const coverUrl = highResSteamCoverUrl(game)

  if (!coverUrl || failed) {
    return (
      <div className="flex h-16 w-16 items-center justify-center rounded-md bg-slate-100 text-xs text-slate-400">
        Cover
      </div>
    )
  }

  return (
    <img
      alt={`${game.title} cover`}
      className="h-16 w-16 rounded-md bg-slate-100 object-cover"
      loading="lazy"
      onError={() => setFailed(true)}
      src={coverUrl}
    />
  )
}

export default function Library() {
  const { refresh } = useAuth()
  const { active, start } = usePlaySession()
  const [filters, setFilters] = useState(defaultFilters)
  const [page, setPage] = useState(1)
  const [games, setGames] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [formGame, setFormGame] = useState(null)
  const [formOpen, setFormOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [startingGameId, setStartingGameId] = useState(null)
  const [sessionError, setSessionError] = useState(null)

  const fetchGames = useCallback(async (signal) => {
    setError(null)
    try {
      const payload = await listGames({ ...filters, page, signal })
      setGames(payload.data)
      setMeta(payload.meta)
    } catch (err) {
      if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return
      if (err?.response?.status === 401) refresh()
      setError('Could not load your library. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [filters, page, refresh])

  useEffect(() => {
    setLoading(true)
    const controller = new AbortController()
    fetchGames(controller.signal)
    return () => controller.abort()
  }, [fetchGames])

  function changeFilters(next) {
    setFilters((current) => ({ ...current, ...next }))
    setPage(1)
  }

  function resetFilters() {
    setFilters(defaultFilters)
    setPage(1)
  }

  async function saveGame(payload) {
    if (formGame) {
      await updateGame(formGame.id, payload)
    } else {
      await createGame(payload)
    }
    setFormOpen(false)
    setFormGame(null)
    setLoading(true)
    await fetchGames()
  }

  async function confirmDelete() {
    await deleteGame(deleteTarget.id)
    setDeleteTarget(null)
    setLoading(true)
    await fetchGames()
  }

  async function handleStart(gameId) {
    setStartingGameId(gameId)
    setSessionError(null)
    try {
      await start(gameId)
    } catch (err) {
      if (err.response?.status === 409 && err.response?.data?.error_code === 'play_session_already_active') {
        setSessionError('You already have an active session. End it first.')
      } else {
        setSessionError('Could not start the session. Please try again.')
      }
    } finally {
      setStartingGameId(null)
    }
  }

  const emptyLibrary = !loading && !error && meta.total === 0
    && !filters.status && !filters.search && filters.sort === defaultFilters.sort

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link to="/dashboard" className="text-sm text-slate-500 hover:underline">Dashboard</Link>
          <Link to="/history" className="ml-3 text-sm text-slate-500 hover:underline">History</Link>
          <h1 className="text-2xl font-semibold">Library</h1>
        </div>
        <Button type="button" onClick={() => { setFormGame(null); setFormOpen(true); }} className="w-auto">
          Add game
        </Button>
      </header>

      <ActiveSessionBanner />
      <LibraryFilters filters={filters} onChange={changeFilters} onReset={resetFilters} />
      <FormError message={sessionError} />
      <FormError message={error} />

      {loading && games.length === 0 ? (
        <div className="rounded-md border border-slate-200 p-8 text-center text-sm text-slate-500">Loading...</div>
      ) : emptyLibrary ? (
        <section className="rounded-md border border-slate-200 p-8 text-center">
          <h2 className="text-lg font-medium">Your library is empty.</h2>
          <p className="mt-1 text-sm text-slate-600">Add a manual entry to start tracking your PC games.</p>
          <Button type="button" onClick={() => setFormOpen(true)} className="mx-auto mt-4 w-auto">
            Add your first game
          </Button>
        </section>
      ) : games.length === 0 ? (
        <section className="rounded-md border border-slate-200 p-8 text-center">
          <h2 className="text-lg font-medium">No games match.</h2>
          <button type="button" onClick={resetFilters} className="mt-3 rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
            Reset filters
          </button>
        </section>
      ) : (
        <section className="overflow-hidden rounded-md border border-slate-200">
          <div className="divide-y divide-slate-200">
            {games.map((game) => (
              <article key={game.id} className="grid gap-4 p-4 md:grid-cols-[64px_1fr_auto] md:items-center">
                <CoverImage game={game} />
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="truncate text-base font-semibold">{game.title}</h2>
                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                      {statusLabel(game.status)}
                    </span>
                  </div>
                  <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                    <span>{game.platform || 'Unknown platform'}</span>
                    <span>{game.genre || 'Unknown genre'}</span>
                    <span>{formatPlaytime(game.playtime_minutes)}</span>
                    <span>Last played {formatDate(game.last_played_at)}</span>
                  </div>
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    disabled={active !== null || startingGameId === game.id}
                    onClick={() => handleStart(game.id)}
                    className="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {startingGameId === game.id ? 'Starting...' : 'Start'}
                  </button>
                  <button type="button" onClick={() => { setFormGame(game); setFormOpen(true); }}
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                    Edit
                  </button>
                  <button type="button" onClick={() => setDeleteTarget(game)}
                    className="rounded-md border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50">
                    Delete
                  </button>
                </div>
              </article>
            ))}
          </div>
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

      {formOpen && (
        <GameFormModal
          initialGame={formGame}
          onClose={() => { setFormOpen(false); setFormGame(null); }}
          onSubmit={saveGame}
        />
      )}
      {deleteTarget && (
        <DeleteGameModal
          game={deleteTarget}
          onClose={() => setDeleteTarget(null)}
          onConfirm={confirmDelete}
        />
      )}
    </div>
  )
}
