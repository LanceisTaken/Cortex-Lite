import { useEffect, useRef, useState } from 'react'
import { searchCpus, searchGpus } from '../../lib/hardware'

const CONFIG = {
  gpu: { fetch: searchGpus, benchmarkField: 'g3d_mark', label: 'GPU' },
  cpu: { fetch: searchCpus, benchmarkField: 'single_thread_mark', label: 'CPU' },
}

export function HardwareAutocomplete({ kind, value, onChange, placeholder }) {
  const config = CONFIG[kind]
  if (!config) throw new Error(`HardwareAutocomplete: unknown kind "${kind}"`)

  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const abortRef = useRef(null)
  const containerRef = useRef(null)

  useEffect(() => {
    if (value) {
      setQuery(value.name)
    }
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
    abortRef.current?.abort()
    abortRef.current = controller
    setLoading(true)
    setError(null)

    const timeout = window.setTimeout(async () => {
      try {
        const rows = await config.fetch({ search: query, signal: controller.signal })
        setResults(rows)
      } catch (fetchError) {
        if (fetchError.name !== 'CanceledError' && fetchError.name !== 'AbortError') {
          setError('Could not load hardware options.')
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
  }, [config, open, query])

  function handleInputChange(event) {
    setQuery(event.target.value)
    if (value) onChange(null)
    setOpen(true)
  }

  function selectRow(row) {
    onChange(row)
    setQuery(row.name)
    setOpen(false)
  }

  function clearSelection() {
    onChange(null)
    setQuery('')
    setResults([])
    setOpen(true)
  }

  return (
    <div ref={containerRef} className="relative">
      <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor={`hardware-${kind}`}>
        {config.label}
      </label>
      <div className="flex gap-2">
        <input
          id={`hardware-${kind}`}
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900"
          onChange={handleInputChange}
          onFocus={() => setOpen(true)}
          placeholder={placeholder ?? `Search ${config.label} model`}
          value={query}
        />
        {value ? (
          <button
            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
            onClick={clearSelection}
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
            <div className="px-3 py-2 text-sm text-slate-500">No matches.</div>
          ) : null}
          {results.map((row) => (
            <button
              className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50"
              key={row.id}
              onClick={() => selectRow(row)}
              type="button"
            >
              <span>
                <span className="font-medium">{row.name}</span>
                <span className="ml-2 text-xs text-slate-500">{row.manufacturer} - {row.released_year}</span>
              </span>
              <span className="shrink-0 text-xs text-slate-600">
                <span className="mr-2 uppercase">{row.tier}</span>
                <span>{row[config.benchmarkField].toLocaleString()}</span>
              </span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
