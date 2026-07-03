import { useEffect, useState } from 'react'

export function LibraryFilters({ filters, onChange, onReset }) {
  const [searchDraft, setSearchDraft] = useState(filters.search)

  useEffect(() => {
    setSearchDraft(filters.search)
  }, [filters.search])

  useEffect(() => {
    const timeout = setTimeout(() => {
      if (searchDraft !== filters.search) onChange({ search: searchDraft })
    }, 300)
    return () => clearTimeout(timeout)
  }, [filters.search, onChange, searchDraft])

  return (
    <div className="grid gap-3 rounded-md border border-slate-200 p-4 md:grid-cols-[1fr_150px_170px_170px_auto]">
      <label className="flex flex-col gap-1 text-sm font-medium">
        Search
        <input
          value={searchDraft}
          onChange={(e) => setSearchDraft(e.target.value)}
          placeholder="Title"
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
        />
      </label>
      <label className="flex flex-col gap-1 text-sm font-medium">
        Status
        <select
          value={filters.status}
          onChange={(e) => onChange({ status: e.target.value })}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
        >
          <option value="">All</option>
          <option value="playing">Playing</option>
          <option value="backlog">Backlog</option>
          <option value="completed">Completed</option>
          <option value="dropped">Dropped</option>
        </select>
      </label>
      <label className="flex flex-col gap-1 text-sm font-medium">
        Metadata
        <select
          value={filters.metadataStatus}
          onChange={(e) => onChange({ metadataStatus: e.target.value })}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
        >
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="ok">Ready</option>
          <option value="missing">Unavailable</option>
        </select>
      </label>
      <label className="flex flex-col gap-1 text-sm font-medium">
        Sort
        <select
          value={filters.sort}
          onChange={(e) => onChange({ sort: e.target.value })}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
        >
          <option value="last_played_desc">Last played</option>
          <option value="title_asc">Title</option>
          <option value="playtime_desc">Playtime</option>
        </select>
      </label>
      <button
        type="button"
        onClick={onReset}
        className="self-end rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
      >
        Reset
      </button>
    </div>
  )
}
