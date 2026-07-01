import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { VerifiedBanner } from '../components/VerifiedBanner'
import { Button } from '../components/ui/Button'

export default function Dashboard() {
  const { user, logout } = useAuth()

  return (
    <div className="mx-auto mt-10 w-full max-w-2xl space-y-6 px-4">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Cortex Lite</h1>
        <div className="flex items-center gap-3 text-sm">
          <Link to="/account" className="text-slate-600 hover:underline">Account</Link>
          <button onClick={logout} className="text-slate-600 hover:underline">Log out</button>
        </div>
      </header>
      <VerifiedBanner user={user} />
      <section className="rounded-md border border-slate-200 p-6">
        <h2 className="text-lg font-medium">Welcome, {user.name}.</h2>
        <p className="mt-1 text-sm text-slate-600">
          Steam library import, session tracking, and the AI settings optimizer
          land in later phases. For now, your account is ready.
        </p>
      </section>
    </div>
  )
}
