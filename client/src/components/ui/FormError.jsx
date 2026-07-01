export function FormError({ message }) {
  if (!message) return null
  return (
    <div role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
      {message}
    </div>
  )
}
