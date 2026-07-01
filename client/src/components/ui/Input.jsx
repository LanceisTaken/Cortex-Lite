export function Input({ label, error, id, ...props }) {
  return (
    <div className="flex flex-col gap-1">
      {label && <label htmlFor={id} className="text-sm font-medium">{label}</label>}
      <input
        id={id}
        {...props}
        className={`rounded-md border px-3 py-2 text-sm
          ${error ? 'border-red-500' : 'border-slate-300'}
          focus:outline-none focus:ring-2 focus:ring-slate-400`}
      />
      {error && <span className="text-xs text-red-600">{error}</span>}
    </div>
  )
}
