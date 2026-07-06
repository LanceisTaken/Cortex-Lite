import { useEffect, useRef, useState } from 'react'
import { listGames } from '../../lib/games'

export function GameSelect({ value, onChange }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const containerRef = useRef(null)

  useEffect(() => {
    setQuery(value ? value.title : '')
  }, [value])

  useEffect(() => {
    if (!open) return undefined

    const handleClickAway = (event) => {
      if (!containerRef.current?.contains(event.target)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickAway)
    return () => document.removeEventListener('mousedown', handleClickAway)
  }, [open])

  useEffect(() => {
    if (!open) return undefined

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    const timeout = window.setTimeout(async () => {
      try {
        const payload = await listGames({ search: query, signal: controller.signal })
        setResults(payload.data)
      } catch (fetchError) {
        if (fetchError.name !== 'CanceledError' && fetchError.name !== 'AbortError') {
          setError('Could not load your games.')
        }
      } finally {
        if (!controller.signal.aborted) {
          setLoading(false)
        }
      }
    }, 300)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [open, query])

  function handleInputChange(event) {
    setQuery(event.target.value)
    if (value) onChange(null)
    setOpen(true)
  }

  return (
    <div ref={containerRef} className="relative">
      <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="optimizer-game">
        Game
      </label>
      <div className="flex gap-2">
        <input
          id="optimizer-game"
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900"
          onChange={handleInputChange}
          onFocus={() => setOpen(true)}
          placeholder="Search your library"
          value={query}
        />
        {value ? (
          <button
            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
            onClick={() => { onChange(null); setQuery(''); setResults([]); setOpen(true) }}
            type="button"
          >
            Clear
          </button>
        ) : null}
      </div>

      {open ? (
        <div className="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg">
          {loading ? <div className="px-3 py-2 text-sm text-slate-500">Loading...</div> : null}
          {error ? <div className="px-3 py-2 text-sm text-rose-700">{error}</div> : null}
          {!loading && !error && results.length === 0 ? (
            <div className="px-3 py-2 text-sm text-slate-500">No games match. Sync Steam or add games from the Library.</div>
          ) : null}
          {results.map((game) => (
            <button
              className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50"
              key={game.id}
              onClick={() => { onChange(game); setQuery(game.title); setOpen(false) }}
              type="button"
            >
              <span className="truncate font-medium">{game.title}</span>
              <span className="shrink-0 text-xs uppercase text-slate-500">{game.status}</span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
