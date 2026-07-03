const states = {
  ok: {
    label: 'Metadata ready',
    className: 'bg-emerald-500',
  },
  pending: {
    label: 'Metadata pending',
    className: 'bg-amber-500',
  },
  missing: {
    label: 'Metadata unavailable',
    className: 'bg-slate-400',
  },
}

export function MetadataStatusBadge({ status }) {
  const state = states[status] ?? states.missing

  return (
    <span
      aria-label={state.label}
      className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-white"
      role="status"
      title={state.label}
    >
      <span className={`h-2.5 w-2.5 rounded-full ${state.className}`} />
    </span>
  )
}
