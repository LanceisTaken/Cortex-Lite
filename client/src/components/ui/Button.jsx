export function Button({ children, disabled, ...props }) {
  return (
    <button
      {...props}
      disabled={disabled}
      className={`w-full rounded-md bg-slate-900 px-4 py-2 text-white font-medium
        hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed
        transition ${props.className ?? ''}`}
    >
      {children}
    </button>
  )
}
